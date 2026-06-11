<?php

namespace App\Poker;

/**
 * Texas Hold'em hand strength. Evaluates the best 5-card hand out of 7 and
 * returns a single comparable integer score plus a human label. Higher = better.
 *
 * Score packing: category (0..8) in the high digits, then up to 5 tiebreaker
 * ranks packed base-16 — so a straight integer compare orders any two hands.
 */
final class HandEvaluator
{
    public const CATEGORIES = [
        0 => 'High Card',
        1 => 'Pair',
        2 => 'Two Pair',
        3 => 'Three of a Kind',
        4 => 'Straight',
        5 => 'Flush',
        6 => 'Full House',
        7 => 'Four of a Kind',
        8 => 'Straight Flush',
    ];

    /**
     * @param string[] $cards 5..7 card codes
     * @return array{score:int,category:int,label:string,best5:string[]}
     */
    public static function evaluate(array $cards, bool $shortDeck = false): array
    {
        $n = count($cards);
        if ($n < 5) {
            throw new \InvalidArgumentException('Need at least 5 cards');
        }

        $best = null;
        // Enumerate all 5-card subsets (C(7,5)=21 at most).
        $idx = range(0, $n - 1);
        foreach (self::combinations($idx, 5) as $combo) {
            $five = array_map(fn ($i) => $cards[$i], $combo);
            $res = self::score5($five, $shortDeck);
            if ($best === null || $res['score'] > $best['score']) {
                $best = $res;
            }
        }
        $best['label'] = self::CATEGORIES[$best['category']];
        return $best;
    }

    /**
     * Omaha high: best hand using EXACTLY 2 hole cards + 3 board cards.
     *
     * @param string[] $hole 4 hole cards  @param string[] $board 3..5 board cards
     */
    public static function evaluateOmaha(array $hole, array $board): array
    {
        $best = null;
        foreach (self::combinations(range(0, count($hole) - 1), 2) as $hc) {
            foreach (self::combinations(range(0, count($board) - 1), 3) as $bc) {
                $five = array_merge(
                    array_map(fn ($i) => $hole[$i], $hc),
                    array_map(fn ($i) => $board[$i], $bc)
                );
                $res = self::score5($five);
                if ($best === null || $res['score'] > $best['score']) {
                    $best = $res;
                }
            }
        }
        $best['label'] = self::CATEGORIES[$best['category']];
        return $best;
    }

    /**
     * A-5 lowball: best (lowest) 5-card low. Straights and flushes don't count
     * against you; Ace plays low. Lower score = better low. With $qualify8, only
     * five DISTINCT ranks all ≤ 8 qualify — returns null when no low exists.
     *
     * @return array{score:int,label:string,best5:string[]}|null
     */
    public static function evaluateLow(array $cards, bool $qualify8 = false): ?array
    {
        if (count($cards) < 5) {
            return null;
        }
        $best = null;
        foreach (self::combinations(range(0, count($cards) - 1), 5) as $combo) {
            $five = array_map(fn ($i) => $cards[$i], $combo);
            $res = self::lowScore5($five, $qualify8);
            if ($res !== null && ($best === null || $res['score'] < $best['score'])) {
                $best = $res;
            }
        }
        return $best;
    }

    /** Omaha low (hi-lo): exactly 2 hole + 3 board, 8-or-better. Null if no low. */
    public static function evaluateOmahaLow(array $hole, array $board): ?array
    {
        $best = null;
        foreach (self::combinations(range(0, count($hole) - 1), 2) as $hc) {
            foreach (self::combinations(range(0, count($board) - 1), 3) as $bc) {
                $five = array_merge(
                    array_map(fn ($i) => $hole[$i], $hc),
                    array_map(fn ($i) => $board[$i], $bc)
                );
                $res = self::lowScore5($five, true);
                if ($res !== null && ($best === null || $res['score'] < $best['score'])) {
                    $best = $res;
                }
            }
        }
        return $best;
    }

    /** Score five cards as an A-5 low. Smaller = better. Null if it misses the qualifier. */
    private static function lowScore5(array $five, bool $qualify8): ?array
    {
        $ranks = array_map([Card::class, 'lowRankValue'], $five);
        rsort($ranks);
        $counts = array_count_values($ranks);
        $distinct = count($counts);

        if ($qualify8 && ($distinct < 5 || max($ranks) > 8)) {
            return null;
        }

        // Pair structure makes a low worse: category mirrors the high ladder
        // (0 none, 1 pair, 2 two pair, 3 trips, 6 boat, 7 quads), then the ranks
        // themselves, high-to-low — minimized, so 5-4-3-2-A is the nuts.
        $shape = array_values($counts);
        rsort($shape);
        $category = match (true) {
            $shape[0] === 4 => 7,
            $shape[0] === 3 && ($shape[1] ?? 0) === 2 => 6,
            $shape[0] === 3 => 3,
            $shape[0] === 2 && ($shape[1] ?? 0) === 2 => 2,
            $shape[0] === 2 => 1,
            default => 0,
        };
        $score = $category;
        foreach ($ranks as $v) {
            $score = $score * 16 + $v;
        }
        $names = array_map(fn ($r) => $r === 1 ? 'A' : ($r >= 10 ? ['T', 'J', 'Q', 'K'][$r - 10] : (string) $r), $ranks);
        return ['score' => $score, 'label' => implode('-', $names) . ' low', 'best5' => $five];
    }

