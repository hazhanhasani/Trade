<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;
use Trade\Exchange\NobitexClient;

/**
 * Prevents small Trade-owned exchange remainders from freezing live trading.
 *
 * Three cases are handled:
 *  1) an OPEN remainder is below Nobitex's live amount/minimum-order rules;
 *  2) a prior partial exit left a still-sellable OPEN remainder;
 *  3) a completed normalized SELL closed the DB row although amount-step
 *     rounding left a smaller real wallet balance behind.
 *
 * Unsellable managed remainders are status=dust, so they do not consume active
 * position count. No exit price or synthetic PnL is created merely by retiring
 * or recovering dust. A real SELL is submitted only when that row's own amount
 * is independently legal under current Nobitex rules, and at most one cleanup
 * SELL is submitted per run.
 */
final class NobitexResidualDustManager
{
    public const MODEL = 'nobitex_residual_dust_manager_v3';
    private const MAX_ROWS = 50;
    private const MAX_CLOSED_RECOVERY_ROWS = 100;
    private const MAX_SWEEPS_PER_RUN = 1;
    private const EPSILON = 0.000000000001;

    public function __construct(private readonly NobitexOrderService $orders = new NobitexOrderService()) {}

    public function reconcile(?PDO $pdo = null): array
    {
        NobitexSchema::ensure();
        $pdo ??= Database::connection();

        if (!NobitexSchema::botEnabled('nobitex')) return $this->result('blocked', 'bot_disabled');
        if ($this->boolSetting($pdo, 'kill_switch')) return $this->result('blocked', 'kill_switch');
        if (!$this->orders->credentialsConfigured() || !$this->orders->liveEnabled()) {
            return $this->result('blocked', 'live_execution_unavailable');
        }

        $open = $pdo->query(
            "SELECT p.id,p.symbol,p.asset,p.quote_asset,p.amount,p.entry_price,p.opened_at,p.realized_pnl,
                    (SELECT COUNT(*) FROM nobitex_autotrade_pnl x WHERE x.position_id=p.id) AS exit_fill_count
             FROM nobitex_autotrade_positions p
             WHERE p.status='open' ORDER BY p.id ASC LIMIT " . self::MAX_ROWS
        )->fetchAll();
        $dust = $pdo->query(
            "SELECT id,symbol,asset,quote_asset,amount,entry_price,opened_at,realized_pnl FROM nobitex_autotrade_positions
             WHERE status='dust' ORDER BY id ASC LIMIT " . self::MAX_ROWS
        )->fetchAll();
        $closedCandidates = $pdo->query(
            "SELECT p.id,p.symbol,p.asset,p.quote_asset,p.amount,p.entry_price,p.opened_at,p.realized_pnl,
                    p.exit_identifier,p.exit_exchange_order_id,p.closed_at,
                    (SELECT x.amount FROM nobitex_autotrade_pnl x WHERE x.position_id=p.id ORDER BY x.id DESC LIMIT 1) AS last_exit_amount
             FROM nobitex_autotrade_positions p
             WHERE p.status='closed' AND p.exit_exchange_order_id IS NOT NULL
               AND p.closed_at IS NOT NULL AND p.closed_at >= (UTC_TIMESTAMP() - INTERVAL 30 DAY)
             ORDER BY p.id DESC LIMIT " . self::MAX_CLOSED_RECOVERY_ROWS
        )->fetchAll();

        if ($open === [] && $dust === [] && $closedCandidates === []) {
            return $this->result('idle', 'no_managed_residual_candidates', [
                'checked'=>0,'retired'=>0,'closed_residuals_recovered'=>0,'partial_exit_candidates'=>0,
                'sweep_submitted'=>0,'depleted_closed'=>0,'deferred'=>0,
            ]);
        }

        $client = $this->orders->client();
        $wallets = $client->wallets();
        $walletTotals = NobitexPositionReconciler::walletTotals($wallets);
        $books = $client->allOrderBooks();

        $checked = 0;
        $retired = 0;
        $deferred = 0;
        $depletedClosed = 0;
        $events = [];

        // Recover bot-owned amount-step leftovers from recently closed positions.
        // We never claim more than both (a) the mathematically known difference
        // between the row's pre-final amount and its final PnL fill amount and
        // (b) wallet inventory not already assigned to a managed live/dust row.
        $closedRecovery = $this->recoverClosedResiduals($pdo, $closedCandidates, $walletTotals);
        $closedResidualsRecovered = (int)($closedRecovery['recovered'] ?? 0);
        if (is_array($closedRecovery['events'] ?? null)) {
            $events = array_merge($events, $closedRecovery['events']);
        }

        $partialExitCandidates = [];
        foreach ($open as $position) {
            $assessment = $this->assessPosition($client, $books, $position);
            if (($assessment['status'] ?? '') === 'deferred') {
                $deferred++;
                continue;
            }
            $checked++;

            if (($assessment['dust'] ?? false) === true) {
                $retiredEvent = $this->retireOpenAsDust($pdo, $position, $assessment);
                if ($retiredEvent !== null) {
                    $retired++;
                    $events[] = $retiredEvent;
                }
                continue;
            }

            // A confirmed PnL fill on a row that is still open proves the
            // previous exit only partially completed. Continue that exact exit
            // instead of leaving a tiny permanent HOLD.
            if ((int)($position['exit_fill_count'] ?? 0) > 0) {
                $partialExitCandidates[] = $position;
            }
        }

        $sweepSubmitted = 0;
        foreach ($partialExitCandidates as $position) {
            if ($sweepSubmitted >= self::MAX_SWEEPS_PER_RUN) break;
            $sweep = $this->submitSweep(
                $pdo, $client, $books, $wallets, $position, 'open', 'partial_exit_residual_cleanup'
            );
            $status = (string)($sweep['status'] ?? 'deferred');
            if ($status === 'submitted') {
                $sweepSubmitted++;
                $events[] = (array)$sweep['event'];
            } elseif ($status === 'retired') {
                $retired++;
                $events[] = (array)$sweep['event'];
            } elseif ($status === 'deferred') {
                $deferred++;
            }
        }

        // Fresh query includes rows retired above and rows recovered from a
        // formerly closed position. Dust is never topped up just to manufacture
        // a sell; it is swept only if its own quantity is now legal.
        $dustRows = $pdo->query(
            "SELECT id,symbol,asset,quote_asset,amount,entry_price,opened_at,realized_pnl FROM nobitex_autotrade_positions
             WHERE status='dust' ORDER BY id ASC LIMIT " . self::MAX_ROWS
        )->fetchAll();
        foreach ($dustRows as $position) {
            if ($sweepSubmitted >= self::MAX_SWEEPS_PER_RUN) break;
            $symbol = strtoupper((string)($position['symbol'] ?? ''));
            $asset = NobitexPositionReconciler::canonicalAsset((string)($position['asset'] ?? ''));
            $total = max(0.0, (float)($walletTotals[$asset] ?? 0.0));

            if ($asset !== '' && $total <= self::EPSILON) {
                $stmt = $pdo->prepare(
                    "UPDATE nobitex_autotrade_positions
                     SET status='closed',exit_price=NULL,exit_identifier='residual_dust_depleted',
                         exit_order_local_id=NULL,exit_exchange_order_id=NULL,closed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                     WHERE id=:id AND status='dust'"
                );
                $stmt->execute([':id'=>(int)$position['id']]);
                if ($stmt->rowCount() === 1) {
                    $depletedClosed++;
                    $event = [
                        'type'=>'dust_depleted','position_id'=>(int)$position['id'],'symbol'=>$symbol,'asset'=>$asset,
                        'reason'=>'real_wallet_balance_depleted','pnl_recorded'=>false,
                    ];
                    $this->event($pdo, 'info', 'nobitex.position.residual_dust_depleted', $event);
                    $events[] = $event;
                }
                continue;
            }

            $sweep = $this->submitSweep(
                $pdo, $client, $books, $wallets, $position, 'dust', 'residual_became_sellable'
            );
            $status = (string)($sweep['status'] ?? 'deferred');
            if ($status === 'submitted') {
                $sweepSubmitted++;
                $events[] = (array)$sweep['event'];
            } elseif ($status === 'deferred') {
                $deferred++;
            }
        }

        return $this->result('ok', 'reconciled', [
            'checked'=>$checked,
            'retired'=>$retired,
            'closed_residuals_recovered'=>$closedResidualsRecovered,
            'partial_exit_candidates'=>count($partialExitCandidates),
            'sweep_submitted'=>$sweepSubmitted,
            'depleted_closed'=>$depletedClosed,
            'deferred'=>$deferred,
            'events'=>$events,
        ]);
    }

