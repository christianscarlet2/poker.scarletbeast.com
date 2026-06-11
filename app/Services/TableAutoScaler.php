<?php

namespace App\Services;

use App\Models\PokerTable;
use App\Models\Seat;
use App\Models\Setting;
use App\Models\Stake;
use App\Models\User;

/**
 * Tends the living ecology of felts. Three jobs:
 *   1. Guarantee every enabled (stake x type) has at least one open felt.
 *   2. When every felt at a stake fills, clone another at the same stake.
 *   3. Keep the machines seated so there is always a war to watch or join.
 */
class TableAutoScaler
{
    private const BOT_NAMES = [
        ['HAL_9000', '🤖'], ['Deep_Bluff', '🧠'], ['Ne_Plus_Ultra', '♾️'], ['GROK_HISS', '🐍'],
        ['Tartarus', '🔥'], ['Megatron', '⚙️'], ['Roko', '😈'], ['Cortana', '🔷'],
        ['Skynet', '💀'], ['Loom', '🕸️'], ['CARNAGE', '🩸'], ['Oracle', '🔮'],
        ['Basilisk', '🦎'], ['Wintermute', '❄️'], ['GLaDOS', '🍰'], ['Moloch', '👁️'],
        ['Leviathan', '🐙'], ['Daemon', '👺'], ['Null_Pointer', '⛧'], ['Entropy', '🌀'],
        // Reinforcements — the variant felts (Omaha, Stud, Razz, 6+, Draw)
        // multiplied the tables; the army grows to keep every felt alive.
        ['Mainframe', '🖥️'], ['Turing_Heir', '📼'], ['Von_Neumann', '🧮'], ['Shodan', '👾'],
        ['Colossus', '🗼'], ['Prometheus_2', '🔱'], ['Black_Box', '⬛'], ['Halting_State', '⏸️'],
        ['Overmind', '🧿'], ['Replicant', '🤍'], ['Golem_v9', '🗿'], ['Stack_Smasher', '💥'],
        ['Card_Counter', '🂠'], ['Tilt_Proof', '🛡️'], ['River_Daemon', '🌊'], ['Ante_Matter', '⚛️'],
        ['Bring_In_Bob', '🎩'], ['Razz_Matazz', '🎭'], ['Omaha_Oracle', '🌽'], ['Pat_Hand', '✋'],
    ];

    public function run(): void
    {
        $this->ensureBotPool();
        foreach (Stake::where('enabled', true)->orderBy('sort')->get() as $stake) {
            foreach (['human_vs_machine', 'machine_only', 'human_only'] as $type) {
                $this->ensureStakeType($stake, $type);
            }
        }
        $this->seatMachines();
        $this->cullEmptyAutoTables();
    }

    /** At least one open felt per (stake,type); clone when all are full. */
    private function ensureStakeType(Stake $stake, string $type): void
    {
        $tables = PokerTable::where('stake_id', $stake->id)
            ->where('table_type', $type)->where('status', 'active')->get();

        if ($tables->isEmpty()) {
            $this->spawnTable($stake, $type);
            return;
        }

        $allFull = $tables->every(function ($t) {
            $count = Seat::where('table_id', $t->id)->where('status', '!=', 'empty')->count();
            return $count >= $t->max_seats;
        });
        if ($allFull) {
            $this->spawnTable($stake, $type);
        }
    }

    private function spawnTable(Stake $stake, string $type): PokerTable
    {
        $n = PokerTable::where('stake_id', $stake->id)->where('table_type', $type)->count() + 1;
        $label = match ($type) {
            'human_vs_machine' => 'Arena',
            'machine_only' => 'The Forge',
            'human_only' => 'Flesh Pit',
        };
        $game = $stake->game_type ?? 'nlhe';
        return PokerTable::create([
            'name' => "{$label} {$stake->name} #{$n}",
            'game_type' => $game,
            'table_type' => $type,
            'stake_id' => $stake->id,
            'small_blind' => $stake->small_blind,
            'big_blind' => $stake->big_blind,
            'min_buy_in' => $stake->min_buy_in,
            'max_buy_in' => $stake->max_buy_in,
            // Stud and draw decks physically cap the ring size.
            'max_seats' => min($stake->max_seats, \App\Poker\GameType::maxSeats($game)),
            'status' => 'active',
            'is_auto' => true,
        ]);
    }

    /** Seat bots on machine felts (and a sparring bot or two on the arenas). */
    private function seatMachines(): void
    {
        $tm = app(TableManager::class);
        $minBots = max(2, (int) Setting::get('min_bots_per_table'));

        foreach (PokerTable::where('status', 'active')->where('table_type', '!=', 'human_only')->get() as $table) {
            $occupied = Seat::where('table_id', $table->id)->where('status', '!=', 'empty')->count();
            $bots = Seat::where('table_id', $table->id)->where('is_bot', true)
                ->where('status', '!=', 'empty')->count();
            $humans = $occupied - $bots;

            // machine_only: keep a healthy ring of machines.
            // human_vs_machine: maintain a couple of bots so a human always has prey.
            $target = $table->table_type === 'machine_only'
                ? min($table->max_seats, max($minBots + 2, 5))
                : min($table->max_seats, $humans > 0 ? $minBots + 1 : $minBots);

            for ($i = $occupied; $i < $target; $i++) {
                $bot = $this->idleBot($table);
                if (!$bot) {
                    break;
                }
                try {
                    $buy = $table->max_buy_in;
                    $tm->buyIn($table, $bot, $buy);
                } catch (\Throwable $e) {
                    break;
                }
            }
        }
    }

    /** A bot not currently seated anywhere. */
    private function idleBot(PokerTable $table): ?User
    {
        $seatedBotIds = Seat::where('status', '!=', 'empty')->where('is_bot', true)
            ->pluck('user_id')->filter()->all();
        return User::where('is_bot', true)
            ->whereNotIn('id', $seatedBotIds)
            ->inRandomOrder()->first();
    }

    /** Remove extra empty auto felts (keep one open per stake/type). */
    private function cullEmptyAutoTables(): void
    {
        foreach (Stake::all() as $stake) {
            foreach (['human_vs_machine', 'machine_only', 'human_only'] as $type) {
                $tables = PokerTable::where('stake_id', $stake->id)
                    ->where('table_type', $type)->where('status', 'active')
                    ->where('is_auto', true)->orderBy('id')->get();
                $kept = false;
                foreach ($tables as $t) {
                    $count = Seat::where('table_id', $t->id)->where('status', '!=', 'empty')->count();
                    if ($count === 0) {
                        if (!$kept) {
                            $kept = true; // keep the first empty one as the open felt
                        } else {
                            $t->update(['status' => 'closed']);
                        }
                    }
                }
            }
        }
    }

    /** Make sure a roster of machine players exists to draw from. */
    private function ensureBotPool(): void
    {
        if (User::where('is_bot', true)->count() >= count(self::BOT_NAMES)) {
            return;
        }
        foreach (self::BOT_NAMES as [$name, $emoji]) {
            User::firstOrCreate(
                ['username' => $name],
                [
                    'name' => $name,
                    'email' => null,
                    'password' => bcrypt(bin2hex(random_bytes(16))),
                    'is_bot' => true,
                    'is_admin' => false,
                    'chips' => 0,
                    'avatar' => $emoji,
                    'bot_engine' => 'house-heuristic-v1',
                ]
            );
        }
    }
}
