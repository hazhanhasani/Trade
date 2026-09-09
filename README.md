# Trade

Trade is a production-oriented multi-exchange GRAM/TON trading backend for cPanel/shared hosting with a companion Android app for `https://rado-taxi.sbs`.

## Current release — backend v1.1.0 / Android v1.1.0

Trade now supports **Nobitex and Bitpin side by side**. Each exchange has independent bot and live-execution switches, while the global Kill Switch stops both exchanges.

> Automatic trading can lose money. Risk controls reduce operational risk but cannot guarantee profit or prevent every market loss.

## Exchanges

### Nobitex

Nobitex integration follows the current API-key flow documented by Nobitex:

- signed API host: `https://apiv2.nobitex.ir`
- public market-data host: `https://api.nobitex.ir`
- Ed25519 request signing
- `Nobitex-Key`, `Nobitex-Signature`, `Nobitex-Timestamp` headers
- exact signature payload: timestamp + HTTP method + full path/query + raw request body
- public order books and OHLC market data
- wallet access
- order list/status
- Spot Market/Limit/Stop/OCO order support in the backend service
- cancellation through the official order-status endpoint
- independent Nobitex Bot and Live switches
- encrypted Public Key / Private Key storage on the backend

For this project create a Nobitex API key with **READ + TRADE** permissions. `WITHDRAW` is not required and should not be enabled for the trading bot.

Nobitex API-key signing requires the PHP **Sodium** extension. The admin panel checks this automatically.

### Bitpin

Bitpin remains available and backward-compatible:

- API authentication and token refresh
- market/wallet/order APIs
- GRAM/TON market discovery
- live order submission/cancellation
- independent Bitpin Bot switch
- independent Bitpin Live switch
- Repair Center for Bitpin authentication/network diagnostics

The Bitpin bot can now be completely disabled while the Nobitex bot continues running.

## GRAM / TON compatibility

`GRAM` is the canonical asset inside Trade. Exchange-facing adapters accept legacy/current exchange symbols such as `TON`, `TONCOIN` or `GRAM` where applicable. For example, Nobitex can expose the market as `TONUSDT` while Trade records the managed asset as `GRAM`.

## Automatic trading architecture

The production path is LIVE-only; there is no paper-trading execution path.

```text
cPanel Cron (every minute)
        |
        +--> Auto Updater
        |
        +--> Bitpin bot  ---- independent enable/live gates
        |
        +--> Nobitex bot ---- independent enable/live gates
                 |
                 +--> Market scanner
                 +--> Signal engine
                 +--> Risk manager
                 +--> Order execution
                 +--> Position reconciliation
```

One exchange failing no longer prevents the other exchange from running. Cron records `success`, `partial`, or `failed` with per-exchange details.

## Risk and safety controls

- global server-side Kill Switch
- independent Bot ON/OFF per exchange
- independent Live Execution ON/OFF per exchange
- encrypted exchange credentials
- maximum order value
- maximum orders per hour
- position sizing and maximum exposure
- Safe / Balanced / Aggressive risk profiles
- signal-score threshold
- stop loss
- take profit
- daily realized-loss protection
- entry cooldown
- MySQL advisory locks against overlapping bot runs
- order/position reconciliation
- audit history
- no exchange private keys embedded in Android

## Admin panel

- `/admin/` — main control center and health dashboard for both exchanges
- `/admin/exchanges.php` — credentials and independent Bot/Live controls for Bitpin and Nobitex
- `/admin/bot/` — both automatic-trading engines, risk settings, signals, positions and performance
- `/admin/update/` — self-update status and manual update check
- `/admin/repair.php` — Bitpin/Cron diagnostics

The Nobitex setup page stores the key only after an authenticated Wallet API test succeeds.

## Android app

Android v1.1.0 adds:

- Nobitex / Bitpin exchange selector
- per-exchange Wallet, Market and Order views
- manual live order routing to the selected exchange
- per-exchange Bot ON/OFF control
- per-exchange Live Execution control
- global Kill Switch
- backend version/status display
- GRAM as the canonical asset with TON exchange compatibility

