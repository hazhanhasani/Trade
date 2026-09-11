# Trade

Trade is a production-oriented crypto trading backend for cPanel/shared hosting with a companion Android app. The current production path is focused on **Nobitex spot trading**, live account observability, fee-aware entry filtering, reconciliation, runtime safety, and coordinated backend/Android releases.

> Automated trading can lose money. Trade's controls are designed to reduce operational and execution risk; they do not guarantee profit.

## Current release

**Backend 1.4.8**

The stable channel publishes coordinated cPanel and Android artifacts under the `trade-latest` release.

## What Trade does

- live Nobitex spot execution with encrypted API credentials;
- full IRT/USDT market scanning with internal 1m/5m/15m analysis;
- multi-strategy regime routing;
- fee/spread/liquidity/slippage-aware Net Edge filtering;
- stop loss, take profit, cooldown, daily-loss protection, exposure limits, and position limits;
- adaptive runtime capacity based on recent realized performance;
- pending-order watchdog and exchange-wallet reconciliation;
- global entry circuit for repeated API/runtime failures;
- stale-safe portfolio snapshots to reduce unnecessary exchange requests;
- execution learning, strategy learning, edge calibration, Shadow Evaluation, Strategy Lab, and Trade Replay;
- live Admin Command Center and Android dashboard using the same account-status truth source;
- Bale notifications for trades, risk events, system warnings, and actionable Cron failures;
- automatic cPanel update flow with coordinated version/manifest handling;
- permanently signed Android release pipeline.

## Operator workflow

The main operational pages are:

- `/admin/` — main dashboard;
- `/admin/command-center.php` — live trading command center;
- `/admin/project-health.php` — read-only project/runtime review and BUY readiness;
- `/admin/bot/` — trading state and positions;
- `/admin/bot/settings.php` — the canonical trading-settings editor;
- `/admin/analytics.php` — fee-aware performance analytics;
- `/admin/notifications.php` — notification center;
- `/admin/system.php` — host/backend health;
- `/admin/logs.php` — PHP/runtime errors;
- `/admin/repair.php` — diagnostics and real CLI Cron test;
- `/admin/update/` — update center.

### BUY readiness

A new automated BUY is allowed only after three layers pass:

1. **Runtime readiness** — Bot, API, Live execution, Cron, emergency state, and entry circuit.
2. **Opportunity quality** — valid strategy/regime, executable liquidity/spread, and positive tradable Net Edge after estimated costs and uncertainty buffers.
3. **Portfolio risk** — quote balance, pending capacity, effective position capacity, exposure, cooldown, strategy learning, order minimums, and global risk checks.

`/admin/project-health.php` separates these states so an operator can distinguish:

- `READY` — runtime and latest BUY opportunity are both ready;
- `WAITING_MARKET` — infrastructure is healthy but no acceptable current entry exists;
- `BLOCKED` — a runtime/risk control is actively preventing new BUYs.

This page is read-only and never submits orders or changes trading settings.

## Settings source of truth

Trading settings are edited only from `/admin/bot/settings.php`. Other trading pages may display the active settings but do not own a second independent configuration state.

Core settings include:

- quote market;
- risk profile;
- target position percentage;
- maximum per-position percentage;
- stop loss / take profit;
- daily loss limit;
- cooldown;
- maximum positions;
- scan limit;
- portfolio exposure limit;
- maximum pending orders;
- pending timeout.

## Runtime safety

Trade uses reduction-first/fail-safe behavior for runtime problems:

- SELL/reconciliation paths remain available when fresh BUY risk is blocked;
- repeated exchange API failures can open a temporary entry circuit;
- pending orders are reconciled/cancelled instead of silently occupying capacity forever;
- wallet drift can reduce/close tracked positions without inventing PnL;
- over-capacity portfolios are reduced sequentially;
- scaled public market aliases that the authenticated Nobitex order API cannot execute are excluded from automated order submission;
- portfolio display snapshots are cached/locked to limit avoidable 429 pressure, while live order-risk checks remain fresh.

