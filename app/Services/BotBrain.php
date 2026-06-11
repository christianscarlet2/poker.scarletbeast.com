<?php

namespace App\Services;

use App\Poker\Card;
use App\Poker\GameType;
use App\Poker\HandEngine;
use App\Poker\HandEvaluator;

/**
 * The machine's mind. A pragmatic, tight-aggressive heuristic — not a solver,
 * but no fish either. It reads hand strength, pot odds, and position, then bets,
 * calls, folds, and bluffs with enough variance to feel alive across a felt of
 * them. This is the silicon the flesh is here to beat.
 */
class BotBrain
{
    /** @return array{0:string,1:int} [action, amount] */
    public function decide(HandEngine $engine, int $seat): array
    {
        $view = $engine->view($seat);           // includes own hole cards
        $legal = $engine->legalActions($seat);
        if (empty($legal)) {
            return ['check', 0];
        }

        $p = $view['players'][$seat];
        $hole = $p['hole'];
        $up = $p['up'] ?? [];
        $board = $view['board'];
        $bb = max(1, $view['bb']);
        $pot = max(1, $view['pot']);
        $toCall = max(0, $view['current_bet'] - $p['committed_street']);
        $rules = GameType::get($view['game'] ?? 'nlhe');

        // Draw phase: choose which cards to throw away, not how much to bet.
        if (isset($legal['draw'])) {
            return ['draw', $this->drawMask($hole)];
        }

        $strength = $this->strengthFor($rules, $hole, $up, $board);

        // A dash of chaos so they don't all play identically.
        $strength = max(0.0, min(1.0, $strength + $this->jitter() * 0.10));

        $potOdds = $toCall > 0 ? $toCall / ($pot + $toCall) : 0.0;

        // ---- No bet to face: check or take the betting lead. -----------------
        if ($toCall === 0) {
            if (isset($legal['bet']) && ($strength > 0.62 || ($strength > 0.40 && $this->chance(0.22)))) {
                return $this->sizedBet($legal['bet'], $pot, $strength, $bb);
            }
            return ['check', 0];
        }

        // ---- Facing a bet: fold / call / raise on strength vs pot odds. ------
        // Value raise with strong hands.
        if (isset($legal['raise']) && $strength > 0.78 && $this->chance(0.7)) {
            return $this->sizedRaise($legal['raise'], $pot, $p['committed_street'], $strength, $bb);
        }
        // Occasional bluff-raise.
        if (isset($legal['raise']) && $strength < 0.30 && $potOdds < 0.35 && $this->chance(0.06)) {
            return $this->sizedRaise($legal['raise'], $pot, $p['committed_street'], 0.55, $bb);
        }
        // Call when the price is right for our strength.
        $callThreshold = $potOdds + 0.06;
        if ($strength >= $callThreshold && isset($legal['call'])) {
            return ['call', 0];
        }
        // Cheap call to set-mine / float small bets.
        if ($toCall <= $bb && $strength > 0.30 && isset($legal['call']) && $this->chance(0.7)) {
            return ['call', 0];
        }
        return isset($legal['fold']) ? ['fold', 0] : ['check', 0];
    }

    private function sizedBet(array $bet, int $pot, float $strength, int $bb): array
    {
        // 45%–80% pot depending on strength, clamped to legal range.
        $frac = 0.45 + $strength * 0.35;
        $amt = (int) round($pot * $frac);
        $amt = max($bet['min'], min($bet['max'], $amt));
        return ['bet', $amt];
    }

    private function sizedRaise(array $raise, int $pot, int $committed, float $strength, int $bb): array
    {
        $target = $committed + (int) round($pot * (0.6 + $strength * 0.6));
        $to = max($raise['min_to'], min($raise['max_to'], $target));
        return ['raise', $to];
    }

    /* --------------------------------------------------- strength estimation */

