<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\PokerTable;
use App\Models\Seat;
use App\Models\Setting;
use App\Models\Stake;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Bankroll;
use Illuminate\Http\Request;

/**
 * The altar. Where the warden tunes the machine: blind ladders, worker topology,
 * rake, the house cold wallets, and the approval of outbound blood (withdrawals).
 */
class AdminController extends Controller
{
    public function overview(Request $request)
    {
        return response()->json([
            'settings' => Setting::all_settings(),
            'stakes' => Stake::orderBy('sort')->get(),
            'game_types' => \App\Poker\GameType::names(),
            'stats' => [
                'players' => User::where('is_bot', false)->count(),
                'bots' => User::where('is_bot', true)->count(),
                'tables' => PokerTable::where('status', 'active')->count(),
                'seated' => Seat::where('status', '!=', 'empty')->count(),
                'cash_in_play' => (int) Seat::sum('stack'),
                'cash_banked' => (int) User::where('is_bot', false)->sum('chips'),
                'rake_total' => (int) \App\Models\Hand::sum('rake'),
                'rake_today' => (int) \App\Models\Hand::whereDate('created_at', today())->sum('rake'),
                'rake_hands' => (int) \App\Models\Hand::where('rake', '>', 0)->count(),
            ],
            'pending_withdrawals' => Withdrawal::with('user:id,username')
                ->where('status', 'pending')->latest('id')->get(),
            'recent_deposits' => Deposit::with('user:id,username')->latest('id')->limit(20)->get(),
        ]);
    }

    /** Update house settings (worker topology, rake, timeouts, wallets, network). */
    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'cpu_count' => ['nullable', 'integer', 'min:1', 'max:64'],
            'workers_per_cpu' => ['nullable', 'integer', 'min:1', 'max:16'],
            'action_timeout' => ['nullable', 'integer', 'min:5', 'max:120'],
            'rake_bps' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'rake_cap_bb' => ['nullable', 'integer', 'min:0', 'max:20'],
            'min_bots_per_table' => ['nullable', 'integer', 'min:0', 'max:8'],
            'bot_think_min' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'bot_think_max' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'crypto_network' => ['nullable', 'in:test,main'],
            'btc_main_wallet' => ['nullable', 'string', 'max:120'],
            'eth_main_wallet' => ['nullable', 'string', 'max:120'],
            'withdraw_auto_approve_under_usd' => ['nullable', 'integer', 'min:0'],
        ]);
        foreach ($data as $k => $v) {
            if ($v !== null) {
                Setting::put($k, $v);
            }
        }
        return response()->json(['ok' => true, 'settings' => Setting::all_settings()]);
    }

    /** Create or update a blind level (stake). */
    public function saveStake(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:32'],
            'game_type' => ['nullable', 'string', 'in:' . implode(',', \App\Poker\GameType::ids())],
            'small_blind' => ['required', 'integer', 'min:1'],
            'big_blind' => ['required', 'integer', 'min:2'],
            'min_buy_in' => ['required', 'integer', 'min:1'],
            'max_buy_in' => ['required', 'integer', 'min:1'],
            'max_seats' => ['required', 'integer', 'min:2', 'max:9'],
            'sort' => ['nullable', 'integer'],
            'enabled' => ['boolean'],
        ]);
        $data['game_type'] = $data['game_type'] ?? 'nlhe';
        // The variant's deck math caps the ring (7 for stud, 5 for draw).
        $data['max_seats'] = min($data['max_seats'], \App\Poker\GameType::maxSeats($data['game_type']));
        $stake = Stake::updateOrCreate(['id' => $data['id'] ?? null], $data);
        return response()->json(['ok' => true, 'stake' => $stake]);
    }

    public function deleteStake(Request $request, Stake $stake)
    {
        $stake->update(['enabled' => false]);
        return response()->json(['ok' => true]);
    }

    /** Approve a withdrawal — hand off to the broadcaster (or mark sent). */
    public function approveWithdrawal(Request $request, Withdrawal $withdrawal)
    {
        if ($withdrawal->status !== 'pending') {
            return response()->json(['error' => 'Already processed.'], 422);
        }
        $withdrawal->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'processed_at' => now(),
        ]);
        \App\Jobs\ProcessWithdrawalJob::dispatch($withdrawal->id)->onQueue('poker_default');
        return response()->json(['ok' => true]);
    }

    public function rejectWithdrawal(Request $request, Withdrawal $withdrawal)
    {
        if ($withdrawal->status !== 'pending') {
            return response()->json(['error' => 'Already processed.'], 422);
        }
        // Refund the bankroll.
        Bankroll::adjust($withdrawal->user_id, $withdrawal->amount_chips, 'adjustment', 'Withdrawal rejected — refund');
        $withdrawal->update(['status' => 'rejected', 'approved_by' => $request->user()->id, 'processed_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /** Grant chips to a user (comps / support). */
    public function grantChips(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'chips' => ['required', 'integer'],
            'memo' => ['nullable', 'string', 'max:120'],
        ]);
        $user = User::where('username', $data['username'])->firstOrFail();
        $bal = Bankroll::adjust($user->id, $data['chips'], 'bonus', $data['memo'] ?? 'Warden grant');
        return response()->json(['ok' => true, 'balance' => $bal]);
    }
}
