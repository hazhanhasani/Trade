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
$system = file_get_contents($root . '/backend/public/admin/system.php');
$client = file_get_contents($root . '/backend/src/Exchange/BitpinClient.php');
$errorReporter = file_get_contents($root . '/backend/src/Observability/ErrorReporter.php');
$baleAlert = file_get_contents($root . '/backend/src/Observability/BaleSystemAlert.php');
$decisionReporter = file_get_contents($root . '/backend/src/Observability/NobitexDecisionReporter.php');
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

expectRepair(is_string($system) && str_contains($system, "elseif(!\$enabled)"), 'System health must skip authenticated probes for disabled exchanges.');
expectRepair(str_contains($system, 'تست خودکار API انجام نشد'), 'System health must clearly explain skipped disabled-exchange probes.');
expectRepair(str_contains($system, 'برای تست واقعی از «مرکز تعمیر» استفاده کن'), 'System health must direct manual exchange tests to Repair.');
expectRepair(str_contains($system, "'tested'=>\$configured&&\$enabled"), 'System health payload must expose whether a real API probe happened.');

expectRepair(is_string($client) && str_contains($client, "CURLOPT_PROXY => ''"), 'Bitpin transport must bypass HTTP proxy variables.');
expectRepair(str_contains($client, "CURLOPT_NOPROXY => '*'"), 'Bitpin transport must explicitly bypass all proxy destinations.');
expectRepair(str_contains($client, 'CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4'), 'Bitpin transport must keep deterministic IPv4 routing for IP-whitelisted API access.');

expectRepair(is_string($errorReporter) && str_contains($errorReporter, 'E_NOTICE,E_USER_NOTICE,E_DEPRECATED,E_USER_DEPRECATED,E_STRICT'), 'Minor PHP notices and deprecations must be captured.');
expectRepair(str_contains($errorReporter, 'public static function log('), 'Application info logs must have a central forensic capture path.');
expectRepair(str_contains($errorReporter, "'file'=>"), 'Bale diagnostic context must carry the exact project-relative file path.');
expectRepair(str_contains($errorReporter, "'line'=>"), 'Bale diagnostic context must carry the exact source line.');
expectRepair(str_contains($errorReporter, "'diagnosis'=>"), 'Bale diagnostic context must include a human-readable diagnosis.');
expectRepair(str_contains($errorReporter, "'action'=>"), 'Bale diagnostic context must include a suggested action.');
expectRepair(str_contains($errorReporter, "'run_id'=>"), 'Bale diagnostics must include run correlation when available.');
expectRepair(str_contains($errorReporter, "default => 'لاگ فنی Trade'"), 'Info-level diagnostics must be mirrored to Bale.');

expectRepair(is_string($baleAlert) && str_contains($baleAlert, "'file'=>'فایل دقیق'"), 'Bale alert formatter must label the exact file.');
expectRepair(str_contains($baleAlert, "'line'=>'خط دقیق'"), 'Bale alert formatter must label the exact line.');
expectRepair(str_contains($baleAlert, 'توضیح:'), 'Bale alert formatter must show the diagnosis text.');
expectRepair(str_contains($baleAlert, 'اقدام پیشنهادی:'), 'Bale alert formatter must show remediation guidance.');
expectRepair(str_contains($baleAlert, 'recentlyQueued($pdo, $hash, 5)'), 'Only a tiny anti-recursion dedupe guard may remain.');
expectRepair(str_contains($baleAlert, "default=>'لاگ'"), 'Info diagnostics must render as log messages in Bale.');

expectRepair(is_string($decisionReporter) && str_contains($decisionReporter, 'no_candidate_passed_signal_and_risk_filters'), 'Nobitex decision reporter must explain the final no-buy reason.');
expectRepair(str_contains($decisionReporter, 'TradableEdge='), 'Nobitex decision reporter must include post-cost tradable edge.');
expectRepair(str_contains($decisionReporter, 'RequiredBuffer='), 'Nobitex decision reporter must include the required execution buffer.');
expectRepair(str_contains($decisionReporter, 'Spread='), 'Nobitex decision reporter must include spread.');
expectRepair(str_contains($decisionReporter, 'reasonFa'), 'Nobitex decision reporter must translate rejection reasons for humans.');
expectRepair(str_contains($decisionReporter, 'NobitexInternalSignalEngine.php → Profit-First v5 | سپس NobitexPortfolioEngine.php → entryBudget()'), 'Decision logs must name the exact profit-first decision path.');
expectRepair(str_contains($decisionReporter, 'multi_strategy_role'), 'Decision trace must identify multi-strategy analysis as shadow diagnostics.');

expectRepair(is_string($cronTick) && str_contains($cronTick, "'cron_cycle'"), 'Every completed Cron cycle must emit a diagnostic log.');
expectRepair(str_contains($cronTick, "'run_id'=>"), 'Cron diagnostic logs must include their Run ID.');
expectRepair(str_contains($cronTick, "'cron_update'"), 'Backend update deferral must emit its own diagnostic log.');
expectRepair(str_contains($cronTick, 'NobitexDecisionReporter'), 'Cron must emit detailed Nobitex decision traces after every no-trade result.');

fwrite(STDOUT, "Repair diagnostics regression tests passed.\n");