    /** Route hand-strength reads through the variant's rulebook. */
    private function strengthFor(array $rules, array $hole, array $up, array $board): float
    {
        // Razz: strength is lowness — distinct unpaired low ranks.
        if ($rules['lo'] !== null && !$rules['hi']) {
            return $this->razzStrength(array_merge($hole, $up));
        }
        if ($rules['family'] === 'stud') {
            return $this->studStrength(array_merge($hole, $up));
        }
        if ($rules['family'] === 'draw') {
            return count($hole) >= 5 ? $this->madeHandStrength($hole) : 0.3;
        }
        if ($rules['use_exactly'] === 2) { // Omaha
            return count($board) >= 3
                ? $this->categoryBase(HandEvaluator::evaluateOmaha($hole, $board)['category']) - 0.08
                : $this->omahaPreflop($hole);
        }
        if ($rules['deck'] === 'short') {
            if (empty($board)) {
                return $this->preflopStrength($hole);
            }
            $eval = HandEvaluator::evaluate(array_merge($hole, $board), true);
            return $this->categoryBase($eval['category']);
        }
        // Hold'em family (NLHE / LHE) — the original path.
        return empty($board)
            ? $this->preflopStrength($hole)
            : $this->postflopStrength($hole, $board);
    }

    /** Razz: count distinct low ranks (≤8, ace low) across visible cards. */
    private function razzStrength(array $cards): float
    {
        $lows = [];
        foreach ($cards as $c) {
            $v = Card::lowRankValue($c);
            if ($v <= 8) {
                $lows[$v] = true;
            }
        }
        return max(0.05, min(1.0, 0.10 + 0.16 * count($lows)));
    }

    /** Stud: made-hand category once 5 cards exist; pair/high heuristic before. */
    private function studStrength(array $cards): float
    {
        if (count($cards) >= 5) {
            return $this->categoryBase(HandEvaluator::evaluate($cards)['category']);
        }
        $pair = $this->pairRank($cards);
        if ($pair > 0) {
            return min(1.0, 0.50 + $pair / 70);
        }
        $hi = max(array_map([Card::class, 'rankValue'], $cards));
        return max(0.10, $hi / 30);
    }

    /** Five made cards in hand (draw games). */
    private function madeHandStrength(array $five): float
    {
        return $this->categoryBase(HandEvaluator::evaluate($five)['category']);
    }

    /** Omaha preflop: the best two-card combo, sweetened by the extra combos. */
    private function omahaPreflop(array $hole): float
    {
        if (count($hole) < 4) {
            return $this->preflopStrength($hole);
        }
        $best = 0.0;
        $extra = 0.0;
        for ($i = 0; $i < 4; $i++) {
            for ($j = $i + 1; $j < 4; $j++) {
                $s = $this->preflopStrength([$hole[$i], $hole[$j]]);
                if ($s > $best) {
                    $extra += $best * 0.10;
                    $best = $s;
                } else {
                    $extra += $s * 0.10;
                }
            }
        }
        return max(0.0, min(1.0, $best + $extra * 0.4));
    }

    /** Baseline strength for a made-hand category 0..8. */
    private function categoryBase(int $cat): float
    {
        return [
            0 => 0.18, 1 => 0.40, 2 => 0.62, 3 => 0.74, 4 => 0.82,
            5 => 0.88, 6 => 0.93, 7 => 0.97, 8 => 0.99,
        ][$cat] ?? 0.2;
    }

