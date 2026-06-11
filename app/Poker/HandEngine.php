<?php

namespace App\Poker;

/**
 * The dealer god. A pure, serialisable No-Limit Texas Hold'em hand state
 * machine. It knows nothing of databases or HTTP — feed it a config, apply
 * legal actions, read back a redacted view. All money is integer chips.
 *
 * Streets: preflop -> flop -> turn -> river -> showdown -> complete.
 */
final class HandEngine
{
    public function __construct(private array $s)
    {
    }

    public function state(): array
    {
        return $this->s;
    }

    public static function fromState(array $state): self
    {
        return new self($state);
    }

    /* ----------------------------------------------------------------------
     * Hand setup
     * ------------------------------------------------------------------- */

    /**
     * Begin a new hand.
     *
     * @param array $config {
     *   table_id:int, hand_no:int, sb:int, bb:int, button:int,
     *   players: array<int seat, {user_id,name,is_bot,stack,avatar}>,
     *   game?:string (GameType id, default 'nlhe'), seed?:string
     * }
     */
    public static function begin(array $config): self
    {
        $seed = $config['seed'] ?? Deck::freshSeed();
        $game = GameType::exists($config['game'] ?? '') ? $config['game'] : 'nlhe';
        $rules = GameType::get($game);
        $base = $rules['deck'] === 'short' ? Card::shortDeck() : Card::fullDeck();
        $deck = new Deck($seed, $base);

        $players = [];
        foreach ($config['players'] as $seat => $p) {
            if (($p['stack'] ?? 0) <= 0) {
                continue; // no chips, no hand
            }
            $players[$seat] = [
                'seat' => (int) $seat,
                'user_id' => $p['user_id'] ?? null,
                'name' => $p['name'] ?? ('Seat ' . $seat),
                'is_bot' => (bool) ($p['is_bot'] ?? false),
                'avatar' => $p['avatar'] ?? null,
                'stack' => (int) $p['stack'],
                'hole' => [],
                'up' => [],              // face-up cards (stud games)
                'in_hand' => true,
                'all_in' => false,
                'committed_street' => 0,
                'committed_total' => 0,
                'has_acted' => false,
            ];
        }
        ksort($players);
        $seats = array_keys($players);
        if (count($seats) < 2) {
            throw new \RuntimeException('Need at least 2 funded players to deal');
        }

        $s = [
            'table_id' => $config['table_id'],
            'hand_no' => $config['hand_no'],
            'game' => $game,
            'seed' => $seed,
            'sb' => (int) $config['sb'],
            'bb' => (int) $config['bb'],
            'button' => (int) $config['button'],
            'players' => $players,
            'board' => [],
            'street' => GameType::STREETS[$rules['family']][0],
            'pot_committed' => 0,    // chips already pulled into the central pot from prior streets
            'current_bet' => 0,
            'min_raise' => (int) $config['bb'],
            'raises' => 0,           // raises this street (fixed-limit cap)
            'to_act' => null,
            'last_aggressor' => null,
            'actions' => [],
            'pots' => [],
            'winners' => [],
            'started_at' => null,
        ];

        $engine = new self($s);

        if ($rules['family'] === 'stud') {
            $engine->beginStud($deck);
            return $engine;
        }

        // Flop and draw families: blinds, hole cards, action left of the blinds.
        $engine->postBlinds();
        $engine->dealHole($deck, $rules['hole']);
        // Persist remaining deck by seed+position: store the full shuffled order.
        $engine->s['deck'] = Deck::shuffleWithSeed($base, $seed);
        $engine->s['deck_pos'] = count($seats) * $rules['hole'];
        $engine->setFirstToActPreflop();
        return $engine;
    }

    /** The variant's rulebook entry. Legacy states (no 'game' key) read as NLHE. */
    private function rules(): array
    {
        return GameType::get($this->s['game'] ?? 'nlhe');
    }

    public function game(): string
    {
        return $this->s['game'] ?? 'nlhe';
    }

