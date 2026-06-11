<?php

namespace App\Jobs;

use App\Models\Withdrawal;
use App\Services\HotWalletSigner;
use App\Services\SignerOffline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Broadcast an approved withdrawal from the house hot wallet. With the signer in
 * cold custody (default), the rite is parked in 'broadcasting' for the warden to
 * sign and send manually — the chips are already debited, so nothing double-pays.
 */
class ProcessWithdrawalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $withdrawalId)
    {
    }

    public function handle(HotWalletSigner $signer): void
    {
        $w = Withdrawal::find($this->withdrawalId);
        if (!$w || $w->status !== 'approved') {
            return;
        }
        try {
            $txid = $signer->send($w->currency, $w->to_address, $w->amount_crypto);
            $w->update(['status' => 'sent', 'txid' => $txid, 'processed_at' => now()]);
            Log::info("withdrawal {$w->id} sent: {$txid}");
        } catch (SignerOffline $e) {
            $w->update(['status' => 'broadcasting', 'reason' => 'Awaiting cold signature']);
            Log::info("withdrawal {$w->id} parked for manual cold broadcast");
        }
    }
}