## Performance and learning

Performance should be evaluated from multiple metrics together:

- realized Net PnL;
- account equity and drawdown;
- win rate;
- profit factor;
- average realized return;
- expected edge vs realized return;
- strategy-level sample counts;
- execution rejects and failure rate;
- API/Cron health.

Low trade count alone is not treated as a defect: a healthy bot may intentionally remain in `no_trade` when no candidate survives fees, spread, liquidity, execution and risk filters.

## Project operating system

The repository includes an explicit operating framework for review, prioritization, research, debugging, product decisions, and release discipline:

`docs/PROJECT_OPERATING_SYSTEM.md`

It maps the `/human`, `/expert`, `/ceo`, `/seo`, `/critic`, `/plan`, `/habit`, `/focus`, `/track`, `/review`, `/planner`, `/prioritize`, `/concise`, `/customer`, `/audience`, `/competitor`, `/research`, `/evaluate`, `/innovate`, and `/debug` modes into concrete project rules.

## Private-surface indexing policy

Admin, API, and installer routes are operational/private surfaces. Web requests to these prefixes emit:

`X-Robots-Tag: noindex, nofollow, noarchive`

Trade also emits baseline `X-Content-Type-Options: nosniff` and `Referrer-Policy: same-origin` headers for normal web requests.

## Nobitex API setup

Create a Nobitex API key for the bot with the minimum permissions required for account reading and spot trading. Withdrawal permission is not required for Trade and should remain disabled.

Trade keeps exchange secrets on the backend; the Android app does not contain Nobitex private API credentials.

PHP Sodium is required for Nobitex Ed25519 signing.

## Cron

Recommended cPanel schedule: **Every Minute**.

Example production command:

```text
'/opt/cpanel/ea-php83/root/usr/bin/php' '/home/USER/public_html/cron/tick.php' >> '/home/USER/public_html/storage/cron.log' 2>&1
```

Use the exact PHP path and account path shown by the Repair/Cron page for the deployed host.

The main tick performs updater checks, reconciliation/safety work, automatic trading, notifications, and run logging. One exchange/runtime component failing should be recorded with a concrete reason instead of being hidden behind a generic Cron message.

## Auto update

The backend updater checks the stable GitHub channel periodically, validates the coordinated manifest/checksums, backs up the current backend, installs the package, validates health, and can roll back on failed validation.

After a backend update is installed during a tick, trading waits until the next tick so execution does not continue inside a process that just replaced its own code.

Stable release:

```text
https://github.com/hazhanhasani/Trade/releases/tag/trade-latest
```

Release assets:

- `Trade-cPanel.zip`
- `latest.json`
- `Trade.apk`

## Quality gate

The PHP Quality Gate covers syntax plus regression tests for:

- strategy/regime routing;
- signal sanity;
- strategy/execution learning;
- edge calibration;
- fill semantics and accounting;
- position reconciliation and capacity;
- runtime safety;
- external trade reconciliation;
- executable-symbol policy;
- portfolio snapshot/rate-limit behavior;
- currency/Toman display;
- Iran clock;
- Admin mobile layout and observability;
- canonical trading settings;
- Command Center behavior;
- Project Operating System / Project Health invariants;
- Android live-panel parity;
- Backend ↔ Android API contract parity.

## Requirements

### cPanel backend

- PHP 8.2+ (8.3 recommended)
- PDO MySQL / MariaDB
- cURL
- OpenSSL
- JSON
- ZIP
- Sodium
- HTTPS

### Android

- JDK 17
- Jetpack Compose
- permanent production signing key

## Release discipline

A release is considered complete only after:

1. PHP syntax and regression tests pass;
2. cPanel package builds;
3. Android release builds with permanent signing;
4. APK signature is verified;
5. coordinated manifest is generated;
6. stable assets publish successfully;
7. the live cPanel host subsequently reports the new backend version.
