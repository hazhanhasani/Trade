# Trade Project Operating System

This document turns the requested working modes into one production workflow for Trade. It is deliberately operational: every mode has an expected output, a measurable signal, and a release gate.

## North Star

Trade is not optimized for the number of orders. It is optimized for reliable, explainable, fee-aware execution with controlled downside and verifiable live state.

Primary product promise:

> The operator can understand in one screen whether Trade is able to buy, why it did or did not buy, what is blocking it, and whether the reported account state matches the exchange.

## Working modes applied

| Mode | Production behavior |
| --- | --- |
| `/human` | Every raw reason code must have a concise Persian explanation. Avoid forcing the operator to read JSON for normal diagnosis. |
| `/expert` | Runtime changes require a named failure mode, a deterministic guard, and a regression test. |
| `/ceo` | Priority order is P0 capital/runtime safety → P1 execution reliability/profitability → P2 UX/observability → P3 convenience. |
| `/seo` | Admin, API, and installer are private operational surfaces and must send `X-Robots-Tag: noindex, nofollow, noarchive`. Public documentation should remain current and descriptive. |
| `/critic` | Assume cache, state, API payloads, pending orders, counters, and settings can become stale or contradictory. Review the failure path before the happy path. |
| `/plan` | Ship small coordinated versions. Backend, cPanel package, manifest, and signed Android artifact must remain version-aligned. |
| `/habit` | Every release follows the same checklist: syntax → regression → package → signature → manifest → stable release → live host verification. |
| `/focus` | One source of truth for settings and one live status source. Do not add parallel configuration paths. |
| `/track` | Track Cron health, API failures, Pending age, circuit state, wallet equity, realized PnL, drawdown, win rate, profit factor, and execution rejects. |
| `/review` | Review recent failures and rejected entries before changing thresholds. A lack of trades is not itself proof of a bug. |
| `/planner` | Separate Runtime, Strategy, Execution, UX, and Release work so one class of change can be rolled back without destabilizing the others. |
| `/prioritize` | Fix deadlocks, state mismatches, rate limits, currency-unit errors, and settings drift before adding new strategies. |
| `/concise` | Dashboards lead with a verdict, then evidence. Detailed JSON remains available only for deeper debugging. |
| `/customer` | The product should answer: “Is the bot live?”, “Why did it not buy?”, “What should I inspect next?”, and “Is this account data live?” |
| `/audience` | Primary audience is an operator who understands trading goals but should not need PHP, SQL, or exchange API internals for routine operation. |
| `/competitor` | Use competitor patterns as product references, not as trading guarantees. Favor explicit entry conditions, backtest/shadow evaluation, and readable risk controls. |
| `/research` | Prefer official exchange and product documentation. Record assumptions separately from verified exchange behavior. |
| `/evaluate` | Evaluate changes by Safety, Expected Benefit, API/Rate-limit Cost, Latency, UX clarity, and Rollback difficulty. |
| `/innovate` | New strategy ideas enter Shadow/Replay first. Live adoption requires observed evidence and a rollback path. |
| `/debug` | Errors should carry component, exchange, reason code, request/run context, and file/line when PHP supplies them. |

## Project Health page

`/admin/project-health.php` is the executive/diagnostic read-only view. It combines:

- live Bot/API/Execution/Cron/Circuit checks;
- effective position capacity;
- latest entry decision and tradable edge;
- account drawdown and realized performance;
- P0/P1/P2 priority generation;
- a clear BUY state: `READY`, `WAITING_MARKET`, or `BLOCKED`.

The page never places orders and never changes settings.

## Entry decision policy

A new BUY must pass three layers:

1. **Runtime readiness** — bot enabled, credentials ready, live execution enabled, Cron healthy, emergency mode normal, entry circuit closed.
2. **Opportunity quality** — executable liquidity/spread, valid strategy/regime, positive tradable Net Edge after estimated fees/slippage/buffer.
3. **Portfolio risk** — available quote balance, Pending capacity, effective position capacity, exposure capacity, cooldown, learning constraints, order minimums, and global risk checks.

