<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;
use Trade\Exchange\NobitexClient;

/**
 * Keeps exchange-minimum residuals from deadlocking live auto-trading.
 *
 * A partially filled/cancelled SELL can leave a real wallet remainder that is
 * too small for Nobitex to accept as a standalone order. Such a remainder is
 * not a useful live position: it cannot be exited, but if it stays `open` it
 * consumes a configured position slot and can block every future BUY.
 *
 * This manager classifies only Trade-owned open positions against Nobitex's
 * live amount/minimum-order rules. Unsellable rows become `dust`, which releases
 * the active slot without inventing an exit price or PnL. A dust row is swept
 * automatically later if its real wallet balance becomes independently sellable.
 */
final class NobitexResidualDustManager
{
    public const MODEL = 'nobitex_residual_dust_manager_v1';
    private const MAX_ROWS = 50;
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
            "SELECT id,symbol,asset,quote_asset,amount FROM nobitex_autotrade_positions
             WHERE status='open' ORDER BY id ASC LIMIT " . self::MAX_ROWS
        )->fetchAll();
        $dust = $pdo->query(
            "SELECT id,symbol,asset,quote_asset,amount FROM nobitex_autotrade_positions
             WHERE status='dust' ORDER BY id ASC LIMIT " . self::MAX_ROWS
        )->fetchAll();
        if ($open === [] && $dust === []) {
            return $this->result('idle', 'no_open_or_dust_positions', [
                'checked'=>0,'retired'=>0,'sweep_submitted'=>0,'depleted_closed'=>0,'deferred'=>0,
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

        // Phase 1: release active capacity held by positions Nobitex cannot sell
        // as standalone orders under the exchange's current live rules.
        foreach ($open as $position) {
            $assessment = $this->assessPosition($client, $books, $position);
            if (($assessment['status'] ?? '') === 'deferred') {
                $deferred++;
                continue;
            }
            $checked++;
            if (($assessment['dust'] ?? false) !== true) continue;

            $stmt = $pdo->prepare(
                "UPDATE nobitex_autotrade_positions
                 SET status='dust',exit_price=NULL,exit_identifier='residual_dust_retired',
                     exit_order_local_id=NULL,exit_exchange_order_id=NULL,closed_at=NULL,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status='open'"
            );
            $stmt->execute([':id'=>(int)$position['id']]);
            if ($stmt->rowCount() !== 1) continue;

            $retired++;
            $event = [
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
            $events[] = ['type'=>'dust_retired'] + $event;
        }

        // Re-read after classification so a newly retired row is eligible for a
        // future sweep immediately if rules changed between scans. At most one
        // real SELL is submitted per run to avoid stacking ambiguous exits.
        $dustRows = $pdo->query(
            "SELECT id,symbol,asset,quote_asset,amount FROM nobitex_autotrade_positions
             WHERE status='dust' ORDER BY id ASC LIMIT " . self::MAX_ROWS
        )->fetchAll();
        $sweepSubmitted = 0;

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
                        'position_id'=>(int)$position['id'],'symbol'=>$symbol,'asset'=>$asset,
                        'reason'=>'real_wallet_balance_depleted','pnl_recorded'=>false,
                    ];
                    $this->event($pdo, 'info', 'nobitex.position.residual_dust_depleted', $event);
                    $events[] = ['type'=>'dust_depleted'] + $event;
                }
                continue;
            }

            if ($this->pendingOrderExists($pdo, $symbol)) {
                $deferred++;
                continue;
            }

            $available = $this->walletAvailable($wallets, $asset);
            $amount = min(max(0.0, (float)($position['amount'] ?? 0.0)), $available);
            if ($amount <= self::EPSILON) {
                $deferred++;
                continue;
            }

            $candidate = $position;
            $candidate['amount'] = $amount;
            $assessment = $this->assessPosition($client, $books, $candidate);
            if (($assessment['status'] ?? '') === 'deferred') {
                $deferred++;
                continue;
            }
            if (($assessment['dust'] ?? false) === true) continue;

            $bestBid = (float)($assessment['best_bid'] ?? 0.0);
            if ($bestBid <= 0.0) {
                $deferred++;
                continue;
            }

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
                // Live exchange rules are authoritative. If the amount became
                // untradable between preflight and submit, keep it as dust.
                $deferred++;
                $events[] = [
                    'type'=>'dust_sweep_deferred','position_id'=>(int)$position['id'],'symbol'=>$symbol,
                    'reason'=>$e->reasonCode(),
                ];
                continue;
            }

            $remote = is_array($created['order'] ?? null) ? $created['order'] : [];
            $exchangeId = trim((string)($remote['id'] ?? ''));
            $stmt = $pdo->prepare(
                "UPDATE nobitex_autotrade_positions
                 SET status='pending_close',exit_identifier=:identifier,exit_order_local_id=:local,
                     exit_exchange_order_id=:exchange_id,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status='dust'"
            );
            $stmt->execute([
                ':identifier'=>$identifier,
                ':local'=>$created['local_id'] ?? null,
                ':exchange_id'=>$exchangeId !== '' ? $exchangeId : null,
                ':id'=>(int)$position['id'],
            ]);
            if ($stmt->rowCount() !== 1) {
                // The remote SELL may already exist; normal order/position
                // reconciliation will recover it from the persisted local order.
                $deferred++;
                continue;
            }

            $sweepSubmitted++;
            $event = [
                'position_id'=>(int)$position['id'],'symbol'=>$symbol,'asset'=>$asset,'amount'=>$amount,
                'best_bid'=>$bestBid,'order_local_id'=>$created['local_id'] ?? null,
                'exchange_order_id'=>$exchangeId !== '' ? $exchangeId : null,
                'reason'=>'residual_became_sellable',
            ];
            $this->event($pdo, 'info', 'nobitex.position.residual_dust_sell_submitted', $event);
            $events[] = ['type'=>'dust_sell_submitted'] + $event;
        }

        return $this->result('ok', 'reconciled', [
            'checked'=>$checked,
            'retired'=>$retired,
            'sweep_submitted'=>$sweepSubmitted,
            'depleted_closed'=>$depletedClosed,
            'deferred'=>$deferred,
            'events'=>$events,
        ]);
    }

    /**
     * Pure classification helper used by regression tests. `below_minimum` is
     * emitted by NobitexOrderRules from live /v2/options data; amount-step
     * rejection is also terminal for a standalone residual SELL.
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
