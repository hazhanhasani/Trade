<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

/**
 * Carries Trade-owned IRT dust into the next Trade-managed IRT position of the
 * same asset, so the next normal position exit can liquidate both quantities in
 * one exchange-legal SELL instead of leaving the old dust stranded forever.
 *
 * Safety rules:
 *  - only status=dust rows created/owned by Trade are eligible;
 *  - only IRT dust is merged into an IRT position (no cross-currency cost basis);
 *  - no pending/reserved dust row is touched;
 *  - the real wallet total must closely match open-position + managed-dust total,
 *    otherwise the asset is skipped to avoid absorbing a user's manual holding;
 *  - cost basis and entry fees are transferred with the dust, so no synthetic PnL
 *    is created merely by reclassifying inventory.
 */
final class NobitexManagedDustCarryForward
{
    public const MODEL = 'nobitex_managed_dust_carry_forward_v1';
    private const EPS = 0.000000000001;
    private const WALLET_MATCH_RELATIVE_TOLERANCE = 0.0005; // 0.05%

    public function reconcile(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        NobitexSchema::ensure();

        $openRows = $pdo->query("SELECT id,symbol,asset,quote_asset,amount,entry_price,entry_fee_quote,entry_fee_source,stop_loss,take_profit
            FROM nobitex_autotrade_positions
            WHERE status='open' AND quote_asset='IRT'
            ORDER BY id ASC LIMIT 30")->fetchAll();
        if ($openRows === []) return $this->result('idle', 'no_open_irt_positions');

        $dustRows = $pdo->query("SELECT id,symbol,asset,quote_asset,amount,entry_price,entry_fee_quote,entry_fee_source
            FROM nobitex_autotrade_positions
            WHERE status='dust' AND quote_asset='IRT' AND (exit_identifier IS NULL OR exit_identifier='')
            ORDER BY id ASC LIMIT 100")->fetchAll();
        if ($dustRows === []) return $this->result('idle', 'no_managed_irt_dust');

        $openByAsset = [];
        foreach ($openRows as $row) {
            $asset = NobitexPositionReconciler::canonicalAsset((string)($row['asset'] ?? ''));
            if ($asset === '') continue;
            // The portfolio engine already prevents duplicate active assets. If a
            // legacy duplicate exists, skip the asset rather than guessing.
            if (isset($openByAsset[$asset])) {
                $openByAsset[$asset] = null;
                continue;
            }
            $openByAsset[$asset] = $row;
        }

        $dustByAsset = [];
        foreach ($dustRows as $row) {
            $asset = NobitexPositionReconciler::canonicalAsset((string)($row['asset'] ?? ''));
            $amount = max(0.0, (float)($row['amount'] ?? 0.0));
            if ($asset === '' || $amount <= self::EPS) continue;
            $dustByAsset[$asset][] = $row;
        }

        $candidates = [];
        foreach ($dustByAsset as $asset => $rows) {
            if (!isset($openByAsset[$asset]) || !is_array($openByAsset[$asset])) continue;
            $candidates[$asset] = ['position'=>$openByAsset[$asset], 'dust'=>$rows];
        }
        if ($candidates === []) return $this->result('idle', 'no_same_asset_open_position');

        try {
            $wallets = (new NobitexOrderService())->client()->wallets();
            $walletTotals = NobitexPositionReconciler::walletTotals($wallets);
        } catch (\Throwable $e) {
            return $this->result('deferred', 'wallet_snapshot_unavailable', [
                'error'=>mb_substr($e->getMessage(), 0, 240),
            ]);
        }

        $mergedAssets = 0;
        $mergedRows = 0;
        $mergedAmount = 0.0;
        $skipped = [];
        $events = [];

        foreach ($candidates as $asset => $candidate) {
            $position = (array)$candidate['position'];
            $dust = (array)$candidate['dust'];
            $openAmount = max(0.0, (float)($position['amount'] ?? 0.0));
            $dustAmount = 0.0;
            foreach ($dust as $row) $dustAmount += max(0.0, (float)($row['amount'] ?? 0.0));
            if ($openAmount <= self::EPS || $dustAmount <= self::EPS) continue;

            $expectedManaged = $openAmount + $dustAmount;
            $walletTotal = max(0.0, (float)($walletTotals[$asset] ?? 0.0));
            $tolerance = max(self::EPS, $expectedManaged * self::WALLET_MATCH_RELATIVE_TOLERANCE);
            if (abs($walletTotal - $expectedManaged) > $tolerance) {
                $skipped[] = [
                    'asset'=>$asset,
                    'reason'=>'wallet_total_does_not_match_managed_inventory',
                    'wallet_total'=>$walletTotal,
                    'expected_managed'=>$expectedManaged,
                    'tolerance'=>$tolerance,
                ];
                continue;
            }

            $merge = $this->mergeAsset($pdo, (int)$position['id'], $asset, array_map(
                static fn(array $row): int => (int)($row['id'] ?? 0),
                $dust
            ));
            if (!($merge['merged'] ?? false)) {
                $skipped[] = ['asset'=>$asset, 'reason'=>$merge['reason'] ?? 'state_changed'];
                continue;
            }

            $mergedAssets++;
            $mergedRows += (int)($merge['merged_rows'] ?? 0);
            $mergedAmount += (float)($merge['dust_amount'] ?? 0.0);
            $events[] = $merge;
        }

        return $this->result('ok', 'reconciled', [
            'merged_assets'=>$mergedAssets,
            'merged_rows'=>$mergedRows,
            'merged_amount'=>$mergedAmount,
            'skipped'=>$skipped,
            'events'=>$events,
        ]);
    }

    private function mergeAsset(PDO $pdo, int $positionId, string $asset, array $dustIds): array
    {
        $dustIds = array_values(array_filter(array_map('intval', $dustIds), static fn(int $id): bool => $id > 0));
        if ($positionId <= 0 || $dustIds === []) return ['merged'=>false,'reason'=>'invalid_merge_target'];

        return Database::transaction(function (PDO $tx) use ($positionId, $asset, $dustIds): array {
            $stmt = $tx->prepare("SELECT id,asset,quote_asset,amount,entry_price,entry_fee_quote,stop_loss,take_profit
                FROM nobitex_autotrade_positions WHERE id=:id AND status='open' AND quote_asset='IRT' FOR UPDATE");
            $stmt->execute([':id'=>$positionId]);
            $position = $stmt->fetch();
            if (!$position) return ['merged'=>false,'reason'=>'open_position_changed'];
            if (NobitexPositionReconciler::canonicalAsset((string)$position['asset']) !== $asset) {
                return ['merged'=>false,'reason'=>'asset_changed'];
            }

            $placeholders = implode(',', array_fill(0, count($dustIds), '?'));
            $dustStmt = $tx->prepare("SELECT id,asset,quote_asset,amount,entry_price,entry_fee_quote
                FROM nobitex_autotrade_positions
                WHERE id IN ({$placeholders}) AND status='dust' AND quote_asset='IRT'
                  AND (exit_identifier IS NULL OR exit_identifier='')
                ORDER BY id ASC FOR UPDATE");
            $dustStmt->execute($dustIds);
            $rows = $dustStmt->fetchAll();
            if ($rows === []) return ['merged'=>false,'reason'=>'dust_state_changed'];

            $openAmount = max(0.0, (float)$position['amount']);
            $openPrice = max(0.0, (float)$position['entry_price']);
            if ($openAmount <= self::EPS || $openPrice <= 0.0) return ['merged'=>false,'reason'=>'invalid_open_cost_basis'];

            $dustAmount = 0.0;
            $cost = $openAmount * $openPrice;
            $fees = max(0.0, (float)($position['entry_fee_quote'] ?? 0.0));
            $mergedIds = [];
            foreach ($rows as $row) {
                if (NobitexPositionReconciler::canonicalAsset((string)($row['asset'] ?? '')) !== $asset) continue;
                $amount = max(0.0, (float)($row['amount'] ?? 0.0));
                $price = max(0.0, (float)($row['entry_price'] ?? 0.0));
                if ($amount <= self::EPS || $price <= 0.0) continue;
                $dustAmount += $amount;
                $cost += $amount * $price;
                $fees += max(0.0, (float)($row['entry_fee_quote'] ?? 0.0));
                $mergedIds[] = (int)$row['id'];
            }
            if ($dustAmount <= self::EPS || $mergedIds === []) return ['merged'=>false,'reason'=>'no_valid_dust_rows'];

            $newAmount = $openAmount + $dustAmount;
            $newEntry = $cost / $newAmount;
            $oldStop = max(0.0, (float)($position['stop_loss'] ?? 0.0));
            $oldTake = max(0.0, (float)($position['take_profit'] ?? 0.0));
            $stopRatio = $oldStop > 0.0 ? $oldStop / $openPrice : 0.0;
            $takeRatio = $oldTake > 0.0 ? $oldTake / $openPrice : 0.0;
            $newStop = $stopRatio > 0.0 ? $newEntry * $stopRatio : $oldStop;
            $newTake = $takeRatio > 0.0 ? $newEntry * $takeRatio : $oldTake;

            $update = $tx->prepare("UPDATE nobitex_autotrade_positions
                SET amount=:amount,entry_price=:entry,entry_fee_quote=:fee,entry_fee_source='dust_carry_forward_weighted_basis',
                    stop_loss=:stop,take_profit=:take,updated_at=UTC_TIMESTAMP()
                WHERE id=:id AND status='open'");
            $update->execute([
                ':amount'=>$newAmount,
                ':entry'=>$newEntry,
                ':fee'=>$fees,
                ':stop'=>$newStop,
                ':take'=>$newTake,
                ':id'=>$positionId,
            ]);
            if ($update->rowCount() !== 1) return ['merged'=>false,'reason'=>'open_position_update_conflict'];

            $close = $tx->prepare("UPDATE nobitex_autotrade_positions
                SET status='closed',amount=0,exit_price=NULL,exit_identifier='dust_carried_into_irt_position',
                    exit_order_local_id=NULL,exit_exchange_order_id=NULL,closed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                WHERE id=:id AND status='dust' AND (exit_identifier IS NULL OR exit_identifier='')");
            $closed = 0;
            foreach ($mergedIds as $id) {
                $close->execute([':id'=>$id]);
                $closed += $close->rowCount();
            }
            if ($closed !== count($mergedIds)) {
                throw new \RuntimeException('Managed dust carry-forward state conflict.');
            }

            $event = [
                'model'=>self::MODEL,
                'position_id'=>$positionId,
                'asset'=>$asset,
                'quote_asset'=>'IRT',
                'dust_position_ids'=>$mergedIds,
                'merged_rows'=>$closed,
                'dust_amount'=>$dustAmount,
                'position_amount_before'=>$openAmount,
                'position_amount_after'=>$newAmount,
                'entry_price_before'=>$openPrice,
                'entry_price_after'=>$newEntry,
                'entry_fee_quote_after'=>$fees,
                'pnl_recorded'=>false,
                'destination'=>'future_normal_irt_exit',
            ];
            $this->event($tx, 'info', 'nobitex.managed_dust_carried_forward', $event);
            return ['merged'=>true] + $event;
        });
    }

    private function result(string $status, string $reason, array $extra = []): array
    {
        return ['status'=>$status,'reason'=>$reason,'model'=>self::MODEL] + $extra;
    }

    private function event(PDO $pdo, string $level, string $event, array $context): void
    {
        $stmt = $pdo->prepare('INSERT INTO nobitex_autotrade_events(level,event_name,context_json,created_at) VALUES(:level,:event,:context,UTC_TIMESTAMP())');
        $stmt->execute([
            ':level'=>$level,
            ':event'=>$event,
            ':context'=>json_encode($context, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
    }
}