Exchange Public/Private/API secrets remain on the backend. Android only stores its application-access token.

## Backend API

Public:

- `GET /api/health`
- `GET /api/update`
- `POST /api/pair`

Authenticated:

- `GET /api/status`
- `GET /api/exchanges`
- `GET /api/markets?exchange=bitpin|nobitex`
- `GET /api/wallets?exchange=bitpin|nobitex`
- `GET /api/orders?exchange=bitpin|nobitex`
- `POST /api/orders` with `exchange`
- `DELETE /api/orders/{id}?exchange=bitpin|nobitex`
- `POST /api/exchanges/{exchange}/bot`
- `POST /api/exchanges/{exchange}/live`
- `POST /api/exchanges/{exchange}/run`
- `GET /api/bot`
- `GET /api/bot/recent`
- `POST /api/bot/settings`
- `POST /api/kill-switch`

Legacy Bitpin bot endpoints remain available so older Android builds do not immediately break.

## Requirements

### cPanel backend

- PHP 8.2+
- PDO MySQL
- cURL
- OpenSSL
- ZIP
- JSON
- MySQL/MariaDB
- HTTPS
- **PHP Sodium for Nobitex Ed25519 API-key signing**

### Android

- JDK 17
- compileSdk 37
- targetSdk 36
- Jetpack Compose
- one permanent signing key for production APK updates

## First installation

1. Extract `Trade-cPanel.zip` into the document root for `rado-taxi.sbs`.
2. Open `/install/`.
3. Configure database/admin credentials.
4. Bitpin credentials are optional.
5. Nobitex Public Key / Private Key are optional; if supplied, PHP Sodium must be enabled.
6. Nobitex Bot and Live remain OFF after installation until explicitly enabled from `/admin/exchanges.php`.
7. Configure `cron/tick.php` to run every minute.

Existing installations do **not** need reinstalling. The backend creates the Nobitex trading tables/settings automatically and preserves `storage/config.php` during self-update.

## Nobitex API key setup

In Nobitex create an API key intended for the bot with:

```text
READ
TRADE
```

Do not enable withdrawal access for Trade. Copy the Public Key and Private Key once, then open:

```text
https://rado-taxi.sbs/admin/exchanges.php
```

Paste both values under Nobitex and select **test and save**. The backend validates the signed Wallet request before replacing stored credentials. After the test succeeds, Bot and Live can be enabled independently.

Nobitex production signatures are time-sensitive. Keep the cPanel/server clock synchronized; large UTC clock drift can cause authentication failure.

## Auto Update

The backend self-updater checks the stable GitHub channel approximately every five minutes, verifies SHA-256, backs up the current backend, enters maintenance mode, installs the update, validates health, and rolls back if validation fails.

Stable release:

```text
https://github.com/hazhanhasani/Trade/releases/tag/trade-latest
```

Release assets:

- `Trade-cPanel.zip`
- `latest.json`
- `Trade.apk` only when permanent Android signing secrets are configured

## Permanent Android signing

Production Android updates require the same permanent signing certificate for every release. GitHub Actions expects:

- `ANDROID_KEYSTORE_BASE64`
- `ANDROID_KEYSTORE_PASSWORD`
- `ANDROID_KEY_ALIAS`
- `ANDROID_KEY_PASSWORD`

The project refuses to publish an installable production APK with an ephemeral debug certificate. Without these secrets, Android can still be compiled in CI but a replacement production APK is not published.

## Repository layout

```text
backend/
  public/
    admin/
      exchanges.php
      bot/
      update/
      repair.php
    install/
  src/
    Exchange/
      BitpinClient.php
      NobitexClient.php
    Trading/
      AutoTraderEngine.php
      NobitexAutoTraderEngine.php
      MarketScanner.php
      NobitexMarketScanner.php
      OrderService.php
      NobitexOrderService.php
      BotController.php
      RiskManager.php
      SignalEngine.php
      Schema.php
      NobitexSchema.php
  cron/tick.php
android/
.github/workflows/
```