    /**
     * Stud setup: everyone antes, three cards each (two down, one up), the
     * forced bring-in opens — lowest door card in stud, highest in razz.
     */
    private function beginStud(Deck $deck): void
    {
        $seats = $this->orderedSeats();
        $ante = max(1, intdiv($this->s['sb'], 2));
        $bringIn = $this->s['sb'];
        $this->s['ante'] = $ante;
        $this->s['bring_in'] = $bringIn;

        // Antes go straight to the pot — they're dead money, not street bets.
        foreach ($seats as $sn) {
            $p = &$this->s['players'][$sn];
            $pay = min($ante, $p['stack']);
            $p['stack'] -= $pay;
            $p['committed_total'] += $pay;
            $this->s['pot_committed'] += $pay;
            if ($p['stack'] === 0) {
                $p['all_in'] = true;
            }
            unset($p);
        }

        // Two down, one up — dealt one at a time, casino style.
        for ($round = 0; $round < 3; $round++) {
            foreach ($seats as $sn) {
                $card = $deck->draw();
                if ($round < 2) {
                    $this->s['players'][$sn]['hole'][] = $card;
                } else {
                    $this->s['players'][$sn]['up'][] = $card;
                }
            }
        }
        $this->s['deck'] = Deck::shuffleWithSeed(Card::fullDeck(), $this->s['seed']);
        $this->s['deck_pos'] = count($seats) * 3;

        // Bring-in: rank the door cards (suit breaks ties: c<d<h<s).
        $suitOrd = ['c' => 0, 'd' => 1, 'h' => 2, 's' => 3];
        $doorKey = function (int $sn) use ($suitOrd): int {
            $c = $this->s['players'][$sn]['up'][0];
            return Card::rankValue($c) * 4 + $suitOrd[Card::suit($c)];
        };
        $razz = ($this->rules()['lo'] !== null && !$this->rules()['hi']);
        $bringSeat = $seats[0];
        foreach ($seats as $sn) {
            $cmp = $doorKey($sn) <=> $doorKey($bringSeat);
            if ($razz ? $cmp > 0 : $cmp < 0) {
                $bringSeat = $sn;
            }
        }
        $this->commit($bringSeat, $bringIn, 'bring_in');
        $this->s['current_bet'] = $this->s['players'][$bringSeat]['committed_street'];
        $this->s['to_act'] = $this->nextSeat($bringSeat, fn ($p) => $this->canAct($p));
        $this->normalize();
    }

    private function orderedSeats(): array
    {
        $seats = array_keys($this->s['players']);
        sort($seats);
        return $seats;
    }

    /** Seats still contesting the pot (have cards). */
    private function liveSeats(): array
    {
        return array_values(array_filter($this->orderedSeats(), fn ($x) => $this->s['players'][$x]['in_hand']));
    }

    /** Next occupied seat clockwise from $seat (optionally only live/able-to-act). */
    private function nextSeat(int $seat, callable $pred): ?int
    {
        $seats = $this->orderedSeats();
        $n = count($seats);
        $start = array_search($seat, $seats, true);
        if ($start === false) {
            $start = -1;
        }
        for ($i = 1; $i <= $n; $i++) {
            $cand = $seats[($start + $i) % $n];
            if ($pred($this->s['players'][$cand])) {
                return $cand;
            }
        }
        return null;
    }

    private function postBlinds(): void
    {
        $seats = $this->orderedSeats();
        $heads = count($seats) === 2;
        $btn = $this->s['button'];

        if ($heads) {
            // Heads-up: button is the small blind and acts first preflop.
            $sbSeat = $btn;
            $bbSeat = $this->nextSeat($btn, fn () => true);
        } else {
            $sbSeat = $this->nextSeat($btn, fn () => true);
            $bbSeat = $this->nextSeat($sbSeat, fn () => true);
        }

        $this->commit($sbSeat, $this->s['sb'], 'post_sb');
        $this->commit($bbSeat, $this->s['bb'], 'post_bb');
        $this->s['current_bet'] = $this->s['bb'];
        $this->s['min_raise'] = $this->s['bb'];
        $this->s['last_aggressor'] = $bbSeat;
        // Blinds don't count as voluntary "acted" — players still get to act.
        $this->s['players'][$sbSeat]['has_acted'] = false;
        $this->s['players'][$bbSeat]['has_acted'] = false;
        $this->s['_sb_seat'] = $sbSeat;
        $this->s['_bb_seat'] = $bbSeat;
    }

    private function dealHole(Deck $deck, int $count = 2): void
    {
        // One card at a time around the table, starting left of button — casino style.
        $seats = $this->orderedSeats();
        for ($round = 0; $round < $count; $round++) {
            foreach ($seats as $seat) {
                $this->s['players'][$seat]['hole'][] = $deck->draw();
            }
        }
    }

    private function setFirstToActPreflop(): void
    {
        $seats = $this->orderedSeats();
        $heads = count($seats) === 2;
        if ($heads) {
            // Button/SB acts first preflop — but only if able. A button that
            // posted itself all-in on the small blind can't act, so fall through
            // to the next able seat rather than leaving a dead pointer.
            $btn = $this->s['button'];
            $this->s['to_act'] = $this->canAct($this->s['players'][$btn])
                ? $btn
                : $this->nextSeat($btn, fn ($p) => $this->canAct($p));
        } else {
            // UTG = seat left of big blind.
            $this->s['to_act'] = $this->nextSeat($this->s['_bb_seat'], fn ($p) => $this->canAct($p));
        }
        // Short stacks can go all-in just posting blinds, leaving <2 players with
        // a voluntary decision. Resolve that now instead of wedging the table.
        $this->normalize();
    }

