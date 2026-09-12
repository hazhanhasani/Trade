<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
$database=(string)file_get_contents($root.'/backend/src/Database.php');
$maintenance=(string)file_get_contents($root.'/backend/src/Support/DatabaseMaintenance.php');

function expectDatabaseBoot(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}

expectDatabaseBoot(str_contains($database,"if (PHP_SAPI === 'cli')"),'Database maintenance must be restricted to CLI/cron boot.');
expectDatabaseBoot(str_contains($database,'DatabaseMaintenance::runIfDue'),'CLI boot must continue to run bounded maintenance.');
expectDatabaseBoot(str_contains($maintenance,'ALTER TABLE'),'Maintenance is allowed to add indexes only outside normal web/API boot.');
expectDatabaseBoot(str_contains($maintenance,"retention_model'=>'telemetry_only_v1'"),'Maintenance must remain telemetry-only and avoid financial truth pruning.');

fwrite(STDOUT,"Database web-boot safety regression tests passed.\n");
