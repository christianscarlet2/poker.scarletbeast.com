<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Side bets — sidebetz.ai-style in-game wagers for the rail. Observers (and
 * players) bet the live hand from their bankroll: who drags the pot, what the
 * flop brings, whether it goes to showdown. Odds derive from PUBLIC info only
 * (live player count, street) so the house edge never leans on hidden cards.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('side_bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_id')->constrained('poker_tables')->cascadeOnDelete();
            $table->unsignedBigInteger('hand_no');
            $table->string('bet_type', 24);          // winner | flop_color | flop_pair | showdown
            $table->string('selection', 24);         // seat number, red|black, yes|no
            $table->unsignedBigInteger('stake');     // cents wagered
            $table->unsignedInteger('odds_x100');    // payout multiplier ×100, locked at placement
            $table->enum('status', ['open', 'won', 'lost', 'void'])->default('open');
            $table->unsignedBigInteger('payout')->default(0);
            $table->timestamps();
            $table->index(['table_id', 'hand_no', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('side_bets');
    }
};
