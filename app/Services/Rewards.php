<?php

namespace App\Services;

use App\Models\BonusCode;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rakeback, the affiliate web, and bonus codes. Rake accruals land here from
 * TableManager::settle the moment the house takes its drop; claims and
 * redemptions flow out through Bankroll so the ledger never lies.
 */
class Rewards
{
    /**
     * Called with each hand's rake map (seat => rake paid). Credits rakeback
     * to the raked humans and the affiliate share to whoever recruited them.
     */
    public static function accrueFromRake(array $userRake): void
    {
        if (empty($userRake)) {
            return;
        }
        $rbBps = (int) Setting::get('rakeback_bps');
        $afBps = (int) Setting::get('affiliate_bps');
        if ($rbBps <= 0 && $afBps <= 0) {
            return;
        }
        foreach ($userRake as $userId => $rake) {
            if ($rake <= 0) {
                continue;
            }
            $user = User::find($userId);
            if (!$user || $user->is_bot) {
                continue;
            }
            $rb = intdiv($rake * $rbBps, 10000);
            if ($rb > 0) {
                $user->increment('rakeback_accrued', $rb);
                $user->increment('rakeback_lifetime', $rb);
            }
            if ($afBps > 0 && $user->referred_by) {
                $aff = intdiv($rake * $afBps, 10000);
                if ($aff > 0) {
                    User::where('id', $user->referred_by)->where('is_bot', false)->each(function ($r) use ($aff) {
                        $r->increment('affiliate_accrued', $aff);
                        $r->increment('affiliate_lifetime', $aff);
                    });
                }
            }
        }
    }

    /** Move an accrued balance into the bankroll. Returns the claimed amount. */
    public static function claim(User $user, string $kind): int
    {
        $col = $kind === 'affiliate' ? 'affiliate_accrued' : 'rakeback_accrued';
        return DB::transaction(function () use ($user, $col, $kind) {
            $fresh = User::lockForUpdate()->find($user->id);
            $amt = (int) $fresh->{$col};
            if ($amt <= 0) {
                throw new \RuntimeException('Nothing accrued yet — go bleed some rake.');
            }
            $fresh->{$col} = 0;
            $fresh->save();
            Bankroll::adjust($user->id, $amt, $kind, $kind === 'affiliate'
                ? 'Affiliate earnings claimed'
                : 'Rakeback claimed');
            return $amt;
        });
    }

    /** Redeem a bonus code into the bankroll. Returns the credited amount. */
    public static function redeem(User $user, string $code): int
    {
        return DB::transaction(function () use ($user, $code) {
            $bonus = BonusCode::where('code', strtoupper(trim($code)))->lockForUpdate()->first();
            if (!$bonus || !$bonus->enabled) {
                throw new \RuntimeException('That code means nothing to the beast.');
            }
            if ($bonus->expires_at && $bonus->expires_at->isPast()) {
                throw new \RuntimeException('That code has rotted away.');
            }
            if ($bonus->max_claims > 0 && $bonus->claims >= $bonus->max_claims) {
                throw new \RuntimeException('That code has been bled dry.');
            }
            if ($bonus->claims()->where('user_id', $user->id)->exists()) {
                throw new \RuntimeException('You already drank from this one.');
            }
            $bonus->claims()->create(['user_id' => $user->id, 'amount' => $bonus->amount]);
            $bonus->increment('claims');
            Bankroll::adjust($user->id, $bonus->amount, 'bonus', "Bonus code: {$bonus->code}", $bonus);
            return $bonus->amount;
        });
    }

    /** Attach a recruit to an affiliate by referral code (signup-time). */
    public static function attachReferral(User $user, string $code): bool
    {
        $ref = User::where('referral_code', strtoupper(trim($code)))->where('is_bot', false)->first();
        if (!$ref || $ref->id === $user->id) {
            return false;
        }
        $user->update(['referred_by' => $ref->id]);
        return true;
    }

    /** Make sure a human has a referral code to spread. */
    public static function ensureCode(User $user): string
    {
        if (!$user->referral_code) {
            $user->update(['referral_code' => strtoupper(Str::random(8))]);
        }
        return $user->referral_code;
    }
}
