<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
$database=(string)file_get_contents($root.'/backend/src/Database.php');
$maintenance=(string)file_get_contents($root.'/backend/src/Support/DatabaseMaintenance.php');
$schema=(string)file_get_contents($root.'/backend/src/Trading/Schema.php');

function expectDatabaseBoot(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}

expectDatabaseBoot(str_contains($database,"if (PHP_SAPI === 'cli')"),'Database maintenance must be restricted to CLI/cron boot.');
expectDatabaseBoot(str_contains($database,'DatabaseMaintenance::runIfDue'),'CLI boot must continue to run bounded maintenance.');
expectDatabaseBoot(str_contains($maintenance,'ALTER TABLE'),'Maintenance is allowed to add indexes only outside normal web/API boot.');
expectDatabaseBoot(str_contains($maintenance,"retention_model'=>'telemetry_only_v1'"),'Maintenance must remain telemetry-only and avoid financial truth pruning.');
expectDatabaseBoot(str_contains($maintenance,'Schema::runLegacyCleanup($pdo)'),'Destructive legacy retirement must run only inside CLI maintenance.');

$ensureStart=strpos($schema,'public static function ensure():void');
$cleanupApi=strpos($schema,'public static function runLegacyCleanup');
expectDatabaseBoot($ensureStart!==false&&$cleanupApi!==false&&$ensureStart<$cleanupApi,'Schema must expose a separate legacy-cleanup maintenance API.');
$ensureBody=substr($schema,$ensureStart,$cleanupApi-$ensureStart);
expectDatabaseBoot(!str_contains($ensureBody,'cleanupLegacyExecution'),'Normal runtime Schema::ensure must never execute destructive legacy cleanup.');
expectDatabaseBoot(str_contains($schema,"DROP TABLE IF EXISTS")&&str_contains($schema,'removeObsoleteFiles'),'Legacy retirement may remain destructive only behind the maintenance API.');

fwrite(STDOUT,"Database web-boot and destructive-cleanup safety regression tests passed.\n");
