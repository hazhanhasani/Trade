<?php

declare(strict_types=1);

$root=dirname(__DIR__);
function text(string $path):string{$v=file_get_contents($path);if($v===false){fwrite(STDERR,"FAIL: cannot read {$path}\n");exit(1);}return$v;}
function ok(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}

$nav=text($root.'/public/admin/_nav.php');
$js=text($root.'/public/admin/assets/cockpit.js');
$theme=text($root.'/public/admin/assets/cockpit-ui.css');
$system=text($root.'/public/admin/system.php');
$logs=text($root.'/public/admin/logs.php');
$dust=text($root.'/src/Trading/NobitexDustConverter.php');
$timeline=text($root.'/src/Trading/NobitexTradeTimeline.php');
$reporter=text($root.'/src/Observability/ErrorReporter.php');
$api=text($root.'/public/index.php');

ok(str_contains($nav,'بازار و اخبار')&&str_contains($nav,'تایم‌لاین')&&str_contains($nav,'خطا و لاگ'),'admin information architecture must expose new modules');
ok(str_contains($nav,'data-theme-toggle')&&str_contains($nav,'data-iran-clock'),'global admin nav must expose theme switch and Iran clock');
ok(str_contains($theme,'html[data-theme="dark"]'),'dark theme variables must exist');
ok(str_contains($theme,'Vazirmatn')&&str_contains($theme,'IRANSansX'),'Persian font stack must be explicit');
ok(str_contains($js,'fa-IR-u-ca-persian')&&str_contains($js,'Asia/Tehran'),'client clock/date localization must use Persian calendar in Tehran');
ok(str_contains($js,'/admin/live-dashboard.php')&&str_contains($js,'poll(async'),'dashboard must update through polling without page refresh');
ok(str_contains($system,'?ajax=live')&&str_contains($system,'NobitexDustConverter'),'system page must live-update host health and expose dust conversion');
ok(str_contains($logs,'SystemObservability')&&str_contains($logs,'?ajax=1'),'error log dashboard must be live');
ok(str_contains($reporter,'BaleSystemAlert')&&str_contains($reporter,'register_shutdown_function'),'runtime/fatal errors must reach retryable Bale alert path');

// Managed residual conversion is intentionally enabled by default because the
// source set is no longer arbitrary idle wallet assets: it is limited to
// Trade-owned status=dust rows. User-owned idle coins must remain untouched.
ok(str_contains($dust,"self::ENABLED_KEY,true"),'managed Trade dust conversion must be enabled by default');
ok(str_contains($dust,"WHERE status='dust'")&&str_contains($dust,"trade_owned_dust_only_to_irt_no_user_idle_assets_max_3_per_run"),'dust conversion must be restricted to Trade-owned residual rows');
ok(str_contains($dust,'asset_has_active_position_or_pending_order')&&str_contains($dust,'MAX_CONVERSIONS_PER_RUN = 3'),'managed dust conversion must skip active assets and cap each run');
ok(str_contains($dust,"status='dust_converting'"),'pending managed dust conversion must reserve rows against duplicate SELLs');
ok(str_contains($dust,"'dstCurrency'=>'rls'"),'managed dust conversion must target IRT/Toman');

ok(str_contains($timeline,'nobitex_autotrade_pnl')&&str_contains($timeline,'NobitexDisplayMoney')&&str_contains($timeline,'IranClock'),'timeline must use confirmed fills, Toman display and Iran time');
ok(str_contains($api,'/api/system/observability')&&str_contains($api,'/api/market-context')&&str_contains($api,'/api/timeline'),'API must expose observability, market context and timeline');

echo "admin_live_observability_test: OK\n";