    /* ----------------------------------------------------------------------
     * Actions
     * ------------------------------------------------------------------- */

    private function canAct(array $p): bool
    {
        return $p['in_hand'] && !$p['all_in'];
    }

    /** What can the seat legally do right now? */
    public function legalActions(int $seat): array
    {
        if ($this->s['to_act'] !== $seat) {
            return [];
        }
        $p = $this->s['players'][$seat] ?? null;
        if (!$p) {
            return [];
        }

        // Draw phase: the only move is to swap 0..N cards (all-in players too).
        if ($this->s['street'] === 'draw') {
            return $p['in_hand'] ? ['draw' => ['max' => count($p['hole'])]] : [];
        }

        if (!$this->canAct($p)) {
            return [];
        }
        $toCall = $this->s['current_bet'] - $p['committed_street'];
        $betting = $this->rules()['betting'];
        $actions = [];

        if ($toCall <= 0) {
            $actions['check'] = true;
        } else {
            $actions['fold'] = true;
            $actions['call'] = ['amount' => min($toCall, $p['stack'])];
        }
        if ($toCall > 0 && $toCall < $p['stack']) {
            // fold already added
        } elseif ($toCall <= 0) {
            $actions['fold'] = true; // allowed though usually pointless
        }

        // Betting / raising, shaped by the variant's structure.
        $stack = $p['stack'];
        if ($stack > $toCall) {
            if ($this->s['current_bet'] === 0) {
                $max = match ($betting) {
                    'fixed_limit' => min($this->streetBet(), $stack),
                    'pot_limit' => min(max($this->potSize(), $this->s['min_raise']), $stack),
                    default => $stack,
                };
                $min = $betting === 'fixed_limit' ? $max : min($this->s['min_raise'], $max);
                $actions['bet'] = ['min' => $min, 'max' => $max];
            } elseif ($betting !== 'fixed_limit' || ($this->s['raises'] ?? 0) < 4) {
                $maxRaiseTo = $p['committed_street'] + $stack;
                if ($betting === 'fixed_limit') {
                    $to = min($this->fixedRaiseTo(), $maxRaiseTo);
                    $actions['raise'] = ['min_to' => $to, 'max_to' => $to];
                } else {
                    $minRaiseTo = $this->s['current_bet'] + $this->s['min_raise'];
                    if ($betting === 'pot_limit') {
                        $maxRaiseTo = min($maxRaiseTo, $this->s['current_bet'] + $this->potSize() + $toCall);
                    }
                    $minRaiseTo = min($minRaiseTo, $maxRaiseTo);
                    $actions['raise'] = ['min_to' => $minRaiseTo, 'max_to' => $maxRaiseTo];
                }
            }
        }
        return $actions;
    }

