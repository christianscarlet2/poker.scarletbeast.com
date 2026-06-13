<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'vr_seen_at')) {
                $table->timestamp('vr_seen_at')->nullable()->after('human_seen_at');
            }
        });
    }
    public function down(): void {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'vr_seen_at')) {
                $table->dropColumn('vr_seen_at');
            }
        });
    }
};
