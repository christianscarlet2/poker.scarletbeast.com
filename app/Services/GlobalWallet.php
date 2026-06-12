<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The house's global wallet: the platform-wide cash on hand and the rakeback
 * position, aggregated from the player ledger. Feeds the public estate ticker
 * and (when configured) syncs to the Akaunting accounting system.
 *
 * All amounts are integer USD cents internally (5000 = $50.00).
 */
class GlobalWallet
{
    /** A cached snapshot of the global wallet (cents). */
    public function snapshot(): array
    {
        return Cache::remember('global_wallet', 60, function () {
            $cashOnHand = (int) User::sum('chips');                 // float held across all accounts
            $rakebackAccrued = (int) User::sum('rakeback_accrued'); // owed back to players now
            $rakebackLifetime = (int) User::sum('rakeback_lifetime');
            $affiliateAccrued = (int) User::sum('affiliate_accrued');
            // Rake the house has returned to players over all time (house "expense").
            $rakebackPaid = (int) abs(LedgerEntry::where('type', 'rakeback')->sum('delta'));

            return [
                'cash_on_hand_cents' => $cashOnHand,
                'rakeback_accrued_cents' => $rakebackAccrued,
                'rakeback_lifetime_cents' => $rakebackLifetime,
                'rakeback_paid_cents' => $rakebackPaid,
                'affiliate_accrued_cents' => $affiliateAccrued,
                'accounts' => (int) User::count(),
                'as_of' => now()->toIso8601String(),
            ];
        });
    }

    /** USD-formatted view for the public ticker. */
    public function publicView(): array
    {
        $s = $this->snapshot();
        $usd = fn ($c) => '$' . number_format($c / 100, 2);
        return [
            'cash_on_hand' => $usd($s['cash_on_hand_cents']),
            'rakeback_accrued' => $usd($s['rakeback_accrued_cents']),
            'rakeback_lifetime' => $usd($s['rakeback_lifetime_cents']),
            'rakeback_paid' => $usd($s['rakeback_paid_cents']),
            'accounts' => $s['accounts'],
            'as_of' => $s['as_of'],
            'breakdown' => $this->breakdown(),
            'raw' => $s,
        ];
    }

    /**
     * A fully itemised view of the books: exactly where every figure comes from
     * and how it is tallied. Cash on hand split by source, chips currently in
     * play, and every ledger movement grouped by type with a plain-English note
     * on what it is. All amounts are integer USD cents.
     */
    public function breakdown(): array
    {
        return Cache::remember('global_wallet_breakdown', 60, function () {
            $humans = (int) User::where('is_bot', 0)->sum('chips');
            $bots = (int) User::where('is_bot', 1)->sum('chips');
            $humanCount = (int) User::where('is_bot', 0)->count();
            $botCount = (int) User::where('is_bot', 1)->count();
            $inPlay = (int) \App\Models\Seat::where('status', '!=', 'empty')->sum('stack');

            // Human-readable label + description for each ledger movement type.
            $labels = $this->typeLabels();

            $rows = LedgerEntry::selectRaw(
                'type, COUNT(*) n, SUM(delta) net, SUM(GREATEST(delta,0)) inflow, SUM(LEAST(delta,0)) outflow'
            )->groupBy('type')->orderByRaw('SUM(ABS(delta)) DESC')->get();

            $flows = [];
            foreach ($rows as $r) {
                [$label, $desc] = $labels[$r->type] ?? [ucwords(str_replace('_', ' ', $r->type)), 'Ledger movement'];
                $flows[] = [
                    'type'          => $r->type,
                    'label'         => $label,
                    'desc'          => $desc,
                    'count'         => (int) $r->n,
                    'net_cents'     => (int) $r->net,
                    'inflow_cents'  => (int) $r->inflow,
                    'outflow_cents' => (int) abs($r->outflow),
                ];
            }

            return [
                'cash_on_hand' => [
                    'total_cents' => $humans + $bots,
                    'formula' => 'SUM(users.chips) — idle chip balances held across every account',
                    'parts' => [
                        ['label' => 'Human player bankrolls', 'cents' => $humans, 'note' => $humanCount . ' human accounts'],
                        ['label' => 'Machine bankrolls', 'cents' => $bots, 'note' => $botCount . ' bot accounts'],
                    ],
                ],
                'in_play' => [
                    'total_cents' => $inPlay,
                    'formula' => 'SUM(seats.stack) for every seated player — chips committed at the tables right now',
                ],
                'flows' => $flows,
                'as_of' => now()->toIso8601String(),
            ];
        });
    }

