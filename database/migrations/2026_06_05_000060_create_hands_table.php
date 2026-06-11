<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The permanent record of every hand played — the bone-ledger. Board, pot,
 * winners, and a full action log are immortalised here for hand history,
 * audit, and the public "observe" replay.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('table_id')->constrained('poker_tables')->cascadeOnDelete();
            $table->unsignedBigInteger('hand_no');
            $table->json('seats')->nullable();     // [{seat_no,user_id,name,is_bot,start_stack}]
            $table->json('board')->nullable();     // ["As","Kd",...]
            $table->json('hole_cards')->nullable(); // {seat_no:["Ah","Ad"]} — revealed at showdown only
            $table->json('actions')->nullable();   // ordered action log
            $table->json('winners')->nullable();   // [{seat_no,user_id,amount,hand_rank}]
            $table->unsignedBigInteger('pot')->default(0);
            $table->unsignedBigInteger('rake')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['table_id', 'hand_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hands');
    }
};
