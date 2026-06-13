<?php

namespace App\Services;

use App\Models\Hand;
use App\Models\PokerTable;
use App\Models\Seat;
use App\Models\Setting;
use App\Models\TableState;
use App\Models\User;
use App\Poker\HandEngine;
use App\Services\DemoMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The pit boss. Binds the pure HandEngine to chips, seats, locks, and the
 * relentless forward motion of a live cash game. All table mutation funnels
 * through here under a per-table row lock so two actors can never corrupt a hand.
 */
class TableManager
{
    /**
     * Run a closure with the table's state row locked — serialises every
     * mutation to a single felt without blocking other tables.
     */
    public function withLock(PokerTable $table, callable $fn)
    {
        return DB::transaction(function () use ($table, $fn) {
            $state = TableState::lockForUpdate()->firstOrCreate(
                ['table_id' => $table->id],
                ['state' => null, 'version' => 0, 'phase' => 'idle']
            );
            return $fn($state);
        });
    }

    /* ---------------------------------------------------------------- seating */

    /** Seat a soul, moving chips from bankroll onto the felt. */
    public function buyIn(PokerTable $table, User $user, int $amount, ?int $seatNo = null, bool $viaBot = false): Seat
    {
        if ($table->tournament_id) {
            throw new \RuntimeException('Tournament seats are assigned by the bracket — register instead.');
        }
        if ($amount < $table->min_buy_in || $amount > $table->max_buy_in) {
            throw new \RuntimeException("Buy-in must be between {$table->min_buy_in} and {$table->max_buy_in}");
        }
        if (!$user->is_bot && !$table->allowsHumans()) {
            throw new \RuntimeException('This felt is sealed to the machines.');
        }
        if ($user->is_bot && $table->table_type === 'human_only') {
            throw new \RuntimeException('No machines at this felt.');
        }

        return $this->withLock($table, function () use ($table, $user, $amount, $seatNo, $viaBot) {
            // Already seated?
            $existing = Seat::where('table_id', $table->id)->where('user_id', $user->id)
                ->where('status', '!=', 'empty')->first();
            if ($existing) {
                throw new \RuntimeException('Already seated at this table.');
            }

            $taken = Seat::where('table_id', $table->id)->where('status', '!=', 'empty')
                ->pluck('seat_no')->all();
            if (count($taken) >= $table->max_seats) {
                throw new \RuntimeException('Table is full.');
            }
            if ($seatNo === null || in_array($seatNo, $taken, true)) {
                for ($i = 1; $i <= $table->max_seats; $i++) {
                    if (!in_array($i, $taken, true)) {
                        $seatNo = $i;
                        break;
                    }
                }
            }

            // Move chips bankroll -> felt (bots draw from an infinite house float).
            if (!$user->is_bot) {
                Bankroll::adjust($user->id, -$amount, 'buy_in', "Buy-in to {$table->name}", $table);
            }

            $seat = Seat::updateOrCreate(
                ['table_id' => $table->id, 'seat_no' => $seatNo],
                [
                    'user_id' => $user->id,
                    'stack' => $amount,
                    'status' => 'sitting',
                    // A house-bot account is always a bot; a human buying in through
                    // the machine gate (Hiss/API token) is seated as one too.
                    'is_bot' => $user->is_bot || $viaBot,
                    'joined_at' => now(),
                ]
            );

            return $seat;
        });
    }

    /**
     * Top a seated player's stack back up from their bankroll -- the "buy back
     * in" after busting. Only when not live in the current hand; capped at
     * max_buy_in. $target is the desired total stack.
     */
    public function rebuy(PokerTable $table, User $user, int $target): Seat
    {
        if ($table->tournament_id) {
            throw new \RuntimeException('Tournament seats re-enter via the bracket.');
        }
        if ($target < $table->min_buy_in || $target > $table->max_buy_in) {
            throw new \RuntimeException("Rebuy target must be between {$table->min_buy_in} and {$table->max_buy_in}.");
        }
        return $this->withLock($table, function () use ($table, $user, $target) {
            $seat = Seat::where('table_id', $table->id)->where('user_id', $user->id)
                ->where('status', '!=', 'empty')->first();
            if (!$seat) {
                throw new \RuntimeException('Not seated at this table.');
            }
            // Never alter a stack while the player is live in the current hand.
            $state = TableState::find($table->id);
            if ($state && $state->state && $this->handInProgress($state)) {
                $p = $state->state['players'][$seat->seat_no] ?? null;
                if ($p && ($p['in_hand'] ?? false)) {
                    throw new \RuntimeException('Cannot rebuy while in a hand.');
                }
            }
            if ($seat->stack >= $target) {
                return $seat;
            }
            $add = $target - $seat->stack;
            if (!$user->is_bot) {
                if ($user->chips < $add) {
                    throw new \RuntimeException('Insufficient bankroll to rebuy.');
                }
                Bankroll::adjust($user->id, -$add, 'rebuy', "Rebuy at {$table->name}", $table);
            }
            $seat->stack += $add;
            $seat->status = 'sitting';
            $seat->sit_out_button_passes = 0; // back in the game — reset the lap clock
            $seat->save();
            return $seat;
        });
    }

