<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

function expectRepair(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$repair = file_get_contents($root . '/backend/public/admin/repair.php');
$cronRun = file_get_contents($root . '/backend/public/admin/cron-run.php');
$client = file_get_contents($root . '/backend/src/Exchange/BitpinClient.php');
$errorReporter = file_get_contents($root . '/backend/src/Observability/ErrorReporter.php');
$baleAlert = file_get_contents($root . '/backend/src/Observability/BaleSystemAlert.php');
$cronTick = file_get_contents($root . '/backend/cron/tick.php');

expectRepair(is_string($repair) && $repair !== '', 'Repair center must exist.');
expectRepair(str_contains($repair, 'bitpinReportedIp'), 'Repair center must parse the IP reported by Bitpin itself.');
expectRepair(str_contains($repair, 'IP معتبر برای Whitelist از دید خود Bitpin'), 'Repair center must identify the authoritative Bitpin whitelist IP.');
expectRepair(str_contains($repair, 'مسیر خروجی مقصدها متفاوت است'), 'Repair center must explain destination-specific egress/NAT mismatch.');
expectRepair(str_contains($repair, "CURLOPT_PROXY=>''"), 'Public IP diagnostic must bypass configured HTTP proxies.');
expectRepair(str_contains($repair, "CURLOPT_NOPROXY=>'*'"), 'Public IP diagnostic must bypass environment proxy routing.');
expectRepair(str_contains($repair, "'updated_deferred'"), 'Repair center must explicitly detect updated_deferred heartbeat state.');
expectRepair(str_contains($repair, 'updated_deferred</span> خطا نیست'), 'Repair center must explain that updated_deferred is not a Cron failure.');
expectRepair(str_contains($repair, '<details'), 'Old run JSON must be collapsible instead of flooding the repair page.');
expectRepair(str_contains($repair, 'اجرای Tick بعدی همین حالا'), 'Repair center must provide a clear next-tick action after an update.');

expectRepair(is_string($cronRun) && str_contains($cronRun, "=== 'updated_deferred'"), 'Cron runner must detect updated_deferred.');
expectRepair(str_contains($cronRun, 'آپدیت Backend داخل Cron با موفقیت انجام شده است'), 'Cron runner must explain successful deferred update state.');
expectRepair(str_contains($cronRun, 'اجرای Tick بعدی همین حالا'), 'Cron runner must offer the next full tick explicitly.');

expectRepair(is_string($client) && str_contains($client, "CURLOPT_PROXY => ''"), 'Bitpin transport must bypass HTTP proxy variables.');
expectRepair(str_contains($client, "CURLOPT_NOPROXY => '*'"), 'Bitpin transport must explicitly bypass all proxy destinations.');
expectRepair(str_contains($client, 'CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4'), 'Bitpin transport must keep deterministic IPv4 routing for IP-whitelisted API access.');

expectRepair(is_string($errorReporter) && str_contains($errorReporter, 'E_NOTICE,E_USER_NOTICE,E_DEPRECATED,E_USER_DEPRECATED,E_STRICT'), 'Minor PHP notices and deprecations must be captured.');
expectRepair(str_contains($errorReporter, 'public static function log('), 'Application info logs must have a central forensic capture path.');
expectRepair(str_contains($errorReporter, "'file'=>$file !== '' ? $file : null"), 'Bale diagnostic context must carry the exact project-relative file path.');
expectRepair(str_contains($errorReporter, "'line'=>$line > 0 ? $line : null"), 'Bale diagnostic context must carry the exact source line.');
expectRepair(str_contains($errorReporter, "'diagnosis'=>$context['diagnosis'] ?? null"), 'Bale diagnostic context must include a human-readable diagnosis.');
expectRepair(str_contains($errorReporter, "'action'=>$context['action'] ?? null"), 'Bale diagnostic context must include a suggested action.');
expectRepair(str_contains($errorReporter, "'run_id'=>$context['run_id'] ?? null"), 'Bale diagnostics must include run correlation when available.');
expectRepair(str_contains($errorReporter, "default => 'لاگ فنی Trade'"), 'Info-level diagnostics must be mirrored to Bale.');

expectRepair(is_string($baleAlert) && str_contains($baleAlert, "'file'=>'فایل دقیق'"), 'Bale alert formatter must label the exact file.');
expectRepair(str_contains($baleAlert, "'line'=>'خط دقیق'"), 'Bale alert formatter must label the exact line.');
expectRepair(str_contains($baleAlert, 'توضیح:'), 'Bale alert formatter must show the diagnosis text.');
expectRepair(str_contains($baleAlert, 'اقدام پیشنهادی:'), 'Bale alert formatter must show remediation guidance.');
expectRepair(str_contains($baleAlert, 'recentlyQueued($pdo, $hash, 5)'), 'Only a tiny anti-recursion dedupe guard may remain.');
expectRepair(str_contains($baleAlert, "default=>'لاگ'"), 'Info diagnostics must render as log messages in Bale.');

expectRepair(is_string($cronTick) && str_contains($cronTick, "'cron_cycle'"), 'Every completed Cron cycle must emit a diagnostic log.');
expectRepair(str_contains($cronTick, "'run_id'=>$runId"), 'Cron diagnostic logs must include their Run ID.');
expectRepair(str_contains($cronTick, "'cron_update'"), 'Backend update deferral must emit its own diagnostic log.');

fwrite(STDOUT, "Repair diagnostics regression tests passed.\n");
