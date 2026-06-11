<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The authoritative live state of a felt's current hand, as one JSON blob.
 * The engine loads it under a row lock, mutates, and saves with a version bump
 * (optimistic concurrency). The server-only secrets (deck, hole cards) live
 * here and are NEVER sent to a client un-redacted.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('table_states', function (Blueprint $table) {
            $table->foreignId('table_id')->primary()->constrained('poker_tables')->cascadeOnDelete();
            $table->longText('state')->nullable();      // full engine state (JSON)
            $table->unsignedBigInteger('version')->default(0);
            $table->string('phase', 24)->default('idle'); // idle|dealing|preflop|flop|turn|river|showdown
            $table->timestamp('act_deadline')->nullable(); // when current actor times out
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_states');
    }
};
