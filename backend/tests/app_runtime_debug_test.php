<?php

declare(strict_types=1);

function appDebugAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$root=dirname(__DIR__,2);
$entry=(string)file_get_contents($root.'/android/app/src/main/java/ir/trade/app/ui/TradeEntry.kt');
$app=(string)file_get_contents($root.'/android/app/src/main/java/ir/trade/app/ui/TradeAppV4.kt');
$worker=(string)file_get_contents($root.'/android/app/src/main/java/ir/trade/app/TradeAlertWorker.kt');
$api=(string)file_get_contents($root.'/android/app/src/main/java/ir/trade/app/data/TradeApi.kt');
$manifest=(string)file_get_contents($root.'/android/app/src/main/AndroidManifest.xml');
$rotation=(string)file_get_contents($root.'/android/app/src/main/java/ir/trade/app/RotationActivity.kt');
$learning=(string)file_get_contents($root.'/android/app/src/main/java/ir/trade/app/StrategyLearningActivity.kt');
$devices=(string)file_get_contents($root.'/backend/public/admin/devices.php');
$assetLinks=(string)file_get_contents($root.'/backend/public/.well-known/assetlinks.json');
$pairFallback=(string)file_get_contents($root.'/backend/public/app/pair/index.php');

appDebugAssert(str_contains($entry,'ManualSetupScreen('),'Manual app setup must render a real credential form.');
appDebugAssert(str_contains($entry,'PairCodeSetupScreen('),'Primary app pairing must provide one-time code entry inside the trusted app.');
appDebugAssert(str_contains($entry,'redeemPairingCode('),'One-time pairing must use one canonical validation path.');
appDebugAssert(str_contains($entry,'pairedApi.isContractCompatible()'),'Pair-code redemption must validate Backend/App API contract before saving.');
appDebugAssert(str_contains($entry,'api.isContractCompatible()'),'Manual setup must validate Backend/App API contract before saving.');
appDebugAssert(!str_contains($entry,'paired || prefs.isConfigured() || manualSetup'),'Manual setup must not bypass credential validation and enter the dashboard directly.');
appDebugAssert(str_contains($entry,'PasswordVisualTransformation()'),'Manual token must be visually protected.');

$pairedApiPos=strpos($entry,'val pairedApi = TradeApi(server, token)');
$pairedStatusPos=strpos($entry,'val status = pairedApi.status()');
$pairedContractPos=strpos($entry,'if (!pairedApi.isContractCompatible())');
$pairedSavePos=strpos($entry,'withContext(Dispatchers.IO) { prefs.save(server, token) }');
appDebugAssert($pairedApiPos!==false&&$pairedStatusPos!==false&&$pairedContractPos!==false&&$pairedSavePos!==false,'Pair-code redemption must authenticate the returned token and validate the API contract.');
appDebugAssert($pairedApiPos<$pairedStatusPos&&$pairedStatusPos<$pairedContractPos&&$pairedContractPos<$pairedSavePos,'Pair-code redemption must validate before encrypted token persistence.');
appDebugAssert(str_contains($entry,'if (server != TRUSTED_SERVER)'),'Pair-code redemption must pin the Backend to the trusted server.');
appDebugAssert(str_contains($entry,'if (token.isBlank())'),'Pair-code redemption must reject a blank token.');
appDebugAssert(str_contains($entry,'uri.scheme.equals("https"'),'Pairing callback must accept HTTPS only.');
appDebugAssert(str_contains($entry,'uri.host.equals(TRUSTED_HOST'),'Pairing callback must pin the verified hostname.');
appDebugAssert(str_contains($entry,'uri.path == PAIR_PATH'),'Pairing callback must pin the pairing path.');
appDebugAssert(!str_contains($manifest,'android:scheme="trade" android:host="pair"'),'Unverified custom-scheme pairing must not be exported by Android.');
appDebugAssert(str_contains($manifest,'android:autoVerify="true"'),'Pairing must use Android verified app links.');
appDebugAssert(str_contains($manifest,'android:scheme="https"')&&str_contains($manifest,'android:host="rado-taxi.sbs"')&&str_contains($manifest,'android:path="/app/pair"'),'Verified pairing intent must match the trusted HTTPS callback exactly.');
appDebugAssert(!str_contains($devices,'trade://pair'),'Admin must never generate interceptable custom-scheme pairing links.');
appDebugAssert(str_contains($devices,'https://rado-taxi.sbs/app/pair?code='),'Admin direct pairing must use the verified HTTPS app link.');
appDebugAssert(str_contains($assetLinks,'"package_name": "ir.trade.app"'),'The trusted domain must publish the production application id.');
appDebugAssert(str_contains($assetLinks,'3A:01:AE:96:A0:8E:AD:7C:A6:97:C8:EA:10:FC:F8:9D:DA:90:D9:3F:39:7E:50:16:D9:CA:40:78:3A:B1:59:7D'),'Digital Asset Links must pin the permanent production signing certificate.');
appDebugAssert(str_contains($pairFallback,"Content-Security-Policy"),'Browser fallback for verified pairing must be CSP protected.');
appDebugAssert(str_contains($pairFallback,"این صفحه کد را مصرف نمی‌کند"),'Browser fallback must not redeem the one-time code outside the trusted app.');

