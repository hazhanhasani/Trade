<?php

declare(strict_types=1);

function marketDataCleanupAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$root=dirname(__DIR__);
$repo=dirname($root);

foreach(['src/Exchange/BitpinClient.php','src/Trading/AutoTraderEngine.php','src/Trading/OrderService.php','src/Trading/MarketScanner.php']as$relative){
    marketDataCleanupAssert(!is_file($root.'/'.$relative),'Retired backend execution file still exists: '.$relative);
}
foreach(['TradeApp.kt','TradeAppV2.kt','TradeAppV3.kt']as$file){
    marketDataCleanupAssert(!is_file($repo.'/android/app/src/main/java/ir/trade/app/ui/'.$file),'Retired Android execution UI still exists: '.$file);
}

$marketHub=(string)file_get_contents($root.'/src/MarketData/MarketDataHub.php');
$store=(string)file_get_contents($root.'/src/MarketData/MarketDataCredentialStore.php');
$schema=(string)file_get_contents($root.'/src/Trading/Schema.php');
$nobitexSchema=(string)file_get_contents($root.'/src/Trading/NobitexSchema.php');
$controller=(string)file_get_contents($root.'/src/Trading/BotController.php');
$api=(string)file_get_contents($root.'/public/index.php');
$repair=(string)file_get_contents($root.'/public/admin/repair.php');
$system=(string)file_get_contents($root.'/public/admin/system.php');
$installer=(string)file_get_contents($root.'/public/install/index.php');
$admin=(string)file_get_contents($root.'/public/admin/exchanges.php');
$dashboard=(string)file_get_contents($root.'/public/admin/index.php');
$sql=(string)file_get_contents($root.'/database/schema.sql');
$android=(string)file_get_contents($repo.'/android/app/src/main/java/ir/trade/app/data/TradeApi.kt');
$entry=(string)file_get_contents($repo.'/android/app/src/main/java/ir/trade/app/ui/TradeEntry.kt');
$v4=(string)file_get_contents($repo.'/android/app/src/main/java/ir/trade/app/ui/TradeAppV4.kt');

marketDataCleanupAssert(str_contains($marketHub,'api.bitpin.market/api/v1/mth/orderbook'),'Bitpin public order-book adapter must remain.');
marketDataCleanupAssert(!str_contains($marketHub,'/usr/authenticate/')&&!str_contains($marketHub,'/odr/orders/')&&!str_contains($marketHub,'/wlt/wallets/'),'Bitpin MarketDataHub adapter must not expose private/execution endpoints.');
marketDataCleanupAssert(str_contains($store,"'abantether', 'bit24', 'tabdeal', 'bitpin'")||str_contains($store,"'abantether','bit24','tabdeal','bitpin'"),'Bitpin must be an allowed credential source in the isolated market-data store.');
marketDataCleanupAssert(str_contains($store,"'execution_allowed' => false")||str_contains($store,"'execution_allowed'=>false"),'All MarketDataCredentialStore sources must explicitly block execution.');
marketDataCleanupAssert(str_contains($store,"['bitpin', 'tabdeal']")||str_contains($store,"['bitpin','tabdeal']"),'Bitpin must remain marked as a public market-data API.');

foreach([$controller,$api,$nobitexSchema,$android,$entry,$v4]as$i=>$content){
    marketDataCleanupAssert(!str_contains(strtolower($content),'bitpin'),'Execution/runtime surface #'.$i.' still contains Bitpin legacy behavior.');
}
foreach([$repair,$system]as$i=>$content){
    marketDataCleanupAssert(!str_contains($content,'Trade\\Trading\\OrderService')&&!str_contains($content,"exchange_name='bitpin'")&&!str_contains($content,'BitpinClient'),'Admin diagnostic surface #'.$i.' still contains retired Bitpin auth/execution behavior.');
}
marketDataCleanupAssert(!str_contains($api,'Trade\\Trading\\OrderService'),'Legacy OrderService import remains in public API.');
marketDataCleanupAssert(str_contains($entry,'TradeAppV4()'),'TradeEntry must point only to V4.');

foreach(['bitpin_key','bitpin_secret','bitpin_live','bitpin_bot']as$needle){
    marketDataCleanupAssert(!str_contains($installer,$needle),'Installer still contains retired execution field: '.$needle);
}
foreach(['autotrade_bitpin_enabled','live_trading_enabled']as$needle){
    marketDataCleanupAssert(!str_contains($nobitexSchema,$needle),'Nobitex schema still contains retired setting: '.$needle);
}
foreach(['autotrade_settings','autotrade_signals','autotrade_positions','autotrade_pnl','autotrade_events','bitpin_market_history_cache']as$table){
    marketDataCleanupAssert(!str_contains($sql,'CREATE TABLE IF NOT EXISTS '.$table.' '),'Fresh schema still creates retired table: '.$table);
    marketDataCleanupAssert(str_contains($schema,"'".$table."'")||str_contains($schema,'DROP TABLE IF EXISTS '.$table),'Upgrade cleanup does not drop retired table: '.$table);
}
marketDataCleanupAssert(str_contains($schema,"DELETE FROM exchange_credentials WHERE exchange_name='bitpin'")&&str_contains($schema,"DELETE FROM orders WHERE exchange_name='bitpin'"),'One-time upgrade cleanup must remove legacy Bitpin execution credentials/orders.');
marketDataCleanupAssert(str_contains($schema,"unset(\$config['bitpin'])"),'Protected config cleanup must remove old Bitpin execution config.');
marketDataCleanupAssert(str_contains($sql,'CREATE TABLE IF NOT EXISTS nobitex_autotrade_settings'),'Fresh schema must use Nobitex-owned settings.');

marketDataCleanupAssert(str_contains($admin,'<h2>Bitpin</h2>')&&str_contains($admin,'name="source" value="bitpin"'),'Bitpin must have an isolated Market Data credential form.');
marketDataCleanupAssert(str_contains($admin,'Market Data only')&&str_contains($admin,'Execution blocked'),'Bitpin UI must clearly remain Market Data only.');
marketDataCleanupAssert(!str_contains($admin,"setExchangeEnabled('bitpin'")&&!str_contains($admin,"setExchangeLive('bitpin'"),'Bitpin must never receive Bot/Live controls.');
marketDataCleanupAssert(!str_contains($dashboard,"\$exchanges['bitpin']")&&!str_contains($dashboard,"'bitpin'=>'Bitpin'"),'Dashboard must not treat Bitpin as an execution exchange.');

foreach(['Bitpin execution','قفل دائمی','Trading disabled','Live execution disabled']as$legacyLabel){
    marketDataCleanupAssert(!str_contains($admin,$legacyLabel),'Admin still frames Bitpin as former execution: '.$legacyLabel);
}

echo "Bitpin market-data-only destructive cleanup regression tests passed.\n";
