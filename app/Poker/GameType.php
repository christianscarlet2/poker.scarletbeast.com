<?php

namespace App\Poker;

/**
 * The rulebook rack. Every poker variant the house spreads, described as data:
 * family (flop / stud / draw), hole-card count, Omaha use-exactly-2 rule,
 * betting structure (no-limit / pot-limit / fixed-limit), deck, and hi/lo split.
 * The HandEngine reads these to become any game; nothing else hardcodes rules.
 */
final class GameType
{
    public const GAMES = [
        'nlhe' => [
            'name' => "No-Limit Hold'em",
            'short' => 'NLHE',
            'family' => 'flop',
            'hole' => 2,
            'use_exactly' => null,       // best 5 of any 7
            'betting' => 'no_limit',
            'deck' => 'full',
            'hi' => true,
            'lo' => null,
        ],
        'lhe' => [
            'name' => "Limit Hold'em",
            'short' => 'LHE',
            'family' => 'flop',
            'hole' => 2,
            'use_exactly' => null,
            'betting' => 'fixed_limit',
            'deck' => 'full',
            'hi' => true,
            'lo' => null,
        ],
        'plo' => [
            'name' => 'Pot-Limit Omaha',
            'short' => 'PLO',
            'family' => 'flop',
            'hole' => 4,
            'use_exactly' => 2,          // exactly 2 from hand + 3 from board
            'betting' => 'pot_limit',
            'deck' => 'full',
            'hi' => true,
            'lo' => null,
        ],
        'plo8' => [
            'name' => 'Omaha Hi-Lo (8+)',
            'short' => 'PLO8',
            'family' => 'flop',
            'hole' => 4,
            'use_exactly' => 2,
            'betting' => 'pot_limit',
            'deck' => 'full',
            'hi' => true,
            'lo' => 'a5_q8',             // A-5 low, 8-or-better qualifier
        ],
        'shortdeck' => [
            'name' => 'Short Deck (6+)',
            'short' => '6+',
            'family' => 'flop',
            'hole' => 2,
            'use_exactly' => null,
            'betting' => 'no_limit',
            'deck' => 'short',           // 36 cards; flush beats full house, A-6789 wheel
            'hi' => true,
            'lo' => null,
        ],
        'stud' => [
            'name' => 'Seven Card Stud',
            'short' => 'STUD',
            'family' => 'stud',          // antes + bring-in, no board, 5 streets
            'hole' => 0,                 // dealt by street: 2 down + 1 up, then up,up,up, down
            'use_exactly' => null,
            'betting' => 'fixed_limit',
            'deck' => 'full',
            'hi' => true,
            'lo' => null,
        ],
        'razz' => [
            'name' => 'Razz (A-5 Low)',
            'short' => 'RAZZ',
            'family' => 'stud',
            'hole' => 0,
            'use_exactly' => null,
            'betting' => 'fixed_limit',
            'deck' => 'full',
            'hi' => false,
            'lo' => 'a5',                // lowball, no qualifier
        ],
        'draw5' => [
            'name' => 'Five Card Draw',
            'short' => 'DRAW',
            'family' => 'draw',          // blinds, bet, one draw, bet, showdown
            'hole' => 5,
            'use_exactly' => null,
            'betting' => 'no_limit',
            'deck' => 'full',
            'hi' => true,
            'lo' => null,
        ],
    ];

    /** Betting streets per family, in order (excludes 'draw' which is a dealing phase). */
    public const STREETS = [
        'flop' => ['preflop', 'flop', 'turn', 'river'],
        'stud' => ['third', 'fourth', 'fifth', 'sixth', 'seventh'],
        'draw' => ['predraw', 'postdraw'],
    ];

    public static function get(string $id): array
    {
        return self::GAMES[$id] ?? self::GAMES['nlhe'];
    }

    public static function exists(string $id): bool
    {
        return isset(self::GAMES[$id]);
    }

    public static function ids(): array
    {
        return array_keys(self::GAMES);
    }

    /** id => display name, for admin selects and lobby labels. */
    public static function names(): array
    {
        return array_map(fn ($g) => $g['name'], self::GAMES);
    }

    /** Max seats a variant can physically deal to from its deck. */
    public static function maxSeats(string $id): int
    {
        return match (self::get($id)['family']) {
            'stud' => 7,   // 7 x 7 cards = 49 of 52
            'draw' => 5,   // 5 x (5 + worst-case 5 redraw) = 50 of 52
            default => 9,
        };
    }
}
