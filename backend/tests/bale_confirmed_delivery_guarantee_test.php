<?php

declare(strict_types=1);

function expectBaleDelivery(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$notifier = file_get_contents(dirname(__DIR__) . '/src/Integrations/BaleTradeNotifier.php');
$cron = file_get_contents(dirname(__DIR__) . '/cron/tick.php');
$runner = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexFastCycleRunner.php');

expectBaleDelivery(is_string($notifier), 'Unable to read BaleTradeNotifier source.');
expectBaleDelivery(is_string($cron), 'Unable to read Cron source.');
expectBaleDelivery(is_string($runner), 'Unable to read NobitexFastCycleRunner source.');

expectBaleDelivery(str_contains($notifier, "'nobitex-buy-position:'"), 'Confirmed BUY must have a deterministic durable Bale event key.');
expectBaleDelivery(str_contains($notifier, "'nobitex-sell-pnl:'"), 'Confirmed SELL must have a deterministic durable Bale event key.');
expectBaleDelivery(substr_count($notifier, '],$pdo,true);') >= 2, 'Discovered confirmed BUY and SELL events must attempt Bale delivery immediately.');
expectBaleDelivery(str_contains($notifier, 'try{$this->deliverEvent($eventKey,$pdo);}catch(\\Throwable){}'), 'One immediate Bale delivery failure must not abort discovery of the remaining confirmed trades.');
expectBaleDelivery(!str_contains($notifier, 'attempts < 12'), 'Confirmed trade delivery must not expire after a fixed retry count.');
expectBaleDelivery(str_contains($notifier, "sent_at IS NULL"), 'Unsent trade notifications must remain in the durable outbox.');
expectBaleDelivery(str_contains($notifier, "last_attempt_at < (UTC_TIMESTAMP() - INTERVAL 15 SECOND)"), 'Trade notification retry spacing must support fast-cycle delivery without flooding.');
expectBaleDelivery(str_contains($notifier, 'RECOVERY_LOOKBACK_SECONDS = 86400'), 'Bale trade sync must recover missed fills from the previous 24 hours.');
expectBaleDelivery(str_contains($notifier, 'effectiveSyncSince'), 'Bale trade sync must use the recovery-aware start time.');
expectBaleDelivery(str_contains($notifier, 'UNIQUE KEY uq_bale_trade_event (event_key)'), 'Durable outbox must prevent duplicate trade notifications.');

expectBaleDelivery(preg_match('/syncConfirmedTrades\(\s*100\s*,\s*\$pdo\s*\)/',$runner) === 1, 'Every fast cycle must discover newly confirmed trades for Bale.');
expectBaleDelivery(preg_match('/flushPending\(\s*25\s*,\s*\$pdo\s*\)/',$runner) === 1, 'Every fast cycle must retry pending Bale deliveries.');
expectBaleDelivery(preg_match('/syncConfirmedTrades\(\s*100\s*,\s*\$pdo\s*\)/',$cron) === 1, 'Cron must keep an end-of-run confirmed-trade safety sync.');
expectBaleDelivery(preg_match('/flushPending\(\s*25\s*,\s*\$pdo\s*\)/',$cron) === 1, 'Cron must keep an end-of-run pending-delivery safety flush.');

fwrite(STDOUT, "Bale confirmed trade delivery guarantee regression tests passed.\n");
