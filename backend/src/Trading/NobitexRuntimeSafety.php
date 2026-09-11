<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

/**
 * Runtime safety controller for live Nobitex trading.
 *
 * Entry protection is fail-safe and reduction-only: when the circuit is open,
 * fresh BUY risk is blocked while reconciliation and SELL paths remain active.
 */
final class NobitexRuntimeSafety
{
    private const DEFAULT_PENDING_TIMEOUT_SECONDS = 90;
    private const CIRCUIT_SECONDS = 300;
    private const API_FAILURE_THRESHOLD = 3;

    public function run(?PDO $pdo = null): array
    {
        NobitexSchema::ensure();
        $pdo ??= Database::connection();
        $this->ensureSchema($pdo);

        $watchdog = $this->watchPendingOrders($pdo);
        $reconciliation = $this->strictWalletReconciliation($pdo);
        $adaptive = $this->refreshAdaptiveCapacity($pdo);
        $shadow = $this->evaluateShadowSignals($pdo);

        // Reset the rolling failure counter only when every exchange-facing
        // safety component in this tick succeeded. A success in one component
        // must not erase a repeated failure in another component.
        $apiFailed = (bool)($watchdog['api_failed'] ?? false) || (bool)($reconciliation['api_failed'] ?? false);
        if (!$apiFailed) $this->recordApiSuccess($pdo);

        return [
            'status'=>'ok',
            'entry_circuit'=>$this->entryCircuit($pdo),
            'api_failures'=>$this->intSetting($pdo, 'nobitex_runtime_api_failures', 0, 0, 1000),
            'watchdog'=>$watchdog,
            'reconciliation'=>$reconciliation,
            'adaptive_capacity'=>$adaptive,
            'shadow_evaluation'=>$shadow,
        ];
    }

    public static function adaptiveMaxPositions(int $configured, array $recentPnlPercent): array
    {
        $configured = max(1, min(20, $configured));
        $values = [];
        foreach ($recentPnlPercent as $value) {
            if (!is_numeric($value) || !is_finite((float)$value)) continue;
            $values[] = (float)$value;
            if (count($values) >= 12) break;
        }
        if ($values === []) {
            return ['effective'=>$configured,'multiplier'=>1.0,'reason'=>'insufficient_history','samples'=>0];
        }

        $wins = count(array_filter($values, static fn(float $v): bool => $v > 0.0));
        $winRate = $wins / count($values);
        $average = array_sum($values) / count($values);
        $consecutiveLosses = 0;
        foreach ($values as $value) {
            if ($value < 0.0) $consecutiveLosses++;
            else break;
        }

        $multiplier = 1.0;
        $reason = 'healthy_recent_performance';
        if ($consecutiveLosses >= 4 || ($average < -0.45 && count($values) >= 5)) {
            $multiplier = 0.50;
            $reason = 'drawdown_defensive_mode';
        } elseif ($consecutiveLosses >= 3 || ($winRate < 0.40 && count($values) >= 5) || $average < 0.0) {
            $multiplier = 0.65;
            $reason = 'weak_recent_performance';
        } elseif (($winRate < 0.50 || $average < 0.20) && count($values) >= 5) {
            $multiplier = 0.85;
            $reason = 'cautious_recent_performance';
        }

        $effective = max(1, min($configured, (int)floor($configured * $multiplier)));
        return [
            'effective'=>$effective,
            'multiplier'=>$multiplier,
            'reason'=>$reason,
            'samples'=>count($values),
            'win_rate'=>round($winRate, 4),
            'average_net_pnl_percent'=>round($average, 4),
            'consecutive_losses'=>$consecutiveLosses,
        ];
    }

    private function strictWalletReconciliation(PDO $pdo): array
    {
        try {
            $result = (new NobitexPositionReconciler())->reconcile($pdo);
            $criticalChanges = (int)($result['closed'] ?? 0) + (int)($result['resized'] ?? 0);
            if ($criticalChanges > 0) {
                $this->openCircuit($pdo, 'position_wallet_drift_reconciled', self::CIRCUIT_SECONDS);
            }
            return ['status'=>'ok','critical_changes'=>$criticalChanges,'api_failed'=>false] + $result;
        } catch (\Throwable $e) {
            $failures = $this->recordApiFailure($pdo, 'wallet_reconciliation', $e->getMessage());
            return [
                'status'=>'deferred',
                'error'=>mb_substr($e->getMessage(),0,300),
                'consecutive_failures'=>$failures,
                'api_failed'=>true,
            ];
        }
    }

