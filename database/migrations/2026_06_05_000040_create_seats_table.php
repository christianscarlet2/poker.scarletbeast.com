<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A chair at a felt. A player's table stack lives here (chips moved from their
 * bankroll on buy-in, returned on stand-up).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('table_id')->constrained('poker_tables')->cascadeOnDelete();
            $table->unsignedInteger('seat_no');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('stack')->default(0); // chips in front of player
            $table->enum('status', ['empty', 'sitting', 'sitting_out', 'leaving'])->default('empty');
            $table->boolean('is_bot')->default(false);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['table_id', 'seat_no']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seats');
    }
};
