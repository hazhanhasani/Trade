# Trade

Trade is a cPanel-friendly Bitpin trading platform backend plus Android client project.

> Status: foundation / phase 1. The repository now contains the cPanel installer, MySQL schema, encrypted credential storage, Bitpin API client, risk controls, and API bootstrap. Android UI is the next layer on top of the HTTP API.

## Design goals

- Shared-hosting/cPanel compatible: PHP 8.2+, MySQL/MariaDB, cURL, OpenSSL.
- No daemon required: scheduled work runs from cPanel Cron.
- Bitpin API is isolated behind an adapter so endpoint changes do not require rewriting the application.
- API key/secret are never stored in the Android APK or committed to Git.
- Credentials are encrypted at rest with AES-256-GCM.
- Live execution has a server-side kill switch and configurable order/risk limits.
- Audit logs are append-only at application level.

## Quick cPanel installation

1. Upload the `backend` directory outside or inside `public_html`.
2. Point the domain/subdomain document root to `backend/public` if your cPanel supports custom document roots.
3. Open `https://YOUR-DOMAIN/install/`.
4. Enter MySQL details, admin credentials, and optionally Bitpin API credentials.
5. After installation, add this cPanel Cron command once per minute:

```bash
* * * * * /usr/local/bin/php /home/CPANEL_USER/path/to/Trade/backend/cron/tick.php >/dev/null 2>&1
```

If your host uses another PHP path, use cPanel's displayed PHP binary.

## Default Bitpin adapter configuration

The adapter defaults to:

- Base URL: `https://api.bitpin.market/api/v1`
- Authenticate: `/usr/authenticate/`
- Refresh: `/usr/refresh_token/`
- Markets: `/mkt/markets/`
- Wallets: `/wlt/wallets/`
- Orders: `/odr/orders/`

Every endpoint is stored in server configuration and can be changed without touching Android code.

## Security notes

- Do not put a real API key or secret in this repository, GitHub Actions, screenshots, or the Android app.
- Use a Bitpin API key with only the permissions the bot actually needs.
- Keep withdrawals disabled for the trading key when the exchange supports permission scoping.
- Use the kill switch before maintenance or credential changes.

## Repository layout

```text
backend/
  public/                 Web/API entrypoint + installer
  src/                    PHP application classes
  database/schema.sql     MySQL schema
  cron/tick.php           cPanel scheduled entrypoint
  storage/                Runtime config/logs (not committed)
android/                   Android application workspace
docs/                      Architecture and implementation notes
```

## Current API endpoints

- `GET /api/health`
- `GET /api/markets`
- `GET /api/wallets`
- `GET /api/orders`
- `POST /api/orders`
- `DELETE /api/orders/{id}`
- `POST /api/kill-switch`

Private endpoints require `Authorization: Bearer <app-api-token>` after installation.
