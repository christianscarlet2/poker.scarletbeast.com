<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Double-entry-ish audit of every chip that moves through a soul's bankroll.
 * `balance_after` is the running total so the ledger can be reconciled against
 * users.chips at any time. Nothing touches chips without leaving a scar here.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->bigInteger('delta');                 // signed chips
            $table->unsignedBigInteger('balance_after');
            // deposit|withdraw|buy_in|cash_out|win|loss|rake|bonus|adjustment
            $table->string('type', 24);
            $table->nullableMorphs('ref');               // ref_type / ref_id
            $table->string('memo', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