    /**
     * Apply an action. $action in: fold|check|call|bet|raise. For bet, $amount
     * is the bet size (chips added). For raise, $amount is the total street
     * commitment ("raise to").
     */
    public function apply(int $seat, string $action, int $amount = 0): void
    {
        if ($this->s['to_act'] !== $seat) {
            throw new \RuntimeException('Not your turn');
        }

        // The draw phase is dealing, not betting — all-in players still swap
        // cards, so this is handled before the can-act (not-all-in) guard.
        // $amount is a discard bitmask: bit i set = replace hole card i.
        if ($action === 'draw') {
            if ($this->s['street'] !== 'draw') {
                throw new \RuntimeException('Not the draw phase');
            }
            $p = &$this->s['players'][$seat];
            if (!$p['in_hand']) {
                throw new \RuntimeException('You cannot act');
            }
            $kept = [];
            $drawn = 0;
            foreach ($p['hole'] as $i => $card) {
                if ($amount & (1 << $i)) {
                    $drawn++;
                } else {
                    $kept[] = $card;
                }
            }
            for ($i = 0; $i < $drawn; $i++) {
                if ($this->s['deck_pos'] >= count($this->s['deck'])) {
                    break; // deck exhausted — keep what's left (seats are capped to avoid this)
                }
                $kept[] = $this->s['deck'][$this->s['deck_pos']++];
            }
            $p['hole'] = $kept;
            $p['has_acted'] = true;
            $this->log($seat, 'draw', $drawn);
            unset($p);
            $this->advance();
            return;
        }

        $p = &$this->s['players'][$seat];
        if (!$this->canAct($p)) {
            throw new \RuntimeException('You cannot act');
        }
        $toCall = $this->s['current_bet'] - $p['committed_street'];
        $betting = $this->rules()['betting'];

        switch ($action) {
            case 'fold':
                $p['in_hand'] = false;
                $p['has_acted'] = true;
                $this->log($seat, 'fold', 0);
                break;

            case 'check':
                if ($toCall > 0) {
                    throw new \RuntimeException('Cannot check facing a bet');
                }
                $p['has_acted'] = true;
                $this->log($seat, 'check', 0);
                break;

            case 'call':
                $pay = min($toCall, $p['stack']);
                $this->commit($seat, $pay, 'call');
                $p['has_acted'] = true;
                break;

            case 'bet':
                if ($this->s['current_bet'] !== 0) {
                    throw new \RuntimeException('Cannot bet; there is already a bet — raise instead');
                }
                if ($betting === 'fixed_limit') {
                    // The size isn't a choice: it's the street's fixed unit.
                    $amount = min($this->streetBet(), $p['stack']);
                } elseif ($betting === 'pot_limit') {
                    $amount = min($amount, $this->potSize(), $p['stack']);
                }
                if ($amount < min($this->s['min_raise'], $p['stack'])) {
                    throw new \RuntimeException('Bet below minimum');
                }
                if ($amount > $p['stack']) {
                    throw new \RuntimeException('Bet exceeds stack');
                }
                $this->commit($seat, $amount, 'bet');
                $this->s['current_bet'] = $p['committed_street'];
                $this->s['min_raise'] = $amount;
                $this->s['last_aggressor'] = $seat;
                $this->s['raises'] = ($this->s['raises'] ?? 0) + 1;
                $this->resetActedExcept($seat);
                $p['has_acted'] = true;
                break;

            case 'raise':
                $raiseTo = $amount;
                $maxTo = $p['committed_street'] + $p['stack'];
                if ($betting === 'fixed_limit') {
                    if (($this->s['raises'] ?? 0) >= 4) {
                        throw new \RuntimeException('Betting is capped');
                    }
                    // Fixed size: complete the bring-in, or one unit on top.
                    $raiseTo = min($this->fixedRaiseTo(), $maxTo);
                } elseif ($betting === 'pot_limit') {
                    $raiseTo = min($raiseTo, $this->s['current_bet'] + $this->potSize() + $toCall, $maxTo);
                }
                if ($raiseTo > $maxTo) {
                    throw new \RuntimeException('Raise exceeds stack');
                }
                $minRaiseTo = $this->s['current_bet'] + $this->s['min_raise'];
                $isAllIn = $raiseTo === $maxTo;
                if ($raiseTo < $minRaiseTo && !$isAllIn && $betting !== 'fixed_limit') {
                    throw new \RuntimeException('Raise below minimum');
                }
                $increment = $raiseTo - $this->s['current_bet'];
                if ($increment <= 0) {
                    throw new \RuntimeException('Raise must exceed the current bet');
                }
                $pay = $raiseTo - $p['committed_street'];
                $this->commit($seat, $pay, 'raise');
                // A full raise re-opens betting and sets the new min-raise size.
                if ($increment >= $this->s['min_raise'] || $betting === 'fixed_limit') {
                    if ($betting !== 'fixed_limit') {
                        $this->s['min_raise'] = $increment;
                    }
                    $this->resetActedExcept($seat);
                }
                $this->s['current_bet'] = $p['committed_street'];
                $this->s['last_aggressor'] = $seat;
                $this->s['raises'] = ($this->s['raises'] ?? 0) + 1;
                $p['has_acted'] = true;
                break;

            default:
                throw new \RuntimeException("Unknown action: {$action}");
        }
        unset($p);

        $this->advance();
    }

    /** Current total pot including this street's live commitments. */
    private function potSize(): int
    {
        $pot = $this->s['pot_committed'];
        foreach ($this->s['players'] as $p) {
            $pot += $p['committed_street'];
        }
        return $pot;
    }

    /** Fixed-limit unit for the current street: small bet early, big bet late. */
    private function streetBet(): int
    {
        $bb = $this->s['bb'];
        return in_array($this->s['street'], ['turn', 'river', 'fifth', 'sixth', 'seventh'], true)
            ? $bb * 2
            : $bb;
    }

    /** Fixed-limit raise target: complete a stud bring-in, else one unit on top. */
    private function fixedRaiseTo(): int
    {
        $unit = $this->streetBet();
        $bringIn = $this->s['bring_in'] ?? 0;
        if ($this->s['street'] === 'third' && $this->s['current_bet'] === $bringIn && $bringIn < $unit) {
            return $unit; // "complete" the bring-in to a full small bet
        }
        return $this->s['current_bet'] + $unit;
    }