    /**
     * Five-card-draw discard mask (bit i = throw hole card i): stand pat on a
     * made straight-or-better, keep pairs and trips, else keep the top card.
     */
    private function drawMask(array $hole): int
    {
        if (count($hole) < 5) {
            return 0;
        }
        $eval = HandEvaluator::evaluate($hole);
        if ($eval['category'] >= 4) {
            return 0; // straight or better — pat
        }
        $counts = [];
        foreach ($hole as $c) {
            $r = Card::rankValue($c);
            $counts[$r] = ($counts[$r] ?? 0) + 1;
        }
        $keepRanks = array_keys(array_filter($counts, fn ($n) => $n >= 2));
        $mask = 0;
        if (!empty($keepRanks)) {
            foreach ($hole as $i => $c) {
                if (!in_array(Card::rankValue($c), $keepRanks, true)) {
                    $mask |= 1 << $i;
                }
            }
            return $mask;
        }
        // No pair: keep only the highest card, redraw four.
        $hiIdx = 0;
        foreach ($hole as $i => $c) {
            if (Card::rankValue($c) > Card::rankValue($hole[$hiIdx])) {
                $hiIdx = $i;
            }
        }
        foreach ($hole as $i => $c) {
            if ($i !== $hiIdx) {
                $mask |= 1 << $i;
            }
        }
        return $mask;
    }

    /** Preflop strength 0..1 from two hole cards (Chen-ish, normalised). */
    private function preflopStrength(array $hole): float
    {
        if (count($hole) < 2) {
            return 0.3;
        }
        $r1 = Card::rankValue($hole[0]);
        $r2 = Card::rankValue($hole[1]);
        $hi = max($r1, $r2);
        $lo = min($r1, $r2);
        $suited = Card::suit($hole[0]) === Card::suit($hole[1]);
        $pair = $r1 === $r2;

        // Chen formula.
        $score = match (true) {
            $hi === 14 => 10,
            $hi === 13 => 8,
            $hi === 12 => 7,
            $hi === 11 => 6,
            default => $hi / 2,
        };
        if ($pair) {
            $score = max($score * 2, 5);
        }
        if ($suited) {
            $score += 2;
        }
        $gap = $hi - $lo;
        $score -= match (true) {
            $gap <= 1 => 0,
            $gap === 2 => 1,
            $gap === 3 => 2,
            $gap === 4 => 4,
            default => 5,
        };
        if ($gap <= 1 && $hi < 12 && !$pair) {
            $score += 1; // 0/1-gap straighty bonus
        }
        // Chen ranges ~ -1..20. Normalise to 0..1.
        return max(0.0, min(1.0, ($score + 1) / 21));
    }

    /** Postflop strength 0..1 from made-hand category + texture. */
    private function postflopStrength(array $hole, array $board): float
    {
        $cards = array_merge($hole, $board);
        if (count($cards) < 5) {
            return $this->preflopStrength($hole);
        }
        $eval = HandEvaluator::evaluate($cards);
        $cat = $eval['category']; // 0..8

        // Baseline by category.
        $base = [
            0 => 0.18, // high card
            1 => 0.40, // pair
            2 => 0.62, // two pair
            3 => 0.74, // trips
            4 => 0.82, // straight
            5 => 0.88, // flush
            6 => 0.93, // full house
            7 => 0.97, // quads
            8 => 0.99, // straight flush
        ][$cat] ?? 0.2;

        // For a bare pair, weight by whether it's top/over-pair.
        if ($cat === 1) {
            $boardHigh = max(array_map([Card::class, 'rankValue'], $board));
            $pairRank = $this->pairRank($cards);
            if ($pairRank >= $boardHigh) {
                $base += 0.12; // top pair / overpair
            }
        }
        return max(0.0, min(1.0, $base));
    }

    private function pairRank(array $cards): int
    {
        $counts = [];
        foreach ($cards as $c) {
            $r = Card::rankValue($c);
            $counts[$r] = ($counts[$r] ?? 0) + 1;
        }
        $best = 0;
        foreach ($counts as $r => $n) {
            if ($n >= 2) {
                $best = max($best, $r);
            }
        }
        return $best;
    }

    private function jitter(): float
    {
        return (mt_rand(0, 1000) / 1000) - 0.5; // -0.5..0.5
    }

    private function chance(float $p): bool
    {
        return (mt_rand(0, 1000) / 1000) < $p;
    }
}
