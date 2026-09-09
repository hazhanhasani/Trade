<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

final class BotController
{
    public function __construct(private readonly OrderService $orders = new OrderService())
    {
    }

    public function status(): array
    {
        Schema::ensure();
        $pdo = Database::connection();
        $settings = Schema::settings($pdo);
        $risk = (new RiskManager())->normalizeSettings($settings);
        $settings = array_merge($settings, $risk);

        $position = $pdo->query(
            "SELECT * FROM autotrade_positions WHERE status IN ('pending_open','open','pending_close') ORDER BY id DESC LIMIT 1"
        )->fetch() ?: null;
        $lastSignal = $pdo->query('SELECT * FROM autotrade_signals ORDER BY id DESC LIMIT 1')->fetch() ?: null;
        $lastRun = $pdo->query('SELECT run_id,status,summary_json,started_at,finished_at FROM bot_runs ORDER BY id DESC LIMIT 1')->fetch() ?: null;
        $quote = (string) $settings['quote_asset'];
        $pnlStmt = $pdo->prepare("SELECT COALESCE(SUM(pnl),0) FROM autotrade_pnl WHERE quote_asset=:quote AND created_at >= UTC_DATE()");
        $pnlStmt->execute([':quote' => $quote]);
        $todayPnl = (float) $pnlStmt->fetchColumn();
        $pnlStmt = $pdo->prepare('SELECT COALESCE(SUM(pnl),0) FROM autotrade_pnl WHERE quote_asset=:quote');
        $pnlStmt->execute([':quote' => $quote]);
        $totalPnl = (float) $pnlStmt->fetchColumn();
        $closed = (int) $pdo->query("SELECT COUNT(*) FROM autotrade_positions WHERE status='closed'")->fetchColumn();
        $wins = (int) $pdo->query("SELECT COUNT(*) FROM autotrade_positions WHERE status='closed' AND realized_pnl > 0")->fetchColumn();
        $kill = (string) ($pdo->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn() ?: '0') === '1';

        if ($lastSignal && isset($lastSignal['details_json'])) {
            $lastSignal['details'] = json_decode((string) $lastSignal['details_json'], true);
            unset($lastSignal['details_json']);
        }
        if ($lastRun && isset($lastRun['summary_json'])) {
            $lastRun['summary'] = json_decode((string) $lastRun['summary_json'], true);
            unset($lastRun['summary_json']);
        }

        return [
            'asset' => 'GRAM',
            'legacy_alias' => 'TON',
            'execution_mode' => 'live_only',
            'bot_enabled' => (bool) $settings['enabled'],
            'live_execution_enabled' => $this->orders->liveEnabled(),
            'kill_switch' => $kill,
            'settings' => $settings,
            'position' => $position,
            'last_signal' => $lastSignal,
            'last_run' => $lastRun,
            'performance' => [
                'quote_asset' => $quote,
                'today_realized_pnl' => $todayPnl,
                'total_realized_pnl' => $totalPnl,
                'closed_positions' => $closed,
                'winning_positions' => $wins,
                'win_rate_percent' => $closed > 0 ? round(($wins / $closed) * 100, 2) : 0.0,
            ],
        ];
    }

    public function setEnabled(bool $enabled): void
    {
        Schema::ensure();
        $stmt = Database::connection()->prepare('UPDATE autotrade_settings SET enabled=:enabled,updated_at=UTC_TIMESTAMP() WHERE id=1');
        $stmt->execute([':enabled' => $enabled ? 1 : 0]);
        $this->audit($enabled ? 'autotrade.enabled' : 'autotrade.disabled');
    }

    public function setLiveEnabled(bool $enabled): void
    {
        Schema::ensure();
        $stmt = Database::connection()->prepare(
            "INSERT INTO settings (key_name,value_text,updated_at) VALUES ('live_trading_enabled',:value,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()"
        );
        $stmt->execute([':value' => $enabled ? '1' : '0']);
        $this->audit($enabled ? 'live_trading.enabled' : 'live_trading.disabled');
    }

    public function setKillSwitch(bool $enabled): void
    {
        $stmt = Database::connection()->prepare(
            "INSERT INTO settings (key_name,value_text,updated_at) VALUES ('kill_switch',:value,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()"
        );
        $stmt->execute([':value' => $enabled ? '1' : '0']);
        $this->audit($enabled ? 'kill_switch.enabled' : 'kill_switch.disabled');
    }