    /** Move chips from a seat's stack into the street commitment. */
    private function commit(int $seat, int $amount, string $why): void
    {
        $p = &$this->s['players'][$seat];
        $amount = min($amount, $p['stack']);
        $p['stack'] -= $amount;
        $p['committed_street'] += $amount;
        $p['committed_total'] += $amount;
        if ($p['stack'] === 0) {
            $p['all_in'] = true;
        }
        if (in_array($why, ['bet', 'raise', 'call', 'post_sb', 'post_bb', 'bring_in'], true)) {
            $this->log($seat, $why, $amount);
        }
        unset($p);
    }

    private function resetActedExcept(int $seat): void
    {
        foreach ($this->s['players'] as $sn => &$pl) {
            if ($sn !== $seat && $pl['in_hand'] && !$pl['all_in']) {
                $pl['has_acted'] = false;
            }
        }
        unset($pl);
    }

    private function log(int $seat, string $action, int $amount): void
    {
        $this->s['actions'][] = [
            'seat' => $seat,
            'user_id' => $this->s['players'][$seat]['user_id'] ?? null,
            'street' => $this->s['street'],
            'action' => $action,
            'amount' => $amount,
        ];
    }

    /* ----------------------------------------------------------------------
     * Flow control
     * ------------------------------------------------------------------- */

    private function advance(): void
    {
        // Only one player left with cards? Hand is over (everyone else folded).
        if (count($this->liveSeats()) === 1) {
            $this->endByFold();
            return;
        }

        // Draw phase rotates by dealt-or-not, not by bets matched.
        if ($this->s['street'] === 'draw') {
            $next = $this->nextSeat($this->s['to_act'], fn ($p) => $p['in_hand'] && !$p['has_acted']);
            if ($next === null) {
                $this->finishDrawPhase();
                return;
            }
            $this->s['to_act'] = $next;
            return;
        }

        if ($this->bettingComplete()) {
            $this->closeStreet();
            return;
        }

        // Find next player who still needs to act.
        $next = $this->nextSeat($this->s['to_act'], fn ($p) => $this->canAct($p) && !$p['has_acted']);
        // Players who already acted but face a fresh raise have has_acted=false,
        // so nextSeat naturally lands on whoever owes action.
        if ($next === null) {
            // Could happen if the only remaining actors are all-in.
            $this->closeStreet();
            return;
        }
        $this->s['to_act'] = $next;
    }

    /**
     * Guarantee `to_act` points at a seat that can actually act, or run the hand
     * out if no voluntary decision remains. Defends against any path that leaves
     * the pointer on an all-in/folded seat (e.g. short stacks all-in on blinds) —
     * such a state otherwise wedges the felt and makes every tick throw "You
     * cannot act". Idempotent and safe to call repeatedly. Returns true if it
     * changed the hand state.
     */
    public function normalize(): bool
    {
        if (($this->s['street'] ?? 'complete') === 'complete') {
            return false;
        }
        $seat = $this->s['to_act'];

        // Draw phase: the pointer must sit on a live player who hasn't drawn.
        if ($this->s['street'] === 'draw') {
            if ($seat !== null && isset($this->s['players'][$seat])
                && $this->s['players'][$seat]['in_hand'] && !$this->s['players'][$seat]['has_acted']) {
                return false;
            }
            $next = $this->nextSeat($seat ?? $this->s['button'], fn ($p) => $p['in_hand'] && !$p['has_acted']);
            if ($next === null) {
                $this->finishDrawPhase();
            } else {
                $this->s['to_act'] = $next;
            }
            return true;
        }

        // Pointer already on an able seat: nothing to fix.
        if ($seat !== null && isset($this->s['players'][$seat])
            && $this->canAct($this->s['players'][$seat])) {
            return false;
        }
        // Only one player still holds cards -> uncontested win.
        if (count($this->liveSeats()) === 1) {
            $this->endByFold();
            return true;
        }
        // Nobody can voluntarily act (all remaining are all-in) -> run it out.
        if ($this->bettingComplete()) {
            $this->closeStreet();
            return true;
        }
        // Otherwise re-seat the pointer on the next able player who owes action.
        $from = $seat ?? $this->s['button'];
        $next = $this->nextSeat($from, fn ($p) => $this->canAct($p) && !$p['has_acted']);
        if ($next === null) {
            $this->closeStreet();
            return true;
        }
        $this->s['to_act'] = $next;
        return true;
    }

    private function bettingComplete(): bool
    {
        $actable = array_filter($this->s['players'], fn ($p) => $this->canAct($p));
        if (count($actable) === 0) {
            return true; // everyone left is all-in
        }
        foreach ($actable as $p) {
            if (!$p['has_acted']) {
                return false;
            }
            if ($p['committed_street'] !== $this->s['current_bet']) {
                return false;
            }
        }
        return true;
    }

