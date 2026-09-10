<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Trade\Support\IranClock;

function ok(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}

$p=IranClock::fromUtc('2026-03-20 20:30:00');
ok(($p['timezone']??'')==='Asia/Tehran','timezone must be Asia/Tehran');
ok(($p['jalali_date']??'')==='1405/01/01','2026-03-21 Tehran date must be 1405/01/01');
ok(($p['jalali_time']??'')==='00:00:00','UTC to Tehran clock conversion must be exact');

$p2=IranClock::fromUtc('2025-03-20 20:30:00');
ok(($p2['jalali_date']??'')==='1404/01/01','2025-03-21 Tehran date must be 1404/01/01');

[$start,$end]=IranClock::todayUtcRange();
$delta=(strtotime($end.' UTC')?:0)-(strtotime($start.' UTC')?:0);
ok($delta===86400,'current Iran civil day must span 24 hours');
ok((bool)preg_match('/^14\d{2}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}$/',IranClock::formatNow()),'formatted current time must be Solar Hijri');

echo "iran_clock_test: OK\n";
