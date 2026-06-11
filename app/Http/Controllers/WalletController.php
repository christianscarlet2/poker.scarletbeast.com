<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\Withdrawal;
use App\Services\Bankroll;
use App\Services\CryptoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The crypto maw, HTTP side. Mint deposit sigils (addresses + QR), show the
 * book, and accept withdrawal demands paid at the live BTC/ETH rate.
 */
class WalletController extends Controller
{
    public function __construct(private CryptoService $crypto)
    {
    }

    public function show(Request $request)
    {
        $u = $request->user();
        return response()->json([
            'chips' => $u->chips,
            'chip_usd' => $this->crypto->chipUsd(),
            'network' => $this->crypto->network(),
            'rates' => [
                'btc' => $this->crypto->rateUsd('btc'),
                'eth' => $this->crypto->rateUsd('eth'),
            ],
            'configured' => [
                'btc' => $this->crypto->isConfigured('btc'),
                'eth' => $this->crypto->isConfigured('eth'),
            ],
            'deposits' => Deposit::where('user_id', $u->id)->latest('id')->limit(15)->get(),
            'withdrawals' => Withdrawal::where('user_id', $u->id)->latest('id')->limit(15)->get(),
        ]);
    }

    /** Mint (or fetch) a deposit address for a currency, with a QR sigil. */
    public function depositAddress(Request $request)
    {
        $data = $request->validate(['currency' => ['required', 'in:btc,eth']]);
        $cur = $data['currency'];
        if (!$this->crypto->isConfigured($cur)) {
            return response()->json([
                'error' => strtoupper($cur) . ' deposits are not yet armed. The warden must set the house xpub.',
            ], 503);
        }
        try {
            $addr = $this->crypto->depositAddressFor($request->user(), $cur);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        $uri = $cur === 'btc' ? "bitcoin:{$addr->address}" : "ethereum:{$addr->address}";
        return response()->json([
            'currency' => $cur,
            'address' => $addr->address,
            'qr' => $this->crypto->qrDataUri($uri),
            'rate_usd' => $this->crypto->rateUsd($cur),
            'chip_usd' => $this->crypto->chipUsd(),
            'note' => 'Send only ' . strtoupper($cur) . ' (' . $this->crypto->network() . ' network). '
                . 'Chips are credited automatically once the maw sees confirmation.',
        ]);
    }

    /** Demand a withdrawal to a user-specified address at the live rate. */
    public function withdraw(Request $request)
    {
        $data = $request->validate([
            'currency' => ['required', 'in:btc,eth'],
            'address' => ['required', 'string', 'min:20', 'max:120'],
            'chips' => ['required', 'integer', 'min:1'],
        ]);
        $u = $request->user();
        $cur = $data['currency'];

        if ($data['chips'] > $u->chips) {
            return response()->json(['error' => 'You cannot bleed what you do not hold.'], 422);
        }

        $rate = $this->crypto->rateUsd($cur);
        $amountCrypto = $this->crypto->cryptoForChips($data['chips'], $cur);

        $withdrawal = DB::transaction(function () use ($u, $cur, $data, $rate, $amountCrypto) {
            // Debit bankroll immediately; refunded if rejected.
            Bankroll::adjust($u->id, -$data['chips'], 'withdraw', "Withdrawal to {$data['address']}");
            return Withdrawal::create([
                'user_id' => $u->id,
                'currency' => $cur,
                'to_address' => $data['address'],
                'amount_chips' => $data['chips'],
                'amount_crypto' => $amountCrypto,
                'rate_usd' => $rate,
                'status' => 'pending',
            ]);
        });

        return response()->json([
            'ok' => true,
            'withdrawal' => $withdrawal,
            'note' => 'The warden reviews every outbound rite. Approved withdrawals are broadcast from the house hot wallet.',
        ]);
    }
}
