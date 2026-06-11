<?php

namespace App\Casino;

use App\Poker\Card;
use App\Poker\HandEvaluator;

/**
 * Video poker — Jacks or Better, the classic 9/6 full-pay table. Deal five,
 * hold a mask, draw, get paid by the ladder. Two-step rounds.
 */
final class VideoPoker
{
    /**
     * TOTAL-RETURN multipliers (stake included) — the standard 9/6 paytable
     * reads this way: "9" on a full house means nine coins back per coin bet,
     * and a paying pair of jacks is a push (your coin back).
     */
    public const PAYS = [
        'royal_flush' => 800,   // max-coin royal bonus
        'straight_flush' => 50,
        'four_kind' => 25,
        'full_house' => 9,
        'flush' => 6,
        'straight' => 4,
        'three_kind' => 3,
        'two_pair' => 2,
        'jacks_or_better' => 1,
    ];

    public static function deal(Rng $rng, int $bet): array
    {
        $deck = $rng->shuffle(Card::fullDeck());
        return [
            'deck' => $deck, 'pos' => 5,
            'hand' => array_slice($deck, 0, 5),
            'bet' => $bet,
            'phase' => 'hold',   // hold | done
            'result' => null,
        ];
    }

    /** $holdMask bit i set = keep card i; the rest are redrawn. */
    public static function draw(array $s, int $holdMask): array
    {
        if ($s['phase'] !== 'hold') {
            throw new \RuntimeException('Round already drawn.');
        }
        foreach ($s['hand'] as $i => $c) {
            if (!($holdMask & (1 << $i))) {
                $s['hand'][$i] = $s['deck'][$s['pos']++];
            }
        }
        $s['phase'] = 'done';
        $s['result'] = self::classify($s['hand']);
        return $s;
    }

    public static function payout(array $s): int
    {
        $mult = self::PAYS[$s['result']] ?? null;
        return $mult !== null ? $s['bet'] * $mult : 0;
    }

    /** Classify a 5-card hand into the Jacks-or-Better ladder. */
    public static function classify(array $hand): string
    {
        $e = HandEvaluator::evaluate($hand);
        $byCat = [8 => 'straight_flush', 7 => 'four_kind', 6 => 'full_house', 5 => 'flush', 4 => 'straight', 3 => 'three_kind', 2 => 'two_pair'];
        if ($e['category'] === 8) {
            // royal: ace-high straight flush
            $ranks = array_map([Card::class, 'rankValue'], $hand);
            rsort($ranks);
            return $ranks[0] === 14 && $ranks[4] === 10 ? 'royal_flush' : 'straight_flush';
        }
        if (isset($byCat[$e['category']])) {
            return $byCat[$e['category']];
        }
        if ($e['category'] === 1) {
            // pair — but only jacks or better pays
            $counts = array_count_values(array_map([Card::class, 'rankValue'], $hand));
            foreach ($counts as $r => $n) {
                if ($n === 2 && $r >= 11) {
                    return 'jacks_or_better';
                }
            }
        }
        return 'nothing';
    }
}
