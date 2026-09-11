<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

function expect(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
function source(string $path):string{$v=file_get_contents(dirname(__DIR__).'/'.$path);if($v===false){fwrite(STDERR,"FAIL: unable to read {$path}\n");exit(1);}return$v;}

$trading=source('public/admin/bot/index.php');
foreach([
    'quote_asset','risk_profile','position_percent','max_position_percent',
    'nobitex_portfolio_exposure_percent','nobitex_max_positions','nobitex_max_pending_orders',
    'nobitex_pending_timeout_seconds','cooldown_minutes','stop_loss_percent',
    'take_profit_percent','daily_loss_limit_percent',
] as $field){expect(str_contains($trading,'name="'.$field.'"'),'Core trading setting disappeared from admin: '.$field);}
expect(str_contains($trading,'function positionAnalytics(?array $position,?array $signal)'), 'Trading panel positionAnalytics must remain null-safe.');
expect(str_contains($trading,"array_filter(\$e['active_positions'],'is_array')"), 'Trading panel must filter invalid/null position rows before rendering.');

$advanced=source('public/admin/bot/settings.php');
expect(str_contains($advanced,'nobitex_max_buy_orders_per_hour'),'Advanced trading page must expose the real hourly BUY safety limit.');
foreach(['dust_enabled','dust_min_toman','dust_max_toman','dust_cooldown_hours'] as $field){expect(str_contains($advanced,'name="'.$field.'"'),'Dust conversion control missing: '.$field);}
expect(str_contains($advanced,'FULL UNIVERSE'),'Advanced settings must explain that full-universe analysis is not capped by legacy scan_limit.');

$reconciler=source('src/Trading/NobitexPositionReconciler.php');
expect(str_contains($reconciler,'SET amount=:new_amount'),'Position reconciliation must use a distinct SET placeholder.');
expect(str_contains($reconciler,'amount>:amount_floor'),'Position reconciliation must use a distinct WHERE placeholder.');
expect(!preg_match('/SET\s+amount=:amount[^;]+amount>:amount/s',$reconciler),'Native PDO repeated named placeholder regression detected.');

$client=source('src/Exchange/NobitexClient.php');
expect(str_contains($client,'authenticatedRead(fn() => $this->request'), 'Safe authenticated Nobitex reads must use transient-network retry protection.');
expect(str_contains($client,'(?:6|7|28)'),'Retry protection must cover DNS/connect/timeout cURL errors 6/7/28.');

$scanner=source('src/Trading/NobitexUniverseScanner.php');
expect(str_contains($scanner,'unset($legacyLimit, $legacyThreshold);'),'Legacy scan limit must remain non-gating.');
expect(str_contains($scanner,"['full_universe_analysis'] = true"),'Scanner must preserve full-universe analysis marker.');

fwrite(STDOUT,"Trading admin settings and live hotfix regression tests passed.\n");
