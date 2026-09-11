<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Observability\BaleSystemAlert;

function expect(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

expect(BaleSystemAlert::routineTechnicalInfo(['component'=>'cron_cycle','status'=>'portfolio_full']), 'Routine cron_cycle must stay local instead of sending a blue Bale alert every minute.');
expect(BaleSystemAlert::routineTechnicalInfo(['component'=>'nobitex_decision_trace','status'=>'no_trade']), 'Forensic no-trade decision traces must remain available locally but not spam Bale.');
expect(BaleSystemAlert::routineTechnicalInfo(['component'=>'nobitex_candidate_rejections','status'=>'no_trade']), 'Candidate rejection chunks must stay local.');
expect(BaleSystemAlert::routineTechnicalInfo(['component'=>'runtime_log','status'=>'waiting_order']), 'Routine pending/wait state must stay local.');
expect(!BaleSystemAlert::routineTechnicalInfo(['component'=>'cron_update','status'=>'updated_deferred']), 'Backend update state changes must remain eligible for Bale notification.');
expect(!BaleSystemAlert::routineTechnicalInfo(['component'=>'position_reconciliation','status'=>'resized']), 'Position reconciliation warnings must not be suppressed by the info policy.');

fwrite(STDOUT, "Bale routine telemetry policy regression tests passed.\n");