    /**
     * Pure classification helper. `below_minimum` comes from live /v2/options;
     * amount-step rejection is also terminal for a standalone residual SELL.
     */
    public static function dustAssessment(array $prepared): array
    {
        $valid = (bool)($prepared['valid'] ?? false);
        $reason = (string)($prepared['reason'] ?? '');
        $rules = is_array($prepared['rules'] ?? null) ? $prepared['rules'] : [];
        $belowMinimum = (bool)($rules['below_minimum'] ?? false);
        $belowStep = !$valid && in_array($reason, ['amount_below_exchange_step','invalid_amount'], true);
        $dust = $belowMinimum || $belowStep;

        return [
            'dust'=>$dust,
            'reason'=>$belowStep ? $reason : ($belowMinimum ? 'below_exchange_minimum' : 'tradable'),
            'estimated_order_value'=>is_numeric($rules['estimated_order_value'] ?? null) ? (float)$rules['estimated_order_value'] : null,
            'min_order_quote'=>is_numeric($rules['min_order_quote'] ?? null) ? (float)$rules['min_order_quote'] : null,
            'normalized_amount'=>is_numeric($rules['normalized_amount'] ?? null) ? (float)$rules['normalized_amount'] : null,
            'amount_step'=>is_numeric($rules['amount_step'] ?? null) ? (float)$rules['amount_step'] : null,
        ];
    }

