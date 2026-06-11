<?php

namespace App\Services;

use App\Models\User;
use App\Poker\GameType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The bone-counter. Sharkscope-style dossiers — cumulative profit curve, win
 * rate in bb/100, VPIP, showdown record, per-variant breakdown — and a profit
 * leaderboard, for every soul and machine in the archive.
 *
 * Performance: the old design rebuilt one player at a time, re-scanning the
 * whole hands table per user (O(users × hands) — 150 s cold for the board).
 * This version keeps incremental accumulators in `stat_state`: each refresh
 * decodes only hands newer than the checkpoint, once, and folds every seated
 * player of that hand into their running totals in the SAME pass. Reads come
 * straight from the accumulators. Refreshed hourly by poker:stats-refresh.
 */
class PlayerStats
{
    private const STATE_KEY = 'player_stats';
    private const GRAPH_POINTS = 300;   // points on the wire for a dossier curve
    private const CURVE_CAP = 1200;     // stored curve points per user before decimation
    private const RECENT_KEEP = 15;
    private const READ_TTL = 3600;      // derived-result cache: 1 hour

    /** Full dossier for one player. */
    public function forUser(User $user): array
    {
        return Cache::remember("pstats:user:{$user->id}", self::READ_TTL, function () use ($user) {
            $acc = $this->state()['users'][$user->id] ?? null;
            return $this->dossier($user, $acc);
        });
    }

    /** Leaderboard: everyone who has played, ranked by lifetime profit. */
    public function leaderboard(int $limit = 50): array
    {
        return Cache::remember('pstats:board', self::READ_TTL, function () use ($limit) {
            $rows = [];
            foreach ($this->state()['users'] as $a) {
                if (($a['hands'] ?? 0) <= 0) {
                    continue;
                }
                $rows[] = [
                    'username' => $a['name'],
                    'avatar' => $a['avatar'],
                    'is_bot' => (bool) $a['is_bot'],
                    'hands_played' => $a['hands'],
                    'total_profit' => $a['profit'],
                    'bb_per_100' => $a['hands'] ? round($a['bb_profit'] * 100 / $a['hands'], 1) : 0.0,
                    'biggest_pot' => $a['biggest'],
                ];
            }
            usort($rows, fn ($x, $y) => $y['total_profit'] <=> $x['total_profit']);
            return array_slice($rows, 0, $limit);
        });
    }

    /* ---------------------------------------------------------- the engine */

    /**
     * Fold every hand newer than the checkpoint into the accumulators. Cheap to
     * call often — it only touches new rows. Returns the number of hands folded.
     */
    public function refresh(): int
    {
        $row = DB::table('stat_state')->where('key', self::STATE_KEY)->first();
        $checkpoint = (int) ($row->checkpoint ?? 0);
        $users = $row && $row->payload ? (json_decode($row->payload, true)['users'] ?? []) : [];

        // bb per table, refreshed each run (cheap, few rows).
        $bbOf = DB::table('poker_tables')->pluck('big_blind', 'id')->all();

        $processed = 0;
        $maxId = $checkpoint;

        // Raw cursor over only the columns we need — no Eloquent hydration, no
        // loading hands the players never sat in. JSON decoded once per hand.
        DB::table('hands')
            ->select('id', 'game_type', 'table_id', 'pot', 'hand_no', 'ended_at', 'seats', 'actions', 'winners', 'hole_cards')
            ->where('id', '>', $checkpoint)
            ->orderBy('id')
            ->chunk(1000, function ($chunk) use (&$users, &$processed, &$maxId, $bbOf) {
                foreach ($chunk as $h) {
                    $this->foldHand($h, $users, $bbOf);
                    $maxId = $h->id;
                    $processed++;
                }
            });

        if ($processed > 0 || !$row) {
            // Display meta (avatar, current username, bot flag) lives on the
            // users table, not in the hand JSON — refresh it for everyone we
            // track, in one query.
            if ($users) {
                $meta = DB::table('users')->whereIn('id', array_keys($users))
                    ->get(['id', 'username', 'avatar', 'is_bot', 'bot_engine']);
                foreach ($meta as $m) {
                    if (isset($users[$m->id])) {
                        $users[$m->id]['name'] = $m->username ?? $users[$m->id]['name'];
                        $users[$m->id]['avatar'] = $m->avatar;
                        $users[$m->id]['is_bot'] = (bool) $m->is_bot;
                    }
                }
            }
            DB::table('stat_state')->updateOrInsert(
                ['key' => self::STATE_KEY],
                ['checkpoint' => $maxId, 'payload' => json_encode(['users' => $users]), 'updated_at' => now(), 'created_at' => now()]
            );
            // Bust the derived caches so the next read recomputes from fresh state.
            Cache::forget('pstats:board');
            // per-user dossier caches are keyed by id; let them lapse on TTL.
        }
        return $processed;
    }

