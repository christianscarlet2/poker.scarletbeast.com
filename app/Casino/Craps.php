<?php

namespace App\Casino;

/**
 * Craps — the pass line with the full come-out/point flow, plus the classic
 * one-roll props: Field, Any Seven, Yo (11), Any Craps. Multi-roll rounds:
 * the pass bet rides until it resolves; props resolve every roll.
 */
final class Craps
{
    public static function start(int $passBet): array
    {
        return [
            'pass' => $passBet,
            'point' => null,
            'rolls' => [],
            'phase' => 'comeout',   // comeout | point | done
            'outcome' => null,      // win | lose
        ];
    }

    /**
     * One roll. $props = one-roll bets riding THIS roll:
     * [['type' => 'field'|'any7'|'yo'|'anycraps', 'amount' => int], ...]
     * Returns [state, propResults, propPaid].
     */
    public static function roll(array $s, array $props, Rng $rng): array
    {
        if ($s['phase'] === 'done') {
            throw new \RuntimeException('The round is over — come out again.');
        }
        $d1 = $rng->below(6) + 1;
        $d2 = $rng->below(6) + 1;
        $sum = $d1 + $d2;
        $s['rolls'][] = [$d1, $d2];

        // --- one-roll props ---
        $propResults = [];
        $propPaid = 0;
        foreach ($props as $p) {
            $amt = (int) $p['amount'];
            $mult = match ($p['type']) {
                // Field: 2 and 12 pay double, 3/4/9/10/11 pay even.
                'field' => in_array($sum, [2, 12], true) ? 2 : (in_array($sum, [3, 4, 9, 10, 11], true) ? 1 : null),
                'any7' => $sum === 7 ? 4 : null,
                'yo' => $sum === 11 ? 15 : null,
                'anycraps' => in_array($sum, [2, 3, 12], true) ? 7 : null,
                default => null,
            };
            $pay = $mult !== null ? $amt + $amt * $mult : 0;
            $propPaid += $pay;
            $propResults[] = ['type' => $p['type'], 'amount' => $amt, 'won' => $mult !== null, 'paid' => $pay];
        }

        // --- the pass line ---
        if ($s['phase'] === 'comeout') {
            if (in_array($sum, [7, 11], true)) {
                $s['phase'] = 'done';
                $s['outcome'] = 'win';
            } elseif (in_array($sum, [2, 3, 12], true)) {
                $s['phase'] = 'done';
                $s['outcome'] = 'lose';
            } else {
                $s['point'] = $sum;
                $s['phase'] = 'point';
            }
        } else { // point phase
            if ($sum === $s['point']) {
                $s['phase'] = 'done';
                $s['outcome'] = 'win';
            } elseif ($sum === 7) {
                $s['phase'] = 'done';
                $s['outcome'] = 'lose';
            }
        }
        return [$s, $propResults, $propPaid];
    }

    /** Pass-line payout when the round ends (stake included; 1:1). */
    public static function passPayout(array $s): int
    {
        return $s['outcome'] === 'win' ? $s['pass'] * 2 : 0;
    }
}