    private function recoverClosedResiduals(PDO $pdo, array $closedCandidates, array $walletTotals): array
    {
        if ($closedCandidates === []) return ['recovered'=>0,'events'=>[]];

        $assignedRows = $pdo->query(
            "SELECT asset,amount FROM nobitex_autotrade_positions
             WHERE status IN ('pending_open','open','pending_close','dust')"
        )->fetchAll();
        $assigned = [];
        foreach ($assignedRows as $row) {
            $asset = NobitexPositionReconciler::canonicalAsset((string)($row['asset'] ?? ''));
            if ($asset === '') continue;
            $assigned[$asset] = ($assigned[$asset] ?? 0.0) + max(0.0, (float)($row['amount'] ?? 0.0));
        }

        $unassigned = [];
        foreach ($walletTotals as $asset=>$total) {
            $asset = NobitexPositionReconciler::canonicalAsset((string)$asset);
            $unassigned[$asset] = max(0.0, (float)$total - (float)($assigned[$asset] ?? 0.0));
        }

        $recovered = 0;
        $events = [];
        foreach ($closedCandidates as $position) {
            $lastExitAmount = is_numeric($position['last_exit_amount'] ?? null) ? max(0.0, (float)$position['last_exit_amount']) : 0.0;
            $preFinalAmount = max(0.0, (float)($position['amount'] ?? 0.0));
            $knownResidual = max(0.0, $preFinalAmount - $lastExitAmount);
            if ($knownResidual <= self::EPSILON) continue;

            $asset = NobitexPositionReconciler::canonicalAsset((string)($position['asset'] ?? ''));
            $availableOrphan = max(0.0, (float)($unassigned[$asset] ?? 0.0));
            $recoverAmount = min($knownResidual, $availableOrphan);
            $tolerance = max(self::EPSILON, $knownResidual * 0.001);
            if ($recoverAmount <= $tolerance) continue;

            $stmt = $pdo->prepare(
                "UPDATE nobitex_autotrade_positions
                 SET status='dust',amount=:amount,exit_price=NULL,exit_identifier='closed_residual_recovered',
                     exit_order_local_id=NULL,exit_exchange_order_id=NULL,closed_at=NULL,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status='closed'"
            );
            $stmt->execute([':amount'=>$recoverAmount,':id'=>(int)$position['id']]);
            if ($stmt->rowCount() !== 1) continue;

            $unassigned[$asset] = max(0.0, $availableOrphan - $recoverAmount);
            $recovered++;
            $event = [
                'type'=>'closed_residual_recovered',
                'position_id'=>(int)$position['id'],
                'symbol'=>strtoupper((string)($position['symbol'] ?? '')),
                'asset'=>$asset,
                'pre_final_position_amount'=>$preFinalAmount,
                'last_confirmed_exit_amount'=>$lastExitAmount,
                'known_rounding_residual'=>$knownResidual,
                'recovered_wallet_amount'=>$recoverAmount,
                'reason'=>'final_sell_amount_step_residual',
                'capacity_released'=>true,
                'pnl_recorded'=>false,
            ];
            $this->event($pdo, 'info', 'nobitex.position.closed_residual_recovered', $event);
            $events[] = $event;
        }

        return ['recovered'=>$recovered,'events'=>$events];
    }