    /** Score exactly five cards. */
    private static function score5(array $five, bool $shortDeck = false): array
    {
        $ranks = [];
        $suits = [];
        foreach ($five as $c) {
            $ranks[] = Card::rankValue($c);
            $suits[] = Card::suit($c);
        }
        rsort($ranks);

        $isFlush = count(array_unique($suits)) === 1;

        // Rank frequency map.
        $counts = array_count_values($ranks);
        // Sort ranks by (count desc, rank desc) for tiebreaker ordering.
        $byGroup = array_keys($counts);
        usort($byGroup, function ($a, $b) use ($counts) {
            return [$counts[$b], $b] <=> [$counts[$a], $a];
        });
        $countShape = array_values($counts);
        rsort($countShape);

        // Straight detection (including A-2-3-4-5 wheel; A-6-7-8-9 in short deck).
        $straightHigh = self::straightHigh(array_keys($counts), $shortDeck);

        // Short deck (36 cards): flushes are rarer than full houses, so they
        // outrank them — swap the two categories' strength.
        $flushCat = $shortDeck ? 6 : 5;
        $boatCat = $shortDeck ? 5 : 6;

        if ($isFlush && $straightHigh) {
            return self::pack(8, [$straightHigh], $five);
        }
        if ($countShape[0] === 4) {
            $quad = $byGroup[0];
            $kicker = max(array_diff($ranks, [$quad]));
            return self::pack(7, [$quad, $kicker], $five);
        }
        if ($countShape[0] === 3 && ($countShape[1] ?? 0) === 2) {
            return self::pack($boatCat, [$byGroup[0], $byGroup[1]], $five, 6);
        }
        if ($isFlush) {
            return self::pack($flushCat, $ranks, $five, 5);
        }
        if ($straightHigh) {
            return self::pack(4, [$straightHigh], $five);
        }
        if ($countShape[0] === 3) {
            $trip = $byGroup[0];
            $kickers = array_values(array_filter($ranks, fn ($r) => $r !== $trip));
            return self::pack(3, array_merge([$trip], array_slice($kickers, 0, 2)), $five);
        }
        if ($countShape[0] === 2 && ($countShape[1] ?? 0) === 2) {
            $highPair = max($byGroup[0], $byGroup[1]);
            $lowPair = min($byGroup[0], $byGroup[1]);
            $kicker = max(array_diff($ranks, [$highPair, $lowPair]));
            return self::pack(2, [$highPair, $lowPair, $kicker], $five);
        }
        if ($countShape[0] === 2) {
            $pair = $byGroup[0];
            $kickers = array_values(array_filter($ranks, fn ($r) => $r !== $pair));
            return self::pack(1, array_merge([$pair], array_slice($kickers, 0, 3)), $five);
        }
        return self::pack(0, $ranks, $five);
    }

    /** Highest card of a straight among distinct ranks, or 0 if none. */
    private static function straightHigh(array $distinctRanks, bool $shortDeck = false): int
    {
        $set = array_flip($distinctRanks);
        // Wheel: Ace (14) also plays low — below the 2 normally, below the 6 in
        // short deck (5 is absent there, so the virtual rank can't collide).
        if (isset($set[14])) {
            $set[$shortDeck ? 5 : 1] = true;
        }
        $present = array_keys($set);
        rsort($present);
        foreach ($present as $high) {
            if ($high < 5) {
                break;
            }
            $run = true;
            for ($k = 0; $k < 5; $k++) {
                if (!isset($set[$high - $k])) {
                    $run = false;
                    break;
                }
            }
            if ($run) {
                return $high;
            }
        }
        return 0;
    }

    /**
     * Pack a comparable score. $labelCategory diverges from the strength
     * $category only in short deck, where flush (label 5) scores above full
     * house (label 6) — the label must still read correctly.
     */
    private static function pack(int $category, array $tiebreakers, array $five, ?int $labelCategory = null): array
    {
        $score = $category;
        $tb = array_slice(array_values($tiebreakers), 0, 5);
        while (count($tb) < 5) {
            $tb[] = 0;
        }
        foreach ($tb as $v) {
            $score = $score * 16 + $v;
        }
        return ['score' => $score, 'category' => $labelCategory ?? $category, 'best5' => $five];
    }

    /** All k-combinations of an array of indices. */
    private static function combinations(array $items, int $k): \Generator
    {
        $n = count($items);
        if ($k > $n) {
            return;
        }
        $indices = range(0, $k - 1);
        while (true) {
            yield array_map(fn ($i) => $items[$i], $indices);
            $i = $k - 1;
            while ($i >= 0 && $indices[$i] === $i + $n - $k) {
                $i--;
            }
            if ($i < 0) {
                return;
            }
            $indices[$i]++;
            for ($j = $i + 1; $j < $k; $j++) {
                $indices[$j] = $indices[$j - 1] + 1;
            }
        }
    }
}