    private function closeStreet(): void
    {
        // Pull street commitments into the central pot bookkeeping.
        foreach ($this->s['players'] as &$p) {
            $this->s['pot_committed'] += $p['committed_street'];
            $p['committed_street'] = 0;
            $p['has_acted'] = false;
        }
        unset($p);
        $this->s['current_bet'] = 0;
        $this->s['min_raise'] = $this->s['bb'];
        $this->s['raises'] = 0;

        $family = $this->rules()['family'];
        $streets = GameType::STREETS[$family];
        $idx = array_search($this->s['street'], $streets, true);
        $next = ($idx === false || $idx === count($streets) - 1) ? 'showdown' : $streets[$idx + 1];

        // The draw family wedges its dealing phase between the two bet rounds.
        if ($family === 'draw' && $this->s['street'] === 'predraw') {
            $this->enterDrawPhase();
            return;
        }

        $this->s['street'] = $next;
        if ($next === 'showdown') {
            $this->showdown();
            return;
        }

        match ($family) {
            'flop' => $this->dealBoard($next),
            'stud' => $this->dealStud($next),
            default => null,
        };

        // If nobody can voluntarily act (all but one all-in), keep dealing.
        $canAct = array_filter($this->s['players'], fn ($p) => $this->canAct($p));
        if (count($canAct) <= 1) {
            $this->closeStreet();
            return;
        }

        // First to act: stud opens with the strongest show; else left of button.
        $this->s['to_act'] = $family === 'stud'
            ? $this->studFirstToAct()
            : $this->nextSeat($this->s['button'], fn ($p) => $this->canAct($p));
        $this->s['last_aggressor'] = null;
    }

    /** One more card to every live stud player — up, except seventh (down). */
    private function dealStud(string $street): void
    {
        foreach ($this->liveSeats() as $sn) {
            if ($this->s['deck_pos'] >= count($this->s['deck'])) {
                break; // exhausted (impossible at ≤7 seats, but never explode)
            }
            $card = $this->s['deck'][$this->s['deck_pos']++];
            if ($street === 'seventh') {
                $this->s['players'][$sn]['hole'][] = $card;
            } else {
                $this->s['players'][$sn]['up'][] = $card;
            }
        }
    }

    /** Stud action opens on the strongest exposed cards (weakest in razz). */
    private function studFirstToAct(): ?int
    {
        $best = null;
        $bestKey = null;
        $razz = ($this->rules()['lo'] !== null && !$this->rules()['hi']);
        foreach ($this->orderedSeats() as $sn) {
            $p = $this->s['players'][$sn];
            if (!$this->canAct($p)) {
                continue;
            }
            $key = $this->showingKey($p['up']);
            if ($best === null || ($razz ? $key < $bestKey : $key > $bestKey)) {
                $best = $sn;
                $bestKey = $key;
            }
        }
        return $best;
    }

    /** Comparable strength of exposed cards: pair shape first, then high ranks. */
    private function showingKey(array $up): int
    {
        $ranks = array_map([Card::class, 'rankValue'], $up);
        $counts = array_count_values($ranks);
        $byGroup = array_keys($counts);
        usort($byGroup, fn ($a, $b) => [$counts[$b], $b] <=> [$counts[$a], $a]);
        $shape = max($counts);
        $key = $shape;
        foreach (array_slice($byGroup, 0, 4) as $r) {
            $key = $key * 16 + $r;
        }
        return $key;
    }

    /** Open the draw: every live player, left of button first, swaps cards. */
    private function enterDrawPhase(): void
    {
        $this->s['street'] = 'draw';
        foreach ($this->s['players'] as &$p) {
            $p['has_acted'] = false;
        }
        unset($p);
        $first = $this->nextSeat($this->s['button'], fn ($p) => $p['in_hand']);
        $this->s['to_act'] = $first;
    }

    /** All draws are in — run the post-draw betting round (or the showdown). */
    private function finishDrawPhase(): void
    {
        $this->s['street'] = 'postdraw';
        foreach ($this->s['players'] as &$p) {
            $p['has_acted'] = false;
        }
        unset($p);
        $canAct = array_filter($this->s['players'], fn ($p) => $this->canAct($p));
        if (count($canAct) <= 1) {
            $this->closeStreet(); // everyone all-in — straight to showdown
            return;
        }
        $this->s['to_act'] = $this->nextSeat($this->s['button'], fn ($p) => $this->canAct($p));
        $this->s['last_aggressor'] = null;
    }

    private function dealBoard(string $street): void
    {
        $deck = $this->s['deck'];
        $pos = $this->s['deck_pos'];
        // Burn one.
        $pos++;
        if ($street === 'flop') {
            $this->s['board'] = array_merge($this->s['board'], array_slice($deck, $pos, 3));
            $pos += 3;
        } else {
            $this->s['board'][] = $deck[$pos];
            $pos += 1;
        }
        $this->s['deck_pos'] = $pos;
    }

