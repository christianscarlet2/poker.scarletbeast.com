<?php

namespace App\Casino;

/**
 * Provably-fair casino randomness: a SHA-256 keystream over a per-round seed,
 * identical in spirit to the poker deck shuffle. The seed is revealed when the
 * round settles so any outcome can be re-derived and audited.
 */
final class Rng
{
    private string $buf = '';
    private int $counter = 0;

    public function __construct(public readonly string $seed)
    {
    }

    public static function freshSeed(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Unbiased integer in [0, $bound). */
    public function below(int $bound): int
    {
        if ($bound <= 1) {
            return 0;
        }
        $limit = intdiv(0x100000000, $bound) * $bound;
        do {
            $r = $this->next32();
        } while ($r >= $limit);
        return $r % $bound;
    }

    /** Fisher–Yates a copy of the array. */
    public function shuffle(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->below($i + 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return $items;
    }

    private function next32(): int
    {
        if (strlen($this->buf) < 4) {
            $this->buf .= hash('sha256', $this->seed . ':' . $this->counter++, true);
        }
        $chunk = substr($this->buf, 0, 4);
        $this->buf = substr($this->buf, 4);
        return unpack('N', $chunk)[1];
    }
}
