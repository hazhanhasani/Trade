<?php

declare(strict_types=1);

function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$root=dirname(__DIR__);
$portfolio=(string)file_get_contents($root.'/src/Trading/NobitexPortfolioEngine.php');
$accounting=(string)file_get_contents($root.'/src/Trading/NobitexTradeAccounting.php');
$reprice=(string)file_get_contents($root.'/src/Trading/NobitexExecutionRepriceService.php');
$orderService=(string)file_get_contents($root.'/src/Trading/NobitexOrderService.php');

check(str_contains($portfolio,'NobitexOrderFill::requestedAmount($remote'),'Recovered orders must preserve the requested amount separately from fills.');
check(str_contains($portfolio,'return NobitexOrderFill::matchedAmount($order, $fallback);'),'Portfolio fills must use the canonical matched-amount parser.');
check(str_contains($portfolio,'return NobitexOrderFill::averagePrice($order, $fallback);'),'Portfolio fill prices must use the canonical average-price parser.');
check(str_contains($portfolio,'return NobitexOrderFill::isDone($order);'),'Portfolio terminal fill handling must use the canonical done-state parser.');

check(str_contains($accounting,'return NobitexOrderFill::matchedAmount($order, 0.0);'),'Accounting must not treat requested amount as matched quantity.');
check(str_contains($accounting,'return NobitexOrderFill::averagePrice($order, $fallback);'),'Accounting must use canonical fill prices.');
check(str_contains($reprice,'NobitexOrderFill::matchedAmount($order,$fallback)'),'Reprice must require a real matched fill before blocking replacement.');

check(str_contains($orderService,'$order[\'requestedAmount\']=$requested'),'Order normalization must preserve requested quantity for recovery.');
check(str_contains($orderService,'$order[\'amount\']=0'),'Non-done normalized orders must not expose requested amount as a matched fill.');

$legacy="['matchedAmount','matched_amount','filledAmount','amount']";
check(!str_contains($portfolio,$legacy),'Portfolio still contains the legacy amount-as-fill parser.');
check(!str_contains($accounting,$legacy),'Accounting still contains the legacy amount-as-fill parser.');

echo "Nobitex fill integration regression tests passed.\n";
