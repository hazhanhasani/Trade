# Trade architecture

## Runtime topology

```text
Android app / Admin
        |
        | HTTPS + app/admin auth
        v
cPanel / PHP API
        |
        +--> Nobitex strategy + risk + execution --> Nobitex API
        |
        +--> MarketDataHub
        |      +--> Bitpin public Order Book
        |      +--> AbanTether Market Data API
        |      +--> Bit24 Market Data API
        |      +--> Tabdeal public Order Book
        |
        +--> MySQL: Nobitex positions/PnL/orders + platform state
        |
        +--> Cron tick: reconciliation, analysis, execution, notifications
```

## Boundary rules

1. **Nobitex is the only execution exchange.** Only Nobitex credentials may reach an order service.
2. Bitpin, AbanTether, Bit24 and Tabdeal belong to `MarketDataHub` and cannot submit/cancel orders or access trading wallets.
3. Market-data secrets, where required, are encrypted with the application encryption key and never rendered back to Admin/Android.
4. Bitpin uses only its public order-book endpoint and stores no private credential in Trade.
5. Every Nobitex order passes through the runtime/risk/order-rule guards before the authenticated client.
6. Kill switch and execution flags are database-backed and Nobitex-owned.
7. Local Nobitex order records are written before remote submission for reconciliation of uncertain outcomes.
8. External price consensus is harden-only: it may reduce/veto a BUY edge but cannot create a signal or relax a risk limit.

## Upgrade cleanup

Version 1.4.32 retires the former secondary execution stack destructively. Existing installations migrate reusable risk settings into `nobitex_autotrade_settings`, then remove the old execution tables, private credentials, order/history rows, settings/config subtree and stale PHP classes. The public Bitpin Market Data adapter remains.

## cPanel execution model

Cron runs once per minute and all work is short-lived/idempotent; no resident worker is required. The updater overlays the signed/checksummed package, and the first runtime schema ensure performs the retirement cleanup on upgraded installations.