    /** Apply one archived hand to every seated player's accumulator. */
    private function foldHand(object $h, array &$users, array $bbOf): void
    {
        $seats = json_decode($h->seats ?? '[]', true) ?: [];
        $actions = json_decode($h->actions ?? '[]', true) ?: [];
        $winners = json_decode($h->winners ?? '[]', true) ?: [];
        $reveal = json_decode($h->hole_cards ?? '[]', true) ?: [];
        if (!$seats) {
            return;
        }

        // Chips IN and voluntary-flag per seat, in one pass over the action log.
        $in = [];
        $vol = [];
        foreach ($actions as $a) {
            $s = $a['seat'] ?? null;
            if ($s === null) {
                continue;
            }
            $act = $a['action'] ?? '';
            if ($act === 'fold' || $act === 'check' || $act === 'draw') {
                continue;
            }
            $in[$s] = ($in[$s] ?? 0) + (int) ($a['amount'] ?? 0);
            if ($act === 'call' || $act === 'bet' || $act === 'raise') {
                $vol[$s] = true;
            }
        }
        // Chips OUT per seat.
        $out = [];
        foreach ($winners as $w) {
            $s = $w['seat'] ?? null;
            if ($s !== null) {
                $out[$s] = ($out[$s] ?? 0) + (int) ($w['amount'] ?? 0);
            }
        }

        $bb = max(1, (int) ($bbOf[$h->table_id] ?? 50));
        $game = $h->game_type ?? 'nlhe';

        foreach ($seats as $sn => $sInfo) {
            $uid = $sInfo['user_id'] ?? null;
            if (!$uid) {
                continue;
            }
            $seat = (int) ($sInfo['seat'] ?? $sn);
            $profit = (int) (($out[$seat] ?? 0) - ($in[$seat] ?? 0));

            $a = &$users[$uid];
            if (!$a) {
                $a = [
                    'name' => $sInfo['name'] ?? "u{$uid}", 'avatar' => null, 'is_bot' => (bool) ($sInfo['is_bot'] ?? false),
                    'hands' => 0, 'profit' => 0, 'bb_profit' => 0.0, 'bb_weighted' => 0,
                    'vpip' => 0, 'showdowns' => 0, 'sd_wins' => 0, 'biggest' => 0,
                    'per_game' => [], 'curve' => [], 'recent' => [],
                ];
            }
            $a['hands']++;
            $a['profit'] += $profit;
            $a['bb_profit'] += $profit / $bb;
            $a['bb_weighted'] += $bb;
            if (isset($vol[$seat])) {
                $a['vpip']++;
            }
            $sawShow = isset($reveal[$seat]) || isset($reveal[(string) $seat]);
            if ($sawShow) {
                $a['showdowns']++;
                if (($out[$seat] ?? 0) > 0) {
                    $a['sd_wins']++;
                }
            }
            if (($out[$seat] ?? 0) > $a['biggest']) {
                $a['biggest'] = (int) $out[$seat];
            }

            $g = &$a['per_game'][$game];
            $g['hands'] = ($g['hands'] ?? 0) + 1;
            $g['profit'] = ($g['profit'] ?? 0) + $profit;
            $g['bb_profit'] = ($g['bb_profit'] ?? 0) + $profit / $bb;
            unset($g);

            // Streaming-capped profit curve (cumulative). Decimate when full so
            // memory stays bounded as the archive grows, shape preserved.
            $a['curve'][] = $a['profit'];
            if (count($a['curve']) > self::CURVE_CAP) {
                $a['curve'] = array_values(array_filter($a['curve'], fn ($_, $i) => $i % 2 === 0, ARRAY_FILTER_USE_BOTH));
            }

            $a['recent'][] = [
                'hand_id' => $h->id, 'hand_no' => $h->hand_no, 'game' => $game,
                'profit' => $profit, 'pot' => (int) $h->pot, 'ended_at' => $h->ended_at,
            ];
            if (count($a['recent']) > self::RECENT_KEEP) {
                array_shift($a['recent']);
            }
            unset($a);
        }
    }

