<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Counts how many times the dealer button has swept past a seat while the player
// sits out. When the chip laps them twice, they are stood up and the seat freed.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('seats', function (Blueprint $t) {
            if (!Schema::hasColumn('seats', 'sit_out_button_passes')) {
                $t->unsignedTinyInteger('sit_out_button_passes')->default(0)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seats', function (Blueprint $t) {
            if (Schema::hasColumn('seats', 'sit_out_button_passes')) {
                $t->dropColumn('sit_out_button_passes');
            }
        });
    }
};
