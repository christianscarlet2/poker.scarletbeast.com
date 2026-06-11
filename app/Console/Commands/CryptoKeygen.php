<?php

namespace App\Console\Commands;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Network\NetworkFactory;
use Illuminate\Console\Command;

/**
 * Forge the house keys. Generates a BIP32 root from fresh entropy and prints:
 *   - the OFFLINE secret (root xprv / entropy) — guard this with your life
 *   - the watch-only account xpubs for BTC + ETH to paste into .env
 *
 * The deposit scanner only ever needs the xpubs. The xprv stays off the box.
 */
class CryptoKeygen extends Command
{
    protected $signature = 'crypto:keygen {--network=test : test|main for the BTC xpub}';
    protected $description = 'Generate house HD keys: offline root secret + watch-only xpubs';

    public function handle(): int
    {
        $net = $this->option('network') === 'main'
            ? NetworkFactory::bitcoin()
            : NetworkFactory::bitcoinTestnet();
        $ethContainer = NetworkFactory::bitcoin(); // ETH xpub uses mainnet BIP32 versions
        Bitcoin::setNetwork($net);

        $entropy = (new Random())->bytes(32);
        $root = HierarchicalKeyFactory::fromEntropy($entropy);

        // BTC account m/44'/0'/0' ; ETH account m/44'/60'/0'
        $btcAccount = $root->derivePath("44'/0'/0'");
        $ethAccount = $root->derivePath("44'/60'/0'");

        $btcXpub = $btcAccount->toExtendedPublicKey($net);
        $ethXpub = $ethAccount->toExtendedPublicKey($ethContainer);

        $this->newLine();
        $this->warn('================ OFFLINE SECRET — STORE COLD, NEVER COMMIT ================');
        $this->line('Entropy (hex):   ' . $entropy->getHex());
        $this->line('Root xprv:       ' . $root->toExtendedPrivateKey($net));
        $this->warn('==========================================================================');
        $this->newLine();
        $this->info('Paste these into .env (watch-only — safe on the server):');
        $this->line('CRYPTO_NETWORK=' . $this->option('network'));
        $this->line('BTC_XPUB=' . $btcXpub);
        $this->line('ETH_HD_XPUB=' . $ethXpub);
        $this->newLine();
        $this->comment('Sweeping & withdrawals require the root secret loaded into a SIGNING');
        $this->comment('service (kept off this host by default). Until then, deposits are');
        $this->comment('credited & swept-flagged for manual processing from the admin altar.');

        return self::SUCCESS;
    }
}
