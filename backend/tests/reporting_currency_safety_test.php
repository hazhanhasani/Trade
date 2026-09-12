<?php

declare(strict_types=1);

function reportAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$root=dirname(__DIR__);
$analytics=(string)file_get_contents($root.'/src/Trading/NobitexPerformanceAnalytics.php');
$command=(string)file_get_contents($root.'/src/Trading/TradeCommandCenter.php');
$app=(string)file_get_contents(dirname(__DIR__,2).'/android/app/src/main/java/ir/trade/app/ui/TradeAppV4.kt');

reportAssert(str_contains($analytics,"'asset_ranking_metric'=>'average_return_percent'"),'Cross-currency asset ranking must declare its unitless ranking metric.');
reportAssert(str_contains($analytics,'$byReturn=((float)$b[\'average_return_percent\'])<=>((float)$a[\'average_return_percent\'])'),'Asset ranking must sort by percentage return instead of incomparable quote-currency money.');
reportAssert(!str_contains($analytics,'usort($rows,static fn(array $a,array $b):int=>((float)$b[\'net_pnl\'])<=>((float)$a[\'net_pnl\']))'),'Asset ranking must never compare Toman PnL directly with USDT PnL.');
reportAssert(str_contains($command,"'equity_curve'=>\$accountEquity['series'] ?? []"),'Command Center must expose account-truth equity series to Android.');
reportAssert(str_contains($app,'curve.takeLast(30)'),'Android chart must bound visible equity samples.');

echo "Reporting currency safety regression tests passed.\n";
