<?php

namespace App\Services;

use App\Models\Hand;
use App\Models\PokerTable;
use Illuminate\Support\Facades\Cache;

/**
 * The tell-engine. Computes PokerTracker-style HUD stats for every player from
 * the archived action logs — VPIP, PFR, AF, 3Bet, c-bet lines, showdown
 * tendencies — the same numbers the PT4 mirror derives, but served from the
 * house's own bone-ledger so the site never depends on an external desktop.
 *
 * One pass over the archive builds everyone's stats; cached briefly so live
 * tables stay fresh without re-scanning per request.
 */
class HudStats
{
    private const CACHE_TTL = 3600; // 1h — warmed hourly by poker:stats-refresh, so reads never pay the full scan

    /** PT4 stat name (as found in .pt4hud layouts) → our computed key. */
    public const MAP = [
        'Player Name Short' => 'name',
        'Player Name' => 'name',
        'Hands Abbreviated' => 'hands',
        'Hands' => 'hands',
        'VPIP' => 'vpip',
        'PFR' => 'pfr',
        'Total AF' => 'af',
        'Aggression Factor' => 'af',
        '3Bet Preflop' => 'threebet',
        'Fold to PF 3Bet After Raise' => 'fold3bet',
        '4Bet+ Ratio' => 'fourbet',
        '4Bet+ Preflop After Raising' => 'fourbet',
        'Fold to PF 4Bet After 3Bet' => 'fold4bet',
        'CBet Flop' => 'cbet_f',
        'CBet Turn' => 'cbet_t',
        'CBet River' => 'cbet_r',
        'Fold to F CBet' => 'fold_cbet_f',
        'Fold to T CBet' => 'fold_cbet_t',
        'Fold to R CBet' => 'fold_cbet_r',
        'Fold to CBet' => 'fold_cbet_f',
        'Went to Showdown' => 'wtsd',
        'WTSD' => 'wtsd',
        'Won At Showdown' => 'wsd',
        'Won When Saw Flop' => 'wwsf',
        'BB/100  Won' => 'bb100',
        'BB/100' => 'bb100',
    ];

    /** Stats for the user_ids seated at one table. */
    public function forTable(PokerTable $table, array $userIds): array
    {
        $all = $this->all();
        return array_intersect_key($all, array_flip(array_filter($userIds)));
    }

    /** @return array<int,array<string,mixed>> user_id => stat key => value */
    public function all(): array
    {
        return Cache::remember('hudstats:all', self::CACHE_TTL, fn () => $this->build());
    }

    /** Bounded recent-window HUD cache for the NN opponent model. Opponents are mostly stable-style
     *  bots, so a recent window captures their tendencies while staying cheap enough to warm off the
     *  hot path (poker:hud-warm). Separate cache key from the live-HUD 'hudstats:all'. */
    public function nnStats(int $limitHands = 600000): array
    {
        $minId = max(0, (int) Hand::max('id') - $limitHands);
        return Cache::remember('hudstats:nn', self::CACHE_TTL, fn () => $this->build($minId));
    }

    /** Rebuild the NN HUD cache with NO dark window: build fresh, then ATOMICALLY swap it in. The
     *  old poker:hud-warm did Cache::forget() THEN rebuilt (~11min), leaving injectOppModel blind
     *  ~40% of every 30-min cycle (the cache was literally empty when audited). This keeps the prior
     *  snapshot serving until the new one is ready, so opponent reads flow ~100% of the time. */
    public function rebuildNn(int $limitHands = 600000): array
    {
        $minId = max(0, (int) Hand::max('id') - $limitHands);
        $stats = $this->build($minId);
        Cache::put('hudstats:nn', $stats, self::CACHE_TTL);
        return $stats;
    }

