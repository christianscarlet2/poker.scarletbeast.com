<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MeController extends Controller
{
    /** Table ids the bot is currently seated at — drives Hiss multi-instance. */
    public function tables(Request $request)
    {
        $ids = \App\Models\Seat::where('user_id', $request->user()->id)
            ->whereNotNull('user_id')->where('status', '!=', 'empty')
            ->pluck('table_id')->unique()->values();
        return response()->json(['tables' => $ids]);
    }

    public function show(Request $request)
    {
        $u = $request->user();
        return response()->json([
            'id' => $u->id,
            'username' => $u->username,
            'chips' => $u->chips,
            'is_admin' => $u->is_admin,
            'is_bot' => $u->is_bot,
            'avatar' => $u->avatar,
            'has_api_token' => (bool) $u->api_token_hash,
            'ledger' => LedgerEntry::where('user_id', $u->id)->latest('id')->limit(20)->get(),
        ]);
    }

    /** Mint a fresh API key — shown once. The machine's pass to the felt. */
    public function regenToken(Request $request)
    {
        $u = $request->user();
        $token = 'sbp_' . Str::random(48);
        $u->api_token_hash = hash('sha256', $token);
        $u->save();
        return response()->json([
            'ok' => true,
            'token' => $token,
            'note' => 'Store this now — it is shown once. Send as: Authorization: Bearer <token>',
        ]);
    }
}
