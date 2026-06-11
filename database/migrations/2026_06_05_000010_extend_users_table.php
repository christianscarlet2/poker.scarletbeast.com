<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The flesh and the machine share one roster. Every soul at the felt — human or
 * bot — is a row here. `is_bot` decides which side of the war they fight on.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 32)->unique()->after('id');
            $table->boolean('is_admin')->default(false)->after('password');
            $table->boolean('is_bot')->default(false)->after('is_admin');
            // chips are the in-house currency: integer chips, never floats.
            $table->unsignedBigInteger('chips')->default(0)->after('is_bot');
            // sha256 of the API key a bot uses to play through the machine gate.
            $table->string('api_token_hash', 64)->nullable()->unique()->after('chips');
            $table->string('bot_engine', 64)->nullable()->after('api_token_hash'); // label for AI bots
            $table->string('avatar', 8)->nullable()->after('bot_engine');           // emoji/glyph
            $table->timestamp('last_seen_at')->nullable()->after('avatar');
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'username', 'is_admin', 'is_bot', 'chips', 'api_token_hash',
                'bot_engine', 'avatar', 'last_seen_at',
            ]);
        });
    }
};
