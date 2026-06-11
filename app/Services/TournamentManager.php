<?php

namespace App\Services;

use App\Models\PokerTable;
use App\Models\Seat;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The bracket god. Runs every tournament's life: registration (buy-in to the
 * prize pool), the deal-out across tables, the timed blind ladder, bust-outs,
 * table balancing, and the final payout by finishing order. Cash mechanics
 * reuse the cash-game engine wholesale — a tournament table is a poker_table
 * whose blinds the clock rewrites and whose chips are glory, not dollars.
 */
class TournamentManager
{
    public function __construct(private TableManager $tm)
    {
    }

    /* ------------------------------------------------------------ lifecycle */

    public function register(Tournament $t, User $user): TournamentEntry
    {
        if (!in_array($t->status, ['registering', 'scheduled'], true)) {
            throw new \RuntimeException('Registration is closed.');
        }
        if ($t->entries()->where('status', '!=', 'refunded')->count() >= $t->max_players) {
            throw new \RuntimeException('Field is full.');
        }
        return DB::transaction(function () use ($t, $user) {
            $existing = TournamentEntry::where('tournament_id', $t->id)->where('user_id', $user->id)->first();
            if ($existing && $existing->status !== 'refunded') {
                throw new \RuntimeException('Already registered.');
            }
            $cost = $t->buy_in + $t->fee;
            if (!$user->is_bot && $cost > 0) {
                Bankroll::adjust($user->id, -$cost, 'tournament_buy_in', "Buy-in: {$t->name}");
            }
            $t->increment('prize_pool', $t->buy_in);
            return TournamentEntry::updateOrCreate(
                ['tournament_id' => $t->id, 'user_id' => $user->id],
                ['status' => 'registered', 'place' => null, 'prize' => 0]
            );
        });
    }

    public function unregister(Tournament $t, User $user): void
    {
        if (!in_array($t->status, ['registering', 'scheduled'], true)) {
            throw new \RuntimeException('Too late to back out.');
        }
        DB::transaction(function () use ($t, $user) {
            $e = TournamentEntry::where('tournament_id', $t->id)->where('user_id', $user->id)
                ->where('status', 'registered')->first();
            if (!$e) {
                throw new \RuntimeException('Not registered.');
            }
            if (!$user->is_bot && ($t->buy_in + $t->fee) > 0) {
                Bankroll::adjust($user->id, $t->buy_in + $t->fee, 'tournament_refund', "Unregister: {$t->name}");
            }
            $t->decrement('prize_pool', min($t->buy_in, $t->prize_pool));
            $e->update(['status' => 'refunded']);
        });
    }

    /** Deal the field out across fresh tables and open the clock. */
    public function start(Tournament $t): void
    {
        if ($t->status === 'running') {
            return;
        }
        $entries = $t->entries()->where('status', 'registered')->with('user')->get();
        if ($entries->count() < $t->min_players) {
            throw new \RuntimeException("Need at least {$t->min_players} players.");
        }

        DB::transaction(function () use ($t, $entries) {
            $blinds = $t->currentBlinds();
            $shuffled = $entries->shuffle();
            $nTables = (int) ceil($shuffled->count() / $t->seats_per_table);

            $tables = [];
            for ($i = 1; $i <= $nTables; $i++) {
                $tables[] = PokerTable::create([
                    'name' => "{$t->name} — Table {$i}",
                    'game_type' => $t->game_type,
                    'table_type' => 'human_vs_machine',
                    'tournament_id' => $t->id,
                    'stake_id' => null,
                    'small_blind' => $blinds['sb'],
                    'big_blind' => $blinds['bb'],
                    'min_buy_in' => $t->starting_stack,
                    'max_buy_in' => $t->starting_stack,
                    'max_seats' => $t->seats_per_table,
                    'status' => 'active',
                    'is_auto' => false,
                ]);
            }

            // Snake-seat the field so tables start near-even.
            foreach ($shuffled->values() as $i => $entry) {
                $table = $tables[$i % $nTables];
                $seatNo = intdiv($i, $nTables) + 1;
                Seat::updateOrCreate(
                    ['table_id' => $table->id, 'seat_no' => $seatNo],
                    [
                        'user_id' => $entry->user_id,
                        'stack' => $t->starting_stack,
                        'status' => 'sitting',
                        'is_bot' => (bool) $entry->user?->is_bot,
                        'joined_at' => now(),
                    ]
                );
                $entry->update(['status' => 'playing']);
            }

            $t->update([
                'status' => 'running',
                'started_at' => now(),
                'level' => 0,
                'level_started_at' => now(),
            ]);
        });
    }

