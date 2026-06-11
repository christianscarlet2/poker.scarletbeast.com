<?php

use App\Poker\Pt4Hud;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HUD profiles: uploaded PokerTracker 4 layouts (.pt4hud) parsed into stat
 * rows the felt can overlay on every seat. user_id NULL = house defaults
 * available to everyone; players pick theirs via users.hud_profile_id.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hud_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('source', 160)->nullable();  // original filename
            $table->json('rows');                        // parsed stat layout
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('hud_profile_id')->nullable()->after('avatar');
        });

        // House default: the vendored BlackRain79 cash layout.
        $file = database_path('hud/Cash - BlackRain79 HUD.pt4hud');
        if (is_file($file)) {
            try {
                $parsed = Pt4Hud::parse(file_get_contents($file));
                DB::table('hud_profiles')->insert([
                    'user_id' => null,
                    'name' => $parsed['name'],
                    'source' => basename($file),
                    'rows' => json_encode($parsed['rows']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // Default seed is best-effort; uploads still work without it.
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('hud_profile_id');
        });
        Schema::dropIfExists('hud_profiles');
    }
};
