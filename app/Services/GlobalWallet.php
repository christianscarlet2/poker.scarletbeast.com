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
            'raw' => $s,
        ];
    }

    /**
     * Push the snapshot to Akaunting as a record. No-op (logged) until the
     * AKAUNTING_URL + AKAUNTING_TOKEN env are set, so this is safe to call now
     * and activates the moment akaunting.scarletbeast.com exists.
     */
    public function syncToAkaunting(): array
    {
        $url = config('services.akaunting.url');
        $token = config('services.akaunting.token');
        $company = config('services.akaunting.company', 1);
        if (!$url || !$token) {
            Log::info('Akaunting sync skipped — not configured (AKAUNTING_URL/AKAUNTING_TOKEN).');
            return ['ok' => false, 'reason' => 'unconfigured', 'snapshot' => $this->snapshot()];
        }

        $s = $this->snapshot();
        try {
            // Record cash-on-hand + rakeback liability as a dated accounting note.
            $res = Http::withToken($token)->acceptJson()->post(rtrim($url, '/') . '/api/wallet-snapshots', [
                'company_id' => $company,
                'cash_on_hand' => $s['cash_on_hand_cents'] / 100,
                'rakeback_accrued' => $s['rakeback_accrued_cents'] / 100,
                'rakeback_lifetime' => $s['rakeback_lifetime_cents'] / 100,
                'as_of' => $s['as_of'],
            ]);
            return ['ok' => $res->successful(), 'status' => $res->status()];
        } catch (\Throwable $e) {
            Log::warning('Akaunting sync failed: ' . $e->getMessage());
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }
}
