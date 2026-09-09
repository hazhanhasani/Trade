# Trade

Trade is a production-oriented Bitpin trading backend for cPanel/shared hosting with a companion Android app for `https://rado-taxi.sbs`.

## Current release — backend v1.0.2 / Android v1.0.1

### Production trading

Trade includes a live automatic trading engine. The production path does not use paper trading.

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
- Repair Center at `/admin/repair.php`
- AES-256-GCM encryption for Bitpin credentials and application tokens
- secure one-time Android pairing
- automatic cPanel self-update with SHA-256 verification, backup, maintenance lock, health validation and rollback
- stable GitHub update channel at release tag `trade-latest`
- Android automatic update check with SHA-256 verification
- permanent Android release signing guard: installable APKs are never produced with an ephemeral CI debug certificate

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
- one permanent signing key for every production APK release

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

The trading schema is ensured automatically by the production trading bootstrap, so existing installations can receive backend updates without reinstalling the application. Runtime configuration in `storage/config.php` is preserved by the updater.

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
- `/admin/repair.php` — Bitpin authentication and Cron diagnostics

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

Android updates are accepted only when the new APK uses the same application ID and signing certificate as the installed application and has a compatible version code. Production builds therefore use one permanent signing key for the lifetime of the app.

Never commit a keystore or its password to this public repository.

GitHub Actions expects these repository secrets:

- `ANDROID_KEYSTORE_BASE64`
- `ANDROID_KEYSTORE_PASSWORD`
- `ANDROID_KEY_ALIAS`
- `ANDROID_KEY_PASSWORD`

When all four secrets are configured, the workflows build and verify a permanently signed release APK. The signer certificate SHA-256 fingerprint is written into `latest.json`.

When permanent signing is missing:

- normal Android push/manual builds fail instead of producing an installable APK with a temporary debug certificate
- pull requests may compile a debug build only for CI validation; that build is not uploaded as an installable release artifact
- `Publish Latest` still publishes backend updates but does not publish a new Android APK
- an already-published signed `Trade.apk` is preserved instead of being deleted

If a previously installed build was signed by a temporary CI/debug key and that private key no longer exists, Android cannot migrate it to a new signing certificate. In that specific case one final uninstall/reinstall is required. After installing the first APK signed with the permanent key, later updates can install normally without deleting the app.

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
- `android-build.yml` — requires permanent signing for installable APK artifacts and verifies the final APK certificate
- `publish-latest.yml` — validates PHP, packages the backend, builds/verifies the permanently signed Android release when signing is configured, creates `latest.json`, and updates the stable release

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
      repair.php
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
