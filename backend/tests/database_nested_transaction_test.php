<?php

declare(strict_types=1);

function dbAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$root=dirname(__DIR__);
$source=(string)file_get_contents($root.'/src/Database.php');
$maintenance=(string)file_get_contents($root.'/src/Support/DatabaseMaintenance.php');

dbAssert(str_contains($source,'private static int $transactionDepth = 0;'),'Database must track nested transaction depth.');
dbAssert(str_contains($source,"SAVEPOINT ' . \$savepoint"),'Nested transactions must create a savepoint.');
dbAssert(str_contains($source,"RELEASE SAVEPOINT ' . \$savepoint"),'Successful nested transactions must release their savepoint.');
dbAssert(str_contains($source,"ROLLBACK TO SAVEPOINT ' . \$savepoint"),'Nested failures must roll back only to their own savepoint.');
dbAssert(str_contains($source,'if ($outermost)'),'Outer transaction ownership must be explicit.');
dbAssert(str_contains($source,'if ($pdo->inTransaction()) $pdo->commit();'),'Only an active outer transaction may commit.');
dbAssert(!str_contains($source,"\$pdo->beginTransaction();\n        try"),'Legacy unconditional beginTransaction wrapper must be retired.');

dbAssert(str_contains($source,'DatabaseMaintenance::runIfDue'),'Database connection boot must invoke bounded maintenance.');
dbAssert(str_contains($maintenance,"['trade_portfolio_snapshots', 'captured_at', 90"),'Portfolio snapshot retention must be bounded.');
dbAssert(str_contains($maintenance,"['trade_notifications', 'created_at', 60, 'read_at IS NOT NULL']"),'Unread app notifications must never be deleted by retention.');
dbAssert(str_contains($maintenance,"'idx_order_exchange_status_created'"),'Order hot path must have a composite execution index.');
dbAssert(str_contains($maintenance,"'idx_bot_status_started'"),'Cron/report hot path must have a composite bot-run index.');
dbAssert(!preg_match("/\['(?:orders|trades|nobitex_autotrade_positions|nobitex_autotrade_pnl)'\s*,\s*'[^']+'\s*,\s*\d+/",$maintenance),'Financial truth tables must not be included in retention policies.');
dbAssert(str_contains($maintenance,'DELETE_BATCH = 5000'),'Maintenance deletes must be bounded to avoid large shared-hosting locks.');

echo "Database nested transaction + retention/index maintenance regression tests passed.\n";
