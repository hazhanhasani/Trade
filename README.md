# Trade

Trade is a production-oriented crypto trading backend for cPanel/shared hosting with a companion Android app. **Nobitex is the sole execution exchange.** Bitpin, AbanTether, Bit24 and Tabdeal are read-only market-data inputs used for consensus and execution hardening.

> Automated trading can lose money. Trade's controls reduce operational/execution risk; they do not guarantee profit.

## Current release

**Backend 1.4.32**

The stable channel publishes coordinated cPanel and Android artifacts under `trade-latest`.

## Core architecture

- Nobitex spot execution with encrypted READ + TRADE credentials; withdrawal permission is not required.
- Full Nobitex IRT/USDT scanning with internal 1m/5m/15m analysis.
- Read-only multi-source market intelligence from Bitpin, AbanTether, Bit24 and Tabdeal.
- Median/outlier filtering and Rial/Toman normalization before external data can influence execution.
- External data is harden-only: it can consume edge, constrain execution or veto an extreme premium; it cannot create BUYs or place orders.
- Fee/spread/liquidity/slippage-aware Net Edge filtering, stop/take/trailing, cooldown, daily-loss, exposure and position limits.
- Wallet/order reconciliation, pending watchdog, adaptive capacity, strategy/execution learning and edge calibration.
- Admin Command Center + Android live dashboard sharing the same Nobitex account truth source.
- Bale notifications, cPanel auto-update, coordinated manifest and permanently signed Android releases.

## Exchange / Market Data setup

Use `/admin/exchanges.php`:

- **Nobitex** — execution credentials and Bot/Live controls.
- **AbanTether** — encrypted API key, Market Data only.
- **Bit24** — encrypted API key + Secret Key, Market Data only.
- **Tabdeal** — public Order Book; optional private keys can be stored for future data features.
- **Bitpin** — public Order Book only; no private credential, wallet, bot or order surface exists.

Upgrades to 1.4.32 remove the retired Bitpin execution implementation and its old database/config state while preserving the public Market Data adapter.

## Operator pages

- `/admin/` — dashboard
- `/admin/command-center.php` — live trading command center
- `/admin/project-health.php` — runtime/BUY readiness
- `/admin/bot/` — Nobitex bot/positions
- `/admin/bot/settings.php` — canonical risk/trading settings
- `/admin/analytics.php` — performance analytics
- `/admin/exchanges.php` — execution + market-data connections
- `/admin/notifications.php` — notification center
- `/admin/system.php` — host/backend health
- `/admin/logs.php` — runtime errors
- `/admin/repair.php` — diagnostics/Cron
- `/admin/update/` — updater

## BUY readiness

A new automated BUY must pass runtime readiness, opportunity quality and portfolio risk. A healthy bot may intentionally remain `no_trade` when no candidate survives fees, spread, liquidity, execution and risk filters.

## Cron

Recommended cPanel schedule: **Every Minute**.

```text
'/opt/cpanel/ea-php83/root/usr/bin/php' '/home/USER/public_html/cron/tick.php' >> '/home/USER/public_html/storage/cron.log' 2>&1
```

Use the exact paths reported by the Repair/Cron page for the deployed host.

## Auto update

The backend checks the stable GitHub manifest, validates checksums, creates a backup, installs the cPanel package and validates runtime state. When an update is installed during a tick, trading resumes on the following tick rather than continuing inside the process that replaced its own code.

Stable release assets: `Trade-cPanel.zip`, `latest.json`, `Trade.apk`.

## Quality gates

A release must pass PHP syntax/regression, destructive legacy-cleanup invariants, cPanel packaging, permanent Android signing/signature verification and coordinated manifest publication.

## Requirements

Backend: PHP 8.2+ (8.3 recommended), PDO MySQL/MariaDB, cURL, OpenSSL, JSON, ZIP, Sodium, HTTPS.

Android: JDK 17, Jetpack Compose, permanent production signing key.
