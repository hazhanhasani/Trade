# Trade

Trade is a cPanel-friendly Bitpin backend with a companion Android app for `https://rado-taxi.sbs`.

## Current milestone — v0.6

Implemented:

- cPanel web installer at `/install/`
- primary backend URL: `https://rado-taxi.sbs`
- Persian RTL management panel at `/admin/`
- smart Update Center at `/admin/update/`
- MySQL/MariaDB schema
- AES-256-GCM encryption for Bitpin credentials and tokens
- secure one-time Android pairing from the admin panel
- markets, wallets, and order retrieval
- guarded order execution layer and server-side Kill Switch
- configurable server-side limits
- cPanel Cron heartbeat/run history
- automatic cPanel self-update with checksum verification, backup, maintenance lock, health validation, and rollback
- stable GitHub update channel at release tag `trade-latest`
- Android automatic update check and download with SHA-256 verification
- Android system package-installer handoff for the final OS-controlled installation step
- capital asset restricted to `TON` or `GRAM`, with `TON` as the installation default

## Requirements

### cPanel backend

- PHP 8.2+
- PDO MySQL
- cURL
- OpenSSL
- ZIP extension
- MySQL/MariaDB
- HTTPS enabled for `rado-taxi.sbs`

### Android build

The Android project uses AGP 9 built-in Kotlin support, JDK 17, compileSdk 37, targetSdk 36, and Jetpack Compose.

## First installation

1. Extract `Trade-cPanel.zip` into the document root used by `rado-taxi.sbs`.
2. Enable HTTPS.
3. Open `https://rado-taxi.sbs/install/`.
4. Enter database/admin details and Bitpin credentials.
5. Select the initial capital asset: **TON** or **GRAM**. TON is the default.
6. Add the cPanel Cron job:

```bash
* * * * * /usr/local/bin/php /home/CPANEL_USER/path/to/Trade/cron/tick.php >/dev/null 2>&1
```

Use the PHP binary path shown by cPanel if it differs.

After this bootstrap installation, backend updates are automatic. The cron process checks the stable release channel every hour by default. It preserves `storage/config.php`, verifies the package SHA-256, creates a backup, enables a maintenance lock during file replacement, checks the installation, and rolls back on failure.

## Update channel

GitHub Actions publishes a stable release at:

```text
trade-latest
```

Assets:

- `Trade-cPanel.zip`
- `latest.json`
- `Trade.apk` when permanent Android signing secrets are configured

The manifest URL used by both products is:

```text
https://github.com/hazhanhasani/Trade/releases/download/trade-latest/latest.json
```

Backend update state is available in `/api/health`, `/api/status`, and `/admin/update/`.

## Android updates

The app checks `https://rado-taxi.sbs/api/update` on launch. When a newer signed APK is available it:

1. downloads the APK automatically;
2. verifies the SHA-256 from the release manifest;
3. stores it inside the app-specific update directory;
4. opens Android's package installer.

Android itself controls the final install confirmation. The app cannot silently bypass the OS package-installer confirmation on a normal non-managed phone.

Every production update must use the same permanent signing key. The keystore must never be committed to this public repository. GitHub Actions expects these repository secrets:

- `ANDROID_KEYSTORE_BASE64`
- `ANDROID_KEYSTORE_PASSWORD`
- `ANDROID_KEY_ALIAS`
- `ANDROID_KEY_PASSWORD`

## Android pairing

The Android app defaults to `https://rado-taxi.sbs`. The Bitpin key and secret never go into the APK. From the admin panel, create a short-lived pairing link; the app exchanges it for its own application token and stores that token with Android Keystore encryption.

## Capital asset

Trade supports these base capital assets:

- `TON`
- `GRAM`

The installer defaults to TON. Market IDs are obtained dynamically from Bitpin; no GRAM market ID is hard-coded.

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

## Repository layout

```text
backend/
  version.php             backend version source
  public/                 API, installer, admin panel
  public/admin/update/    automatic update status center
  src/Updater.php         cPanel updater, backup and rollback
  src/Exchange/           Bitpin adapter
  src/Trading/            guarded order service
  cron/tick.php           scheduler + automatic updater
  storage/                runtime configuration and backups (not committed)
android/
  app/src/main/.../update automatic APK updater
.github/workflows/
  android-build.yml
  cpanel-package.yml
  publish-latest.yml      stable GitHub release publisher
```
