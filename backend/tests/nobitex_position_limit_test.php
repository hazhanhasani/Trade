<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexPortfolioRotation;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$base = static function (int $id, float $edge, float $pnl, string $openedAt): array {
    return [
        'id'=>$id,
        'symbol'=>'ASSET' . $id . 'IRT',
        'asset'=>'ASSET' . $id,
        'quote_asset'=>'IRT',
        'amount'=>1.0,
        'status'=>'open',
        'forward_edge_percent'=>$edge,
        'unrealized_net_pnl_percent'=>$pnl,
        'estimated_exit_cost_percent'=>0.2,
        'opened_at'=>$openedAt,
    ];
};

$within = [];
for ($i = 1; $i <= 6; $i++) $within[] = $base($i, (float)$i, 0.0, '2026-09-10 00:00:00');
$plan = NobitexPortfolioRotation::capacityReductionPlan($within, 6);
expect(($plan['rotate'] ?? true) === false, 'Portfolio at the configured limit must not be reduced.');
expect(($plan['reason'] ?? '') === 'position_limit_satisfied', 'Satisfied limit must report the correct reason.');

$over = $within;
$over[] = $base(7, -1.5, -0.4, '2026-09-09 00:00:00');
$plan = NobitexPortfolioRotation::capacityReductionPlan($over, 6);
expect(($plan['rotate'] ?? false) === true, 'Portfolio above the configured limit must request a reduction SELL.');
expect(($plan['reason'] ?? '') === 'position_limit_reduction', 'Over-limit plan must use the hard capacity reduction reason.');
expect((int)($plan['victim']['id'] ?? 0) === 7, 'Weakest forward edge must be selected first.');
expect((int)($plan['excess_positions'] ?? 0) === 1, 'Seven positions with a six-position limit must report one excess position.');

$tied = [
    $base(11, -0.5, -2.0, '2026-09-09 00:00:00'),
    $base(12, -0.5,  1.0, '2026-09-10 00:00:00'),
    $base(13,  0.5,  5.0, '2026-09-08 00:00:00'),
];
$plan = NobitexPortfolioRotation::capacityReductionPlan($tied, 2);
expect((int)($plan['victim']['id'] ?? 0) === 12, 'For equal edge, the better current net PnL should be sold first to avoid realizing the larger loss.');

$many = [];
for ($i = 1; $i <= 20; $i++) $many[] = $base($i, (float)$i, 0.0, '2026-09-10 00:00:00');
$plan = NobitexPortfolioRotation::capacityReductionPlan($many, 6);
expect((int)($plan['excess_positions'] ?? -1) === 14, '20 active positions with a six-position limit must report 14 excess positions.');
expect((int)($plan['victim']['id'] ?? 0) === 1, 'The weakest available position must be the first reduction victim.');

// If some live positions cannot be scanned in this tick, the total active-count
// override must still keep hard capacity enforcement active using the positions
// that do have reliable market data.
$scannable = [
    $base(21, -3.0, 0.2, '2026-09-10 00:00:00'),
    $base(22, -2.0, 0.1, '2026-09-10 00:00:00'),
];
$plan = NobitexPortfolioRotation::capacityReductionPlan($scannable, 6, 20);
expect(($plan['rotate'] ?? false) === true, 'Scan failures must not make an actually over-limit portfolio look compliant.');
expect((int)($plan['excess_positions'] ?? -1) === 14, 'Active-count override must preserve the real excess count.');
expect((int)($plan['victim']['id'] ?? 0) === 21, 'Reduction should use the weakest scannable victim when some snapshots fail.');

// Capacity enforcement runs before PortfolioEngine. If it returns
// waiting_reconcile merely because a pending_open/pending_close row exists,
// AutoTrader stops before PortfolioEngine can reconcile that row. That creates
// a permanent capacity deadlock and blocks future BUYs. Pending state must yield
// with no_reduction so the normal portfolio reconciliation path runs this tick.
$capacitySource = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexCapacityManager.php');
expect(is_string($capacitySource) && $capacitySource !== '', 'Capacity manager source must be readable.');
expect(str_contains($capacitySource, "result('no_reduction','pending_order_present'"), 'Pending capacity state must yield to PortfolioEngine reconciliation.');
expect(!str_contains($capacitySource, "result('waiting_reconcile','pending_order_present'"), 'Pending capacity state must not stop the orchestrator before reconciliation.');

fwrite(STDOUT, "Nobitex active position limit regression tests passed.\n");
