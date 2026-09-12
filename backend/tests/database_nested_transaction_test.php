<?php

declare(strict_types=1);

function dbAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$root=dirname(__DIR__);
$source=(string)file_get_contents($root.'/src/Database.php');

dbAssert(str_contains($source,'private static int $transactionDepth = 0;'),'Database must track nested transaction depth.');
dbAssert(str_contains($source,"SAVEPOINT ' . \$savepoint"),'Nested transactions must create a savepoint.');
dbAssert(str_contains($source,"RELEASE SAVEPOINT ' . \$savepoint"),'Successful nested transactions must release their savepoint.');
dbAssert(str_contains($source,"ROLLBACK TO SAVEPOINT ' . \$savepoint"),'Nested failures must roll back only to their own savepoint.');
dbAssert(str_contains($source,'if ($outermost)'),'Outer transaction ownership must be explicit.');
dbAssert(str_contains($source,'if ($pdo->inTransaction()) $pdo->commit();'),'Only an active outer transaction may commit.');
dbAssert(!str_contains($source,"\$pdo->beginTransaction();\n        try"),'Legacy unconditional beginTransaction wrapper must be retired.');

echo "Database nested transaction regression tests passed.\n";
