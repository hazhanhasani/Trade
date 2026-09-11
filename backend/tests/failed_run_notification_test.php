<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\TradeNotificationCenter;

function expectFailedRun(bool $condition,string $message):void
{
    if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

$run=[
    'run_id'=>'abc123',
    'started_at'=>'2026-09-11 14:00:00',
    'finished_at'=>'2026-09-11 14:00:08',
    'summary_json'=>json_encode([
        'backend_version'=>'1.4.5',
        'exchanges'=>[
            'bitpin'=>['status'=>'disabled'],
            'nobitex'=>['status'=>'failed','error'=>'Nobitex HTTP 429 — too many requests'],
        ],
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
];

$d=TradeNotificationCenter::failedRunDiagnostic($run);
expectFailedRun($d['exchange']==='nobitex','Failed exchange must be identified.');
expectFailedRun(str_contains($d['reason'],'429'),'Root cause must be exposed.');
expectFailedRun($d['backend_version']==='1.4.5','Backend version must be preserved.');
expectFailedRun($d['run_id']==='abc123','Run id must be preserved.');
expectFailedRun(strlen((string)$d['fingerprint'])===24,'Diagnostic fingerprint must be stable length.');

$missing=TradeNotificationCenter::failedRunDiagnostic([
    'run_id'=>'missing-summary',
    'started_at'=>'2026-09-11 14:01:00',
    'summary_json'=>'',
]);
expectFailedRun($missing['exchange']==='unknown','Missing summary should not invent an exchange.');
expectFailedRun($missing['reason']!=='','Missing summary must still produce an actionable reason.');

$source=file_get_contents(dirname(__DIR__).'/src/Trading/TradeNotificationCenter.php')?:'';
expectFailedRun(str_contains($source,"INTERVAL 30 MINUTE"),'Failed-run notification sync must be time-bounded.');
expectFailedRun(str_contains($source,'intdiv((int)$diag[\'timestamp\'],600)'),'Same-cause failures must be deduplicated in a 10-minute bucket.');
expectFailedRun(str_contains($source,"body='یکی از چرخه‌های Cron با وضعیت failed پایان یافته است.'"),'Legacy generic failed-run alerts must be recognized for cleanup.');

fwrite(STDOUT,"Failed cron notification regression tests passed.\n");
