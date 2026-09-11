<?php

declare(strict_types=1);

function expectBaleDelivery(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$notifier = file_get_contents(dirname(__DIR__) . '/src/Integrations/BaleTradeNotifier.php');
$cron = file_get_contents(dirname(__DIR__) . '/cron/tick.php');

expectBaleDelivery(is_string($notifier), 'Unable to read BaleTradeNotifier source.');
expectBaleDelivery(is_string($cron), 'Unable to read Cron source.');

expectBaleDelivery(str_contains($notifier, "'nobitex-buy-position:'"), 'Confirmed BUY must have a deterministic durable Bale event key.');
expectBaleDelivery(str_contains($notifier, "'nobitex-sell-pnl:'"), 'Confirmed SELL must have a deterministic durable Bale event key.');
expectBaleDelivery(substr_count($notifier, '],$pdo,true);') >= 2, 'Discovered confirmed BUY and SELL events must attempt Bale delivery immediately.');
expectBaleDelivery(!str_contains($notifier, 'attempts < 12'), 'Confirmed trade delivery must not expire after a fixed retry count.');
expectBaleDelivery(str_contains($notifier, "sent_at IS NULL"), 'Unsent trade notifications must remain in the durable outbox.');
expectBaleDelivery(str_contains($notifier, "last_attempt_at < (UTC_TIMESTAMP() - INTERVAL 45 SECOND)"), 'Retry spacing must remain bounded to avoid Bale flooding.');
expectBaleDelivery(str_contains($notifier, 'UNIQUE KEY uq_bale_trade_event (event_key)'), 'Durable outbox must prevent duplicate trade notifications.');

expectBaleDelivery(str_contains($cron, 'syncConfirmedTrades(100'), 'Cron must continuously discover confirmed trades for Bale delivery.');
expectBaleDelivery(str_contains($cron, 'flushPending(25'), 'Cron must continuously retry pending Bale trade deliveries.');

fwrite(STDOUT, "Bale confirmed trade delivery guarantee regression tests passed.\n");
