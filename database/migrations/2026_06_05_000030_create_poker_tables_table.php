<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single felt. table_type segregates the war:
 *   human_vs_machine — open arena, flesh and silicon at the same felt
 *   machine_only     — the bots grind each other, humans may only observe
 *   human_only       — no bots admitted; pure meat
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('poker_tables', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->string('game_type', 24)->default('nlhe'); // no-limit hold'em
            $table->enum('table_type', ['human_vs_machine', 'machine_only', 'human_only'])
                  ->default('human_vs_machine');
            $table->foreignId('stake_id')->constrained('stakes')->cascadeOnDelete();
            $table->unsignedBigInteger('small_blind');
            $table->unsignedBigInteger('big_blind');
            $table->unsignedBigInteger('min_buy_in');
            $table->unsignedBigInteger('max_buy_in');
            $table->unsignedInteger('max_seats')->default(9);
            $table->unsignedBigInteger('hand_no')->default(0);
            $table->enum('status', ['active', 'closing', 'closed'])->default('active');
            $table->boolean('is_auto')->default(true); // spawned by the auto-scaler
            $table->timestamp('last_action_at')->nullable();
            $table->timestamps();

            $table->index(['table_type', 'stake_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poker_tables');
    }
};
