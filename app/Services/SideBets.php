<?php

namespace App\Services;

use App\Models\PokerTable;
use App\Models\SideBet;
use App\Models\TableState;
use App\Models\User;
use App\Poker\Card;
use App\Poker\GameType;
use Illuminate\Support\Facades\DB;

/**
 * The rail's bookmaker. Dynamic in-hand side bets priced from PUBLIC
 * information only — live player count, street, game family — never the
 * hole cards the house can see. Odds lock at placement; settlement fires
 * the moment the hand completes inside TableManager::settle.
 *
 * The book (margin ~5% under fair):
 *   winner      pick the seat that drags the pot      pays live_players × 0.95
 *   flop_color  majority color of the flop (red/black) pays 1.90      (fair 2.00)
 *   flop_pair   the flop comes paired                  pays 5.50      (fair ~5.88)
 *   showdown    the hand reaches a contested showdown  pays 2.60
 */
class SideBets
{
    public const MARGIN = 0.95;

    /** The live offer sheet for a felt, given its current public state. */
    public static function offers(PokerTable $table): array
    {
        $state = TableState::find($table->id);
        $s = $state?->state;
        $family = GameType::get($table->game_type ?? 'nlhe')['family'];
        $offers = [];
        if (!$s || ($s['street'] ?? 'complete') === 'complete') {
            return ['hand_no' => null, 'offers' => []];
        }
        $street = $s['street'];
        $first = GameType::STREETS[$family][0];
        $live = array_filter($s['players'], fn ($p) => $p['in_hand']);

        if (count($live) > 1) {
            $odds = (int) floor(count($live) * self::MARGIN * 100);
            $offers[] = [
                'type' => 'winner',
                'label' => 'Pick the winner of this hand',
                'odds_x100' => $odds,
                'selections' => array_values(array_map(fn ($p) => [
                    'key' => (string) $p['seat'],
                    'label' => "#{$p['seat']} {$p['name']}",
                ], $live)),
            ];
        }
        if ($family === 'flop' && $street === $first) {
            $offers[] = [
                'type' => 'flop_color',
                'label' => 'Flop majority color',
                'odds_x100' => 190,
                'selections' => [['key' => 'red', 'label' => '♥♦ Red'], ['key' => 'black', 'label' => '♠♣ Black']],
            ];
            $offers[] = [
                'type' => 'flop_pair',
                'label' => 'Flop comes paired',
                'odds_x100' => 550,
                'selections' => [['key' => 'yes', 'label' => 'Paired flop']],
            ];
            $offers[] = [
                'type' => 'showdown',
                'label' => 'Hand reaches showdown',
                'odds_x100' => 260,
                'selections' => [['key' => 'yes', 'label' => 'Showdown']],
            ];
        }
        return ['hand_no' => $s['hand_no'] ?? null, 'street' => $street, 'offers' => $offers];
    }

    /** Place a wager from the bankroll. Odds lock now. */
    public static function place(PokerTable $table, User $user, string $type, string $selection, int $stake): SideBet
    {
        if ($user->is_bot) {
            throw new \RuntimeException('Machines play, they do not gamble on themselves.');
        }
        if ($stake < 10 || $stake > 100000) {
            throw new \RuntimeException('Stake must be between $0.10 and $1,000.');
        }
        $sheet = self::offers($table);
        $offer = collect($sheet['offers'])->firstWhere('type', $type);
        if (!$offer) {
            throw new \RuntimeException('That market is closed right now.');
        }
        if (!collect($offer['selections'])->firstWhere('key', $selection)) {
            throw new \RuntimeException('Not a live selection.');
        }

        return DB::transaction(function () use ($table, $user, $type, $selection, $stake, $offer, $sheet) {
            Bankroll::adjust($user->id, -$stake, 'side_bet', "Side bet: {$type} {$selection} @ table {$table->id}");
            return SideBet::create([
                'user_id' => $user->id,
                'table_id' => $table->id,
                'hand_no' => $sheet['hand_no'],
                'bet_type' => $type,
                'selection' => $selection,
                'stake' => $stake,
                'odds_x100' => $offer['odds_x100'],
                'status' => 'open',
            ]);
        });
    }

    /**
     * Settle every open bet on a completed hand. Called from
     * TableManager::settle with the final engine state.
     */
    public static function settle(PokerTable $table, array $s): void
    {
        $bets = SideBet::where('table_id', $table->id)
            ->where('hand_no', $s['hand_no'] ?? -1)
            ->where('status', 'open')->get();
        if ($bets->isEmpty()) {
            return;
        }

        $winnerSeats = array_unique(array_map(fn ($w) => (string) $w['seat'], $s['winners'] ?? []));
        $board = $s['board'] ?? [];
        $flop = array_slice($board, 0, 3);
        $live = array_filter($s['players'], fn ($p) => $p['in_hand']);
        $showdown = count($live) > 1;

        foreach ($bets as $bet) {
            [$won, $void] = match ($bet->bet_type) {
                'winner' => [in_array($bet->selection, $winnerSeats, true), false],
                'flop_color' => count($flop) === 3
                    ? [self::majorityColor($flop) === $bet->selection, false]
                    : [false, true],   // never saw a flop — push
                'flop_pair' => count($flop) === 3
                    ? [self::isPaired($flop), false]
                    : [false, true],
                'showdown' => [(bool) $showdown, false],
                default => [false, true],
            };

            if ($void) {
                $bet->update(['status' => 'void', 'payout' => $bet->stake]);
                Bankroll::adjust($bet->user_id, $bet->stake, 'side_bet_void', 'Side bet pushed — stake returned', $bet);
            } elseif ($won) {
                $payout = intdiv($bet->stake * $bet->odds_x100, 100);
                $bet->update(['status' => 'won', 'payout' => $payout]);
                Bankroll::adjust($bet->user_id, $payout, 'side_bet_win', "Side bet won: {$bet->bet_type} {$bet->selection}", $bet);
            } else {
                $bet->update(['status' => 'lost', 'payout' => 0]);
            }
        }
    }

    private static function majorityColor(array $flop): string
    {
        $red = count(array_filter($flop, fn ($c) => in_array(Card::suit($c), ['h', 'd'], true)));
        return $red >= 2 ? 'red' : 'black';
    }

    private static function isPaired(array $flop): bool
    {
        $ranks = array_map([Card::class, 'rankValue'], $flop);
        return count(array_unique($ranks)) < 3;
    }
}
