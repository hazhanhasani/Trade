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

appDebugAssert(str_contains($entry,'ManualSetupScreen('),'Manual app setup must render a real credential form.');
appDebugAssert(str_contains($entry,'api.isContractCompatible()'),'Manual setup must validate Backend/App API contract before saving.');
appDebugAssert(!str_contains($entry,'paired || prefs.isConfigured() || manualSetup'),'Manual setup must not bypass credential validation and enter the dashboard directly.');
appDebugAssert(str_contains($entry,'PasswordVisualTransformation()'),'Manual token must be visually protected.');

$pairedApiPos=strpos($entry,'val pairedApi = TradeApi(server, token)');
$pairedStatusPos=strpos($entry,'val status = pairedApi.status()');
$pairedContractPos=strpos($entry,'if (!pairedApi.isContractCompatible())');
$pairedSavePos=strpos($entry,'withContext(Dispatchers.IO) { prefs.save(server, token) }');
appDebugAssert($pairedApiPos!==false&&$pairedStatusPos!==false&&$pairedContractPos!==false&&$pairedSavePos!==false,'Automatic pairing must authenticate the returned token and validate the API contract.');
appDebugAssert($pairedApiPos<$pairedStatusPos&&$pairedStatusPos<$pairedContractPos&&$pairedContractPos<$pairedSavePos,'Automatic pairing must validate before encrypted token persistence.');
appDebugAssert(str_contains($entry,'if (server != TRUSTED_SERVER)'),'Pairing callback must pin the Backend to the trusted server.');
appDebugAssert(str_contains($entry,'if (token.isBlank())'),'Pairing callback must reject a blank token.');

appDebugAssert(str_contains($worker,'if (!canNotify()) return Result.success()'),'Missing Android notification permission must not consume unread alerts.');
appDebugAssert(str_contains($worker,'api.notifications(100, true)'),'Worker must consume a bounded unread batch instead of one notification.');
appDebugAssert(str_contains($worker,'MAX_VISIBLE_NOTIFICATIONS'),'Worker must bound visible notification fan-out.');
appDebugAssert(str_contains($worker,'api.markNotificationRead(all = true)'),'Worker must acknowledge consumed unread rows on the Backend.');
appDebugAssert(str_contains($api,'suspend fun markNotificationRead'),'Android API client must expose notification acknowledgement.');
appDebugAssert(str_contains($manifest,'android.permission.POST_NOTIFICATIONS'),'Android 13 notification permission must remain declared.');
appDebugAssert(str_contains($manifest,'android:scheme="trade" android:host="pair"'),'Secure app pairing callback must remain registered.');

appDebugAssert(str_contains($app,'var snapshotJson by remember { mutableStateOf(prefs.offlineSnapshot()) }'),'Large command-center snapshot must not use rememberSaveable/Bundle persistence.');
appDebugAssert(!str_contains($app,'var snapshotJson by rememberSaveable'),'Large snapshot must stay out of Android saved-instance state.');
appDebugAssert(str_contains($app,'withContext(Dispatchers.IO) { prefs.saveOfflineSnapshot(serialized) }'),'Encrypted snapshot persistence must run off the UI thread.');
appDebugAssert(str_contains($app,'if (refreshing) return'),'Live refresh must reject overlapping network refreshes.');
foreach(['homeScroll','marketScroll','tradesScroll','reportsScroll','settingsScroll'] as $name){
    appDebugAssert(str_contains($app,"val {$name} = rememberLazyListState()"),"{$name} must preserve page scroll state.");
}
appDebugAssert(str_contains($app,'var settingsDirty by rememberSaveable'),'Editable settings must track dirty state across live refreshes.');
appDebugAssert(!str_contains($app,'remember(root.toString()) { mutableStateOf(current.optDouble("position_percent"'),'Live refresh must not reset settings fields while the user is typing.');
appDebugAssert(str_contains($app,'val window = curve.takeLast(30)'),'Equity chart scale must be calculated from the visible sample window.');
appDebugAssert(str_contains($app,'val values = window.map'),'Equity chart min/max must use the visible window, not hidden historical outliers.');
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

echo "App callback/notification/state/scroll/chart/page-boundary regression tests passed.\n";
