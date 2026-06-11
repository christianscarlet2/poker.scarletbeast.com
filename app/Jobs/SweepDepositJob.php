<?php

namespace App\Jobs;

use App\Models\Deposit;
use App\Models\Setting;
use App\Services\HotWalletSigner;
use App\Services\SignerOffline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sweep a credited deposit from its one-time HD address into the house cold
 * wallet. If the signer is offline (default), the deposit stays 'credited' and
 * is flagged for the warden to sweep cold — never lost, only deferred.
 */
class SweepDepositJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $depositId)
    {
    }

    public function handle(HotWalletSigner $signer): void
    {
        $deposit = Deposit::find($this->depositId);
        if (!$deposit || $deposit->status === 'swept') {
            return;
        }
        $mainWallet = Setting::get($deposit->currency === 'btc' ? 'btc_main_wallet' : 'eth_main_wallet');
        if (!$mainWallet) {
            Log::info("sweep deferred: no main {$deposit->currency} wallet set");
            return;
        }
        try {
            $txid = $signer->send($deposit->currency, $mainWallet, $deposit->amount_crypto);
            $deposit->update(['status' => 'swept', 'sweep_txid' => $txid]);
            Log::info("swept deposit {$deposit->id} -> {$mainWallet} ({$txid})");
        } catch (SignerOffline $e) {
            // Expected default posture — leave credited, flag for manual sweep.
            Log::info("sweep deferred (signer offline) for deposit {$deposit->id}");
        }
    }
}
