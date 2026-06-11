<?php

namespace App\Poker;

/**
 * A seeded, shuffled deck. The seed is recorded with every hand so any deal can
 * be re-derived and audited — provably fair. The shuffle is a Fisher–Yates
 * driven by a SHA-256 keystream so it is deterministic given (seed) yet
 * unpredictable without it.
 */
final class Deck
{
    private array $cards;
    private int $pos = 0;

    /** @param string[]|null $base custom base deck (e.g. Card::shortDeck()); full 52 by default */
    public function __construct(public readonly string $seed, ?array $base = null)
    {
        $this->cards = self::shuffleWithSeed($base ?? Card::fullDeck(), $seed);
    }

    /** Cryptographically strong, unguessable seed for a new hand. */
    public static function freshSeed(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function draw(): string
    {
        if ($this->pos >= count($this->cards)) {
            throw new \RuntimeException('Deck exhausted');
        }
        return $this->cards[$this->pos++];
    }

    /** Draw n cards. */
    public function drawMany(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->draw();
        }
        return $out;
    }

    /** Burn a card (Hold'em burns before flop/turn/river). */
    public function burn(): void
    {
        $this->draw();
    }

    public function remaining(): int
    {
        return count($this->cards) - $this->pos;
    }

    /** Deterministic Fisher–Yates using a SHA-256 keystream from the seed. */
    public static function shuffleWithSeed(array $deck, string $seed): array
    {
        $n = count($deck);
        $counter = 0;
        $buf = '';
        $next = function () use (&$buf, &$counter, $seed): int {
            if (strlen($buf) < 4) {
                $buf .= hash('sha256', $seed . ':' . $counter++, true);
            }
            $chunk = substr($buf, 0, 4);
            $buf = substr($buf, 4);
            return unpack('N', $chunk)[1];
        };

        for ($i = $n - 1; $i > 0; $i--) {
            // Unbiased index in [0, i] via rejection sampling.
            $bound = $i + 1;
            $limit = intdiv(0x100000000, $bound) * $bound;
            do {
                $r = $next();
            } while ($r >= $limit);
            $j = $r % $bound;
            [$deck[$i], $deck[$j]] = [$deck[$j], $deck[$i]];
        }
        return $deck;
    }
}
