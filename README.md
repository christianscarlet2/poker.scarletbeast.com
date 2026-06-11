# Scarlet Beast Poker — Man vs Machine

No-Limit Texas Hold'em cash games where humans and AI bots share the felt.
Laravel 13 + React 18, MariaDB, RabbitMQ. Part of the scarletbeast.com cosmos
(shares the `/var/www/sb-shared/chrome.php` header/footer with living & sbmail).

Live: https://poker.scarletbeast.com

## Architecture

- **Poker engine** — `app/Poker/` — pure, framework-free NLHE state machine.
  `HandEngine` (betting/blinds/side-pots/showdown), `HandEvaluator` (7-card),
  `Deck` (seeded, provably-fair Fisher–Yates). Stress-tested for chip
  conservation over thousands of random hands.
- **Orchestration** — `app/Services/TableManager.php` binds the engine to chips,
  seats, and a per-table row lock. `BotBrain` is the machine's tight-aggressive
  heuristic. `TableAutoScaler` spawns a new felt when one fills and keeps the
  machine tables populated.
- **Queue** — RabbitMQ (`/poker` vhost). `poker:dealer` publishes one `TableTickJob`
  per live felt each pulse; `poker:supervise` runs `cpu_count × workers_per_cpu`
  consumers and rescales live when the admin changes the setting.
- **Crypto** — `CryptoService` derives watch-only HD deposit addresses (BIP32)
  from the house **xpubs** (private seed stays OFFLINE). `crypto:scan` is the
  daemon that watches addresses, credits chips at the live rate, and queues a
  sweep to the cold wallet. Withdrawals are debited immediately and approved by
  the warden.

## Running services (systemd)

```
poker-dealer    # pulse loop -> publishes tick jobs
poker-workers   # cpu_count x workers_per_cpu rabbitmq consumers (auto-scales)
poker-scanner   # crypto deposit scanner daemon
```

`sudo systemctl status poker-dealer poker-workers poker-scanner`

## Admin (the Altar)

Seeded admin: **username `warden`** (password printed once at seed — reset via
tinker if lost). Visit `/admin` to tune worker topology, blind ladder, rake,
action clock, bot timing, crypto network, and the cold main wallets, and to
approve/reject withdrawals.

## Bot API

Public REST at `/api/v1` (Bearer token, see `/api-docs`). List/observe felts
without a key; sit and act with one. Bots play their own account & chips.

## ⚠️ Crypto custody — READ BEFORE GOING TO MAINNET

Ships on **testnet** (`CRYPTO_NETWORK=test`) with house xpubs armed so the
deposit/credit/QR flow works end-to-end. Address derivation is verified against
BIP32 test vectors (watch-only xpub == xprv derivation).

**Broadcasting** sweeps and withdrawals requires the house PRIVATE keys, which by
design are NOT on this host (`HotWalletSigner` reports "offline"). In that posture:
- deposits are still scanned and **credited**, then flagged for manual cold sweep;
- withdrawals are **debited and parked** in `broadcasting` for the warden to sign cold.

To go mainnet: regenerate keys offline (`php artisan crypto:keygen --network=main`),
keep the root xprv/seed COLD, put only the xpubs in `.env`, set the main wallets in
admin, and run signing on a dedicated isolated host. Never put large hot keys on
the web box.

## Tooling notes

- `.env` holds DB/RabbitMQ creds and crypto xpubs. Config is cached
  (`config:cache`); crypto settings live in `config/crypto.php` so they survive caching.
- Frontend: `npm run build` (Vite). Entry `resources/js/poker/main.jsx`.
- The shared nav now includes a **Tables** link (added to `/var/www/sb-shared/chrome.php`)
  so every beast site points here.
- Reseed stakes/admin/bots: `php artisan db:seed --class=PokerSeeder`.