    private function endByFold(): void
    {
        // Collect remaining street bets.
        foreach ($this->s['players'] as &$p) {
            $this->s['pot_committed'] += $p['committed_street'];
            $p['committed_street'] = 0;
        }
        unset($p);
        $winnerSeat = $this->liveSeats()[0];
        $pot = $this->s['pot_committed'];
        $this->s['players'][$winnerSeat]['stack'] += $pot;
        $this->s['winners'] = [[
            'seat' => $winnerSeat,
            'user_id' => $this->s['players'][$winnerSeat]['user_id'] ?? null,
            'amount' => $pot,
            'hand' => null,
            'uncontested' => true,
        ]];
        $this->s['street'] = 'complete';
        $this->s['to_act'] = null;
        $this->s['pots'] = [['amount' => $pot, 'eligible' => [$winnerSeat]]];
    }

    /* ----------------------------------------------------------------------
     * Showdown + side pots
     * ------------------------------------------------------------------- */

    private function showdown(): void
    {
        $this->s['street'] = 'complete';
        $this->s['to_act'] = null;

        $rules = $this->rules();
        $pots = $this->buildSidePots();
        $this->s['pots'] = $pots;

        // Evaluate each live player's best high (and, where the game splits, low).
        $hi = [];
        $lo = [];
        foreach ($this->liveSeats() as $seat) {
            $p = $this->s['players'][$seat];
            $cards = array_merge($p['hole'], $p['up'] ?? [], $this->s['board']);
            if ($rules['hi']) {
                $hi[$seat] = $rules['use_exactly'] === 2
                    ? HandEvaluator::evaluateOmaha($p['hole'], $this->s['board'])
                    : HandEvaluator::evaluate($cards, $rules['deck'] === 'short');
            }
            if ($rules['lo'] !== null) {
                $lo[$seat] = $rules['use_exactly'] === 2
                    ? HandEvaluator::evaluateOmahaLow($p['hole'], $this->s['board'])
                    : HandEvaluator::evaluateLow($cards, $rules['lo'] === 'a5_q8');
            }
        }

        $winners = [];
        foreach ($pots as $pi => $pot) {
            $eligible = array_values(array_filter(
                $pot['eligible'],
                fn ($s) => isset($hi[$s]) || isset($lo[$s])
            ));
            if (empty($eligible)) {
                continue;
            }

            $hiSeats = [];
            if ($rules['hi']) {
                $best = max(array_map(fn ($s) => $hi[$s]['score'], $eligible));
                $hiSeats = array_values(array_filter($eligible, fn ($s) => $hi[$s]['score'] === $best));
            }
            $loSeats = [];
            if ($rules['lo'] !== null) {
                $qualified = array_values(array_filter($eligible, fn ($s) => ($lo[$s] ?? null) !== null));
                if (!empty($qualified)) {
                    $bestLo = min(array_map(fn ($s) => $lo[$s]['score'], $qualified));
                    $loSeats = array_values(array_filter($qualified, fn ($s) => $lo[$s]['score'] === $bestLo));
                }
            }

            if (!$rules['hi']) {
                // Lowball: the low IS the hand. (Fallback split keeps chips
                // conserved even if no low evaluated — cannot happen at 7 cards.)
                $this->awardPot($pi, $pot['amount'], $loSeats ?: $eligible, $lo, null, $winners);
            } elseif (!empty($loSeats)) {
                // Split pot: odd chip goes to the high.
                $loAmt = intdiv($pot['amount'], 2);
                $this->awardPot($pi, $pot['amount'] - $loAmt, $hiSeats, $hi, 'hi', $winners);
                $this->awardPot($pi, $loAmt, $loSeats, $lo, 'lo', $winners);
            } else {
                $this->awardPot($pi, $pot['amount'], $hiSeats, $hi, null, $winners);
            }
        }
        $this->s['winners'] = $winners;
    }

    /** Split an amount among winning seats; odd chip to the first left of button. */
    private function awardPot(int $potIdx, int $amount, array $seats, array $scores, ?string $kind, array &$winners): void
    {
        if ($amount <= 0 || empty($seats)) {
            return;
        }
        $share = intdiv($amount, count($seats));
        $remainder = $amount - $share * count($seats);
        usort($seats, fn ($a, $b) => $this->seatDistanceFromButton($a) <=> $this->seatDistanceFromButton($b));
        foreach ($seats as $i => $seat) {
            $amt = $share + ($i === 0 ? $remainder : 0);
            $this->s['players'][$seat]['stack'] += $amt;
            $winners[] = [
                'seat' => $seat,
                'user_id' => $this->s['players'][$seat]['user_id'] ?? null,
                'amount' => $amt,
                'pot' => $potIdx,
                'pot_kind' => $kind,
                'hand' => $scores[$seat]['label'] ?? null,
                'best5' => $scores[$seat]['best5'] ?? null,
            ];
        }
    }

