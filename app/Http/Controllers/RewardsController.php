<?php

namespace App\Http\Controllers;

use App\Models\BonusCode;
use App\Models\Setting;
use App\Models\User;
use App\Services\Rewards;
use Illuminate\Http\Request;

/**
 * The rewards counter: rakeback claims, the affiliate web, bonus redemptions —
 * and the warden's promo mint.
 */
class RewardsController extends Controller
{
    /** The player's full rewards picture. */
    public function show(Request $request)
    {
        $u = $request->user();
        Rewards::ensureCode($u);
        $u->refresh();
        $recruits = User::where('referred_by', $u->id)->get(['id', 'username', 'avatar', 'created_at']);
        return response()->json([
            'rakeback' => [
                'accrued' => (int) $u->rakeback_accrued,
                'lifetime' => (int) $u->rakeback_lifetime,
                'pct' => ((int) Setting::get('rakeback_bps')) / 100,
            ],
            'affiliate' => [
                'accrued' => (int) $u->affiliate_accrued,
                'lifetime' => (int) $u->affiliate_lifetime,
                'pct' => ((int) Setting::get('affiliate_bps')) / 100,
                'code' => $u->referral_code,
                'link' => url('/register?ref=' . $u->referral_code),
                'recruits' => $recruits->map(fn ($r) => [
                    'username' => $r->username, 'avatar' => $r->avatar,
                    'since' => $r->created_at?->toDateString(),
                ]),
            ],
            'bonuses' => \App\Models\BonusClaim::where('user_id', $u->id)
                ->with('bonusCode:id,code,name')->latest('id')->limit(20)->get()
                ->map(fn ($c) => [
                    'code' => $c->bonusCode?->code, 'name' => $c->bonusCode?->name,
                    'amount' => $c->amount, 'when' => $c->created_at?->toDateString(),
                ]),
        ]);
    }

    public function claim(Request $request)
    {
        $kind = $request->validate(['kind' => ['required', 'in:rakeback,affiliate']])['kind'];
        try {
            $amt = Rewards::claim($request->user(), $kind);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json(['ok' => true, 'claimed' => $amt]);
    }

    public function redeem(Request $request)
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:32']])['code'];
        try {
            $amt = Rewards::redeem($request->user(), $code);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json(['ok' => true, 'credited' => $amt]);
    }

    /* ------------------------------------------------------------- the altar */

    public function adminIndex(Request $request)
    {
        return response()->json([
            'codes' => BonusCode::withCount('claims')->orderByDesc('id')->limit(50)->get(),
            'rakeback_bps' => (int) Setting::get('rakeback_bps'),
            'affiliate_bps' => (int) Setting::get('affiliate_bps'),
        ]);
    }

    public function adminStore(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:96'],
            'amount' => ['required', 'integer', 'min:1'],
            'max_claims' => ['nullable', 'integer', 'min:0'],
            'expires_at' => ['nullable', 'date'],
        ]);
        $code = BonusCode::updateOrCreate(
            ['code' => strtoupper($data['code'])],
            [
                'name' => $data['name'],
                'amount' => $data['amount'],
                'max_claims' => $data['max_claims'] ?? 0,
                'expires_at' => $data['expires_at'] ?? null,
                'enabled' => true,
            ]
        );
        return response()->json(['ok' => true, 'code' => $code]);
    }

    public function adminToggle(Request $request, BonusCode $code)
    {
        $code->update(['enabled' => !$code->enabled]);
        return response()->json(['ok' => true, 'enabled' => $code->enabled]);
    }
}
