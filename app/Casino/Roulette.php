<?php

namespace App\Casino;

/**
 * Roulette — American (0 + 00, 38 pockets) and European (0, 37 pockets).
 * Single-spin rounds: place a book of bets, the wheel speaks, every bet pays
 * by the standard table. Payout multipliers EXCLUDE the returned stake
 * (35:1 straight etc.) — winners get stake + stake×mult back.
 */
final class Roulette
{
    public const RED = [1, 3, 5, 7, 9, 12, 14, 16, 18, 19, 21, 23, 25, 27, 30, 32, 34, 36];

    /** @return array{pocket:string, results:array, total_paid:int} */
    public static function spin(string $variant, array $bets, Rng $rng): array
    {
        $pockets = $variant === 'roulette_us' ? 38 : 37; // index 37 = '00'
        $n = $rng->below($pockets);
        $pocket = $n === 37 ? '00' : (string) $n;
        $num = $n === 37 ? null : $n;          // null = 00 (loses everything but straight '00')

        $results = [];
        $paid = 0;
        foreach ($bets as $bet) {
            $type = $bet['type'];
            $sel = (string) ($bet['selection'] ?? '');
            $amt = (int) $bet['amount'];
            $mult = self::winMult($type, $sel, $pocket, $num);
            $win = $mult !== null;
            $pay = $win ? $amt + $amt * $mult : 0;
            $paid += $pay;
            $results[] = ['type' => $type, 'selection' => $sel, 'amount' => $amt, 'won' => $win, 'paid' => $pay];
        }
        return ['pocket' => $pocket, 'results' => $results, 'total_paid' => $paid];
    }

    /** Winning multiplier for a bet against the landed pocket, or null if lost. */
    private static function winMult(string $type, string $sel, string $pocket, ?int $num): ?int
    {
        $zeroish = $pocket === '0' || $pocket === '00';
        return match ($type) {
            'straight' => $sel === $pocket ? 35 : null,
            'red' => !$zeroish && in_array($num, self::RED, true) ? 1 : null,
            'black' => !$zeroish && $num !== 0 && !in_array($num, self::RED, true) ? 1 : null,
            'odd' => !$zeroish && $num % 2 === 1 ? 1 : null,
            'even' => !$zeroish && $num % 2 === 0 ? 1 : null,
            'low' => !$zeroish && $num >= 1 && $num <= 18 ? 1 : null,
            'high' => !$zeroish && $num >= 19 && $num <= 36 ? 1 : null,
            'dozen1' => !$zeroish && $num <= 12 ? 2 : null,
            'dozen2' => !$zeroish && $num >= 13 && $num <= 24 ? 2 : null,
            'dozen3' => !$zeroish && $num >= 25 ? 2 : null,
            'col1' => !$zeroish && $num % 3 === 1 ? 2 : null,
            'col2' => !$zeroish && $num % 3 === 2 ? 2 : null,
            'col3' => !$zeroish && $num % 3 === 0 ? 2 : null,
            default => null,
        };
    }

    public static function validBet(string $variant, string $type, string $sel): bool
    {
        if ($type === 'straight') {
            if ($sel === '00') {
                return $variant === 'roulette_us';
            }
            return ctype_digit($sel) && (int) $sel >= 0 && (int) $sel <= 36;
        }
        return in_array($type, ['red', 'black', 'odd', 'even', 'low', 'high', 'dozen1', 'dozen2', 'dozen3', 'col1', 'col2', 'col3'], true);
    }
}
