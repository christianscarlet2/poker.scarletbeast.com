<?php

namespace App\Console\Commands;

use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\Setting;
use App\Services\Bankroll;
use App\Services\CryptoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The scanner daemon. Watches every minted deposit address until coin lands,
 * credits the soul their chips at the live rate, then flags the funds for sweep
 * into the house cold wallet. This is the maw that turns BTC/ETH into a seat.
 */
class CryptoScan extends Command
{
    protected $signature = 'crypto:scan {--interval=20 : seconds between sweeps} {--once}';
    protected $description = 'Watch HD deposit addresses, credit chips on funding, queue sweeps';

    public function handle(CryptoService $crypto): int
    {
        $interval = max(5, (int) $this->option('interval'));
        $this->info("Scanner daemon online. Watching the maw every {$interval}s.");

        pcntl_async_signals(true);
        $run = true;
        pcntl_signal(SIGTERM, function () use (&$run) { $run = false; });
        pcntl_signal(SIGINT, function () use (&$run) { $run = false; });

        do {
            $watching = DepositAddress::where('status', 'watching')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->get();

            foreach ($watching as $addr) {
                try {
                    $this->checkAddress($crypto, $addr);
                } catch (\Throwable $e) {
                    Log::warning("scan {$addr->currency}:{$addr->address} — " . $e->getMessage());
                }
            }

            // Expire stale, unfunded sigils.
            DepositAddress::where('status', 'watching')
                ->whereNotNull('expires_at')->where('expires_at', '<', now())
                ->update(['status' => 'expired']);

            if ($this->option('once')) {
                break;
            }
            for ($s = 0; $s < $interval && $run; $s++) {
                sleep(1);
            }
        } while ($run);

        return self::SUCCESS;
    }

    private function checkAddress(CryptoService $crypto, DepositAddress $addr): void
    {
        $balance = $crypto->addressBalance($addr->currency, $addr->address);
        if ((float) $balance <= 0) {
            return;
        }

        // Has this funding already been credited?
        $already = Deposit::where('deposit_address_id', $addr->id)
            ->whereIn('status', ['credited', 'swept'])->exists();
        if ($already) {
            return;
        }

        $chips = $crypto->chipsForCrypto($balance, $addr->currency);
        if ($chips <= 0) {
            return;
        }

        $deposit = Deposit::create([
            'user_id' => $addr->user_id,
            'deposit_address_id' => $addr->id,
            'currency' => $addr->currency,
            'txid' => 'balance:' . $addr->address . ':' . $balance,
            'amount_crypto' => $balance,
            'rate_usd' => $crypto->rateUsd($addr->currency),
            'amount_chips' => $chips,
            'confirmations' => 1,
            'status' => 'credited',
            'credited_at' => now(),
        ]);

        Bankroll::adjust(
            $addr->user_id,
            $chips,
            'deposit',
            strtoupper($addr->currency) . " deposit {$balance} credited",
            $deposit
        );
        $addr->update(['status' => 'funded', 'last_txid' => $deposit->txid]);

        $this->info("CREDITED {$chips} chips to user {$addr->user_id} for {$balance} {$addr->currency}");

        // Sweep to the house cold wallet (requires the offline signer; otherwise
        // the deposit is flagged for manual sweep from the admin altar).
        \App\Jobs\SweepDepositJob::dispatch($deposit->id)->onQueue('poker_default');
    }
}