A rejected BUY must remain a normal `no_trade` outcome unless an actual infrastructure/API failure occurred.

## Performance KPIs

Do not use any single metric as a profit guarantee. Review these together:

- account equity and account drawdown;
- realized bot PnL by Iran day/week/month;
- win rate and profit factor;
- average realized return;
- expected edge vs realized return calibration;
- strategy-level sample count and performance;
- execution rejects and failed-order rate;
- API 429/5xx frequency;
- Pending age and cancellation/reconciliation count;
- Cron success/partial/failed ratio;
- rate of `no_candidate_passed_signal_and_risk_filters` vs actual BUY submissions.

## Review cadence

### Every Cron / continuous

- Reconcile pending orders and exchange wallet state.
- Refresh adaptive capacity and safety circuit.
- Record a machine-readable reason for every no-trade decision.
- Avoid duplicate or stale notifications.

### Daily operator review

- Open Project Health.
- Resolve P0 first.
- Check account drawdown, daily realized PnL, API failures, and stale Pending orders.
- Review the dominant entry rejection reason before modifying settings.

### Weekly strategy review

- Compare expected vs realized edge.
- Review strategy samples; do not overreact to tiny sample sizes.
- Inspect Shadow/Replay outcomes before promoting a strategy change.
- Review exchange API changes and newly observed invalid symbols.

### Release review

A release is not considered complete until:

1. PHP syntax and regression suite pass.
2. cPanel package builds.
3. Android release builds with permanent signing.
4. APK signature verification passes.
5. coordinated update manifest is generated.
6. stable release assets publish atomically.
7. live cPanel reports the new backend version on a later tick.

## Competitive research notes

Current public product documentation from established trading-bot products reinforces several product patterns relevant to Trade:

- 3Commas exposes explicit trade-start and trade-close conditions and supports multiple conditions for advanced bots. This supports Trade's direction of making entry blockers visible instead of hiding them behind one score.
- 3Commas emphasizes backtesting before real trading. Trade's closest production-safe equivalents are Shadow Evaluation, Strategy Lab, and Trade Replay; new ideas should use those paths before live execution.
- Pionex documents bot-specific risk and parameter tradeoffs instead of presenting automation as guaranteed profit. Trade should keep risk explanations next to presets and performance data.

Reference material reviewed in September 2026:

- https://help.3commas.io/en/articles/3318096-dca-bot-seize-opportunities-with-trade-start-and-trade-close-conditions
- https://help.3commas.io/en/articles/16281102-how-the-dca-bot-works-strategy-setup-guide
- https://help.3commas.io/en/articles/3109033-dca-bot-introduction-and-general-information
- https://support.pionex.com/hc/en-us/articles/45085712163225-Grid-Trading-Bot

## Change evaluation scorecard

Before a material live-trading change, score each item 0–2:

| Dimension | 0 | 1 | 2 |
| --- | --- | --- | --- |
| Safety | increases unbounded risk | neutral/guarded | reduces a known risk |
| Evidence | no evidence | shadow/small sample | reproducible live/replay evidence |
| Explainability | opaque | partially observable | explicit reason/telemetry |
| Rate-limit cost | high/new polling | moderate | cached/batched/no extra API calls |
| Rollback | hard/data migration | reversible with effort | immediate/config/version rollback |
| Testability | not deterministic | source/integration check | deterministic regression test |

Changes scoring below 8/12 should not be promoted directly to Live without additional evidence.

## Current focus order

1. Keep live cPanel synchronized with stable release.
2. Eliminate Runtime deadlocks and stale pending states.
3. Make every no-BUY reason operator-readable.
4. Improve strategy evidence and edge calibration instead of forcing entries.
5. Reduce unnecessary exchange requests and 429 exposure.
6. Keep Android and Admin on the same live truth source.
7. Only then add new strategy families or more aggressive presets.
