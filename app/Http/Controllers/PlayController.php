<?php

namespace App\Http\Controllers;

use App\Models\Hand;
use App\Models\PokerTable;
use App\Models\Seat;
use App\Services\TableManager;
use Illuminate\Http\Request;

/**
 * The felt's HTTP face. Every method here serves flesh (session) and machine
 * (Bearer token) identically — the war is fought through one set of endpoints.
 */
class PlayController extends Controller
{
    public function __construct(private TableManager $tm)
    {
    }

    /** Lobby: tables grouped by arena type and stake, plus a live hero felt. */
    public function lobby(Request $request)
    {
        // The lobby is public and identical for everyone, and the SPA polls it
        // every few seconds — so cache the computed payload for a beat. This
        // turns a stampede of identical reads into one cheap recompute per tick.
        $payload = \Illuminate\Support\Facades\Cache::remember('lobby:v3', 3, function () {
            $tables = PokerTable::with('stake')
                ->where('status', 'active')
                ->orderBy('stake_id')->orderBy('id')->get();

            // One query for all seat occupancy across every table (was N+1).
            $seatsByTable = Seat::where('status', '!=', 'empty')
                ->whereIn('table_id', $tables->pluck('id'))
                ->get(['table_id', 'is_bot'])
                ->groupBy('table_id');

            $rows = $tables->map(function (PokerTable $t) use ($seatsByTable) {
                $seated = $seatsByTable->get($t->id) ?? collect();
                $game = $t->game_type ?? 'nlhe';
                $gt = \App\Poker\GameType::get($game);
                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'type' => $t->table_type,
                    'game' => $game,
                    'game_name' => $gt['name'],
                    'game_short' => $gt['short'],
                    'stake' => $t->stake?->name,
                    'sb' => $t->small_blind,
                    'bb' => $t->big_blind,
                    'min_buy_in' => $t->min_buy_in,
                    'max_buy_in' => $t->max_buy_in,
                    'max_seats' => $t->max_seats,
                    'players' => $seated->count(),
                    'humans' => $seated->where('is_bot', false)->count(),
                    'bots' => $seated->where('is_bot', true)->count(),
                    'hand_no' => $t->hand_no,
                ];
            });

            // Hero: the busiest live felt right now — the front-page brawl.
            $hero = $rows->sortByDesc(fn ($r) => $r['players'] * 10 + $r['hand_no'])->first();

            return [
                // Plain array (not a Collection) so it survives cache serialization.
                'tables' => $rows->values()->all(),
                'hero_table_id' => $hero['id'] ?? null,
                'types' => [
                    'human_vs_machine' => 'Human vs Machine',
                    'machine_only' => 'Machine Only',
                    'human_only' => 'Human Only',
                ],
            ];
        });

        $payload['demo'] = \App\Services\DemoMode::live();

