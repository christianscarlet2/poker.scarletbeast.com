<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Casino rounds — every spin, hand, roll and pull, with its provably-fair
 * seed (revealed at settle), the wagered/paid ledger, and serialized state
 * for the multi-step games (blackjack, craps, video poker).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('casino_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('game', 24);            // roulette_us|roulette_eu|blackjack|craps|videopoker|slots
            $table->string('seed', 64);
            $table->json('state')->nullable();     // multi-step game state
            $table->json('outcome')->nullable();   // final result payload for the client/audit
            $table->unsignedBigInteger('wagered')->default(0);
            $table->unsignedBigInteger('paid')->default(0);
            $table->enum('status', ['open', 'settled'])->default('open');
            $table->timestamps();
            $table->index(['user_id', 'game', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casino_rounds');
    }
};
