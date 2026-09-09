# Trade Android

The Android client will communicate only with the Trade cPanel API. Bitpin credentials must never be packaged in the APK.

Planned stack:

- Kotlin
- Jetpack Compose
- Material 3
- Retrofit/OkHttp
- DataStore for server URL + application API token
- WorkManager for periodic UI refresh/notifications

Initial screens:

1. Server setup / pairing
2. Dashboard
3. Markets
4. Wallets
5. Orders
6. Bot status
7. Strategies
8. Risk limits and emergency stop
9. Logs / diagnostics
