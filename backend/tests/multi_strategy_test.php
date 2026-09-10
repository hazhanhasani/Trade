<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexMarketRegimeDetector;
use Trade\Trading\NobitexMultiStrategyRouter;

function msAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$detector = new NobitexMarketRegimeDetector();
$router = new NobitexMultiStrategyRouter();
$market = ['orderbook_imbalance'=>0.20,'spread_percent'=>0.20,'quote_asset'=>'IRT'];

$trendPrices = [];
$p = 100.0;
for ($i=0;$i<180;$i++) { $p *= 1.0007; $trendPrices[] = $p; }
$trendI1 = ['momentum_5_percent'=>0.42,'ema_gap_percent'=>0.32,'trend_consistency'=>0.88,'volatility_percent'=>0.12,'rsi14'=>63,'macd_histogram_percent'=>0.08];
$trendI5 = ['momentum_5_percent'=>1.20,'ema_gap_percent'=>0.68,'trend_consistency'=>0.86,'volatility_percent'=>0.18,'rsi14'=>64,'macd_histogram_percent'=>0.14];
$trendI15 = ['momentum_5_percent'=>2.30,'ema_gap_percent'=>1.10,'trend_consistency'=>0.82,'volatility_percent'=>0.22,'rsi14'=>62,'macd_histogram_percent'=>0.18];
$trendRegime = $detector->detect($market,$trendPrices,$trendI1,$trendI5,$trendI15);
msAssert(($trendRegime['regime']??'')===NobitexMarketRegimeDetector::TRENDING_UP,'uptrend regime was not detected');
$trendRoute = $router->route(['market'=>$market,'prices'=>['1m'=>$trendPrices],'indicators'=>['1m'=>$trendI1,'5m'=>$trendI5,'15m'=>$trendI15]],$trendRegime);
msAssert(($trendRoute['selected']['key']??'')==='trend_momentum_v1','trend regime should route to trend strategy');
msAssert(($trendRoute['selected']['entry_allowed']??false)===true,'healthy uptrend should allow trend entry before execution costs');

$rangePrices=[];
for($i=0;$i<180;$i++) $rangePrices[]=100.0 + sin($i/4.0)*0.75;
$rangeI1=['momentum_5_percent'=>-0.08,'ema_gap_percent'=>-0.03,'trend_consistency'=>0.50,'volatility_percent'=>0.10,'rsi14'=>38,'macd_histogram_percent'=>-0.01];
$rangeI5=['momentum_5_percent'=>0.04,'ema_gap_percent'=>0.02,'trend_consistency'=>0.52,'volatility_percent'=>0.12,'rsi14'=>44,'macd_histogram_percent'=>0.00];
$rangeI15=['momentum_5_percent'=>0.02,'ema_gap_percent'=>0.01,'trend_consistency'=>0.49,'volatility_percent'=>0.10,'rsi14'=>48,'macd_histogram_percent'=>0.00];
$rangeRegime=$detector->detect($market,$rangePrices,$rangeI1,$rangeI5,$rangeI15);
msAssert(($rangeRegime['regime']??'')===NobitexMarketRegimeDetector::RANGING,'ranging regime was not detected');
$rangeRoute=$router->route(['market'=>$market,'prices'=>['1m'=>$rangePrices],'indicators'=>['1m'=>$rangeI1,'5m'=>$rangeI5,'15m'=>$rangeI15]],$rangeRegime);
msAssert(($rangeRoute['selected']['key']??'')==='mean_reversion_v1','range regime should route to mean reversion');

$breakoutPrices=[];
for($i=0;$i<179;$i++) $breakoutPrices[]=100.0 + sin($i/5.0)*0.18;
$breakoutPrices[]=101.60;
$breakoutI1=['momentum_5_percent'=>1.65,'ema_gap_percent'=>0.62,'trend_consistency'=>0.82,'volatility_percent'=>0.35,'rsi14'=>72,'macd_histogram_percent'=>0.20];
$breakoutI5=['momentum_5_percent'=>2.10,'ema_gap_percent'=>0.80,'trend_consistency'=>0.78,'volatility_percent'=>0.30,'rsi14'=>68,'macd_histogram_percent'=>0.22];
$breakoutI15=['momentum_5_percent'=>1.20,'ema_gap_percent'=>0.45,'trend_consistency'=>0.70,'volatility_percent'=>0.22,'rsi14'=>62,'macd_histogram_percent'=>0.12];
$breakoutRegime=$detector->detect($market,$breakoutPrices,$breakoutI1,$breakoutI5,$breakoutI15);
msAssert(($breakoutRegime['regime']??'')===NobitexMarketRegimeDetector::BREAKOUT_UP,'upside breakout regime was not detected');
$breakoutRoute=$router->route(['market'=>$market,'prices'=>['1m'=>$breakoutPrices],'indicators'=>['1m'=>$breakoutI1,'5m'=>$breakoutI5,'15m'=>$breakoutI15]],$breakoutRegime);
msAssert(($breakoutRoute['selected']['key']??'')==='breakout_v1','breakout regime should route to breakout strategy');
msAssert(($breakoutRoute['selected']['entry_allowed']??false)===true,'confirmed upside breakout should allow breakout entry before execution costs');

$chaosPrices=[];$p=100.0;
for($i=0;$i<180;$i++){ $p *= 1.0 + (($i%2===0?1:-1)*0.025); $chaosPrices[]=$p; }
$chaosI=['momentum_5_percent'=>0.0,'ema_gap_percent'=>0.0,'trend_consistency'=>0.50,'volatility_percent'=>2.40,'rsi14'=>50,'macd_histogram_percent'=>0.0];
$chaosRegime=$detector->detect($market,$chaosPrices,$chaosI,$chaosI,$chaosI);
msAssert(($chaosRegime['regime']??'')===NobitexMarketRegimeDetector::HIGH_VOLATILITY,'extreme volatility regime was not detected');
$chaosRoute=$router->route(['market'=>$market,'prices'=>['1m'=>$chaosPrices],'indicators'=>['1m'=>$chaosI,'5m'=>$chaosI,'15m'=>$chaosI]],$chaosRegime);
msAssert(($chaosRoute['selected']['key']??'')==='none','high-volatility regime should not receive an entry strategy');
msAssert(($chaosRoute['entry_enabled']??true)===false,'high-volatility regime must disable entries');

echo "Multi-strategy regime/router regression tests passed.\n";