    private function build(int $minId = 0): array
    {
        $bbOf = PokerTable::pluck('big_blind', 'id');
        $acc = []; // user_id => counters

        $q = Hand::orderBy('id');
        if ($minId > 0) { $q = $q->where('id', '>=', $minId); }
        $q->chunk(1000, function ($chunk) use (&$acc, $bbOf) {
            foreach ($chunk as $hand) {
                $this->tally($hand, $acc, max(1, (int) ($bbOf[$hand->table_id] ?? 50)));
            }
        });

        $out = [];
        $pct = fn ($n, $d) => $d > 0 ? round($n * 100 / $d) : null;
        foreach ($acc as $uid => $a) {
            $out[$uid] = [
                'name' => $a['name'],
                'hands' => $a['hands'],
                'vpip' => $pct($a['vpip'], $a['hands']),
                'pfr' => $pct($a['pfr'], $a['hands']),
                'af' => $a['af_calls'] > 0
                    ? round(($a['af_bets']) / $a['af_calls'], 1)
                    : ($a['af_bets'] > 0 ? 99.0 : null),
                'threebet' => $pct($a['3b'], $a['3b_opp']),
                'fold3bet' => $pct($a['f3b'], $a['f3b_opp']),
                'fourbet' => $pct($a['4b'], $a['4b_opp']),
                'fold4bet' => $pct($a['f4b'], $a['f4b_opp']),
                'cbet_f' => $pct($a['cb_f'], $a['cb_f_opp']),
                'cbet_t' => $pct($a['cb_t'], $a['cb_t_opp']),
                'cbet_r' => $pct($a['cb_r'], $a['cb_r_opp']),
                'fold_cbet_f' => $pct($a['fcb_f'], $a['fcb_f_opp']),
                'fold_cbet_t' => $pct($a['fcb_t'], $a['fcb_t_opp']),
                'fold_cbet_r' => $pct($a['fcb_r'], $a['fcb_r_opp']),
                'wtsd' => $pct($a['wtsd'], $a['sawflop']),
                'wsd' => $pct($a['wsd'], $a['wtsd']),
                'wwsf' => $pct($a['wwsf'], $a['sawflop']),
                'bb100' => $a['hands'] > 0 ? round($a['bb_profit'] * 100 / $a['hands'], 1) : null,
            ];
        }
        return $out;
    }

