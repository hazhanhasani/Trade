<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexPositionReconciler;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function near(float $a, float $b, float $epsilon = 1e-9): bool
{
    return abs($a - $b) <= $epsilon;
}

$wallets = NobitexPositionReconciler::walletTotals([
    'wallets'=>[
        ['currency'=>'X','balance'=>'100','activeBalance'=>'20','blockedBalance'=>'80'],
        ['currency'=>'Y','activeBalance'=>'7.5','blockedBalance'=>'2.5'],
        ['currency'=>'Z','available'=>'4','blocked'=>'1'],
    ],
]);
expect(near((float)$wallets['X'], 100.0), 'Total balance must be preferred over activeBalance so blocked funds do not mimic a manual sale.');
expect(near((float)$wallets['Y'], 10.0), 'activeBalance + blockedBalance fallback must preserve total inventory.');
expect(near((float)$wallets['Z'], 5.0), 'available + blocked fallback must preserve total inventory.');

$position = [['id'=>1,'symbol'=>'XIRT','amount'=>100.0]];
$none = NobitexPositionReconciler::plan($position, 100.0);
expect(($none['action'] ?? '') === 'none', 'Equal wallet inventory must not alter a position.');

$extra = NobitexPositionReconciler::plan($position, 130.0);
expect(($extra['action'] ?? '') === 'none', 'External extra holdings must never increase or resize a managed position.');

$tolerated = NobitexPositionReconciler::plan($position, 99.96);
expect(($tolerated['action'] ?? '') === 'none', 'Tiny wallet rounding differences inside tolerance must not resize a position.');

$partial = NobitexPositionReconciler::plan($position, 40.0);
expect(($partial['action'] ?? '') === 'resize', 'A material manual partial sale must be detected.');
expect(count($partial['positions'] ?? []) === 1, 'Partial sale should produce one position change.');
expect(($partial['positions'][0]['action'] ?? '') === 'resize', 'Remaining inventory above dust must resize, not close.');
expect(near((float)($partial['positions'][0]['after_amount'] ?? -1), 40.0), 'Managed amount must shrink to the real remaining wallet inventory.');

$dust = NobitexPositionReconciler::plan($position, 0.4);
expect(($dust['action'] ?? '') === 'close', 'Near-zero post-sale dust must close the ghost position.');
expect(($dust['positions'][0]['action'] ?? '') === 'close', 'Dust position change must be close.');

$zero = NobitexPositionReconciler::plan($position, 0.0);
expect(($zero['action'] ?? '') === 'close', 'A fully sold wallet position must close.');

$legacy = [
    ['id'=>1,'symbol'=>'XIRT','amount'=>60.0],
    ['id'=>2,'symbol'=>'XUSDT','amount'=>40.0],
];
$legacyPlan = NobitexPositionReconciler::plan($legacy, 75.0);
expect(($legacyPlan['action'] ?? '') === 'resize', 'Legacy duplicate asset positions must reconcile deterministically.');
expect(count($legacyPlan['positions'] ?? []) === 1, 'Oldest position should retain inventory first and only deficit position should change.');
expect((int)($legacyPlan['positions'][0]['id'] ?? 0) === 2, 'Remaining wallet inventory must be allocated oldest position first.');
expect(near((float)($legacyPlan['positions'][0]['after_amount'] ?? -1), 15.0), 'Second legacy position should be reduced to remaining inventory.');

fwrite(STDOUT, "Nobitex manual position reconciliation regression tests passed.\n");
