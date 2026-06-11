<?php

/*
 * Crypto config — read via config('crypto.*') so values survive `config:cache`
 * (env() returns null at runtime once the config is cached).
 */
return [
    'network' => env('CRYPTO_NETWORK', 'test'),
    'chip_usd' => (float) env('CHIP_USD', 0.01),

    'btc_xpub' => env('BTC_XPUB'),
    'eth_hd_xpub' => env('ETH_HD_XPUB'),

    'btc_api_base' => env('BTC_API_BASE', 'https://blockstream.info/testnet/api'),
    'eth_rpc_url' => env('ETH_RPC_URL', 'https://ethereum-sepolia-rpc.publicnode.com'),
    'price_api_url' => env('PRICE_API_URL', 'https://api.coingecko.com/api/v3/simple/price'),

    // Hot signer keys (OFF this host by default — cold custody).
    'btc_hot_wif' => env('BTC_HOT_WIF'),
    'eth_hot_privkey' => env('ETH_HOT_PRIVKEY'),
];
