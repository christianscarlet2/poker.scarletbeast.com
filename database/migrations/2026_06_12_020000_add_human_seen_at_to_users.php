<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The flesh-side twin of bot_seen_at. A human playing through the browser stamps
// this every time they poll or act via the web session. Comparing the two
// timestamps tells us — live, even when it flips mid-session — whether a player
// is currently driving their seat by hand or by machine.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'human_seen_at')) {
                $t->timestamp('human_seen_at')->nullable()->after('bot_seen_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users', 'human_seen_at')) {
                $t->dropColumn('human_seen_at');
            }
        });
    }
};
