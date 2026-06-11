<?php

namespace App\Poker;

/**
 * A playing card as a two-char code: rank + suit, e.g. "As", "Td", "2c", "Kh".
 * Ranks: 2 3 4 5 6 7 8 9 T J Q K A. Suits: s h d c.
 */
final class Card
{
    public const RANKS = ['2', '3', '4', '5', '6', '7', '8', '9', 'T', 'J', 'Q', 'K', 'A'];
    public const SUITS = ['s', 'h', 'd', 'c'];

    /** Numeric rank value, 2..14 (Ace high). */
    public static function rankValue(string $card): int
    {
        $r = $card[0];
        return match ($r) {
            'T' => 10, 'J' => 11, 'Q' => 12, 'K' => 13, 'A' => 14,
            default => (int) $r,
        };
    }

    public static function suit(string $card): string
    {
        return $card[1];
    }

    /** A fresh ordered 52-card deck. */
    public static function fullDeck(): array
    {
        $deck = [];
        foreach (self::RANKS as $r) {
            foreach (self::SUITS as $s) {
                $deck[] = $r . $s;
            }
        }
        return $deck;
    }

    /** A 36-card short deck (6+): deuces through fives stripped out. */
    public static function shortDeck(): array
    {
        return array_values(array_filter(
            self::fullDeck(),
            fn ($c) => self::rankValue($c) >= 6
        ));
    }

    /** Low rank value for A-5 lowball: Ace counts 1, rest face value. */
    public static function lowRankValue(string $card): int
    {
        $v = self::rankValue($card);
        return $v === 14 ? 1 : $v;
    }
}
