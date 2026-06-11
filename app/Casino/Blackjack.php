<?php

namespace App\Casino;

use App\Poker\Card;

/**
 * Blackjack — six-deck shoe, dealer stands on all 17s, blackjack pays 3:2,
 * double on any first two cards. (No splits in v1 — the felt is honest about
 * it.) Multi-step rounds: deal → hit/stand/double → dealer runs out → pay.
 */
final class Blackjack
{
    /** Begin a round: returns the mutable round state. */
    public static function deal(Rng $rng, int $bet): array
    {
        $shoe = $rng->shuffle(array_merge(...array_fill(0, 6, Card::fullDeck())));
        $s = [
            'shoe' => $shoe, 'pos' => 0,
            'player' => [], 'dealer' => [],
            'bet' => $bet, 'doubled' => false,
            'phase' => 'player',  // player | done
            'outcome' => null,    // win | lose | push | blackjack
        ];
        $s['player'][] = self::draw($s);
        $s['dealer'][] = self::draw($s);
        $s['player'][] = self::draw($s);
        $s['dealer'][] = self::draw($s);

        $pbj = self::total($s['player']) === 21;
        $dbj = self::total($s['dealer']) === 21;
        if ($pbj || $dbj) {
            $s['phase'] = 'done';
            $s['outcome'] = $pbj && $dbj ? 'push' : ($pbj ? 'blackjack' : 'lose');
        }
        return $s;
    }

    public static function act(array $s, string $action): array
    {
        if ($s['phase'] !== 'player') {
            throw new \RuntimeException('Hand is over.');
        }
        switch ($action) {
            case 'hit':
                $s['player'][] = self::draw($s);
                if (self::total($s['player']) > 21) {
                    $s['phase'] = 'done';
                    $s['outcome'] = 'lose';
                } elseif (self::total($s['player']) === 21) {
                    return self::standOut($s);
                }
                return $s;
            case 'double':
                if (count($s['player']) !== 2) {
                    throw new \RuntimeException('Double only on your first two.');
                }
                $s['doubled'] = true;
                $s['player'][] = self::draw($s);
                if (self::total($s['player']) > 21) {
                    $s['phase'] = 'done';
                    $s['outcome'] = 'lose';
                    return $s;
                }
                return self::standOut($s);
            case 'stand':
                return self::standOut($s);
            default:
                throw new \RuntimeException('hit, stand, or double.');
        }
    }

    /** Dealer draws to 17+, then the comparison. */
    private static function standOut(array $s): array
    {
        while (self::total($s['dealer']) < 17) {
            $s['dealer'][] = self::draw($s);
        }
        $p = self::total($s['player']);
        $d = self::total($s['dealer']);
        $s['phase'] = 'done';
        $s['outcome'] = $d > 21 || $p > $d ? 'win' : ($p === $d ? 'push' : 'lose');
        return $s;
    }

    /** Total stake at risk (doubles double it). */
    public static function wagered(array $s): int
    {
        return $s['bet'] * ($s['doubled'] ? 2 : 1);
    }

    /** What the finished round pays back (stake included). */
    public static function payout(array $s): int
    {
        $w = self::wagered($s);
        return match ($s['outcome']) {
            'blackjack' => $s['bet'] + intdiv($s['bet'] * 3, 2),
            'win' => $w * 2,
            'push' => $w,
            default => 0,
        };
    }

    public static function total(array $cards): int
    {
        $t = 0;
        $aces = 0;
        foreach ($cards as $c) {
            $v = Card::rankValue($c);
            if ($v === 14) { $aces++; $t += 11; }
            else { $t += min(10, $v); }
        }
        while ($t > 21 && $aces-- > 0) {
            $t -= 10;
        }
        return $t;
    }

    private static function draw(array &$s): string
    {
        return $s['shoe'][$s['pos']++];
    }

    /** Client view: hide the hole card while the player acts. */
    public static function view(array $s): array
    {
        $hide = $s['phase'] === 'player';
        return [
            'player' => $s['player'],
            'player_total' => self::total($s['player']),
            'dealer' => $hide ? [$s['dealer'][0], '??'] : $s['dealer'],
            'dealer_total' => $hide ? null : self::total($s['dealer']),
            'phase' => $s['phase'],
            'outcome' => $s['outcome'],
            'bet' => $s['bet'],
            'doubled' => $s['doubled'],
            'can_double' => $s['phase'] === 'player' && count($s['player']) === 2,
        ];
    }
}
