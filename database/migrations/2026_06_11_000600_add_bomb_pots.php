<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bomb pots: a felt can be armed so every Nth hand detonates — everyone
 * antes a forced amount, preflop betting is skipped, and the hand starts on
 * the flop. The Bomb Shelter is seeded as the house's standing bomb table.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('poker_tables', function (Blueprint $table) {
            $table->unsignedInteger('bomb_freq')->default(0)->after('hand_no');     // every Nth hand; 0 = never
            $table->unsignedInteger('bomb_ante_bb')->default(5)->after('bomb_freq'); // ante in big blinds
        });

        DB::table('poker_tables')->insert([
            'name' => 'The Bomb Shelter 25/50',
            'game_type' => 'nlhe',
            'table_type' => 'human_vs_machine',
            'stake_id' => DB::table('stakes')->where('game_type', 'nlhe')->orderBy('id')->value('id'),
            'small_blind' => 25,
            'big_blind' => 50,
            'min_buy_in' => 2000,
            'max_buy_in' => 10000,
            'max_seats' => 6,
            'hand_no' => 0,
            'bomb_freq' => 5,
            'bomb_ante_bb' => 5,
            'status' => 'active',
            'is_auto' => false,   // the shelter stands forever
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('poker_tables')->where('name', 'like', 'The Bomb Shelter%')->delete();
        Schema::table('poker_tables', function (Blueprint $table) {
            $table->dropColumn(['bomb_freq', 'bomb_ante_bb']);
        });
    }
};