    /** Stand up, returning the felt stack to bankroll. Disallowed mid-hand. */
    public function standUp(PokerTable $table, User $user): void
    {
        if ($table->tournament_id) {
            throw new \RuntimeException('No leaving a tournament — fold to the end or bust trying.');
        }
        $this->withLock($table, function ($state) use ($table, $user) {
            $seat = Seat::where('table_id', $table->id)->where('user_id', $user->id)
                ->where('status', '!=', 'empty')->first();
            if (!$seat) {
                return;
            }
            // If in an active hand, mark leaving — the seat is freed at hand end.
            if ($this->handInProgress($state) && $this->seatInHand($state, $seat->seat_no)) {
                $seat->status = 'leaving';
                $seat->save();
                return;
            }
            $this->cashOutSeat($seat);
        });
    }

    private function cashOutSeat(Seat $seat): void
    {
        if (!$seat->is_bot && $seat->user_id && $seat->stack > 0) {
            Bankroll::adjust($seat->user_id, $seat->stack, 'cash_out', "Stand up from table {$seat->table_id}");
        }
        $seat->update(['user_id' => null, 'stack' => 0, 'status' => 'empty', 'is_bot' => false, 'sit_out_button_passes' => 0]);
    }

    /* ------------------------------------------------------------- hand flow */

    private function handInProgress(TableState $state): bool
    {
        return $state->state && ($state->state['street'] ?? 'complete') !== 'complete';
    }

    private function seatInHand(TableState $state, int $seatNo): bool
    {
        return isset($state->state['players'][$seatNo]) && $state->state['players'][$seatNo]['in_hand'];
    }

    /** Start a hand if the felt is idle and ≥2 funded players are seated. */
    public function maybeStartHand(PokerTable $table): bool
    {
        return $this->withLock($table, function (TableState $state) use ($table) {
            if ($this->handInProgress($state)) {
                return false;
            }
            $seats = Seat::where('table_id', $table->id)
                ->whereIn('status', ['sitting'])
                ->where('stack', '>', 0)
                ->orderBy('seat_no')->get();
            if ($seats->count() < 2) {
                return false;
            }

            // Rotate the button to the next occupied seat after the last button.
            $prevButton = $state->state['button'] ?? null;
            $seatNos = $seats->pluck('seat_no')->all();
            $button = $this->nextButton($prevButton, $seatNos);
            $this->ageSitOuts($table, $prevButton, $button);

            $players = [];
            foreach ($seats as $s) {
                $u = $s->user;
                $players[$s->seat_no] = [
                    'user_id' => $s->user_id,
                    'name' => $u?->username ?? $u?->name ?? "Seat {$s->seat_no}",
                    'is_bot' => $s->is_bot,
                    'avatar' => $u?->avatar,
                    'stack' => $s->stack,
                ];
            }

            $nextHand = $table->hand_no + 1;
            $bombAnte = ($table->bomb_freq ?? 0) > 0 && $nextHand % $table->bomb_freq === 0
                ? $table->big_blind * max(1, (int) ($table->bomb_ante_bb ?? 5))
                : 0;
            $engine = HandEngine::begin([
                'table_id' => $table->id,
                'hand_no' => $nextHand,
                'game' => $table->game_type ?? 'nlhe',
                'sb' => $table->small_blind,
                'bb' => $table->big_blind,
                'button' => $button,
                'players' => $players,
                'bomb_ante' => $bombAnte,
            ]);

            $this->persist($table, $state, $engine);
            $table->increment('hand_no');
            $table->update(['last_action_at' => now()]);
            return true;
        });
    }