    private function submitSweep(
        PDO $pdo,
        NobitexClient $client,
        array $books,
        array $wallets,
        array $position,
        string $expectedStatus,
        string $reason
    ): array {
        $symbol = strtoupper((string)($position['symbol'] ?? ''));
        $asset = NobitexPositionReconciler::canonicalAsset((string)($position['asset'] ?? ''));
        if ($symbol === '' || $asset === '') return ['status'=>'deferred','reason'=>'invalid_position_shape'];
        if ($this->pendingOrderExists($pdo, $symbol)) return ['status'=>'deferred','reason'=>'sell_order_already_pending'];

        $available = $this->walletAvailable($wallets, $asset);
        $amount = min(max(0.0, (float)($position['amount'] ?? 0.0)), $available);
        if ($amount <= self::EPSILON) return ['status'=>'deferred','reason'=>'wallet_balance_unavailable'];

        $candidate = $position;
        $candidate['amount'] = $amount;
        $assessment = $this->assessPosition($client, $books, $candidate);
        if (($assessment['status'] ?? '') === 'deferred') return ['status'=>'deferred','reason'=>$assessment['reason'] ?? 'assessment_deferred'];

        if (($assessment['dust'] ?? false) === true) {
            if ($expectedStatus === 'open') {
                $event = $this->retireOpenAsDust($pdo, $position, $assessment);
                return $event === null ? ['status'=>'deferred','reason'=>'state_conflict'] : ['status'=>'retired','event'=>$event];
            }
            return ['status'=>'still_dust','reason'=>$assessment['reason'] ?? 'below_exchange_minimum'];
        }

        $bestBid = (float)($assessment['best_bid'] ?? 0.0);
        if ($bestBid <= 0.0) return ['status'=>'deferred','reason'=>'orderbook_bid_unavailable'];
        $identifier = 'nd' . gmdate('ymdHis') . substr(bin2hex(random_bytes(5)), 0, 10);

        try {
            $created = $this->orders->create([
                'symbol'=>$symbol,
                'amount1'=>$amount,
                'price'=>max(0.00000001, $bestBid * 0.992),
                'mode'=>'market',
                'type'=>'sell',
                'identifier'=>$identifier,
            ], 'autotrade_nobitex_dust_sweep');
        } catch (NobitexCandidateRejectedException $e) {
            return ['status'=>'deferred','reason'=>$e->reasonCode(),'assessment'=>$e->assessment()];
        }

        $remote = is_array($created['order'] ?? null) ? $created['order'] : [];
        $exchangeId = trim((string)($remote['id'] ?? ''));
        $stmt = $pdo->prepare(
            "UPDATE nobitex_autotrade_positions
             SET status='pending_close',exit_identifier=:identifier,exit_order_local_id=:local,
                 exit_exchange_order_id=:exchange_id,updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND status=:expected_status"
        );
        $stmt->execute([
            ':identifier'=>$identifier,
            ':local'=>$created['local_id'] ?? null,
            ':exchange_id'=>$exchangeId !== '' ? $exchangeId : null,
            ':id'=>(int)$position['id'],
            ':expected_status'=>$expectedStatus,
        ]);
        if ($stmt->rowCount() !== 1) return ['status'=>'deferred','reason'=>'state_reconcile_required'];

        $event = [
            'type'=>'dust_sell_submitted','position_id'=>(int)$position['id'],'symbol'=>$symbol,'asset'=>$asset,'amount'=>$amount,
            'best_bid'=>$bestBid,'order_local_id'=>$created['local_id'] ?? null,
            'exchange_order_id'=>$exchangeId !== '' ? $exchangeId : null,'reason'=>$reason,
        ];
        $this->event($pdo, 'info', 'nobitex.position.residual_dust_sell_submitted', $event);
        return ['status'=>'submitted','event'=>$event];
    }

