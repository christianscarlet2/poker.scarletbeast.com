<?php

namespace App\Http\Controllers;

use App\Services\GlobalWallet;

class GlobalWalletController extends Controller
{
    public function __construct(private GlobalWallet $wallet)
    {
    }

    /** Public estate ticker — house cash on hand + rakeback. */
    public function show()
    {
        return response()->json($this->wallet->publicView());
    }

    /** Every individual ledger line item (newest first) for the public ledger. */
    public function ledger()
    {
        return response()->json(['items' => $this->wallet->ledgerItems()]);
    }

    /** Trigger an Akaunting sync (admin/cron). */
    public function sync()
    {
        return response()->json($this->wallet->syncToAkaunting());
    }
}