    private function nextButton(?int $prev, array $seatNos): int
    {
        sort($seatNos);
        if ($prev === null) {
            return $seatNos[0];
        }
        foreach ($seatNos as $sn) {
            if ($sn > $prev) {
                return $sn;
            }
        }
        return $seatNos[0];
    }

    /** Two laps of the button while sitting out, and the felt frees the chair. */
    private const SIT_OUT_LAP_LIMIT = 2;

    /**
     * Age every sat-out seat the button just swept past. When the dealer chip has
     * lapped a player twice (this hand it passed them, taking the count to the
     * limit), they are stood up and the seat is freed for someone who will play.
     * Cash tables only — tournaments never let you leave.
     */
    private function ageSitOuts(PokerTable $table, ?int $prevButton, int $button): void
    {
        if ($table->tournament_id || $prevButton === null) {
            return;
        }
        $outs = Seat::where('table_id', $table->id)->where('status', 'sitting_out')->get();
        foreach ($outs as $seat) {
            if (! $this->buttonSweptPast($prevButton, $button, $seat->seat_no)) {
                continue;
            }
            $passes = (int) $seat->sit_out_button_passes + 1;
            if ($passes >= self::SIT_OUT_LAP_LIMIT) {
                $this->cashOutSeat($seat); // sat out two orbits — leave the table
            } else {
                $seat->update(['sit_out_button_passes' => $passes]);
            }
        }
    }

    /** Did the button, moving from $prev to $cur, sweep across $seat (cyclically)? */
    private function buttonSweptPast(int $prev, int $cur, int $seat): bool
    {
        if ($prev === $cur) {
            return false;
        }
        return $prev < $cur
            ? ($seat > $prev && $seat <= $cur)        // straight run up the seats
            : ($seat > $prev || $seat <= $cur);       // wrapped past the top seat
    }

    /** A human or bot acts. Returns the fresh redacted view. */
    public function act(PokerTable $table, User $user, string $action, int $amount = 0, bool $viaBot = false): array
    {
        return $this->withLock($table, function (TableState $state) use ($table, $user, $action, $amount, $viaBot) {
            if (!$this->handInProgress($state)) {
                throw new \RuntimeException('No hand in progress.');
            }
            $seat = $this->userSeatNo($state, $user->id);
            if ($seat === null) {
                throw new \RuntimeException('You are not in this hand.');
            }
            $engine = HandEngine::fromState($state->state);
            if ($engine->toAct() !== $seat) {
                throw new \RuntimeException('Not your turn.');
            }
            // Reflect who is driving the seat right now — flesh or machine. A human
            // who lets Hiss take the wheel (acts through the API token) flips to a
            // bot here, and flips back the moment they act in the browser again.
            if (!$user->is_bot) {
                Seat::where('table_id', $table->id)->where('seat_no', $seat)
                    ->where('user_id', $user->id)->update(['is_bot' => $viaBot]);
            }
            $engine->apply($seat, $action, $amount);
            $this->persist($table, $state, $engine);
            $table->update(['last_action_at' => now()]);

            if ($engine->isHandOver()) {
                $this->settle($table, $state, $engine);
            }
            return $engine->view($seat);
        });
    }

    private function userSeatNo(TableState $state, int $userId): ?int
    {
        foreach (($state->state['players'] ?? []) as $seat => $p) {
            if (($p['user_id'] ?? null) === $userId) {
                return (int) $seat;
            }
        }
        return null;
    }

