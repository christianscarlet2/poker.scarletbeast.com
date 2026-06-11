<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every chip that touches a soul's off-table bankroll passes through here so the
 * ledger and the balance can never drift apart. On-table stacks are NOT
 * bankroll — they live in seats and only hit the ledger on buy-in / cash-out.
 */
class Bankroll
{
    /**
     * Atomically adjust a user's bankroll and append a ledger scar.
     * Returns the new balance. Throws if it would go negative.
     */
    public static function adjust(int $userId, int $delta, string $type, ?string $memo = null, $ref = null): int
    {
        return DB::transaction(function () use ($userId, $delta, $type, $memo, $ref) {
            $user = User::lockForUpdate()->findOrFail($userId);
            $new = $user->chips + $delta;
            if ($new < 0) {
                throw new \RuntimeException('Insufficient chips');
            }
            $user->chips = $new;
            $user->save();

            LedgerEntry::create([
                'user_id' => $userId,
                'delta' => $delta,
                'balance_after' => $new,
                'type' => $type,
                'ref_type' => $ref ? $ref::class : null,
                'ref_id' => $ref?->getKey(),
                'memo' => $memo,
            ]);

            return $new;
        });
    }
}
