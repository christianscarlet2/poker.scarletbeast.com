<?php

namespace App\Http\Controllers;

use App\Models\Seat;
use App\Models\Tournament;
use App\Poker\GameType;
use App\Services\TournamentManager;
use Illuminate\Http\Request;

/**
 * Tournaments over HTTP: the public schedule and live brackets, player
 * registration, and the warden's create/start/cancel levers.
 */
class TournamentController extends Controller
{
    public function __construct(private TournamentManager $tm)
    {
    }

    public function index(Request $request)
    {
        $rows = Tournament::orderByRaw("FIELD(status,'running','registering','scheduled','finished','cancelled')")
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn ($t) => $this->summary($t));
        return response()->json(['tournaments' => $rows]);
    }

    public function show(Request $request, Tournament $tournament)
    {
        $t = $tournament;
        $entries = $t->entries()->with('user:id,username,avatar,is_bot')
            ->where('status', '!=', 'refunded')
            ->orderByRaw('place IS NULL DESC, place ASC')->get()
            ->map(fn ($e) => [
                'username' => $e->user?->username,
                'avatar' => $e->user?->avatar,
                'is_bot' => (bool) $e->user?->is_bot,
                'status' => $e->status,
                'place' => $e->place,
                'prize' => $e->prize,
                // live stack when still playing
                'stack' => $e->status === 'playing'
                    ? (int) Seat::whereIn('table_id', $t->tables()->pluck('id'))
                        ->where('user_id', $e->user_id)->value('stack')
                    : null,
            ]);

        $tables = $t->tables()->where('status', 'active')->get()
            ->map(fn ($tb) => [
                'id' => $tb->id,
                'name' => $tb->name,
                'players' => Seat::where('table_id', $tb->id)->where('status', '!=', 'empty')->count(),
            ]);

        $you = $request->user()
            ? $t->entries()->where('user_id', $request->user()->id)->where('status', '!=', 'refunded')->first()?->status
            : null;

        return response()->json(['tournament' => $this->summary($t) + [
            'entries' => $entries,
            'tables' => $tables,
            'blind_levels' => $t->blind_levels,
            'payout_pct' => $t->payout_pct,
            'you' => $you,
        ]]);
    }

    public function register(Request $request, Tournament $tournament)
    {
        try {
            $this->tm->register($tournament, $request->user());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json(['ok' => true]);
    }

    public function unregister(Request $request, Tournament $tournament)
    {
        try {
            $this->tm->unregister($tournament, $request->user());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json(['ok' => true]);
    }

    /* ------------------------------------------------------------- the altar */

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:96'],
            'game_type' => ['nullable', 'string', 'in:' . implode(',', GameType::ids())],
            'buy_in' => ['required', 'integer', 'min:0'],
            'fee' => ['nullable', 'integer', 'min:0'],
            'starting_stack' => ['required', 'integer', 'min:100'],
            'seats_per_table' => ['nullable', 'integer', 'min:2', 'max:9'],
            'min_players' => ['nullable', 'integer', 'min:2'],
            'max_players' => ['nullable', 'integer', 'min:2', 'max:512'],
            'starts_at' => ['nullable', 'date'],
            'blind_levels' => ['nullable', 'array'],
            'blind_levels.*.sb' => ['required_with:blind_levels', 'integer', 'min:1'],
            'blind_levels.*.bb' => ['required_with:blind_levels', 'integer', 'min:2'],
            'blind_levels.*.minutes' => ['required_with:blind_levels', 'integer', 'min:1'],
            'payout_pct' => ['nullable', 'array'],
            'payout_pct.*' => ['integer', 'min:1', 'max:100'],
        ]);
        $game = $data['game_type'] ?? 'nlhe';
        $seats = min($data['seats_per_table'] ?? 6, GameType::maxSeats($game));
        $payouts = $data['payout_pct'] ?? [50, 30, 20];
        if (array_sum($payouts) !== 100) {
            return response()->json(['error' => 'Payout percentages must sum to 100.'], 422);
        }
        $t = Tournament::create([
            'name' => $data['name'],
            'game_type' => $game,
            'buy_in' => $data['buy_in'],
            'fee' => $data['fee'] ?? 0,
            'starting_stack' => $data['starting_stack'],
            'seats_per_table' => $seats,
            'min_players' => $data['min_players'] ?? 2,
            'max_players' => $data['max_players'] ?? 64,
            'starts_at' => $data['starts_at'] ?? null,
            'blind_levels' => $data['blind_levels'] ?? [
                ['sb' => 25, 'bb' => 50, 'minutes' => 8],
                ['sb' => 50, 'bb' => 100, 'minutes' => 8],
                ['sb' => 100, 'bb' => 200, 'minutes' => 8],
                ['sb' => 200, 'bb' => 400, 'minutes' => 8],
                ['sb' => 400, 'bb' => 800, 'minutes' => 8],
                ['sb' => 800, 'bb' => 1600, 'minutes' => 8],
            ],
            'payout_pct' => $payouts,
            'status' => 'registering',
            'created_by' => $request->user()->id,
        ]);
        return response()->json(['ok' => true, 'tournament' => $this->summary($t)]);
    }

    public function start(Request $request, Tournament $tournament)
    {
        try {
            $this->tm->start($tournament);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json(['ok' => true]);
    }

    public function cancel(Request $request, Tournament $tournament)
    {
        $this->tm->cancel($tournament);
        return response()->json(['ok' => true]);
    }

    public function fillBots(Request $request, Tournament $tournament)
    {
        $n = (int) $request->input('count', 8);
        $added = $this->tm->fillWithBots($tournament, max(1, min(64, $n)));
        return response()->json(['ok' => true, 'added' => $added]);
    }

    private function summary(Tournament $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'game_type' => $t->game_type,
            'game_name' => GameType::get($t->game_type)['name'],
            'buy_in' => $t->buy_in,
            'fee' => $t->fee,
            'starting_stack' => $t->starting_stack,
            'seats_per_table' => $t->seats_per_table,
            'min_players' => $t->min_players,
            'max_players' => $t->max_players,
            'status' => $t->status,
            'level' => $t->level,
            'blinds' => $t->currentBlinds(),
            'prize_pool' => $t->prize_pool,
            'players' => $t->entries()->whereIn('status', ['registered', 'playing'])->count(),
            'field' => $t->entries()->where('status', '!=', 'refunded')->count(),
            'starts_at' => $t->starts_at?->toIso8601String(),
            'started_at' => $t->started_at?->toIso8601String(),
            'finished_at' => $t->finished_at?->toIso8601String(),
        ];
    }
}
