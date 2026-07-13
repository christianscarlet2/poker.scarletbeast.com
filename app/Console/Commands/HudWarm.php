<?php

namespace App\Console\Commands;

use App\Services\HudStats;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Warm the bounded NN opponent-model HUD cache (`hudstats:nn`) off the hot path, so the live
 * decision path (BotBrain::injectOppModel) only ever READS the cache and never pays the full
 * archive scan. Bounded to recent hands — the opponents are mostly stable-style bots.
 */
class HudWarm extends Command
{
    protected $signature = 'poker:hud-warm {--hands=150000}';
    protected $description = 'Warm the bounded NN opponent-model HUD cache (hudstats:nn)';

    public function handle(HudStats $hud): int
    {
        $t = microtime(true);
        // build-then-atomic-swap (rebuildNn) instead of forget-then-rebuild: no dark window where
        // injectOppModel goes blind during the ~11-min rebuild. [opp-read reliability 2026-07-13]
        $s = $hud->rebuildNn((int) $this->option('hands'));
        // side-dump for the offline opp backfill (backfill_opp.py reads this)
        @file_put_contents('/tmp/hud_nn_stats.json', json_encode($s));
        $this->info(sprintf('hudstats:nn warmed: %d players in %.1fs', count($s), microtime(true) - $t));
        return self::SUCCESS;
    }
}