    /** Load the accumulator state, building it once if it has never run. */
    private function state(): array
    {
        $row = DB::table('stat_state')->where('key', self::STATE_KEY)->first();
        if (!$row || $row->payload === null) {
            $this->refresh(); // first-ever build (one pass)
            $row = DB::table('stat_state')->where('key', self::STATE_KEY)->first();
        }
        return $row && $row->payload ? json_decode($row->payload, true) : ['users' => []];
    }

    /** Shape one player's accumulator into the dossier the UI expects. */
    private function dossier(User $user, ?array $a): array
    {
        $a ??= ['hands' => 0, 'profit' => 0, 'bb_profit' => 0.0, 'bb_weighted' => 0,
                'vpip' => 0, 'showdowns' => 0, 'sd_wins' => 0, 'biggest' => 0,
                'per_game' => [], 'curve' => [], 'recent' => []];

        $perGame = [];
        foreach ($a['per_game'] as $game => $g) {
            $perGame[] = [
                'game' => $game,
                'name' => GameType::get($game)['name'],
                'hands' => $g['hands'],
                'profit' => $g['profit'],
                'bb_per_100' => $g['hands'] ? round($g['bb_profit'] * 100 / $g['hands'], 1) : 0.0,
            ];
        }
        usort($perGame, fn ($x, $y) => $y['hands'] <=> $x['hands']);

        $hands = (int) $a['hands'];
        return [
            'username' => $user->username,
            'avatar' => $user->avatar,
            'is_bot' => $user->is_bot,
            'bot_engine' => $user->is_bot ? $user->bot_engine : null,
            'member_since' => $user->created_at?->toDateString(),
            'hands_played' => $hands,
            'total_profit' => (int) $a['profit'],
            'avg_profit' => $hands ? intdiv((int) $a['profit'], $hands) : 0,
            'bb_per_100' => $hands ? round($a['bb_profit'] * 100 / $hands, 1) : 0.0,
            'avg_stake_bb' => $hands ? intdiv((int) $a['bb_weighted'], $hands) : 0,
            'vpip' => $hands ? round($a['vpip'] * 100 / $hands, 1) : 0.0,
            'showdowns' => (int) $a['showdowns'],
            'showdown_win_pct' => $a['showdowns'] ? round($a['sd_wins'] * 100 / $a['showdowns'], 1) : 0.0,
            'biggest_pot' => (int) $a['biggest'],
            'per_game' => $perGame,
            'graph' => $this->downsample($a['curve']),
            'recent' => array_reverse($a['recent']),
        ];
    }

    /** Keep the profit curve light on the wire without losing its shape. */
    private function downsample(array $curve): array
    {
        $n = count($curve);
        if ($n <= self::GRAPH_POINTS) {
            return array_map('intval', $curve);
        }
        $out = [];
        $step = $n / self::GRAPH_POINTS;
        for ($i = 0; $i < self::GRAPH_POINTS; $i++) {
            $out[] = (int) $curve[(int) floor($i * $step)];
        }
        $out[] = (int) $curve[$n - 1];
        return $out;
    }
}