appDebugAssert(str_contains($worker,'if (!canNotify()) return Result.success()'),'Missing Android notification permission must not consume unread alerts.');
appDebugAssert(str_contains($worker,'api.notifications(100, true)'),'Worker must consume a bounded unread batch instead of one notification.');
appDebugAssert(str_contains($worker,'MAX_VISIBLE_NOTIFICATIONS'),'Worker must bound visible notification fan-out.');
appDebugAssert(str_contains($worker,'api.markNotificationsRead(receivedIds)'),'Worker must acknowledge only the exact unread rows it consumed.');
appDebugAssert(!str_contains($worker,'api.markNotificationRead(all = true)'),'Worker must never mark unread rows outside its bounded batch as read.');
appDebugAssert(str_contains($api,'suspend fun markNotificationsRead'),'Android API client must expose exact batch notification acknowledgement.');
appDebugAssert(str_contains($api,'notifications.batch_read_v1'),'Batch acknowledgement must be capability-gated.');
appDebugAssert(str_contains($manifest,'android.permission.POST_NOTIFICATIONS'),'Android 13 notification permission must remain declared.');

appDebugAssert(str_contains($app,'var snapshotJson by remember { mutableStateOf(prefs.offlineSnapshot()) }'),'Large command-center snapshot must not use rememberSaveable/Bundle persistence.');
appDebugAssert(!str_contains($app,'var snapshotJson by rememberSaveable'),'Large snapshot must stay out of Android saved-instance state.');
appDebugAssert(str_contains($app,'withContext(Dispatchers.IO) { prefs.saveOfflineSnapshot(serialized) }'),'Encrypted snapshot persistence must run off the UI thread.');
appDebugAssert(str_contains($app,'if (refreshing) return'),'Live refresh must reject overlapping network refreshes.');
appDebugAssert(str_contains($app,'private const val LIVE_REFRESH_MS = 30_000L'),'Heavy command-center refresh must be rate-limited to 30 seconds.');
foreach(['homeScroll','marketScroll','tradesScroll','reportsScroll','settingsScroll'] as $name){
    appDebugAssert(str_contains($app,"val {$name} = rememberLazyListState()"),"{$name} must preserve page scroll state.");
}
appDebugAssert(str_contains($app,'var settingsDirty by rememberSaveable'),'Editable settings must track dirty state across live refreshes.');
appDebugAssert(!str_contains($app,'remember(root.toString()) { mutableStateOf(current.optDouble("position_percent"'),'Live refresh must not reset settings fields while the user is typing.');
appDebugAssert(str_contains($app,'val window = downsample(curve, 48)'),'Equity chart must downsample the available window instead of showing only the last 30 points.');
appDebugAssert(str_contains($app,'private fun downsample('),'Equity chart must expose deterministic downsampling.');
appDebugAssert(str_contains($app,'val values = window.map'),'Equity chart min/max must use the visible sampled window.');
appDebugAssert(str_contains($app,'private fun normalizeLocalizedNumber'),'Persian/Arabic numeric input must be normalized before parsing.');
appDebugAssert(str_contains($app,'localizedDoubleOrNull(customPosition)'),'Custom risk fields must parse localized digits.');
appDebugAssert(str_contains($app,'reports.optJSONObject("by_quote")'),'Reports must consume quote-separated PnL instead of implying one cross-currency total.');
appDebugAssert(str_contains($app,'private val FaLocale = Locale("fa", "IR")'),'Primary Android dashboard numbers must use Persian locale formatting.');
appDebugAssert(str_contains($app,'Futures/معاملات اهرمی در این مسیر اجرا نمی‌شوند'),'App must make the current Spot-only execution boundary explicit.');

// Specialized deep-link dashboards may observe learning/rotation, but they must
// bootstrap the same authenticated status/capability contract as the main app.
appDebugAssert(str_contains($rotation,'val status = api.status()'),'Rotation page must authenticate through the canonical status endpoint before loading data.');
appDebugAssert(str_contains($rotation,'api.rotationStatus(20)'),'Rotation page must use the read-only rotation monitor endpoint.');
appDebugAssert(str_contains($learning,'val status = api.status()'),'Strategy-learning page must authenticate through the canonical status endpoint before loading data.');
appDebugAssert(str_contains($learning,'api.strategyLearning(240)')&&str_contains($learning,'api.edgeCalibration()'),'Strategy-learning page must stay on read-only learning/calibration endpoints.');

// Futures/leveraged execution is intentionally absent from the current Spot
// product. Do not silently introduce leverage through app code or deep links.
foreach([$api,$entry,$app,$rotation,$learning] as $source){
    appDebugAssert(!preg_match('/createFuture|openShort|setLeverage|liquidationOrder|futuresOrder/i',$source),'Android must not expose an undeclared Futures/leveraged execution path.');
}

echo "App pairing/notification/state/scroll/chart/page-boundary regression tests passed.\n";