    /** Cancel + refund everyone still in. */
    public function cancel(Tournament $t): void
    {
        DB::transaction(function () use ($t) {
            foreach ($t->entries()->whereIn('status', ['registered', 'playing'])->with('user')->get() as $e) {
                if (!$e->user?->is_bot && ($t->buy_in + $t->fee) > 0) {
                    Bankroll::adjust($e->user_id, $t->buy_in + $t->fee, 'tournament_refund', "Cancelled: {$t->name}");
                }
                $e->update(['status' => 'refunded']);
            }
            foreach ($t->tables as $table) {
                Seat::where('table_id', $table->id)->update(['user_id' => null, 'stack' => 0, 'status' => 'empty', 'is_bot' => false]);
                $table->update(['status' => 'closed']);
            }
            $t->update(['status' => 'cancelled', 'prize_pool' => 0, 'finished_at' => now()]);
        });
    }

    /* -------------------------------------------------------------- the clock */

    /**
     * Pulse every running tournament: advance blind levels by time, eliminate
     * busted stacks, balance tables, crown a champion. Called from the dealer
     * loop alongside the cash-table autoscaler.
     */
    public function tick(): void
    {
        // Auto-start scheduled tournaments whose hour has come.
        foreach (Tournament::whereIn('status', ['registering', 'scheduled'])
            ->whereNotNull('starts_at')->where('starts_at', '<=', now())->get() as $t) {
            try {
                $this->start($t);
            } catch (\Throwable $e) {
                // not enough players — push the start back a bit
                $t->update(['starts_at' => now()->addMinutes(5)]);
            }
        }

        foreach (Tournament::where('status', 'running')->get() as $t) {
            $this->advanceLevel($t);
            $this->sweepBusts($t);
            $this->balance($t);
            $this->maybeFinish($t);
        }
    }

    /** Blind ladder: when the level's minutes elapse, raise the stakes. */
    private function advanceLevel(Tournament $t): void
    {
        $levels = $t->blind_levels;
        $cur = $levels[min($t->level, count($levels) - 1)];
        $mins = max(1, (int) ($cur['minutes'] ?? 10));
        if ($t->level >= count($levels) - 1) {
            return; // final level rides forever
        }
        if ($t->level_started_at && $t->level_started_at->diffInMinutes(now()) >= $mins) {
            $t->update(['level' => $t->level + 1, 'level_started_at' => now()]);
            $b = $t->currentBlinds();
            PokerTable::where('tournament_id', $t->id)->where('status', 'active')
                ->update(['small_blind' => $b['sb'], 'big_blind' => $b['bb']]);
        }
    }

    /** Bust-outs: zero stacks between hands become finishing places. */
    private function sweepBusts(Tournament $t): void
    {
        $busted = Seat::whereIn('table_id', $t->tables()->pluck('id'))
            ->whereNotNull('user_id')
            ->where('stack', '<=', 0)
            ->whereIn('status', ['sitting', 'sitting_out'])
            ->get();
        if ($busted->isEmpty()) {
            return;
        }
        // Don't eliminate mid-hand — only seats not in a live hand.
        foreach ($busted as $seat) {
            $state = \App\Models\TableState::find($seat->table_id);
            $inHand = $state && $state->state
                && ($state->state['street'] ?? 'complete') !== 'complete'
                && !empty($state->state['players'][$seat->seat_no]['in_hand']);
            if ($inHand) {
                continue;
            }
            $remaining = TournamentEntry::where('tournament_id', $t->id)->where('status', 'playing')->count();
            TournamentEntry::where('tournament_id', $t->id)->where('user_id', $seat->user_id)
                ->where('status', 'playing')
                ->update(['status' => 'busted', 'place' => $remaining]);
            $seat->update(['user_id' => null, 'stack' => 0, 'status' => 'empty', 'is_bot' => false]);
        }
    }