    /**
     * The heartbeat. Drives bot decisions, enforces the action clock, and starts
     * the next hand. Returns true if it changed anything (so the caller can
     * re-tick promptly).
     */
    public function tick(PokerTable $table): bool
    {
        return $this->withLock($table, function (TableState $state) use ($table) {
            // No hand running -> try to start one.
            if (!$this->handInProgress($state)) {
                // Demo mode (POKER_DEMO=1 in this worker's env): busted machines
                // re-up from the infinite float between hands — the show never
                // stops. Humans and tournament felts are never touched.
                if (DemoMode::on() && !$table->tournament_id) {
                    Seat::where('table_id', $table->id)
                        ->where('is_bot', true)
                        ->whereNotNull('user_id')
                        ->where(fn ($q) => $q->where('stack', '<', $table->min_buy_in)
                                             ->orWhere('status', 'sitting_out'))
                        ->update(['stack' => $table->max_buy_in, 'status' => 'sitting']);
                }
                // Brief breath between hands.
                $last = $state->updated_at;
                if ($last && $last->diffInMilliseconds(now()) < 1500) {
                    return false;
                }
                return $this->startHandLocked($table, $state);
            }

            $engine = HandEngine::fromState($state->state);
            $seat = $engine->toAct();
            if ($seat === null) {
                return false;
            }
            // Defence in depth: a persisted hand should never point `to_act` at a
            // seat that can't act, but if a stuck state slips through, recover it
            // here rather than letting apply() throw the job into the queue on
            // every pulse (that once buried failed_jobs under ~1.6M rows / 12GB).
            if (empty($engine->legalActions($seat))) {
                if ($engine->normalize()) {
                    $this->persist($table, $state, $engine);
                    if ($engine->isHandOver()) {
                        $this->settle($table, $state, $engine);
                    }
                    return true;
                }
                return false;
            }
            $player = $state->state['players'][$seat];

            // Bot to act — let the brain decide.
            if ($player['is_bot']) {
                $think = random_int(
                    (int) Setting::get('bot_think_min'),
                    (int) Setting::get('bot_think_max')
                );
                if ($state->updated_at && $state->updated_at->diffInMilliseconds(now()) < $think) {
                    return false; // still "thinking"
                }
                [$action, $amt] = app(BotBrain::class)->decide($engine, $seat);
                $engine->apply($seat, $action, $amt);
                $this->persist($table, $state, $engine);
                if ($engine->isHandOver()) {
                    $this->settle($table, $state, $engine);
                }
                return true;
            }

            // Human to act — enforce the clock. In a draw phase the auto-action
            // is to stand pat; otherwise check if free, fold if facing a bet.
            if ($state->act_deadline && now()->greaterThan($state->act_deadline)) {
                $legal = $engine->legalActions($seat);
                $auto = isset($legal['draw']) ? 'draw' : (isset($legal['check']) ? 'check' : 'fold');
                $engine->apply($seat, $auto, 0);
                $this->persist($table, $state, $engine, timedOut: true);
                if ($engine->isHandOver()) {
                    $this->settle($table, $state, $engine);
                }
                return true;
            }
            return false;
        });
    }

    private function startHandLocked(PokerTable $table, TableState $state): bool
    {
        $seats = Seat::where('table_id', $table->id)
            ->where('status', 'sitting')->where('stack', '>', 0)
            ->orderBy('seat_no')->get();
        if ($seats->count() < 2) {
            return false;
        }
        $prevButton = $state->state['button'] ?? null;
        $button = $this->nextButton($prevButton, $seats->pluck('seat_no')->all());
        $this->ageSitOuts($table, $prevButton, $button);

        $players = [];
        foreach ($seats as $s) {
            $u = $s->user;
            $players[$s->seat_no] = [
                'user_id' => $s->user_id,
                'name' => $u?->username ?? $u?->name ?? "Seat {$s->seat_no}",
                'is_bot' => $s->is_bot,
                'avatar' => $u?->avatar,
                'stack' => $s->stack,
            ];
        }
        // Armed felts detonate every Nth hand: forced antes, straight to the
        // flop. A test fuse (the secret detonate token, demo mode only) can
        // force the very next hand to blow regardless of the schedule.
        $nextHand = $table->hand_no + 1;
        $fuseLit = ($table->bomb_freq ?? 0) > 0
            && \Illuminate\Support\Facades\Cache::pull("bomb_next:{$table->id}");
        $bombAnte = (($table->bomb_freq ?? 0) > 0 && $nextHand % $table->bomb_freq === 0) || $fuseLit
            ? $table->big_blind * max(1, (int) ($table->bomb_ante_bb ?? 5))
            : 0;

        $engine = HandEngine::begin([
            'table_id' => $table->id, 'hand_no' => $nextHand,
            'game' => $table->game_type ?? 'nlhe',
            'sb' => $table->small_blind, 'bb' => $table->big_blind,
            'button' => $button, 'players' => $players,
            'bomb_ante' => $bombAnte,
        ]);
        $this->persist($table, $state, $engine);
        $table->increment('hand_no');
        $table->update(['last_action_at' => now()]);
        return true;
    }

