# Multi-Exchange Market Data Architecture

## Execution policy

Trade has exactly one live execution venue: **Nobitex**.

| Provider | Market data | Order execution |
| --- | --- | --- |
| Nobitex | Yes | **Yes — sole execution venue** |
| AbanTether | Yes | No |
| Bit24 | Yes | No |
| Tabdeal | Yes | No |
| Bitpin | Yes | **No — permanently blocked** |

Bitpin's legacy trading classes remain only as compatibility shims. `OrderService` rejects create/cancel operations, `AutoTraderEngine` is a deterministic `market_data_only` no-op, `BotController` rejects every non-Nobitex execution request, and the cron schedules only `NobitexAutoTraderEngine`.

## Unit normalization

Nobitex stores logical `IRT` market prices internally in **RLS (Rial)**. External Iranian sources are normalized to **Toman** before they enter the multi-exchange consensus. `NobitexExternalMarketOracle` therefore converts the Nobitex candidate from RLS to Toman before comparing it with the external reference.

This boundary is intentional and protects the strategy from false 10x arbitrage signals caused by Rial/Toman mismatches.

## Consensus

`MarketDataHub` requires at least two usable sources. The reference is built with a two-stage median filter:

1. A broad unit/outlier guard rejects quotes more than 12% away from the initial median.
2. A final market-outlier guard rejects quotes more than 3.5% away from the remaining median.

A single provider can never influence live execution by itself.

## Harden-only rule

External market data is advisory and may only make a Nobitex BUY safer. It can:

- consume part of the expected execution edge;
- downgrade a market order to the already bounded limit plan;
- veto an extreme Nobitex premium when consensus quality is sufficient.

It cannot:

- create a BUY signal;
- increase expected edge or profit;
- relax a hard price/risk bound;
- submit, cancel or modify an order on AbanTether, Bit24, Tabdeal or Bitpin.

If external sources are unavailable, stale, malformed or insufficient, the external guard is neutral and the existing Nobitex risk/execution rules remain authoritative.

## Credentials

Market-data credentials are stored encrypted in `exchange_credentials` using the existing application encryption key. They are entered through **Admin → Exchanges** and are never rendered back into the UI.

- AbanTether: API key used for its authenticated coin-price endpoint.
- Bit24: API key sent through `X-BIT24-APIKEY` for market-data requests.
- Tabdeal: public order-book data works without a key; an optional encrypted key/secret slot is retained for future authenticated data features.
- Bitpin: public order-book only; no trading credential is used by the new architecture.

Nobitex credentials remain separate because they are the only credentials permitted to reach an execution service.

## Operational test

From **Admin → Exchanges**, use **Test USDT/IRT and Consensus** after entering credentials. The result exposes source health, normalized mids and consensus state, but never exposes API keys.

Authenticated provider calls must be verified from the deployed server because CI intentionally disables external-network influence on trading tests.

## Regression guarantees

`backend/tests/multi_exchange_market_data_test.php` covers:

- 10x unit-outlier rejection;
- minimum two-source consensus;
- no positive edge boost from external data;
- external premium penalty and extreme-premium veto;
- AbanTether per-asset response isolation;
- permanent Bitpin order create/cancel blocking;
- Nobitex-only Admin/Controller execution ownership;
- Nobitex-only cron scheduling.

The dedicated GitHub Actions workflow also runs PHP syntax validation and the existing adaptive-execution regression test.