        return response()->json($payload);
    }

    public function tableState(Request $request, PokerTable $table)
    {
        return response()->json($this->tm->viewFor($table, $request->user()));
    }

    public function sit(Request $request, PokerTable $table)
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'seat' => ['nullable', 'integer'],
        ]);
        try {
            $seat = $this->tm->buyIn($table, $request->user(), $data['amount'], $data['seat'] ?? null);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        // Kick the felt so a hand can begin promptly.
        \App\Jobs\TableTickJob::dispatch($table->id)->onQueue('poker_default');
        return response()->json(['ok' => true, 'seat' => $seat->seat_no, 'state' => $this->tm->viewFor($table, $request->user())]);
    }

    public function leave(Request $request, PokerTable $table)
    {
        try {
            $this->tm->standUp($table, $request->user());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json(['ok' => true, 'state' => $this->tm->viewFor($table, $request->user())]);
    }

    public function act(Request $request, PokerTable $table)
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:fold,check,call,bet,raise'],
            'amount' => ['nullable', 'integer', 'min:0'],
        ]);
        try {
            $view = $this->tm->act($table, $request->user(), $data['action'], $data['amount'] ?? 0);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        \App\Jobs\TableTickJob::dispatch($table->id)->onQueue('poker_default');
        return response()->json(['ok' => true, 'state' => $view]);
    }

    /** Read-only observation — anyone may watch the machines bleed. */
    public function observe(Request $request, PokerTable $table)
    {
        // Observers never get hole cards (pass null user → redacted view).
        $view = $this->tm->viewFor($table, null);
        return response()->json($view);
    }

    /** Recent hand history for a felt (for replay / audit). */
    public function hands(Request $request, PokerTable $table)
    {
        $hands = Hand::where('table_id', $table->id)
            ->latest('id')->limit(25)
            ->get(['id', 'hand_no', 'game_type', 'board', 'winners', 'pot', 'ended_at']);
        return response()->json(['hands' => $hands]);
    }

    /**
     * Secret test fuse: force the NEXT hand on an armed (bomb_freq > 0) felt
     * to be a bomb pot. Requires the BOMB_TOKEN secret and a live demo mode —
     * useless in production play. Deliberately undocumented.
     */
    public function detonate(Request $request, PokerTable $table)
    {
        $token = (string) config('app.bomb_token', '');
        if ($token === '' || !hash_equals($token, (string) $request->input('token'))) {
            return response()->json(['error' => 'Nothing here.'], 404);
        }
        if (!\App\Services\DemoMode::live()) {
            return response()->json(['error' => 'The fuse only lights in demo mode.'], 422);
        }
        if (($table->bomb_freq ?? 0) <= 0) {
            return response()->json(['error' => 'This felt is not armed.'], 422);
        }
        \Illuminate\Support\Facades\Cache::put("bomb_next:{$table->id}", 1, 600);
        return response()->json(['ok' => true, 'note' => 'The next hand detonates.']);
    }

    /** The game catalog: every variant the house spreads, as data. */
    public function games(Request $request)
    {
        $games = [];
        foreach (\App\Poker\GameType::GAMES as $id => $g) {
            $games[] = [
                'id' => $id,
                'name' => $g['name'],
                'short' => $g['short'],
                'family' => $g['family'],
                'hole_cards' => $g['family'] === 'stud' ? 7 : $g['hole'],
                'use_exactly' => $g['use_exactly'],
                'betting' => $g['betting'],
                'deck' => $g['deck'] === 'short' ? 36 : 52,
                'hi_lo_split' => $g['lo'] !== null && $g['hi'],
                'lowball' => $g['lo'] !== null && !$g['hi'],
                'max_seats' => \App\Poker\GameType::maxSeats($id),
            ];
        }
        return response()->json(['games' => $games]);
    }

    /** Sharkscope-style leaderboard: every player ranked by lifetime profit. */
    public function players(Request $request, \App\Services\PlayerStats $stats)
    {
        return response()->json(['players' => $stats->leaderboard()]);
    }

    /** One player's full statistical dossier. */
    public function playerStats(Request $request, string $username, \App\Services\PlayerStats $stats)
    {
        $user = \App\Models\User::where('username', $username)->first();
        if (!$user) {
            return response()->json(['error' => 'No such soul in the ledger.'], 404);
        }
        return response()->json(['stats' => $stats->forUser($user)]);
    }

    /** Full archived record of one hand — feeds the step-through replay. */
    public function hand(Request $request, Hand $hand)
    {
        return response()->json([
            'hand' => [
                'id' => $hand->id,
                'table_id' => $hand->table_id,
                'table_name' => $hand->table?->name,
                'hand_no' => $hand->hand_no,
                'game_type' => $hand->game_type ?? 'nlhe',
                'game_name' => \App\Poker\GameType::get($hand->game_type ?? 'nlhe')['name'],
                'seats' => $hand->seats,
                'board' => $hand->board,
                'hole_cards' => $hand->hole_cards, // showdown-revealed only
                'actions' => $hand->actions,
                'winners' => $hand->winners,
                'pot' => $hand->pot,
                'rake' => $hand->rake,
                'ended_at' => $hand->ended_at?->toIso8601String(),
            ],
        ]);
    }
}