    /**
     * Keep tables balanced: close short tables by moving their players onto
     * seats elsewhere (only between hands), collapsing toward a final table.
     */
    private function balance(Tournament $t): void
    {
        $tables = $t->tables()->where('status', 'active')->get();
        if ($tables->count() <= 1) {
            return;
        }
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table->id] = Seat::where('table_id', $table->id)->where('status', '!=', 'empty')->count();
        }
        $live = array_sum($counts);
        // Can the field fit on one fewer table?
        if ($live > ($tables->count() - 1) * $t->seats_per_table) {
            return;
        }
        // Break the shortest table — but never one mid-hand.
        asort($counts);
        $breakId = array_key_first($counts);
        $state = \App\Models\TableState::find($breakId);
        if ($state && $state->state && ($state->state['street'] ?? 'complete') !== 'complete') {
            return; // wait for the hand to finish
        }
        $movers = Seat::where('table_id', $breakId)->where('status', '!=', 'empty')->get();
        $targets = $tables->where('id', '!=', $breakId);
        foreach ($movers as $mover) {
            foreach ($targets as $target) {
                $taken = Seat::where('table_id', $target->id)->where('status', '!=', 'empty')->pluck('seat_no')->all();
                if (count($taken) >= $target->max_seats) {
                    continue;
                }
                for ($n = 1; $n <= $target->max_seats; $n++) {
                    if (!in_array($n, $taken, true)) {
                        Seat::updateOrCreate(
                            ['table_id' => $target->id, 'seat_no' => $n],
                            ['user_id' => $mover->user_id, 'stack' => $mover->stack, 'status' => 'sitting', 'is_bot' => $mover->is_bot, 'joined_at' => now()]
                        );
                        $mover->update(['user_id' => null, 'stack' => 0, 'status' => 'empty', 'is_bot' => false]);
                        continue 3;
                    }
                }
            }
        }
        // If everyone found a new seat, fold the felt.
        if (Seat::where('table_id', $breakId)->where('status', '!=', 'empty')->count() === 0) {
            \App\Models\TableState::where('table_id', $breakId)->delete();
            PokerTable::where('id', $breakId)->update(['status' => 'closed']);
        }
    }

    /** One player left standing → champion, payouts, close the books. */
    private function maybeFinish(Tournament $t): void
    {
        $playing = TournamentEntry::where('tournament_id', $t->id)->where('status', 'playing')->get();
        if ($playing->count() !== 1) {
            return;
        }
        DB::transaction(function () use ($t, $playing) {
            $champ = $playing->first();
            $champ->update(['status' => 'busted', 'place' => 1]);

            // Pay by percentage table; remainder (rounding) rides on 1st.
            $pcts = $t->payout_pct ?: [100];
            $paid = 0;
            foreach ($pcts as $i => $pct) {
                $place = $i + 1;
                $entry = TournamentEntry::where('tournament_id', $t->id)->where('place', $place)->with('user')->first();
                if (!$entry) {
                    continue;
                }
                $amt = ($place === 1)
                    ? 0 // assigned after the loop with the remainder
                    : intdiv($t->prize_pool * (int) $pct, 100);
                if ($place !== 1) {
                    $paid += $amt;
                    $entry->update(['prize' => $amt]);
                    if (!$entry->user?->is_bot && $amt > 0) {
                        Bankroll::adjust($entry->user_id, $amt, 'tournament_prize', "{$t->name} — {$place}.");
                    }
                }
            }
            $first = TournamentEntry::where('tournament_id', $t->id)->where('place', 1)->with('user')->first();
            if ($first) {
                $amt = max(0, $t->prize_pool - $paid);
                $first->update(['prize' => $amt]);
                if (!$first->user?->is_bot && $amt > 0) {
                    Bankroll::adjust($first->user_id, $amt, 'tournament_prize', "{$t->name} — CHAMPION");
                }
            }

            foreach ($t->tables()->where('status', 'active')->get() as $table) {
                Seat::where('table_id', $table->id)->update(['user_id' => null, 'stack' => 0, 'status' => 'empty', 'is_bot' => false]);
                \App\Models\TableState::where('table_id', $table->id)->delete();
                $table->update(['status' => 'closed']);
            }
            $t->update(['status' => 'finished', 'finished_at' => now()]);
        });
    }

    /** Admin convenience: pad the field with idle machines. */
    public function fillWithBots(Tournament $t, int $count): int
    {
        $added = 0;
        $registered = $t->entries()->where('status', '!=', 'refunded')->pluck('user_id')->all();
        foreach (User::where('is_bot', true)->whereNotIn('id', $registered)->inRandomOrder()->limit($count)->get() as $bot) {
            try {
                $this->register($t, $bot);
                $added++;
            } catch (\Throwable $e) {
                break;
            }
        }
        return $added;
    }
}