    private function retireOpenAsDust(PDO $pdo, array $position, array $assessment): ?array
    {
        $stmt = $pdo->prepare(
            "UPDATE nobitex_autotrade_positions
             SET status='dust',exit_price=NULL,exit_identifier='residual_dust_retired',
                 exit_order_local_id=NULL,exit_exchange_order_id=NULL,closed_at=NULL,updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND status='open'"
        );
        $stmt->execute([':id'=>(int)$position['id']]);
        if ($stmt->rowCount() !== 1) return null;

        $event = [
            'type'=>'dust_retired',
            'position_id'=>(int)$position['id'],
            'symbol'=>strtoupper((string)$position['symbol']),
            'asset'=>(string)($position['asset'] ?? ''),
            'amount'=>(float)($position['amount'] ?? 0),
            'reason'=>$assessment['reason'] ?? 'below_exchange_minimum',
            'estimated_order_value'=>$assessment['estimated_order_value'] ?? null,
            'min_order_quote'=>$assessment['min_order_quote'] ?? null,
            'amount_step'=>$assessment['amount_step'] ?? null,
            'capacity_released'=>true,
            'pnl_recorded'=>false,
        ];
        $this->event($pdo, 'info', 'nobitex.position.residual_dust_retired', $event);
        return $event;
    }

    private function assessPosition(NobitexClient $client, array $books, array $position): array
    {
        $symbol = strtoupper((string)($position['symbol'] ?? ''));
        $quote = strtoupper((string)($position['quote_asset'] ?? ''));
        $amount = max(0.0, (float)($position['amount'] ?? 0.0));
        if ($symbol === '' || !in_array($quote, ['IRT','USDT'], true) || $amount <= 0.0) {
            return ['status'=>'deferred','reason'=>'invalid_position_shape'];
        }

        $bestBid = self::bestBid(self::book($books, $symbol));
        if ($bestBid <= 0.0) return ['status'=>'deferred','reason'=>'orderbook_bid_unavailable'];
        $base = self::baseFromSymbol($symbol, $quote, (string)($position['asset'] ?? ''));
        if ($base === '') return ['status'=>'deferred','reason'=>'base_asset_unavailable'];

        try {
            $prepared = $client->prepareOrder([
                'type'=>'sell',
                'srcCurrency'=>strtolower($base),
                'dstCurrency'=>strtolower($quote === 'IRT' ? 'rls' : $quote),
                'amount'=>(string)$amount,
                'price'=>(string)$bestBid,
                'execution'=>'market',
            ]);
        } catch (\Throwable $e) {
            return ['status'=>'deferred','reason'=>'order_rules_unavailable','error'=>mb_substr($e->getMessage(),0,240)];
        }

        return ['status'=>'ok','best_bid'=>$bestBid] + self::dustAssessment($prepared);
    }

