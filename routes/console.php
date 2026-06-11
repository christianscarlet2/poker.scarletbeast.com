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