    private function watchPendingOrders(PDO $pdo): array
    {
        $timeout = $this->intSetting($pdo, 'nobitex_pending_timeout_seconds', self::DEFAULT_PENDING_TIMEOUT_SECONDS, 30, 600);
        $rows = $pdo->query(
            "SELECT id,status,entry_identifier,entry_exchange_order_id,exit_identifier,exit_exchange_order_id,updated_at,created_at
             FROM nobitex_autotrade_positions
             WHERE status IN ('pending_open','pending_close')
             ORDER BY updated_at ASC LIMIT 10"
        )->fetchAll();
        if ($rows === []) return ['status'=>'idle','checked'=>0,'cancel_requested'=>0,'errors'=>0,'api_failed'=>false];

        $orders = new NobitexOrderService();
        if (!$orders->credentialsConfigured()) {
            return ['status'=>'skipped','reason'=>'credentials_missing','checked'=>0,'api_failed'=>false];
        }
        $client = $orders->client();
        $cancelled = 0;
        $errors = 0;
        $details = [];

        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '');
            $started = trim((string)($row['updated_at'] ?? $row['created_at'] ?? ''));
            $startedTs = $started !== '' ? strtotime($started . ' UTC') : false;
            $age = $startedTs === false ? 0 : max(0, time() - $startedTs);
            if ($age < $timeout) continue;

            $remoteId = trim((string)($status === 'pending_open' ? ($row['entry_exchange_order_id'] ?? '') : ($row['exit_exchange_order_id'] ?? '')));
            $identifier = trim((string)($status === 'pending_open' ? ($row['entry_identifier'] ?? '') : ($row['exit_identifier'] ?? '')));
            if ($remoteId === '' && $identifier === '') continue;