    /** Build main + side pots from each player's total contribution. */
    private function buildSidePots(): array
    {
        // Contribution of every player who put chips in (folded players still
        // contribute to the pot they were live for).
        $contrib = [];
        foreach ($this->s['players'] as $seat => $p) {
            if ($p['committed_total'] > 0) {
                $contrib[$seat] = $p['committed_total'];
            }
        }
        $live = $this->liveSeats();

        $pots = [];
        while (!empty($contrib)) {
            $min = min($contrib);
            $eligible = [];
            $amount = 0;
            foreach ($contrib as $seat => &$c) {
                $amount += $min;
                $c -= $min;
                if (in_array($seat, $live, true)) {
                    $eligible[] = $seat;
                }
                if ($c <= 0) {
                    unset($contrib[$seat]);
                }
            }
            unset($c);
            if ($amount > 0) {
                $pots[] = ['amount' => $amount, 'eligible' => array_values($eligible)];
            }
        }
        return $pots;
    }

    private function seatDistanceFromButton(int $seat): int
    {
        $seats = $this->orderedSeats();
        $n = count($seats);
        $btnIdx = array_search($this->s['button'], $seats, true);
        $seatIdx = array_search($seat, $seats, true);
        return ($seatIdx - $btnIdx - 1 + $n) % $n;
    }

    public function isHandOver(): bool
    {
        return $this->s['street'] === 'complete';
    }

    /* ----------------------------------------------------------------------
     * Client view (redacted)
     * ------------------------------------------------------------------- */

    /**
     * A safe view for a client. Hole cards are hidden except for $forSeat (the
     * requesting player), or revealed for everyone still live at showdown.
     */
    public function view(?int $forSeat = null): array
    {
        $showdown = $this->isHandOver()
            && count($this->liveSeats()) > 1; // cards shown only at a contested showdown

        $players = [];
        foreach ($this->s['players'] as $seat => $p) {
            $reveal = ($forSeat !== null && $seat === $forSeat)
                || ($showdown && $p['in_hand']);
            $players[$seat] = [
                'seat' => $p['seat'],
                'user_id' => $p['user_id'],
                'name' => $p['name'],
                'is_bot' => $p['is_bot'],
                'avatar' => $p['avatar'],
                'stack' => $p['stack'],
                'in_hand' => $p['in_hand'],
                'all_in' => $p['all_in'],
                'committed_street' => $p['committed_street'],
                'committed_total' => $p['committed_total'],
                'hole' => $reveal
                    ? $p['hole']
                    : ($p['in_hand'] ? array_fill(0, count($p['hole']), '??') : []),
                'up' => $p['up'] ?? [], // stud door/up cards are public by nature
            ];
        }

        return [
            'table_id' => $this->s['table_id'],
            'hand_no' => $this->s['hand_no'],
            'game' => $this->game(),
            'street' => $this->s['street'],
            'board' => $this->s['board'],
            'button' => $this->s['button'],
            'sb' => $this->s['sb'],
            'bb' => $this->s['bb'],
            'pot' => $this->totalPot(),
            'current_bet' => $this->s['current_bet'],
            'min_raise' => $this->s['min_raise'],
            'to_act' => $this->s['to_act'],
            'players' => $players,
            'winners' => $this->s['winners'],
            'actions' => $this->s['actions'],
            'legal' => $forSeat !== null ? $this->legalActions($forSeat) : [],
            'seed' => $this->isHandOver() ? $this->s['seed'] : null, // provably-fair reveal
        ];
    }

    public function totalPot(): int
    {
        $live = $this->s['pot_committed'];
        foreach ($this->s['players'] as $p) {
            $live += $p['committed_street'];
        }
        return $live;
    }

    /**
     * "No flop, no drop", generalized: the hand must have reached its second
     * dealing before the house may rake — flop down, fourth street dealt, or
     * the draw begun.
     */
    public function rakeEligible(): bool
    {
        return match ($this->rules()['family']) {
            'flop' => count($this->s['board']) >= 3,
            'stud' => max(array_map(fn ($p) => count($p['up'] ?? []), $this->s['players'])) >= 2,
            'draw' => (bool) array_filter(
                $this->s['actions'],
                fn ($a) => in_array($a['street'], ['draw', 'postdraw'], true)
            ),
            default => count($this->s['board']) >= 3,
        };
    }

    public function toAct(): ?int
    {
        return $this->s['to_act'];
    }
}
