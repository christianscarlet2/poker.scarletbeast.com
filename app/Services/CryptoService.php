<?php

namespace App\Services;

use App\Models\DepositAddress;
use App\Models\Setting;
use App\Models\User;
use BitWasp\Bitcoin\Address\PayToPubKeyHashAddress;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Network\NetworkFactory;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use kornrunner\Keccak;

/**
 * The crypto maw. Derives watch-only HD deposit addresses from the house's
 * extended public keys (the private seed stays OFFLINE), converts coin<->chips
 * at live rates, queries balances for the scanner, and renders QR sigils.
 *
 * BTC: BIP32 P2PKH receive addresses (m/.../0/index) off BTC_XPUB.
 * ETH: BIP32 secp256k1 child pubkey -> keccak256 -> 20-byte address off ETH_HD_XPUB.
 */
class CryptoService
{
    public function network(): string
    {
        return Setting::get('crypto_network', config('crypto.network'));
    }

    public function isConfigured(string $currency): bool
    {
        return $currency === 'btc' ? (bool) config('crypto.btc_xpub') : (bool) config('crypto.eth_hd_xpub');
    }

    /* ----------------------------------------------------- address derivation */

    private function btcNetwork()
    {
        return $this->network() === 'main'
            ? NetworkFactory::bitcoin()
            : NetworkFactory::bitcoinTestnet();
    }

    public function deriveBtcAddress(int $index): string
    {
        $xpub = config('crypto.btc_xpub');
        if (!$xpub) {
            throw new \RuntimeException('BTC_XPUB not configured');
        }
        $network = $this->btcNetwork();
        Bitcoin::setNetwork($network);
        $hd = HierarchicalKeyFactory::fromExtended($xpub, $network);
        $child = $hd->derivePath("0/{$index}");
        $address = new PayToPubKeyHashAddress($child->getPublicKey()->getPubKeyHash());
        return $address->getAddress($network);
    }

    public function deriveEthAddress(int $index): string
    {
        $xpub = config('crypto.eth_hd_xpub');
        if (!$xpub) {
            throw new \RuntimeException('ETH_HD_XPUB not configured');
        }
        // ETH always uses mainnet BIP32 versions for the xpub container.
        $network = NetworkFactory::bitcoin();
        Bitcoin::setNetwork($network);
        $hd = HierarchicalKeyFactory::fromExtended($xpub, $network);
        $child = $hd->derivePath("0/{$index}");

        // Uncompressed public key point -> drop 0x04 prefix -> keccak256 -> last 20 bytes.
        $point = $child->getPublicKey()->getPoint();
        $x = str_pad(gmp_strval(gmp_init($point->getX(), 10), 16), 64, '0', STR_PAD_LEFT);
        $y = str_pad(gmp_strval(gmp_init($point->getY(), 10), 16), 64, '0', STR_PAD_LEFT);
        $pub = hex2bin($x . $y);
        $hash = Keccak::hash($pub, 256);
        return '0x' . substr($hash, -40);
    }

    public function nextIndex(string $currency): int
    {
        $max = DepositAddress::where('currency', $currency)->max('derivation_index');
        return $max === null ? 0 : $max + 1;
    }

    /** Get the user's active deposit address for a currency, or mint a new one. */
    public function depositAddressFor(User $user, string $currency): DepositAddress
    {
        $existing = DepositAddress::where('user_id', $user->id)
            ->where('currency', $currency)->where('status', 'watching')->first();
        if ($existing) {
            return $existing;
        }
        $index = $this->nextIndex($currency);
        $address = $currency === 'btc' ? $this->deriveBtcAddress($index) : $this->deriveEthAddress($index);

        return DepositAddress::create([
            'user_id' => $user->id,
            'currency' => $currency,
            'address' => $address,
            'derivation_index' => $index,
            'status' => 'watching',
            'expires_at' => now()->addDays(7),
        ]);
    }

    /* ----------------------------------------------------------- rates / chips */

    /** USD price of 1 unit of the coin (cached 60s). */
    public function rateUsd(string $currency): float
    {
        $id = $currency === 'btc' ? 'bitcoin' : 'ethereum';
        return (float) Cache::remember("rate:$currency", 60, function () use ($id) {
            try {
                $url = config('crypto.price_api_url');
                $res = Http::timeout(8)->get($url, ['ids' => $id, 'vs_currencies' => 'usd']);
                $v = $res->json("$id.usd");
                if ($v) {
                    return (float) $v;
                }
            } catch (\Throwable $e) {
                // fall through to fallback
            }
            // Conservative fallbacks if the oracle is silent.
            return $id === 'bitcoin' ? 65000.0 : 3200.0;
        });
    }

    public function chipUsd(): float
    {
        return (float) config('crypto.chip_usd');
    }

    /** Chips credited for a crypto amount at current rate. */
    public function chipsForCrypto(string $amountCrypto, string $currency): int
    {
        $usd = (float) $amountCrypto * $this->rateUsd($currency);
        return (int) floor($usd / $this->chipUsd());
    }

    /** Crypto owed for a chip amount at current rate (8 dp string). */
    public function cryptoForChips(int $chips, string $currency): string
    {
        $usd = $chips * $this->chipUsd();
        $coin = $usd / max(0.0001, $this->rateUsd($currency));
        return number_format($coin, 18, '.', '');
    }

    /* ------------------------------------------------------------ chain reads */

    /** On-chain confirmed balance of an address, in whole coins (string). */
    public function addressBalance(string $currency, string $address): string
    {
        return $currency === 'btc' ? $this->btcBalance($address) : $this->ethBalance($address);
    }

    private function btcBalance(string $address): string
    {
        $base = rtrim(config('crypto.btc_api_base'), '/');
        $res = Http::timeout(12)->get("$base/address/$address");
        if (!$res->ok()) {
            return '0';
        }
        $j = $res->json();
        $funded = $j['chain_stats']['funded_txo_sum'] ?? 0;
        $spent = $j['chain_stats']['spent_txo_sum'] ?? 0;
        $sats = max(0, $funded - $spent);
        return number_format($sats / 1e8, 8, '.', '');
    }

    private function ethBalance(string $address): string
    {
        $rpc = config('crypto.eth_rpc_url');
        $res = Http::timeout(12)->post($rpc, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'eth_getBalance',
            'params' => [$address, 'latest'],
        ]);
        $hex = $res->json('result');
        if (!$hex) {
            return '0';
        }
        $wei = gmp_init($hex, 16);
        // wei -> eth (18 dp) without float loss.
        $whole = gmp_strval(gmp_div_q($wei, gmp_pow(10, 18)));
        $frac = str_pad(gmp_strval(gmp_mod($wei, gmp_pow(10, 18))), 18, '0', STR_PAD_LEFT);
        return rtrim("$whole.$frac", '0') ?: '0';
    }

    /* ------------------------------------------------------------------- QR */

    public function qrDataUri(string $text): string
    {
        $result = (new Builder(
            writer: new PngWriter(),
            data: $text,
            size: 320,
            margin: 12,
        ))->build();
        return $result->getDataUri();
    }
}
