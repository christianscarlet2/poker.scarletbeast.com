<?php

namespace App\Services;

use App\Models\Hand;
use App\Models\PokerTable;
use App\Models\User;
use App\Poker\GameType;
use Illuminate\Support\Facades\Cache;

/**
 * The bone-counter. Builds a Sharkscope-style dossier for any player — human or
 * machine — from the immortal hand archive: cumulative profit curve, win rate
 * in bb/100, VPIP, showdown record, and a per-variant breakdown. Every number
 * derives from the archived action logs (chips in) and winners (chips out).
 */
class PlayerStats
{
    private const CACHE_TTL = 60;          // seconds — stats lag live play by ≤1min
    private const GRAPH_POINTS = 300;      // downsample the profit curve for the wire

    /** Full dossier for one player. */
    public function forUser(User $user): array
    {
        return Cache::remember("pstats:{$user->id}", self::CACHE_TTL, function () use ($user) {
            return $this->build($user);
        });
    }

    /** Leaderboard: every soul and machine that has played, ranked by profit. */
    public function leaderboard(int $limit = 50): array
    {
        return Cache::remember('pstats:board', self::CACHE_TTL, function () use ($limit) {
            $rows = [];
            foreach (User::whereNotNull('username')->get() as $u) {
                $s = $this->build($u, graph: false);
                if ($s['hands_played'] > 0) {
                    $rows[] = [
                        'username' => $u->username,
                        'avatar' => $u->avatar,
                        'is_bot' => $u->is_bot,
                        'hands_played' => $s['hands_played'],
                        'total_profit' => $s['total_profit'],
                        'bb_per_100' => $s['bb_per_100'],
                        'biggest_pot' => $s['biggest_pot'],
                    ];
                }
            }
            usort($rows, fn ($a, $b) => $b['total_profit'] <=> $a['total_profit']);
            return array_slice($rows, 0, $limit);
        });
    }

    private function build(User $user, bool $graph = true): array
    {
        $tables = PokerTable::pluck('big_blind', 'id'); // id => bb

        $handsPlayed = 0;
        $totalProfit = 0;
        $totalBbProfit = 0.0;   // profit normalized to big blinds (for bb/100)
        $bbWeighted = 0;        // sum of stakes for avg stake
        $vpipHands = 0;
        $showdowns = 0;
        $showdownWins = 0;
        $biggestPot = 0;
        $perGame = [];
        $curve = [];            // cumulative profit by hand index
        $recent = [];

        // Stream the archive oldest-first so the curve reads left to right.
        Hand::orderBy('id')->chunk(500, function ($chunk) use (
            $user, $tables, &$handsPlayed, &$totalProfit, &$totalBbProfit, &$bbWeighted,
            &$vpipHands, &$showdowns, &$showdownWins, &$biggestPot, &$perGame, &$curve, &$recent
        ) {
            foreach ($chunk as $hand) {
                $seat = null;
                foreach (($hand->seats ?? []) as $sn => $s) {
                    if (($s['user_id'] ?? null) === $user->id) {
                        $seat = (int) ($s['seat'] ?? $sn);
                        break;
                    }
                }
                if ($seat === null) {
                    continue;
                }

                // Chips in: every logged wager by this player (antes, blinds,
                // bring-ins, calls, bets, raises). 'draw' logs cards, not chips.
                $in = 0;
                $voluntary = false;
                foreach (($hand->actions ?? []) as $a) {
                    if (($a['seat'] ?? null) !== $seat) {
                        continue;
                    }
                    if (in_array($a['action'], ['fold', 'check', 'draw'], true)) {
                        continue;
                    }
                    $in += (int) ($a['amount'] ?? 0);
                    if (in_array($a['action'], ['call', 'bet', 'raise'], true)) {
                        $voluntary = true;
                    }
                }
                // Chips out: every pot share awarded.
                $out = 0;
                foreach (($hand->winners ?? []) as $w) {
                    if (($w['seat'] ?? null) === $seat) {
                        $out += (int) ($w['amount'] ?? 0);
                    }
                }

                $profit = $out - $in;
                $bb = max(1, (int) ($tables[$hand->table_id] ?? 50));
                $game = $hand->game_type ?? 'nlhe';

                $handsPlayed++;
                $totalProfit += $profit;
                $totalBbProfit += $profit / $bb;
                $bbWeighted += $bb;
                if ($voluntary) {
                    $vpipHands++;
                }
                // Showdown: this player's hole cards were revealed at the end.
                if (isset($hand->hole_cards[$seat]) || isset($hand->hole_cards[(string) $seat])) {
                    $showdowns++;
                    if ($out > 0) {
                        $showdownWins++;
                    }
                }
                if ($out > 0) {
                    $biggestPot = max($biggestPot, $out);
                }

                $g = &$perGame[$game];
                $g['game'] = $game;
                $g['name'] = GameType::get($game)['name'];
                $g['hands'] = ($g['hands'] ?? 0) + 1;
                $g['profit'] = ($g['profit'] ?? 0) + $profit;
                $g['bb_profit'] = ($g['bb_profit'] ?? 0) + $profit / $bb;
                unset($g);

                $curve[] = $totalProfit;
                $recent[] = [
                    'hand_id' => $hand->id,
                    'hand_no' => $hand->hand_no,
                    'game' => $game,
                    'profit' => $profit,
                    'pot' => $hand->pot,
                    'ended_at' => $hand->ended_at?->toIso8601String(),
                ];
                if (count($recent) > 15) {
                    array_shift($recent);
                }
            }
        });

        foreach ($perGame as &$g) {
            $g['bb_per_100'] = $g['hands'] ? round($g['bb_profit'] * 100 / $g['hands'], 1) : 0.0;
            unset($g['bb_profit']);
        }
        unset($g);
        usort($perGame, fn ($a, $b) => $b['hands'] <=> $a['hands']);

        return [
            'username' => $user->username,
            'avatar' => $user->avatar,
            'is_bot' => $user->is_bot,
            'bot_engine' => $user->is_bot ? $user->bot_engine : null,
            'member_since' => $user->created_at?->toDateString(),
            'hands_played' => $handsPlayed,
            'total_profit' => $totalProfit,
            'avg_profit' => $handsPlayed ? intdiv($totalProfit, $handsPlayed) : 0,
            'bb_per_100' => $handsPlayed ? round($totalBbProfit * 100 / $handsPlayed, 1) : 0.0,
            'avg_stake_bb' => $handsPlayed ? intdiv($bbWeighted, $handsPlayed) : 0,
            'vpip' => $handsPlayed ? round($vpipHands * 100 / $handsPlayed, 1) : 0.0,
            'showdowns' => $showdowns,
            'showdown_win_pct' => $showdowns ? round($showdownWins * 100 / $showdowns, 1) : 0.0,
            'biggest_pot' => $biggestPot,
            'per_game' => array_values($perGame),
            'graph' => $graph ? $this->downsample($curve) : null,
            'recent' => array_reverse($recent),
        ];
    }

    /** Keep the profit curve light on the wire without losing its shape. */
    private function downsample(array $curve): array
    {
        $n = count($curve);
        if ($n <= self::GRAPH_POINTS) {
            return $curve;
        }
        $out = [];
        $step = $n / self::GRAPH_POINTS;
        for ($i = 0; $i < self::GRAPH_POINTS; $i++) {
            $out[] = $curve[(int) floor($i * $step)];
        }
        $out[] = $curve[$n - 1];
        return $out;
    }
}
