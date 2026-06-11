<?php

namespace App\Console\Commands;

use App\Services\HudStats;
use App\Services\PlayerStats;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Fold new hands into the statistics accumulators and warm the read caches.
 * Scheduled hourly so the player-stats pages and the HUD never trigger a cold
 * full-archive scan on a visitor's request.
 */
class StatsRefresh extends Command
{
    protected $signature = 'poker:stats-refresh';
    protected $description = 'Incrementally refresh player statistics and warm their caches';

    public function handle(PlayerStats $stats, HudStats $hud): int
    {
        $t = microtime(true);
        $n = $stats->refresh();                 // fold only new hands
        Cache::forget('pstats:board');
        $stats->leaderboard();                  // warm the board
        Cache::forget('hudstats:all');
        $hud->all();                            // warm the HUD stats
        $this->info(sprintf('stats refreshed: %d new hands in %.1fs', $n, microtime(true) - $t));
        return self::SUCCESS;
    }
}
