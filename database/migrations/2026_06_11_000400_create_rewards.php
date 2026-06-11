<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The rewards engine: rakeback (a slice of your own rake returned), the
 * affiliate web (recruiters earn a slice of their recruits' rake forever),
 * and bonus codes (admin-minted promos redeemed at signup or after).
 * Accruals sit on the user row until claimed into the bankroll — every claim
 * goes through Bankroll so the ledger stays gospel.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 16)->nullable()->unique()->after('bot_engine');
            $table->foreignId('referred_by')->nullable()->after('referral_code');
            $table->unsignedBigInteger('rakeback_accrued')->default(0)->after('referred_by');
            $table->unsignedBigInteger('affiliate_accrued')->default(0)->after('rakeback_accrued');
            $table->unsignedBigInteger('rakeback_lifetime')->default(0)->after('affiliate_accrued');
            $table->unsignedBigInteger('affiliate_lifetime')->default(0)->after('rakeback_lifetime');
        });

        Schema::create('bonus_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 96);
            $table->unsignedBigInteger('amount');               // cents credited on redeem
            $table->unsignedInteger('max_claims')->default(0);  // 0 = unlimited
            $table->unsignedInteger('claims')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bonus_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bonus_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->timestamps();
            $table->unique(['bonus_code_id', 'user_id']);
        });

        // House dials (basis points of rake): 1500 = 15% rakeback,
        // 1000 = 10% of recruits' rake to the affiliate.
        DB::table('settings')->insertOrIgnore([
            ['key' => 'rakeback_bps', 'value' => '1500'],
            ['key' => 'affiliate_bps', 'value' => '1000'],
        ]);

        // Every existing human gets a referral code to spread.
        foreach (DB::table('users')->where('is_bot', false)->whereNull('referral_code')->get() as $u) {
            DB::table('users')->where('id', $u->id)
                ->update(['referral_code' => strtoupper(substr(md5($u->id . '|' . $u->username . '|sbp'), 0, 8))]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_claims');
        Schema::dropIfExists('bonus_codes');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['referral_code', 'referred_by', 'rakeback_accrued', 'affiliate_accrued', 'rakeback_lifetime', 'affiliate_lifetime']);
        });
    }
};
