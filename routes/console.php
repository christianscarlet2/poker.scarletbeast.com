<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep failed_jobs from ever bloating again: a buggy queued job (e.g. a stuck
// table tick) can otherwise write millions of ~6KB stack traces. Prune anything
// older than 48h nightly. See guard in TableManager::tick().
Schedule::command('queue:prune-failed --hours=48')->dailyAt('04:10');

// Player statistics: fold new hands into the durable accumulators and warm the
// read caches every hour, so /players and /player never run a cold full-archive
// scan on a visitor's request (that once took 150s). See App\Services\PlayerStats.
// DISABLED-pending-cursor-fix: Schedule::command('poker:stats-refresh')->hourly()->withoutOverlapping();