    public function updateSettings(array $input): array
    {
        Schema::ensure();
        $pdo = Database::connection();
        $current = Schema::settings($pdo);

        $quote = strtoupper(trim((string) ($input['quote_asset'] ?? $current['quote_asset'])));
        if (!preg_match('/^[A-Z0-9]{2,12}$/', $quote)) {
            throw new \InvalidArgumentException('Invalid quote asset.');
        }
        $profile = strtolower(trim((string) ($input['risk_profile'] ?? $current['risk_profile'])));
        if (!in_array($profile, ['safe', 'balanced', 'aggressive'], true)) {
            throw new \InvalidArgumentException('risk_profile must be safe, balanced or aggressive.');
        }

        $candidate = [
            'risk_profile' => $profile,
            'position_percent' => $this->number($input, 'position_percent', (float) $current['position_percent']),
            'max_position_percent' => $this->number($input, 'max_position_percent', (float) $current['max_position_percent']),
            'stop_loss_percent' => $this->number($input, 'stop_loss_percent', (float) $current['stop_loss_percent']),
            'take_profit_percent' => $this->number($input, 'take_profit_percent', (float) $current['take_profit_percent']),
            'daily_loss_limit_percent' => $this->number($input, 'daily_loss_limit_percent', (float) $current['daily_loss_limit_percent']),
            'min_signal_score' => (int) $this->number($input, 'min_signal_score', (float) $current['min_signal_score']),
            'cooldown_minutes' => (int) $this->number($input, 'cooldown_minutes', (float) $current['cooldown_minutes']),
        ];
        $normalized = (new RiskManager())->normalizeSettings($candidate);

        $stmt = $pdo->prepare(
            'UPDATE autotrade_settings SET quote_asset=:quote,risk_profile=:profile,position_percent=:position,max_position_percent=:max_position,stop_loss_percent=:stop,take_profit_percent=:take,daily_loss_limit_percent=:daily,min_signal_score=:score,cooldown_minutes=:cooldown,updated_at=UTC_TIMESTAMP() WHERE id=1'
        );
        $stmt->execute([
            ':quote' => $quote,
            ':profile' => $normalized['risk_profile'],
            ':position' => $normalized['position_percent'],
            ':max_position' => $normalized['max_position_percent'],
            ':stop' => $normalized['stop_loss_percent'],
            ':take' => $normalized['take_profit_percent'],
            ':daily' => $normalized['daily_loss_limit_percent'],
            ':score' => $normalized['min_signal_score'],
            ':cooldown' => $normalized['cooldown_minutes'],
        ]);
        $this->audit('autotrade.settings_updated', $normalized + ['quote_asset' => $quote]);
        return Schema::settings($pdo);
    }

    public function runNow(): array
    {
        return (new AutoTraderEngine())->run();
    }

    public function recentData(int $limit = 25): array
    {
        Schema::ensure();
        $pdo = Database::connection();
        $limit = max(1, min(100, $limit));
        return [
            'signals' => $pdo->query("SELECT id,symbol,action,score,price,executed,order_local_id,created_at FROM autotrade_signals ORDER BY id DESC LIMIT {$limit}")->fetchAll(),
            'positions' => $pdo->query("SELECT id,symbol,amount,entry_price,stop_loss,take_profit,status,exit_price,realized_pnl,opened_at,closed_at,created_at FROM autotrade_positions ORDER BY id DESC LIMIT {$limit}")->fetchAll(),
            'pnl' => $pdo->query("SELECT id,position_id,pnl,pnl_percent,quote_asset,entry_price,exit_price,amount,created_at FROM autotrade_pnl ORDER BY id DESC LIMIT {$limit}")->fetchAll(),
            'events' => $pdo->query("SELECT id,level,event_name,context_json,created_at FROM autotrade_events ORDER BY id DESC LIMIT {$limit}")->fetchAll(),
        ];
    }

    private function number(array $input, string $key, float $default): float
    {
        if (!array_key_exists($key, $input) || $input[$key] === '') {
            return $default;
        }
        if (!is_numeric($input[$key]) || !is_finite((float) $input[$key])) {
            throw new \InvalidArgumentException($key . ' must be numeric.');
        }
        return (float) $input[$key];
    }

    private function audit(string $event, array $context = []): void
    {
        $stmt = Database::connection()->prepare('INSERT INTO audit_logs (event_name,context_json,created_at) VALUES (:event,:context,UTC_TIMESTAMP())');
        $stmt->execute([
            ':event' => $event,
            ':context' => $context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
