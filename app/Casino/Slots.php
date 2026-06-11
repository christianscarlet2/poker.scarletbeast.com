<?php

namespace App\Casino;

/**
 * Slots — "Beast Reels": three weighted reels, one payline, classic fruit-
 * machine maths tuned to ~94% RTP. Single-spin rounds.
 */
final class Slots
{
    /** Each reel is a 32-stop strip; symbol frequency = its weight. */
    public const STRIP = [
        '🍒', '🍒', '🍒', '🍒', '🍒', '🍒', '🍒',
        '🍋', '🍋', '🍋', '🍋', '🍋', '🍋',
        '🔔', '🔔', '🔔', '🔔', '🔔',
        '🐍', '🐍', '🐍', '🐍',
        '7️⃣', '7️⃣', '7️⃣',
        '💀', '💀',
        '⛧',
        '🍒', '🍋', '🔔', '🐍',
    ];

    /** Three-of-a-kind multipliers (stake excluded). Tuned with the cherry
     *  consolation ladder below to land the machine at ~94% RTP. */
    public const TRIPLES = [
        '⛧' => 150, '💀' => 60, '7️⃣' => 40, '🐍' => 14, '🔔' => 10, '🍋' => 5, '🍒' => 3,
    ];

    public static function spin(Rng $rng, int $bet): array
    {
        $reels = [];
        for ($i = 0; $i < 3; $i++) {
            $reels[] = self::STRIP[$rng->below(count(self::STRIP))];
        }
        [$a, $b, $c] = $reels;
        $mult = 0;
        $label = 'No hit';
        if ($a === $b && $b === $c) {
            $mult = self::TRIPLES[$a] ?? 0;
            $label = "Triple {$a}";
        } elseif (($a === '🍒') + ($b === '🍒') + ($c === '🍒') === 2) {
            $mult = 2;  // two cherries: 2:1
            $label = 'Two cherries';
        } elseif ($a === '🍒' || $b === '🍒' || $c === '🍒') {
            $mult = 0;
            // one cherry returns half the stake
            return ['reels' => $reels, 'label' => 'One cherry', 'paid' => intdiv($bet, 2)];
        }
        $paid = $mult > 0 ? $bet + $bet * $mult : 0;
        return ['reels' => $reels, 'label' => $label, 'paid' => $paid];
    }
}