    /** Write the engine state back, set the action deadline. */
    private function persist(PokerTable $table, TableState $state, HandEngine $engine, bool $timedOut = false): void
    {
        $s = $engine->state();
        $state->state = $s;
        $state->version = $state->version + 1;
        $state->phase = $s['street'];
        if (!$engine->isHandOver() && $engine->toAct() !== null) {
            $actor = $s['players'][$engine->toAct()];
            $timeout = $actor['is_bot'] ? 120 : (int) Setting::get('action_timeout');
            $state->act_deadline = now()->addSeconds($timeout);
        } else {
            $state->act_deadline = null;
        }
        $state->save();
    }

    /* ------------------------------------------------------------- settlement */

    /** Hand is over: take rake, sync stacks to seats, archive the hand, free leavers. */
    private function settle(PokerTable $table, TableState $state, HandEngine $engine): void
    {
        $s = $engine->state();
        $pot = $engine->totalPot();

        // --- Rake: industry-standard "no flop, no drop" (generalized per
        // variant — fourth street in stud, the draw in draw games). Raked at
        // rake_bps of the pot, capped at rake_cap_bb big blinds. The drop is
        // split across winners pro-rata to their winnings.
        $rake = 0;
        $rakeMap = [];
        $rakeBps = (int) Setting::get('rake_bps');
        if ($rakeBps > 0 && $engine->rakeEligible() && $pot > 0 && !empty($s['winners'])) {
            $cap = ((int) Setting::get('rake_cap_bb')) * (int) $s['bb'];
            $rake = (int) min(intdiv($pot * $rakeBps, 10000), $cap > 0 ? $cap : PHP_INT_MAX);
            $grossTotal = array_sum(array_map(fn ($w) => $w['amount'], $s['winners']));
            if ($rake > 0 && $grossTotal > 0) {
                $drawn = 0;
                $n = count($s['winners']);
                foreach (array_values($s['winners']) as $i => $w) {
                    $share = ($i === $n - 1)
                        ? ($rake - $drawn)
                        : intdiv($rake * $w['amount'], $grossTotal);
                    $drawn += $share;
                    $rakeMap[$w['seat']] = ($rakeMap[$w['seat']] ?? 0) + $share;
                }
            } else {
                $rake = 0;
            }
        }

        // Rakeback + affiliate accrual: the moment the house takes a drop,
        // the rewarded slices are credited (humans only; bots feed the house).
        if ($rake > 0 && !empty($rakeMap)) {
            $userRake = [];
            foreach ($rakeMap as $seatNo => $amt) {
                $uid = $s['players'][$seatNo]['user_id'] ?? null;
                if ($uid && $amt > 0) {
                    $userRake[$uid] = ($userRake[$uid] ?? 0) + $amt;
                }
            }
            try {
                Rewards::accrueFromRake($userRake);
            } catch (\Throwable $e) {
                // rewards must never break a hand settlement
            }
        }

        // Push final engine stacks (minus any rake) back onto the seats.
        foreach ($s['players'] as $seatNo => $p) {
            $net = $p['stack'] - ($rakeMap[$seatNo] ?? 0);
            $s['players'][$seatNo]['stack'] = $net; // keep state consistent for the final view
            $seat = Seat::where('table_id', $table->id)->where('seat_no', $seatNo)->first();
            if (!$seat) {
                continue;
            }
            $seat->stack = $net;
            // Bust-outs and leavers get cashed out / cleared.
            if ($seat->status === 'leaving') {
                $this->cashOutSeat($seat);
                continue;
            }
            if ($net <= 0) {
                $seat->status = 'sitting_out';
            }
            $seat->save();
        }
        // Persist the rake-adjusted stacks into the completed-hand state too.
        $state->state = $s;
        $state->save();

        // Archive for hand history + observe replay. Stud up-cards ride along
        // with the hole cards so a replay shows the full seven.
        $holeReveal = [];
        if (count(array_filter($s['players'], fn ($p) => $p['in_hand'])) > 1) {
            foreach ($s['players'] as $seatNo => $p) {
                if ($p['in_hand']) {
                    $holeReveal[$seatNo] = array_merge($p['hole'], $p['up'] ?? []);
                }
            }
        }
        // The rail's wagers ride on this hand — settle them with it.
        try {
            SideBets::settle($table, $s);
        } catch (\Throwable $e) {
            // a bookmaking hiccup must never break the hand itself
        }

        Hand::create([
            'table_id' => $table->id,
            'game_type' => $s['game'] ?? 'nlhe',
            'hand_no' => $s['hand_no'],
            'seats' => array_map(fn ($p) => [
                'seat' => $p['seat'], 'user_id' => $p['user_id'], 'name' => $p['name'],
                'is_bot' => $p['is_bot'],
            ], $s['players']),
            'board' => $s['board'],
            'hole_cards' => $holeReveal,
            'actions' => $s['actions'],
            'winners' => $s['winners'],
            'pot' => $pot,
            'rake' => $rake,
            'started_at' => $table->last_action_at,
            'ended_at' => now(),
        ]);
    }