    /** Walk one archived hand and feed every seated player's counters. */
    private function tally(Hand $hand, array &$acc, int $bb): void
    {
        $seats = $hand->seats ?? [];
        $actions = $hand->actions ?? [];
        if (empty($seats) || empty($actions)) {
            return;
        }
        $seatUser = [];
        foreach ($seats as $sn => $s) {
            $uid = $s['user_id'] ?? null;
            if ($uid) {
                $seat = (int) ($s['seat'] ?? $sn);
                $seatUser[$seat] = $uid;
                $a = &$acc[$uid];
                $a ??= array_fill_keys([
                    'hands', 'vpip', 'pfr', 'af_bets', 'af_calls',
                    '3b', '3b_opp', 'f3b', 'f3b_opp', '4b', '4b_opp', 'f4b', 'f4b_opp',
                    'cb_f', 'cb_f_opp', 'cb_t', 'cb_t_opp', 'cb_r', 'cb_r_opp',
                    'fcb_f', 'fcb_f_opp', 'fcb_t', 'fcb_t_opp', 'fcb_r', 'fcb_r_opp',
                    'sawflop', 'wtsd', 'wsd', 'wwsf', 'bb_profit',
                ], 0);
                $a['name'] = $s['name'] ?? "u{$uid}";
                $a['hands']++;
                unset($a);
            }
        }

        $first = $actions[0]['street'] ?? 'preflop';
        $postStreets = ['flop' => 'cb_f', 'turn' => 'cb_t', 'river' => 'cb_r'];

        $raises = 0;              // preflop raise count (completion/raise; blinds excluded)
        $raiserAt = [];           // seat => raise number they made
        $aggr = [null, null];     // [prev street aggressor, current street aggressor]
        $street = $first;
        $streetHasBet = false;
        $folded = [];
        $vpipSeat = [];           // voluntarily put chips in this hand
        $pfrSeat = [];            // raised the first round this hand
        $in = [];                 // chips in / out for bb100
        $out = [];
        $sawSecond = false;

        foreach ($actions as $ax) {
            $seat = $ax['seat'] ?? null;
            $act = $ax['action'] ?? '';
            $st = $ax['street'] ?? $first;
            $uid = $seatUser[$seat] ?? null;

            if ($st !== $street) {
                // street rollover: current aggressor becomes "previous".
                $aggr = [$aggr[1], null];
                $street = $st;
                $streetHasBet = false;
                if ($st !== $first && $st !== 'draw') {
                    $sawSecond = true;
                }
            }

            if (!in_array($act, ['fold', 'check', 'draw'], true)) {
                $in[$seat] = ($in[$seat] ?? 0) + (int) ($ax['amount'] ?? 0);
            }
            if (!$uid) {
                continue;
            }
            $a = &$acc[$uid];

            if ($st === $first) {
                // ----- first betting round: VPIP / PFR / 3bet ladder -----
                switch ($act) {
                    case 'call':
                    case 'bet':
                        $vpipSeat[$seat] = true;
                        break;
                    case 'raise':
                        $vpipSeat[$seat] = true;
                        $pfrSeat[$seat] = true;
                        if ($raises === 1) {
                            $a['3b']++;
                        }
                        if ($raises === 2) {
                            $a['4b']++;
                        }
                        break;
                    case 'fold':
                        // fold facing a 3bet after raising / facing 4bet after 3betting
                        if (isset($raiserAt[$seat])) {
                            if ($raiserAt[$seat] === 1 && $raises >= 2) {
                                $a['f3b']++;
                            }
                            if ($raiserAt[$seat] === 2 && $raises >= 3) {
                                $a['f4b']++;
                            }
                        }
                        break;
                }
                // Opportunities counted at action time.
                if (in_array($act, ['fold', 'check', 'call', 'raise'], true)) {
                    if ($raises === 1) {
                        $a['3b_opp']++;
                    }
                    if ($raises === 2) {
                        $a['4b_opp']++;
                    }
                    if (isset($raiserAt[$seat])) {
                        if ($raiserAt[$seat] === 1 && $raises >= 2) {
                            $a['f3b_opp']++;
                        }
                        if ($raiserAt[$seat] === 2 && $raises >= 3) {
                            $a['f4b_opp']++;
                        }
                    }
                }
                if ($act === 'raise') {
                    $raises++;
                    $raiserAt[$seat] = $raises;
                    $aggr[1] = $seat;
                }
                if ($act === 'bet') { // stud completion logs as raise; safety net
                    $raises++;
                    $raiserAt[$seat] = $raises;
                    $aggr[1] = $seat;
                }
            } elseif (isset($postStreets[$st])) {
                // ----- postflop: AF + c-bet lines -----
                if ($act === 'bet' || $act === 'raise') {
                    $a['af_bets']++;
                }
                if ($act === 'call') {
                    $a['af_calls']++;
                }
                $key = $postStreets[$st];           // cb_f / cb_t / cb_r
                $fKey = 'f' . $key;                 // fcb_f ...
                if (!$streetHasBet) {
                    // First-in spot: the prior-street aggressor may continue.
                    if ($seat === $aggr[0] && in_array($act, ['bet', 'check'], true)) {
                        $a["{$key}_opp"]++;
                        if ($act === 'bet') {
                            $a[$key]++;
                        }
                    }
                } elseif ($aggr[1] === $aggr[0] && $seat !== $aggr[1]) {
                    // Facing a continuation bet from the same aggressor.
                    if (in_array($act, ['fold', 'call', 'raise'], true)) {
                        $a["{$fKey}_opp"]++;
                        if ($act === 'fold') {
                            $a[$fKey]++;
                        }
                    }
                }
                if ($act === 'bet' || $act === 'raise') {
                    $streetHasBet = true;
                    $aggr[1] = $seat;
                }
            }

            if ($act === 'fold') {
                $folded[$seat] = true;
            }
            unset($a);
        }

        // ----- hand-level outcomes -----
        foreach (($hand->winners ?? []) as $w) {
            $out[$w['seat'] ?? -1] = ($out[$w['seat'] ?? -1] ?? 0) + (int) ($w['amount'] ?? 0);
        }
        $reveal = $hand->hole_cards ?? [];
        foreach ($seatUser as $seat => $uid) {
            $a = &$acc[$uid];
            if (isset($vpipSeat[$seat])) {
                $a['vpip']++;
            }
            if (isset($pfrSeat[$seat])) {
                $a['pfr']++;
            }
            $profit = ($out[$seat] ?? 0) - ($in[$seat] ?? 0);
            $a['bb_profit'] += $profit / $bb;
            if ($sawSecond && (!isset($folded[$seat]) || isset($reveal[$seat]) || isset($reveal[(string) $seat]))) {
                $a['sawflop']++;
                if (isset($reveal[$seat]) || isset($reveal[(string) $seat])) {
                    $a['wtsd']++;
                    if (($out[$seat] ?? 0) > 0) {
                        $a['wsd']++;
                    }
                }
                if (($out[$seat] ?? 0) > 0) {
                    $a['wwsf']++;
                }
            }
            unset($a);
        }
    }
}
