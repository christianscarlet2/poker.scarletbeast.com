<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable accumulator for the player-statistics engine. A single row holds the
 * incremental aggregates for every player plus the id of the last hand folded
 * in — so each refresh only scans hands newer than the checkpoint instead of
 * re-reading the whole 30 MB archive (which took 150 s the old O(users×hands)
 * way). Persisted in the DB so a cache flush never forces a cold full rebuild.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('stat_state', function (Blueprint $table) {
            $table->string('key', 48)->primary();
            $table->unsignedBigInteger('checkpoint')->default(0); // last hand id processed
            $table->longText('payload')->nullable();              // JSON: per-user accumulators
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stat_state');
    }
};
