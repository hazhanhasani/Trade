<?php

declare(strict_types=1);

function appDebugAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$root=dirname(__DIR__,2);
$entry=(string)file_get_contents($root.'/android/app/src/main/java/ir/trade/app/ui/TradeEntry.kt');
$worker=(string)file_get_contents($root.'/android/app/src/main/java/ir/trade/app/TradeAlertWorker.kt');
$api=(string)file_get_contents($root.'/android/app/src/main/java/ir/trade/app/data/TradeApi.kt');
$manifest=(string)file_get_contents($root.'/android/app/src/main/AndroidManifest.xml');

appDebugAssert(str_contains($entry,'ManualSetupScreen('),'Manual app setup must render a real credential form.');
appDebugAssert(str_contains($entry,'api.isContractCompatible()'),'Manual setup must validate Backend/App API contract before saving.');
appDebugAssert(!str_contains($entry,'paired || prefs.isConfigured() || manualSetup'),'Manual setup must not bypass credential validation and enter the dashboard directly.');
appDebugAssert(str_contains($entry,'PasswordVisualTransformation()'),'Manual token must be visually protected.');
appDebugAssert(str_contains($worker,'if (!canNotify()) return Result.success()'),'Missing Android notification permission must not consume unread alerts.');
appDebugAssert(str_contains($worker,'api.notifications(100, true)'),'Worker must consume a bounded unread batch instead of one notification.');
appDebugAssert(str_contains($worker,'MAX_VISIBLE_NOTIFICATIONS'),'Worker must bound visible notification fan-out.');
appDebugAssert(str_contains($worker,'api.markNotificationRead(all = true)'),'Worker must acknowledge consumed unread rows on the Backend.');
appDebugAssert(str_contains($api,'suspend fun markNotificationRead'),'Android API client must expose notification acknowledgement.');
appDebugAssert(str_contains($manifest,'android.permission.POST_NOTIFICATIONS'),'Android 13 notification permission must remain declared.');
appDebugAssert(str_contains($manifest,'android:scheme="trade" android:host="pair"'),'Secure app pairing callback must remain registered.');

echo "App callback/notification regression tests passed.\n";
