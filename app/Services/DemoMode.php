<?php

namespace App\Services;

/**
 * Demo mode — the exhibition switch. Passed as a parameter to the daemons
 * (`poker:supervise --demo` exports POKER_DEMO=1 into every queue worker;
 * `poker:dealer --demo` sets it for the autoscaler). When on:
 *
 *   - busted machines re-up from the infinite house float between hands,
 *     so the show never stops
 *   - the autoscaler packs every machine-eligible felt with 4-6 bots
 *
 * Humans are never touched: their chips stay real even in demo mode.
 */
final class DemoMode
{
    public static function on(): bool
    {
        return getenv('POKER_DEMO') === '1';
    }

    /**
     * Web-process check: the daemons carry the env flag, web requests don't —
     * the dealer heartbeats the cache while demo is live, and HTTP trusts that.
     */
    public static function live(): bool
    {
        return self::on() || (bool) \Illuminate\Support\Facades\Cache::get('demo:on');
    }

    public static function heartbeat(): void
    {
        \Illuminate\Support\Facades\Cache::put('demo:on', 1, 30);
    }

    public static function enable(): void
    {
        putenv('POKER_DEMO=1');
    }

    /** Demo seating target for a felt: 4-6 bots, varied per table, ring-capped. */
    public static function botTarget(int $tableId, int $maxSeats): int
    {
        return min($maxSeats - 1, 4 + ($tableId % 3)); // 4, 5, or 6 — stable per felt
    }
}
