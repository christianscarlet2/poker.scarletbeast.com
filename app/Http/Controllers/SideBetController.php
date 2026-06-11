<?php

namespace App\Http\Controllers;

use App\Models\PokerTable;
use App\Models\SideBet;
use App\Services\SideBets;
use Illuminate\Http\Request;

/**
 * The rail window: live offer sheets, wager placement, and the bettor's slip.
 */
class SideBetController extends Controller
{
    /** Current offers + the caller's open/recent slips at this felt. */
    public function sheet(Request $request, PokerTable $table)
    {
        $out = SideBets::offers($table);
        if ($request->user()) {
            $out['mine'] = SideBet::where('user_id', $request->user()->id)
                ->where('table_id', $table->id)
                ->latest('id')->limit(15)
                ->get(['id', 'hand_no', 'bet_type', 'selection', 'stake', 'odds_x100', 'status', 'payout']);
        }
        return response()->json($out);
    }

    public function place(Request $request, PokerTable $table)
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:24'],
            'selection' => ['required', 'string', 'max:24'],
            'amount' => ['required', 'integer', 'min:10'],
        ]);
        try {
            $bet = SideBets::place($table, $request->user(), $data['type'], $data['selection'], $data['amount']);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json(['ok' => true, 'bet' => $bet]);
    }
}
