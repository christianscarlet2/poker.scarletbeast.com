<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tournaments — the proposal's "man vs machine spectacle" in bracket form.
 * A tournament owns its tables (poker_tables.tournament_id), a timed blind
 * ladder, a prize pool fed by buy-ins, and a finishing order paid by
 * percentage. Entries track each soul from registration to bust or glory.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 96);
            $table->string('game_type', 24)->default('nlhe');
            $table->unsignedBigInteger('buy_in')->default(0);      // to the prize pool (cents)
            $table->unsignedBigInteger('fee')->default(0);         // to the house (cents)
            $table->unsignedBigInteger('starting_stack')->default(10000);
            $table->unsignedInteger('seats_per_table')->default(6);
            $table->unsignedInteger('min_players')->default(2);
            $table->unsignedInteger('max_players')->default(64);
            $table->json('blind_levels');                          // [{sb,bb,minutes}, ...]
            $table->json('payout_pct');                            // [50,30,20] by finishing place
            $table->unsignedInteger('level')->default(0);          // index into blind_levels
            $table->timestamp('level_started_at')->nullable();
            $table->unsignedBigInteger('prize_pool')->default(0);
            $table->enum('status', ['scheduled', 'registering', 'running', 'finished', 'cancelled'])
                  ->default('registering');
            $table->timestamp('starts_at')->nullable();            // auto-start time (optional)
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->index(['status', 'starts_at']);
        });

        Schema::create('tournament_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['registered', 'playing', 'busted', 'refunded'])->default('registered');
            $table->unsignedInteger('place')->nullable();          // finishing position (1 = champion)
            $table->unsignedBigInteger('prize')->default(0);
            $table->timestamps();
            $table->unique(['tournament_id', 'user_id']);
        });

        Schema::table('poker_tables', function (Blueprint $table) {
            $table->foreignId('tournament_id')->nullable()->after('stake_id')->index();
        });
        // Tournament tables carry no cash stake — allow detaching from stakes.
        Schema::table('poker_tables', function (Blueprint $table) {
            $table->unsignedBigInteger('stake_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('poker_tables', function (Blueprint $table) {
            $table->dropColumn('tournament_id');
        });
        Schema::dropIfExists('tournament_entries');
        Schema::dropIfExists('tournaments');
    }
};