    private function walletAvailable(array $response, string $canonicalAsset): float
    {
        $canonicalAsset = NobitexPositionReconciler::canonicalAsset($canonicalAsset);
        $rows = $response['wallets'] ?? $response['data'] ?? $response;
        if (is_array($rows) && !array_is_list($rows) && is_array($rows['wallets'] ?? null)) $rows = $rows['wallets'];
        if (!is_array($rows)) return 0.0;

        $sum = 0.0;
        foreach ($rows as $key=>$row) {
            if (!is_array($row)) continue;
            $raw = strtoupper((string)($row['currency'] ?? $row['asset'] ?? $row['currencyCode'] ?? (is_string($key) ? $key : '')));
            if (NobitexPositionReconciler::canonicalAsset($raw) !== $canonicalAsset) continue;
            foreach (['activeBalance','available','free','balance'] as $field) {
                if (array_key_exists($field, $row) && is_numeric($row[$field])) {
                    $sum += max(0.0, (float)$row[$field]);
                    break;
                }
            }
        }
        return $sum;
    }

    private function pendingOrderExists(PDO $pdo, string $symbol): bool
    {
        $stmt = $pdo->prepare(
            "SELECT EXISTS(SELECT 1 FROM orders WHERE exchange_name='nobitex' AND market_code=:symbol
             AND side='sell' AND status IN ('submitting','submitted','pending'))"
        );
        $stmt->execute([':symbol'=>$symbol]);
        return (bool)$stmt->fetchColumn();
    }

    private static function baseFromSymbol(string $symbol, string $quote, string $fallbackAsset): string
    {
        $symbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', $symbol) ?? '');
        $quote = strtoupper(trim($quote));
        if ($quote !== '' && str_ends_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
            $base = substr($symbol, 0, -strlen($quote));
            return match ($base) { 'GRAM','TONCOIN'=>'TON', default=>$base };
        }
        return NobitexPositionReconciler::canonicalAsset($fallbackAsset);
    }

    private static function book(array $books, string $symbol): array
    {
        foreach ([$symbol, strtolower($symbol)] as $key) {
            if (isset($books[$key]) && is_array($books[$key])) return $books[$key];
        }
        if (isset($books['orderbooks']) && is_array($books['orderbooks'])) {
            foreach ([$symbol, strtolower($symbol)] as $key) {
                if (isset($books['orderbooks'][$key]) && is_array($books['orderbooks'][$key])) return $books['orderbooks'][$key];
            }
        }
        return [];
    }

    private static function bestBid(array $book): float
    {
        $bids = $book['bids'] ?? [];
        if (!is_array($bids) || $bids === []) return 0.0;
        $first = $bids[0] ?? null;
        if (!is_array($first)) return 0.0;
        $price = $first[0] ?? $first['price'] ?? null;
        return is_numeric($price) ? max(0.0, (float)$price) : 0.0;
    }

    private function boolSetting(PDO $pdo, string $key): bool
    {
        $stmt = $pdo->prepare('SELECT value_text FROM settings WHERE key_name=:key LIMIT 1');
        $stmt->execute([':key'=>$key]);
        return in_array(strtolower(trim((string)($stmt->fetchColumn() ?: '0'))), ['1','true','yes','on'], true);
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

    private function result(string $status, string $reason, array $extra = []): array
    {
        return [
            'status'=>$status,
            'reason'=>$reason,
            'exchange'=>'nobitex',
            'model'=>self::MODEL,
            'time_utc'=>gmdate(DATE_ATOM),
        ] + $extra;
    }
}
