# Multi-Exchange Market Data Architecture

## Roles

Trade has exactly one execution venue: **Nobitex**.

| Provider | Market data | Execution credentials / orders |
| --- | --- | --- |
| Nobitex | Yes | **Yes — sole execution venue** |
| AbanTether | Yes | No |
| Bit24 | Yes | No |
| Tabdeal | Yes | No |
| Bitpin | Yes | No |

Bitpin is implemented only as a public read-only market-data adapter inside `MarketDataHub`, at the same architectural layer as AbanTether, Bit24 and Tabdeal. It has no exchange client, wallet API, order service, bot state, live-execution flag, execution route, private credential record, position table or trading history in Trade.

Upgrades from older Trade builds perform a one-time destructive cleanup of the retired secondary execution stack. Risk settings are migrated to `nobitex_autotrade_settings`, while obsolete execution tables, credentials, orders/history, settings and stale PHP source files are removed.

## Unit normalization and consensus

Nobitex stores logical `IRT` prices internally in RLS (Rial). External Iranian market sources are normalized to Toman before consensus. `MarketDataHub` requires at least two usable sources and applies both a broad unit/outlier guard and a final market-outlier guard.

External market data is advisory and can only harden a Nobitex BUY: consume expected execution edge, downgrade an execution plan to bounded Limit, or veto an extreme premium. It cannot create a BUY, increase edge, relax risk bounds, or submit/cancel an order.

## Credentials

- AbanTether: encrypted API key.
- Bit24: encrypted API key + secret/private key.
- Tabdeal: public order book; optional encrypted key/secret slots for future authenticated data.
- Bitpin: public order book only; no stored private credentials.
- Nobitex: separate encrypted execution credentials with READ + TRADE only; withdrawal permission is unnecessary.

## Operational test

Use **Admin → Exchanges → Test USDT/IRT and Consensus**. The result exposes source health, normalized mids and consensus state without rendering stored keys.

## Regression guarantees

CI verifies that:

- the old Bitpin exchange/trading classes no longer exist;
- installer/API/Android execution paths are Nobitex-only;
- old Bitpin database tables/data/settings are explicitly purged on upgrade;
- Bitpin remains present as a public Market Data source;
- 10x Rial/Toman anomalies are rejected;
- a single external provider cannot influence execution;
- external data never creates a positive edge boost.
