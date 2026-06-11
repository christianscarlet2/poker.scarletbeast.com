<?php

namespace App\Services;

/**
 * The signing hand. Broadcasting a sweep or a withdrawal needs the house PRIVATE
 * keys, which by design live OFF this host. When no signer is configured this
 * service reports "offline" and the caller leaves the transaction queued for the
 * warden to sign cold — funds are never silently lost, only deferred.
 *
 * To arm hot signing, set BTC_HOT_WIF / ETH_HOT_PRIVKEY in the environment of a
 * dedicated, isolated signer (NOT recommended on the web box for large floats).
 */
class HotWalletSigner
{
    public function canSign(string $currency): bool
    {
        return $currency === 'btc'
            ? (bool) config('crypto.btc_hot_wif')
            : (bool) config('crypto.eth_hot_privkey');
    }

    /**
     * Attempt to broadcast a payment. Returns a txid on success.
     * Throws SignerOffline when keys are not present.
     *
     * NOTE: live broadcast (UTXO selection for BTC, nonce/gas for ETH) is wired
     * to the configured RPC only when a signer key is present. Default posture
     * is offline-cold; this is intentional for a real-money float.
     */
    public function send(string $currency, string $toAddress, string $amountCrypto): string
    {
        if (!$this->canSign($currency)) {
            throw new SignerOffline(strtoupper($currency) . ' signer is offline (cold custody).');
        }
        // A real deployment wires raw-tx construction + eth_sendRawTransaction /
        // sendrawtransaction here using the isolated hot key. Left as an explicit
        // integration point so no half-signed broadcast path ships by default.
        throw new SignerOffline('Hot signing integration not enabled on this host.');
    }
}