            try {
                $response = $remoteId !== '' ? $client->orderStatus($remoteId) : $client->orderStatus(null, $identifier);
                $remote = $orders->normalizedOrder($response);
                $state = strtolower(trim((string)($remote['status'] ?? '')));
                if (NobitexOrderFill::isDone($remote) || in_array($state, ['canceled','cancelled','failed','rejected'], true)) {
                    $details[] = ['position_id'=>(int)$row['id'],'status'=>'terminal_waiting_reconcile','remote_state'=>$state];
                    continue;
                }

                if ($remoteId !== '') $client->cancelOrder($remoteId);
                else $client->cancelOrder(null, $identifier);
                $cancelled++;
                $details[] = ['position_id'=>(int)$row['id'],'status'=>'cancel_requested','age_seconds'=>$age];
                $this->event($pdo, 'warning', 'nobitex.watchdog.cancel_requested', [
                    'position_id'=>(int)$row['id'],'position_status'=>$status,'age_seconds'=>$age,'timeout_seconds'=>$timeout,
                ]);
            } catch (\Throwable $e) {
                $errors++;
                $details[] = ['position_id'=>(int)$row['id'],'status'=>'error','error'=>mb_substr($e->getMessage(),0,180)];
            }
        }

        $failures = null;
        if ($errors > 0) {
            $failures = $this->recordApiFailure($pdo, 'pending_watchdog', $errors.' watchdog error(s)');
        }

        return [
            'status'=>'processed',
            'checked'=>count($rows),
            'cancel_requested'=>$cancelled,
            'errors'=>$errors,
            'api_failed'=>$errors > 0,
            'consecutive_failures'=>$failures,
            'details'=>$details,
        ];
    }

    private function refreshAdaptiveCapacity(PDO $pdo): array
    {
        $configured = $this->intSetting($pdo, 'nobitex_max_positions', 5, 1, 20);
        $rows = $pdo->query(
            "SELECT pnl_percent FROM nobitex_autotrade_pnl
             WHERE accounted_at IS NOT NULL
             ORDER BY id DESC LIMIT 12"
        )->fetchAll();
        $values = array_map(static fn(array $r): mixed => $r['pnl_percent'] ?? null, $rows);
        $decision = self::adaptiveMaxPositions($configured, $values);
        $this->setSetting($pdo, 'nobitex_effective_max_positions', (string)$decision['effective']);
        $this->setSetting($pdo, 'nobitex_effective_max_positions_reason', (string)$decision['reason']);
        return ['configured'=>$configured] + $decision;
    }

    private function evaluateShadowSignals(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT s.id,s.symbol,s.action,s.price,s.created_at,o.signal_id,o.return_15m,o.return_60m,o.return_240m
             FROM nobitex_autotrade_signals s
             LEFT JOIN nobitex_shadow_signal_outcomes o ON o.signal_id=s.id
             WHERE s.created_at <= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)
               AND (o.signal_id IS NULL OR o.return_240m IS NULL)
             ORDER BY s.id DESC LIMIT 80"
        )->fetchAll();
        if ($rows === []) return ['status'=>'idle','checked'=>0,'updated'=>0];

        $updated = 0;
        foreach ($rows as $row) {
            $signalId = (int)$row['id'];
            $symbol = (string)$row['symbol'];
            $entry = max(0.0, (float)$row['price']);
            $created = strtotime((string)$row['created_at'].' UTC');
            if ($entry <= 0.0 || $symbol === '' || $created === false) continue;

            $returns = [];
            foreach ([15,60,240] as $minutes) {
                $field = 'return_'.$minutes.'m';
                if ($row[$field] !== null) continue;
                $targetTs = $created + ($minutes * 60);
                if (time() < $targetTs) continue;

                $stmt = $pdo->prepare(
                    "SELECT price FROM nobitex_autotrade_signals
                     WHERE symbol=:symbol AND created_at>=:target
                     ORDER BY created_at ASC LIMIT 1"
                );
                $stmt->execute([':symbol'=>$symbol,':target'=>gmdate('Y-m-d H:i:s',$targetTs)]);
                $future = $stmt->fetchColumn();
                if (!is_numeric($future) || (float)$future <= 0.0) continue;
                $returns[$field] = (((float)$future - $entry) / $entry) * 100.0;
            }
            if ($returns === [] && $row['signal_id'] !== null) continue;

            $stmt = $pdo->prepare(
                "INSERT INTO nobitex_shadow_signal_outcomes
                    (signal_id,symbol,action,entry_price,return_15m,return_60m,return_240m,created_at,updated_at)
                 VALUES (:id,:symbol,:action,:entry,:r15,:r60,:r240,UTC_TIMESTAMP(),UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                    return_15m=COALESCE(VALUES(return_15m),return_15m),
                    return_60m=COALESCE(VALUES(return_60m),return_60m),
                    return_240m=COALESCE(VALUES(return_240m),return_240m),
                    updated_at=UTC_TIMESTAMP()"
            );
            $stmt->execute([
                ':id'=>$signalId,':symbol'=>$symbol,':action'=>(string)$row['action'],':entry'=>$entry,
                ':r15'=>$returns['return_15m'] ?? null,':r60'=>$returns['return_60m'] ?? null,':r240'=>$returns['return_240m'] ?? null,
            ]);
            $updated++;
        }
        return ['status'=>'processed','checked'=>count($rows),'updated'=>$updated];
    }

    private function entryCircuit(PDO $pdo): array
    {
        $until = trim((string)($this->setting($pdo, 'nobitex_entry_circuit_until') ?? ''));
        $ts = $until !== '' ? strtotime($until . ' UTC') : false;
        $open = $ts !== false && $ts > time();
        return [
            'open'=>$open,
            'until'=>$open ? gmdate(DATE_ATOM, $ts) : null,
            'reason'=>$open ? ($this->setting($pdo, 'nobitex_entry_circuit_reason') ?? 'runtime_safety') : null,
        ];
    }

    private function openCircuit(PDO $pdo, string $reason, int $seconds): void
    {
        $until = gmdate('Y-m-d H:i:s', time() + max(60, $seconds));
        $this->setSetting($pdo, 'nobitex_entry_circuit_until', $until);
        $this->setSetting($pdo, 'nobitex_entry_circuit_reason', $reason);
        $this->event($pdo, 'warning', 'nobitex.runtime.entry_circuit_opened', ['reason'=>$reason,'until'=>$until]);
    }

    private function recordApiFailure(PDO $pdo, string $component, string $message): int
    {
        $count = $this->intSetting($pdo, 'nobitex_runtime_api_failures', 0, 0, 1000) + 1;
        $this->setSetting($pdo, 'nobitex_runtime_api_failures', (string)$count);
        $this->setSetting($pdo, 'nobitex_runtime_last_api_error', mb_substr($component.': '.$message,0,500));
        if ($count >= self::API_FAILURE_THRESHOLD) {
            $this->openCircuit($pdo, 'repeated_exchange_api_failures', self::CIRCUIT_SECONDS);
        }
        return $count;
    }

    private function recordApiSuccess(PDO $pdo): void
    {
        $this->setSetting($pdo, 'nobitex_runtime_api_failures', '0');
    }

    private function ensureSchema(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS nobitex_shadow_signal_outcomes (
                signal_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                symbol VARCHAR(40) NOT NULL,
                action VARCHAR(16) NOT NULL,
                entry_price DECIMAL(36,18) NOT NULL,
                return_15m DECIMAL(14,6) NULL,
                return_60m DECIMAL(14,6) NULL,
                return_240m DECIMAL(14,6) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_shadow_symbol (symbol),
                INDEX idx_shadow_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function setting(PDO $pdo, string $key): ?string
    {
        $stmt=$pdo->prepare('SELECT value_text FROM settings WHERE key_name=:key LIMIT 1');
        $stmt->execute([':key'=>$key]);
        $value=$stmt->fetchColumn();
        return $value===false?null:(string)$value;
    }

    private function setSetting(PDO $pdo, string $key, string $value): void
    {
        $stmt=$pdo->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES (:key,:value,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");
        $stmt->execute([':key'=>$key,':value'=>$value]);
    }

    private function intSetting(PDO $pdo,string $key,int $default,int $min,int $max):int
    {
        $value=$this->setting($pdo,$key);
        return is_numeric($value)?max($min,min($max,(int)$value)):$default;
    }

    private function event(PDO $pdo,string $level,string $event,array $context=[]):void
    {
        $pdo->prepare('INSERT INTO nobitex_autotrade_events (level,event_name,context_json,created_at) VALUES (:level,:event,:context,UTC_TIMESTAMP())')->execute([
            ':level'=>$level,
            ':event'=>$event,
            ':context'=>$context===[]?null:json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
    }
}
