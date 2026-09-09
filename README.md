# Trade

Trade is a production-oriented Bitpin trading backend for cPanel/shared hosting with a companion Android app for `https://rado-taxi.sbs`.

## Current release — v1.0.1

### Production trading

Trade now includes a live automatic trading engine. The production path does not use paper trading.

Implemented:

- live Bitpin authentication, wallet, market, order and cancellation APIs
- automatic GRAM market discovery, with TON retained as a compatibility alias
- multi-factor signal engine using RSI, EMA/trend, momentum, volume and order-book context
- automatic BUY/SELL/HOLD decisions
- server-side risk profiles: `safe`, `balanced`, `aggressive`
- maximum position/exposure checks
- maximum order value and maximum orders-per-hour limits
- stop-loss and take-profit management
- daily loss protection and cooldown controls
- order/position reconciliation against Bitpin
- MySQL persistence for bot settings, signals, positions, runs, orders and audit logs
- advisory lock to prevent overlapping cron trading runs
- server-side Kill Switch
- Persian RTL admin panel at `/admin/`
- trading control dashboard at `/admin/bot/`
- Update Center at `/admin/update/`
- AES-256-GCM encryption for Bitpin credentials and application tokens
- secure one-time Android pairing
- automatic cPanel self-update with SHA-256 verification, backup, maintenance lock, health validation and rollback
- stable GitHub update channel at release tag `trade-latest`
- Android automatic update check with SHA-256 verification

> Automatic trading can lose money. Risk controls reduce operational risk but cannot guarantee profit or prevent every market loss.

## GRAM / TON compatibility

`GRAM` is the primary asset symbol used by the trading engine. `TON` is retained as a legacy/compatibility alias so older configuration or exchange responses do not break the application. Market IDs are resolved dynamically from Bitpin; no GRAM market ID is hard-coded.

## Requirements

### cPanel backend

- PHP 8.2+
- PDO MySQL
- cURL
- OpenSSL
- ZIP extension
- JSON extension
- MySQL/MariaDB
- HTTPS enabled

### Android

- JDK 17
- compileSdk 37
- targetSdk 36
- Jetpack Compose
- permanent signing secrets for production APK releases

## First installation

1. Extract `Trade-cPanel.zip` into the document root used by `rado-taxi.sbs`.
2. Enable HTTPS.
3. Open `https://rado-taxi.sbs/install/`.
4. Enter database/admin details and Bitpin API credentials.
5. Complete installation. New installations use GRAM as the primary trading asset.
6. Add this cPanel Cron job and replace the path with the actual installation path:

```bash
* * * * * /usr/local/bin/php /home/CPANEL_USER/path/to/Trade/cron/tick.php >/dev/null 2>&1
```

Use the PHP CLI path shown by cPanel if it differs.

The main cron performs updater checks and the live trading cycle. Trading occurs only when all production guards allow it, including configured Bitpin credentials, enabled live trading, enabled bot state and a disabled Kill Switch.

## Existing installations

The trading schema is ensured automatically by the production trading bootstrap, so existing installations can receive the update without reinstalling the application. Runtime configuration in `storage/config.php` is preserved by the updater.

After updating, review `/admin/bot/` before enabling live execution and confirm the risk profile and limits are appropriate for the account.

## Safety controls

The production execution path includes:

- Kill Switch
- live-trading configuration gate
- bot enable/disable state
- max order value
- max orders per hour
- max exposure/position sizing
- daily loss limit
- stop loss
- take profit
- cooldown between entries
- duplicate/overlapping cron protection
- local order/audit history

## Admin pages

- `/admin/` — main control center
- `/admin/bot/` — automatic trading controls, state, positions and signals
- `/admin/update/` — update and deployment status

## API

Public:

- `GET /api/health`
- `GET /api/update`
- `POST /api/pair`

Authenticated app API:

- `GET /api/status`
- `GET /api/markets`
- `GET /api/wallets`
- `GET /api/orders`
- `POST /api/orders`
- `DELETE /api/orders/{id}`
- `POST /api/kill-switch`

Bot control/status endpoints are exposed by the production backend where configured by the admin controller.

## Android pairing

The Android app defaults to `https://rado-taxi.sbs`. Bitpin API credentials are never embedded in the APK. Pairing issues an application token and Android stores it using platform-protected storage.

## Permanent Android signing

Production updates must use the same signing key. Never commit a keystore to this public repository.

GitHub Actions expects:

- `ANDROID_KEYSTORE_BASE64`
- `ANDROID_KEYSTORE_PASSWORD`
- `ANDROID_KEY_ALIAS`
- `ANDROID_KEY_PASSWORD`

If all four secrets are configured, the release workflow creates `Trade.apk`. Otherwise the standalone Android workflow can only produce a debug APK and `Trade.apk` is not published to the stable release channel.

## Stable update channel

GitHub Actions publishes/updates the `trade-latest` release.

Release assets:

- `Trade-cPanel.zip`
- `latest.json`
- `Trade.apk` when permanent Android signing is available

Manifest:

```text
https://github.com/hazhanhasani/Trade/releases/download/trade-latest/latest.json
```

The backend verifies the update package checksum before replacing files and can roll back when validation fails.

## CI/CD

- `php-lint.yml` — PHP syntax quality gate
- `cpanel-package.yml` — validates PHP and creates `Trade-cPanel.zip`
- `android-build.yml` — builds a signed release APK when signing secrets exist, otherwise a debug APK
- `publish-latest.yml` — validates PHP, packages the backend, builds the signed Android release when possible, creates `latest.json`, and updates the stable `trade-latest` release

## Repository layout

```text
backend/
  version.php
  bootstrap.php
  database/schema.sql
  public/
    admin/
      bot/
      update/
    install/
  src/
    Exchange/BitpinClient.php
    Trading/
      AutoTraderEngine.php
      BotController.php
      MarketScanner.php
      OrderService.php
      RiskManager.php
      Schema.php
      SignalEngine.php
  cron/
    tick.php
    trading_tick.php
  storage/
android/
  app/
.github/workflows/
  php-lint.yml
  android-build.yml
  cpanel-package.yml
  publish-latest.yml
```
