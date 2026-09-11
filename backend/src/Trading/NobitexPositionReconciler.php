<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

/**
 * Reconciles Trade-managed spot positions with the real Nobitex spot wallet.
 *
 * This is intentionally conservative: exchange wallet state may reduce or
 * close a managed position, but it never creates/increases a position and it
 * never fabricates PnL for an external/manual sale whose execution price is
 * not proven by a Trade order.
 */
final class NobitexPositionReconciler
{
    private const RELATIVE_TOLERANCE = 0.0005; // 0.05%
    private const DUST_CLOSE_RATIO = 0.005;    // <=0.5% of tracked amount
    private const ABSOLUTE_EPSILON = 0.000000000001;

    public function __construct(private readonly NobitexOrderService $orders = new NobitexOrderService()) {}

    public function reconcile(?PDO $pdo = null): array
    {
        NobitexSchema::ensure();
        $pdo ??= Database::connection();
        $positions = $pdo->query("SELECT id,symbol,asset,quote_asset,amount,entry_price,status,opened_at,updated_at
            FROM nobitex_autotrade_positions
            WHERE status='open'
            ORDER BY id ASC LIMIT 50")->fetchAll();

        if ($positions === []) {
            return ['status'=>'ok','checked'=>0,'closed'=>0,'resized'=>0,'unchanged'=>0,'events'=>[]];
        }

        $wallets = $this->orders->client()->wallets();
        $walletTotals = self::walletTotals($wallets);
        $byAsset = [];
        foreach ($positions as $position) {
            $asset = strtoupper(trim((string)($position['asset'] ?? '')));
            if ($asset === '') continue;
            $byAsset[$asset][] = $position;
        }

        $result = ['status'=>'ok','checked'=>count($positions),'closed'=>0,'resized'=>0,'unchanged'=>0,'events'=>[]];

        foreach ($byAsset as $asset => $assetPositions) {
            $tracked = 0.0;
            foreach ($assetPositions as $position) $tracked += max(0.0, (float)($position['amount'] ?? 0));
            if ($tracked <= self::ABSOLUTE_EPSILON) continue;

            $walletTotal = max(0.0, (float)($walletTotals[$asset] ?? 0.0));
            $plan = self::plan($assetPositions, $walletTotal);
            if (($plan['action'] ?? 'none') === 'none') {
                $result['unchanged'] += count($assetPositions);
                continue;
            }

            foreach ($plan['positions'] as $change) {
                $positionId = (int)$change['id'];
                $before = (float)$change['before_amount'];
                $after = (float)$change['after_amount'];
                $action = (string)$change['action'];

                if ($action === 'close') {
                    $stmt = $pdo->prepare("UPDATE nobitex_autotrade_positions
                        SET status='closed', amount=0, exit_price=NULL,
                            closed_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP()
                        WHERE id=:id AND status='open'");
                    $stmt->execute([':id'=>$positionId]);
                    if ($stmt->rowCount() !== 1) continue;
                    $result['closed']++;
                    $event = [
                        'position_id'=>$positionId,
                        'asset'=>$asset,
                        'symbol'=>$change['symbol'],
                        'reason'=>'external_balance_depleted',
                        'tracked_amount_before'=>$before,
                        'wallet_total'=>$walletTotal,
                        'remaining_amount'=>0.0,
                        'pnl_recorded'=>false,
                    ];
                    $this->event($pdo, 'warning', 'nobitex.position.external_close_detected', $event);
                    $result['events'][] = ['type'=>'closed'] + $event;
                    continue;
                }

                if ($action === 'resize' && $after >= 0.0 && $after + self::ABSOLUTE_EPSILON < $before) {
                    $stmt = $pdo->prepare("UPDATE nobitex_autotrade_positions
                        SET amount=:amount, updated_at=UTC_TIMESTAMP()
                        WHERE id=:id AND status='open' AND amount>:amount");
                    $stmt->execute([':amount'=>$after,':id'=>$positionId]);
                    if ($stmt->rowCount() !== 1) continue;
                    $result['resized']++;
                    $event = [
                        'position_id'=>$positionId,
                        'asset'=>$asset,
                        'symbol'=>$change['symbol'],
                        'reason'=>'external_balance_reduced',
                        'tracked_amount_before'=>$before,
                        'wallet_total'=>$walletTotal,
                        'remaining_amount'=>$after,
                        'reduced_amount'=>max(0.0, $before - $after),
                        'pnl_recorded'=>false,
                    ];
                    $this->event($pdo, 'warning', 'nobitex.position.external_resize_detected', $event);
                    $result['events'][] = ['type'=>'resized'] + $event;
                }
            }
        }

        return $result;
    }

    /**
     * Pure reconciliation planner used by runtime and regression tests.
     * Wallet inventory is allocated to oldest tracked positions first. The
     * normal engine currently prevents duplicate managed positions per asset,
     * but deterministic allocation keeps recovery safe for legacy data too.
     */
    public static function plan(array $positions, float $walletTotal): array
    {
        $walletTotal = max(0.0, $walletTotal);
        $tracked = 0.0;
        foreach ($positions as $position) $tracked += max(0.0, (float)($position['amount'] ?? 0));
        if ($tracked <= self::ABSOLUTE_EPSILON) return ['action'=>'none','positions'=>[]];

        $tolerance = max(self::ABSOLUTE_EPSILON, $tracked * self::RELATIVE_TOLERANCE);
        if ($walletTotal + $tolerance >= $tracked) return ['action'=>'none','positions'=>[]];

        // A tiny post-sale remainder is treated as dust so the bot does not
        // keep a ghost position and block a portfolio slot indefinitely.
        if ($walletTotal <= max(self::ABSOLUTE_EPSILON, $tracked * self::DUST_CLOSE_RATIO)) {
            $changes = [];
            foreach ($positions as $position) {
                $before = max(0.0, (float)($position['amount'] ?? 0));
                if ($before <= self::ABSOLUTE_EPSILON) continue;
                $changes[] = [
                    'id'=>(int)($position['id'] ?? 0),
                    'symbol'=>(string)($position['symbol'] ?? ''),
                    'before_amount'=>$before,
                    'after_amount'=>0.0,
                    'action'=>'close',
                ];
            }
            return ['action'=>'close','tracked_total'=>$tracked,'wallet_total'=>$walletTotal,'positions'=>$changes];
        }

        $remaining = $walletTotal;
        $changes = [];
        foreach ($positions as $position) {
            $before = max(0.0, (float)($position['amount'] ?? 0));
            if ($before <= self::ABSOLUTE_EPSILON) continue;
            $after = min($before, max(0.0, $remaining));
            $remaining = max(0.0, $remaining - $after);
            if ($after + max(self::ABSOLUTE_EPSILON, $before * self::RELATIVE_TOLERANCE) >= $before) continue;
            $changes[] = [
                'id'=>(int)($position['id'] ?? 0),
                'symbol'=>(string)($position['symbol'] ?? ''),
                'before_amount'=>$before,
                'after_amount'=>$after,
                'action'=>$after <= max(self::ABSOLUTE_EPSILON, $before * self::DUST_CLOSE_RATIO) ? 'close' : 'resize',
            ];
        }

        return ['action'=>'resize','tracked_total'=>$tracked,'wallet_total'=>$walletTotal,'positions'=>$changes];
    }

    /** @return array<string,float> */
    public static function walletTotals(array $response): array
    {
        $rows = $response['wallets'] ?? $response['data'] ?? $response;
        if (is_array($rows) && !array_is_list($rows) && is_array($rows['wallets'] ?? null)) $rows = $rows['wallets'];
        if (!is_array($rows)) return [];

        $out = [];
        foreach ($rows as $key => $row) {
            if (!is_array($row)) continue;
            $asset = strtoupper(trim((string)($row['currency'] ?? $row['asset'] ?? $row['currencyCode'] ?? (is_string($key) ? $key : ''))));
            if ($asset === '') continue;

            if (array_key_exists('balance', $row) && is_numeric($row['balance'])) {
                $total = (float)$row['balance'];
            } elseif (array_key_exists('activeBalance', $row) && array_key_exists('blockedBalance', $row)
                && is_numeric($row['activeBalance']) && is_numeric($row['blockedBalance'])) {
                $total = (float)$row['activeBalance'] + (float)$row['blockedBalance'];
            } elseif (array_key_exists('available', $row) && array_key_exists('blocked', $row)
                && is_numeric($row['available']) && is_numeric($row['blocked'])) {
                $total = (float)$row['available'] + (float)$row['blocked'];
            } else {
                $total = 0.0;
                foreach (['activeBalance','available','free'] as $field) {
                    if (array_key_exists($field, $row) && is_numeric($row[$field])) { $total = (float)$row[$field]; break; }
                }
            }
            if (!is_finite($total)) $total = 0.0;
            $out[$asset] = max(0.0, $total);
        }
        return $out;
    }

    private function event(PDO $pdo, string $level, string $event, array $context): void
    {
        $stmt = $pdo->prepare('INSERT INTO nobitex_autotrade_events (level,event_name,context_json,created_at) VALUES (:level,:event,:context,UTC_TIMESTAMP())');
        $stmt->execute([
            ':level'=>$level,
            ':event'=>$event,
            ':context'=>json_encode($context, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
    }
}
