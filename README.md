# Trade

Trade is a cPanel-friendly Bitpin trading backend with a companion Android app.

## Current milestone — v0.3 Live

Implemented:

- cPanel web installer at `/install/`
- primary backend URL: `https://rado-taxi.sbs`
- Persian RTL admin panel at `/admin/`
- MySQL/MariaDB schema
- AES-256-GCM encryption for Bitpin API credentials and access/refresh tokens
- Bitpin authentication and token refresh
- markets, wallets, and exchange order retrieval
- guarded live order creation through `POST /api/orders`
- live order cancellation through `DELETE /api/orders/{id}`
- server-side Kill Switch
- configurable maximum order value and maximum orders per hour
- append-only application audit events for live order submission/failure/cancellation
- cPanel Cron heartbeat/run history
- Android Jetpack Compose client using `https://rado-taxi.sbs` by default
- Android Live order form and Kill Switch control
- GitHub Actions PHP syntax gate and Android debug APK build

## Requirements

### cPanel backend

- PHP 8.2+
- PDO MySQL
- cURL
- OpenSSL
- MySQL/MariaDB
- HTTPS enabled for `rado-taxi.sbs`

### Android build

The project uses Android Gradle Plugin 9.4.0, Gradle 9.6.0, JDK 17, compileSdk 37, targetSdk 36, and Compose BOM 2026.08.00. `android.newDsl=false` is currently used for Kotlin plugin compatibility with AGP 9.x.

## Quick cPanel installation for rado-taxi.sbs

1. Upload the `backend` directory to the hosting account.
2. Set the document root for `rado-taxi.sbs` to `backend/public`.
3. Enable a valid HTTPS certificate for `rado-taxi.sbs`.
4. Open `https://rado-taxi.sbs/install/`.
5. Enter database/admin details plus Bitpin API Key and Secret.
6. Leave **Live Trading** enabled if this installation should submit real Bitpin orders.
7. Configure maximum order value and maximum orders per hour.
8. Save the generated **App API Token**; it is shown only once.
9. Open `https://rado-taxi.sbs/admin/`.
10. Add the cPanel Cron job:

```bash
* * * * * /usr/local/bin/php /home/CPANEL_USER/path/to/Trade/backend/cron/tick.php >/dev/null 2>&1
```

Use the PHP binary path shown by cPanel if it differs.

## Android pairing

The Android app defaults to:

```text
https://rado-taxi.sbs
```

Only the generated App API Token is entered in Android. The Bitpin API Key and Secret stay on the server and are never embedded in the APK.

## Bitpin adapter defaults

- Base URL: `https://api.bitpin.market/api/v1`
- Authenticate: `/usr/authenticate/`
- Refresh: `/usr/refresh_token/`
- Markets: `/mkt/markets/`
- Wallets: `/wlt/wallets/`
- Orders: `/odr/orders/`

The adapter is isolated on the server and its endpoints are configurable because Bitpin documentation currently warns that some APIs are being deprecated.

## API

- `GET /api/health`
- `GET /api/status`
- `GET /api/markets`
- `GET /api/wallets`
- `GET /api/orders`
- `POST /api/orders` — submit a live Bitpin order when live trading is enabled
- `DELETE /api/orders/{id}` — cancel an exchange order
- `POST /api/kill-switch` — stop/resume new live submissions

Private endpoints require:

```text
Authorization: Bearer <app-api-token>
```

Example live order body:

```json
{
  "market": 5,
  "amount1": 3,
  "price": 50000,
  "mode": "limit",
  "type": "buy"
}
```

`market` is the numeric Bitpin market ID returned by the market API.

## Security boundaries

- Never commit or embed the real Bitpin API Key/Secret in GitHub or Android.
- Credentials and refresh/access tokens are encrypted at rest.
- Android receives only the Trade application API token.
- Cleartext HTTP is disabled; use HTTPS only.
- Use the minimum exchange API permissions needed for trading and leave withdrawal permission disabled when Bitpin permission scoping allows it.
- Kill Switch is checked before new live orders are sent.
- Live order limits are enforced server-side, not only in Android.

## Automated trading

The live execution layer is now available. Automated trading logic should call the same guarded `OrderService` rather than communicating with Bitpin directly. Strategy rules are intentionally separated from exchange execution so indicators and strategies can be changed without weakening API-key security or the risk controls.

## Repository layout

```text
backend/
  public/                 API, installer, admin panel
  src/Exchange/           Bitpin adapter
  src/Trading/            guarded live order service
  database/schema.sql     MySQL schema
  cron/tick.php           cPanel scheduled entrypoint
  storage/                runtime configuration (not committed)
android/                   Android Jetpack Compose app
.github/workflows/         PHP lint + Android build
```