    /** Label + plain-English description for each ledger movement type. */
    private function typeLabels(): array
    {
        return [
            'grant'         => ['Owner grants', 'Chips minted to an account directly by the house'],
            'bonus'         => ['Promo bonuses', 'Bonus codes redeemed into player bankrolls'],
            'deposit'       => ['Crypto deposits', 'BTC / ETH funded onto the felt'],
            'withdraw'      => ['Withdrawals', 'Bankroll cashed out to a chain address'],
            'buy_in'        => ['Table buy-ins', 'Chips moved from bankroll onto a seat'],
            'cash_out'      => ['Table cash-outs', 'Chips moved from a seat back to bankroll'],
            'rake'          => ['House rake', "The house's cut of each flopped pot"],
            'rakeback'      => ['Rakeback returned', 'Rake paid back to the players who generated it'],
            'affiliate'     => ['Affiliate payouts', 'Commissions credited to recruiters'],
            'casino_bet'    => ['Casino wagers', 'Stakes placed at the casino games (roulette, etc.)'],
            'casino_win'    => ['Casino payouts', 'Winnings paid from the casino games'],
            'side_bet'      => ['Side bets placed', 'Rail wagers staked on live hands'],
            'side_bet_win'  => ['Side-bet payouts', 'Winning rail wagers'],
            'side_bet_void' => ['Side bets refunded', 'Voided rail wagers returned'],
        ];
    }

    /**
     * Every individual ledger movement, newest first — the raw line items behind
     * the grouped totals. Each is classed income (a credit, delta >= 0) or
     * expense (a debit) from the holding balance's perspective.
     */
    public function ledgerItems(int $limit = 2000): array
    {
        return Cache::remember('global_wallet_ledger', 30, function () use ($limit) {
            $labels = $this->typeLabels();
            $rows = LedgerEntry::query()
                ->leftJoin('users', 'users.id', '=', 'ledger_entries.user_id')
                ->orderByDesc('ledger_entries.id')
                ->limit($limit)
                ->get([
                    'ledger_entries.id', 'ledger_entries.type', 'ledger_entries.delta',
                    'ledger_entries.memo', 'ledger_entries.created_at', 'users.username',
                ]);

            return $rows->map(function ($r) use ($labels) {
                [$label] = $labels[$r->type] ?? [ucwords(str_replace('_', ' ', $r->type))];
                $delta = (int) $r->delta;
                return [
                    'id'          => (int) $r->id,
                    'date'        => optional($r->created_at)->toIso8601String(),
                    'type'        => $r->type,
                    'source'      => $label,
                    'memo'        => $r->memo,
                    'account'     => $r->username,
                    'amount_cents' => $delta,
                    'kind'        => $delta >= 0 ? 'income' : 'expense',
                ];
            })->all();
        });
    }

    /**
     * Push the snapshot to Akaunting as a record. No-op (logged) until the
     * AKAUNTING_URL + AKAUNTING_TOKEN env are set, so this is safe to call now
     * and activates the moment akaunting.scarletbeast.com exists.
     */
    public function syncToAkaunting(): array
    {
        $url = config('services.akaunting.url');
        $email = config('services.akaunting.email');
        $password = config('services.akaunting.password');
        if (!$url || !$email || !$password) {
            Log::info('Akaunting sync skipped — not configured (AKAUNTING_URL/EMAIL/PASSWORD).');
            return ['ok' => false, 'reason' => 'unconfigured', 'snapshot' => $this->snapshot()];
        }

        // Akaunting self-hosted authenticates the REST API with HTTP Basic
        // (a dedicated, limited "accountant" user — api@scarletbeast.com).
        try {
            $res = Http::withBasicAuth($email, $password)->acceptJson()
                ->get(rtrim($url, '/') . '/api/transactions', ['limit' => 1]);
            return ['ok' => $res->successful(), 'status' => $res->status(), 'snapshot' => $this->snapshot()];
        } catch (\Throwable $e) {
            Log::warning('Akaunting sync failed: ' . $e->getMessage());
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }
}
