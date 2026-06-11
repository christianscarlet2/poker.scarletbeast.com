<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table chat + emotes. Text is talk; emotes are theater — each one plays an
 * animation on the felt, and the targeted ones (rockets, tomatoes…) fly from
 * the sender's seat to the victim's.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('table_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('table_id')->constrained('poker_tables')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('username', 32);
            $table->enum('kind', ['chat', 'emote']);
            $table->string('body', 160);                 // text, or the emote key
            $table->unsignedInteger('from_seat')->nullable();   // null = railbird
            $table->unsignedInteger('target_seat')->nullable(); // projectiles only
            $table->timestamps();
            $table->index(['table_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_chats');
    }
};