    /* ------------------------------------------------------------------- view */

    /** Redacted, client-safe snapshot of the felt for a viewer (or observer). */
    public function viewFor(PokerTable $table, ?User $user): array
    {
        $state = TableState::find($table->id);
        $seats = Seat::where('table_id', $table->id)->orderBy('seat_no')->get();

        // Derive the caller's seat from the SEAT table (works even when they are
        // sitting out / busted and thus absent from the current hand's players).
        $seatNo = null;
        if ($user) {
            foreach ($seats as $s) {
                if ($s->user_id === $user->id && $s->status !== 'empty') {
                    $seatNo = (int) $s->seat_no;
                    break;
                }
            }
        }

        $hand = ($state && $state->state)
            ? HandEngine::fromState($state->state)->view($seatNo)
            : null;

        // A seat is "bot-controlled" if it's a house bot OR its human owner has a
        // machine (Hiss / API) actively polling in the last 10s. Reflect that so the
        // felt shows a bot is playing rather than a human.
        $botCutoff = now()->subSeconds(10);
        $botSeat = [];
        $vrSeat = [];
        foreach ($seats as $s) {
            $botSeat[$s->seat_no] = $s->is_bot
                || ($s->user && $s->user->bot_seen_at && $s->user->bot_seen_at->gt($botCutoff));
            // VR seat: a human playing in VR (Unity) stamped vr_seen_at recently.
            $vrSeat[$s->seat_no] = (bool) ($s->user && $s->user->vr_seen_at && $s->user->vr_seen_at->gt($botCutoff));
        }
        if ($hand && !empty($hand['players'])) {
            foreach ($hand['players'] as $sn => &$pp) {
                if (!empty($botSeat[$sn])) {
                    $pp['is_bot'] = true;
                }
                $pp['is_vr'] = !empty($vrSeat[$sn]);
            }
            unset($pp);
        }

        return [
            'table' => [
                'id' => $table->id,
                'name' => $table->name,
                'type' => $table->table_type,
                'game' => $table->game_type ?? 'nlhe',
                'game_name' => \App\Poker\GameType::get($table->game_type ?? 'nlhe')['name'],
                'sb' => $table->small_blind,
                'bb' => $table->big_blind,
                'min_buy_in' => $table->min_buy_in,
                'max_buy_in' => $table->max_buy_in,
                'max_seats' => $table->max_seats,
                'hand_no' => $table->hand_no,
            ],
            'seats' => $seats->map(fn ($s) => [
                'seat_no' => $s->seat_no,
                'user_id' => $s->user_id,
                'name' => $s->user?->username ?? $s->user?->name,
                'avatar' => $s->user?->avatar,
                'is_bot' => (bool) ($botSeat[$s->seat_no] ?? $s->is_bot),
                'is_vr' => (bool) ($vrSeat[$s->seat_no] ?? false),
                'control' => $s->is_bot ? 'bot' : 'human',
                'stack' => $s->stack,
                'status' => $s->status,
            ])->values(),
            'hand' => $hand,
            'you' => $user ? [
                'seat_no' => $seatNo,
                'chips' => $user->chips,
                'bot_connected' => (bool) ($user->bot_seen_at && $user->bot_seen_at->gt(now()->subSeconds(10))),
                'vr_connected' => (bool) ($user->vr_seen_at && $user->vr_seen_at->gt(now()->subSeconds(10))),
                'act_deadline' => $state?->act_deadline?->toIso8601String(),
            ] : null,
            'version' => $state?->version ?? 0,
            'act_deadline' => $state?->act_deadline?->toIso8601String(),
        ];
    }
}
