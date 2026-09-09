# Permanent Android release signing

Production package: `ir.trade.app`

The release workflow is configured to sign every production APK with the same permanent key. The private keystore must **not** be committed to this public repository.

## Required GitHub Actions secrets

Add these repository secrets once:

- `ANDROID_KEYSTORE_BASE64`
- `ANDROID_KEYSTORE_PASSWORD`
- `ANDROID_KEY_ALIAS`
- `ANDROID_KEY_PASSWORD`

The workflow decodes the keystore only inside the GitHub Actions runner and builds `app-release.apk` with v1/v2/v3/v4 signing enabled.

## Release identity

- Alias: `trade_release`
- Package/applicationId: `ir.trade.app`
- Current version: `0.4.0`
- Current versionCode: `2`
- Certificate SHA-256: `C0:DE:EC:32:8B:62:FC:EB:36:18:BE:39:0A:3C:EF:04:CC:C3:22:DD:C1:C0:FB:A2:45:CB:B5:06:E8:38:FD:0B`

Keep the keystore backup in more than one secure location. Losing it means future APKs cannot update an installed production app signed by this certificate.

## One-time migration note

Old APKs built before permanent signing may have been signed by an ephemeral debug key. Android cannot update an app when the signing certificate changes. If such an old build is installed, a one-time uninstall/reinstall may be required. After installing the first permanently signed release, future releases can update it normally as long as the same keystore and package name are retained and `versionCode` increases.
