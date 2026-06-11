<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The house learns every game. Stakes (and archived hands) carry a game_type so
 * the auto-scaler can spread Omaha, Stud, Razz, Short Deck, Limit, and Draw
 * felts alongside the original NLHE. poker_tables.game_type already existed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('stakes', function (Blueprint $table) {
            $table->string('game_type', 24)->default('nlhe')->after('name');
        });
        Schema::table('hands', function (Blueprint $table) {
            $table->string('game_type', 24)->default('nlhe')->after('table_id');
        });

        // One mid-stakes ladder rung per new variant, enabled — the auto-scaler
        // spawns the felts. Stud/draw seat caps respect their deck math.
        $rows = [
            //  name            game        sb   bb    min    max    seats
            ['PLO 25/50',      'plo',       25,  50,  2000, 10000, 6],
            ['PLO8 25/50',     'plo8',      25,  50,  2000, 10000, 6],
            ['LHE 50/100',     'lhe',       50, 100,  2000, 10000, 6],
            ['6+ 100',         'shortdeck', 50, 100,  4000, 20000, 6],
            ['Stud 50/100',    'stud',      50, 100,  2000, 10000, 7],
            ['Razz 50/100',    'razz',      50, 100,  2000, 10000, 7],
            ['Draw 25/50',     'draw5',     25,  50,  2000, 10000, 5],
        ];
        $now = now();
        foreach ($rows as $i => [$name, $game, $sb, $bb, $min, $max, $seats]) {
            DB::table('stakes')->insert([
                'name' => $name,
                'game_type' => $game,
                'small_blind' => $sb,
                'big_blind' => $bb,
                'min_buy_in' => $min,
                'max_buy_in' => $max,
                'max_seats' => $seats,
                'sort' => 100 + $i,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('stakes')->where('game_type', '!=', 'nlhe')->delete();
        Schema::table('stakes', function (Blueprint $table) {
            $table->dropColumn('game_type');
        });
        Schema::table('hands', function (Blueprint $table) {
            $table->dropColumn('game_type');
        });
    }
};
