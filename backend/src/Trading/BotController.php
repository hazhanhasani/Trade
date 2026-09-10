<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

final class BotController
{
    public function __construct(
        private readonly OrderService $bitpin = new OrderService(),
        private readonly NobitexOrderService $nobitex = new NobitexOrderService(),
    ) {}

    public function status(): array
    {
        NobitexSchema::ensure();
        $pdo = Database::connection();
        $settings = Schema::settings($pdo);
        $settings = array_merge($settings, (new RiskManager())->normalizeSettings($settings));
        $quote = (string) $settings['quote_asset'];
        $kill = (string) ($pdo->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn() ?: '0') === '1';

        $bitpinPositions = $this->activePositions($pdo, 'bitpin');
        $nobitexPositions = $this->activePositions($pdo, 'nobitex');
        $lastRun = $pdo->query('SELECT run_id,status,summary_json,started_at,finished_at FROM bot_runs ORDER BY id DESC LIMIT 1')->fetch() ?: null;
        if ($lastRun && isset($lastRun['summary_json'])) {
            $lastRun['summary'] = json_decode((string) $lastRun['summary_json'], true);
            unset($lastRun['summary_json']);
        }
        $cronHealth = $this->cronHealth($lastRun);

        $exchanges = [
            'bitpin' => $this->exchangeStatus(
                $pdo,
                'bitpin',
                $quote,
                $bitpinPositions,
                $lastRun,
                $this->bitpin->liveEnabled()
            ),
            'nobitex' => $this->exchangeStatus(
                $pdo,
                'nobitex',
                $quote,
                $nobitexPositions,
                $lastRun,
                $this->nobitex->liveEnabled()
            ) + ['sodium_available'=>function_exists('sodium_crypto_sign_detached')],
        ];

        return [
            'asset'=>'MULTI',
            'capital_asset'=>'IRT/USDT',
            'quote_priority'=>['IRT','USDT'],
            'execution_mode'=>'live_only',
            // Legacy fields map to Bitpin so older Android versions keep working.
            'bot_enabled'=>$exchanges['bitpin']['bot_enabled'],
            'live_execution_enabled'=>$exchanges['bitpin']['live_execution_enabled'],
            'kill_switch'=>$kill,
            'settings'=>$settings,
            'position'=>$bitpinPositions[0] ?? null,
            'last_run'=>$lastRun,
            'cron_health'=>$cronHealth,
            'exchanges'=>$exchanges,
            'performance'=>$exchanges['bitpin']['performance'],
        ];
    }

    public function setEnabled(bool $enabled): void
    {
        $this->setExchangeEnabled('bitpin', $enabled);
    }

    public function setExchangeEnabled(string $exchange, bool $enabled): void
    {
        NobitexSchema::ensure();
        $exchange = $this->exchange($exchange);
        if ($enabled && !$this->credentialExists(Database::connection(), $exchange)) {
            throw new \RuntimeException(ucfirst($exchange) . ' credentials are not configured.');
        }
        NobitexSchema::setBotEnabled($exchange, $enabled);
        $this->audit($exchange . ($enabled ? '.autotrade.enabled' : '.autotrade.disabled'));
    }

    public function setLiveEnabled(bool $enabled): void
    {
        $this->setExchangeLive('bitpin', $enabled);
    }

    public function setExchangeLive(string $exchange, bool $enabled): void
    {
        $exchange = $this->exchange($exchange);
        if ($exchange === 'bitpin') {
            $stmt = Database::connection()->prepare(
                "INSERT INTO settings (key_name,value_text,updated_at) VALUES ('live_trading_enabled',:value,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()"
            );
            $stmt->execute([':value'=>$enabled ? '1' : '0']);
        } else {
            $this->nobitex->setLiveEnabled($enabled);
        }
        $this->audit($exchange . ($enabled ? '.live.enabled' : '.live.disabled'));
    }

    public function setKillSwitch(bool $enabled): void
    {
        $stmt = Database::connection()->prepare(
            "INSERT INTO settings (key_name,value_text,updated_at) VALUES ('kill_switch',:value,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()"
        );
        $stmt->execute([':value'=>$enabled ? '1' : '0']);
        $this->audit($enabled ? 'kill_switch.enabled' : 'kill_switch.disabled');
    }

    public function updateSettings(array $input): array
    {
        NobitexSchema::ensure();
        $pdo = Database::connection();
        $current = Schema::settings($pdo);
        $quote = strtoupper(trim((string) ($input['quote_asset'] ?? $current['quote_asset'])));
        if (!in_array($quote, ['USDT','IRT'], true)) throw new \InvalidArgumentException('quote_asset must be USDT or IRT.');
        $profile = strtolower(trim((string) ($input['risk_profile'] ?? $current['risk_profile'])));
        if (!in_array($profile, ['safe','balanced','aggressive'], true)) throw new \InvalidArgumentException('risk_profile must be safe, balanced or aggressive.');

        $candidate = [
            'risk_profile'=>$profile,
            'position_percent'=>$this->number($input,'position_percent',(float)$current['position_percent']),
            'max_position_percent'=>$this->number($input,'max_position_percent',(float)$current['max_position_percent']),
            'stop_loss_percent'=>$this->number($input,'stop_loss_percent',(float)$current['stop_loss_percent']),
            'take_profit_percent'=>$this->number($input,'take_profit_percent',(float)$current['take_profit_percent']),
            'daily_loss_limit_percent'=>$this->number($input,'daily_loss_limit_percent',(float)$current['daily_loss_limit_percent']),
            'min_signal_score'=>(int)$this->number($input,'min_signal_score',(float)$current['min_signal_score']),
            'cooldown_minutes'=>(int)$this->number($input,'cooldown_minutes',(float)$current['cooldown_minutes']),
        ];
        $n = (new RiskManager())->normalizeSettings($candidate);
        $stmt = $pdo->prepare('UPDATE autotrade_settings SET quote_asset=:quote,risk_profile=:profile,position_percent=:position,max_position_percent=:max_position,stop_loss_percent=:stop,take_profit_percent=:take,daily_loss_limit_percent=:daily,min_signal_score=:score,cooldown_minutes=:cooldown,updated_at=UTC_TIMESTAMP() WHERE id=1');
        $stmt->execute([
            ':quote'=>$quote, ':profile'=>$n['risk_profile'], ':position'=>$n['position_percent'], ':max_position'=>$n['max_position_percent'],
            ':stop'=>$n['stop_loss_percent'], ':take'=>$n['take_profit_percent'], ':daily'=>$n['daily_loss_limit_percent'],
            ':score'=>$n['min_signal_score'], ':cooldown'=>$n['cooldown_minutes'],
        ]);
        $this->audit('autotrade.shared_settings_updated', $n + ['quote_asset'=>$quote]);
        return Schema::settings($pdo);
    }

    public function runNow(string $exchange = 'bitpin'): array
    {
        $exchange = $this->exchange($exchange);
        if (!NobitexSchema::botEnabled($exchange)) return ['status'=>'disabled','exchange'=>$exchange,'asset'=>'MULTI'];
        return $exchange === 'nobitex' ? (new NobitexAutoTraderEngine())->run() : (new AutoTraderEngine())->run();
    }

    public function recentData(int $limit = 25): array
    {
        NobitexSchema::ensure();
        $pdo = Database::connection();
        $limit = max(1,min(100,$limit));
        return [
            'bitpin'=>[
                'signals'=>$pdo->query("SELECT id,symbol,action,score,price,details_json,executed,order_local_id,created_at FROM autotrade_signals ORDER BY id DESC LIMIT {$limit}")->fetchAll(),
                'positions'=>$pdo->query("SELECT id,symbol,asset,quote_asset,amount,entry_price,stop_loss,take_profit,status,exit_price,realized_pnl,opened_at,closed_at,created_at FROM autotrade_positions ORDER BY id DESC LIMIT {$limit}")->fetchAll(),
                'pnl'=>$pdo->query("SELECT id,position_id,pnl,pnl_percent,quote_asset,entry_price,exit_price,amount,created_at FROM autotrade_pnl ORDER BY id DESC LIMIT {$limit}")->fetchAll(),
            ],
            'nobitex'=>[
                'signals'=>$pdo->query("SELECT id,symbol,action,score,price,details_json,executed,order_local_id,created_at FROM nobitex_autotrade_signals ORDER BY id DESC LIMIT {$limit}")->fetchAll(),
                'positions'=>$pdo->query("SELECT id,symbol,asset,quote_asset,amount,entry_price,stop_loss,take_profit,status,exit_price,realized_pnl,opened_at,closed_at,created_at FROM nobitex_autotrade_positions ORDER BY id DESC LIMIT {$limit}")->fetchAll(),
                'pnl'=>$pdo->query("SELECT id,position_id,pnl,pnl_percent,quote_asset,entry_price,exit_price,amount,created_at FROM nobitex_autotrade_pnl ORDER BY id DESC LIMIT {$limit}")->fetchAll(),
            ],
        ];
    }

    private function exchangeStatus(PDO $pdo, string $exchange, string $quote, array $positions, ?array $lastRun, bool $live): array
    {
        return [
            'name'=>$exchange === 'nobitex' ? 'Nobitex' : 'Bitpin',
            'credentials_configured'=>$this->credentialExists($pdo, $exchange),
            'bot_enabled'=>NobitexSchema::botEnabled($exchange),
            'live_execution_enabled'=>$live,
            'position'=>$positions[0] ?? null,
            'active_positions'=>$positions,
            'active_position_count'=>count($positions),
            'latest_signal'=>$this->latestSignal($pdo, $exchange),
            'latest_order'=>$this->latestOrder($pdo, $exchange),
            'last_decision'=>$this->lastDecision($lastRun, $exchange),
            'performance'=>$this->performance($pdo, $exchange, $quote),
        ];
    }

    private function activePositions(PDO $pdo, string $exchange): array
    {
        $table = $exchange === 'nobitex' ? 'nobitex_autotrade_positions' : 'autotrade_positions';
        return $pdo->query("SELECT id,symbol,asset,quote_asset,amount,entry_price,stop_loss,take_profit,status,opened_at,updated_at FROM {$table} WHERE status IN ('pending_open','open','pending_close') ORDER BY id DESC LIMIT 10")->fetchAll();
    }

    private function latestSignal(PDO $pdo, string $exchange): ?array
    {
        $table = $exchange === 'nobitex' ? 'nobitex_autotrade_signals' : 'autotrade_signals';
        $row = $pdo->query("SELECT id,symbol,action,score,price,details_json,executed,created_at FROM {$table} ORDER BY id DESC LIMIT 1")->fetch() ?: null;
        if (!$row) return null;
        if (isset($row['details_json'])) {
            $decoded = json_decode((string)$row['details_json'], true);
            if (is_array($decoded)) $row['details'] = $decoded;
            unset($row['details_json']);
        }
        return $row;
    }

    private function latestOrder(PDO $pdo, string $exchange): ?array
    {
        $stmt = $pdo->prepare("SELECT local_id,exchange_order_id,identifier,market_code,side,order_mode,amount,price,status,source,error_text,created_at,updated_at FROM orders WHERE exchange_name=:exchange ORDER BY id DESC LIMIT 1");
        $stmt->execute([':exchange'=>$exchange]);
        return $stmt->fetch() ?: null;
    }

    private function lastDecision(?array $lastRun, string $exchange): ?array
    {
        $summary = is_array($lastRun['summary'] ?? null) ? $lastRun['summary'] : [];
        $exchanges = is_array($summary['exchanges'] ?? null) ? $summary['exchanges'] : [];
        return is_array($exchanges[$exchange] ?? null) ? $exchanges[$exchange] : null;
    }

    private function cronHealth(?array $lastRun): array
    {
        if (!$lastRun) return ['status'=>'unknown','healthy'=>false,'age_seconds'=>null,'message'=>'No cron run has been recorded yet.'];
        $time = (string)($lastRun['finished_at'] ?: $lastRun['started_at'] ?: '');
        $ts = $time !== '' ? strtotime($time . ' UTC') : false;
        $age = $ts === false ? null : max(0, time() - $ts);
        $healthy = $age !== null && $age <= 180 && (string)$lastRun['status'] !== 'failed';
        return [
            'status'=>$healthy ? 'healthy' : (($age !== null && $age > 180) ? 'stale' : (string)$lastRun['status']),
            'healthy'=>$healthy,
            'age_seconds'=>$age,
            'last_status'=>(string)$lastRun['status'],
            'message'=>$healthy ? 'Cron is running normally.' : (($age !== null && $age > 180) ? 'Cron has not completed in the expected window.' : 'The latest cron run needs attention.'),
        ];
    }

    private function performance(PDO $pdo, string $exchange, string $quote): array
    {
        $table = $exchange === 'nobitex' ? 'nobitex_autotrade_pnl' : 'autotrade_pnl';
        $positions = $exchange === 'nobitex' ? 'nobitex_autotrade_positions' : 'autotrade_positions';
        $byQuote = [];
        foreach (['IRT','USDT'] as $q) {
            $stmt=$pdo->prepare("SELECT COALESCE(SUM(pnl),0) FROM {$table} WHERE quote_asset=:q AND created_at>=UTC_DATE()");$stmt->execute([':q'=>$q]);$today=(float)$stmt->fetchColumn();
            $stmt=$pdo->prepare("SELECT COALESCE(SUM(pnl),0) FROM {$table} WHERE quote_asset=:q");$stmt->execute([':q'=>$q]);$total=(float)$stmt->fetchColumn();
            $stmt=$pdo->prepare("SELECT COUNT(*) FROM {$positions} WHERE status='closed' AND quote_asset=:q");$stmt->execute([':q'=>$q]);$closed=(int)$stmt->fetchColumn();
            $stmt=$pdo->prepare("SELECT COUNT(*) FROM {$positions} WHERE status='closed' AND quote_asset=:q AND realized_pnl>0");$stmt->execute([':q'=>$q]);$wins=(int)$stmt->fetchColumn();
            $byQuote[$q]=['today_realized_pnl'=>$today,'total_realized_pnl'=>$total,'closed_positions'=>$closed,'winning_positions'=>$wins,'win_rate_percent'=>$closed>0?round(($wins/$closed)*100,2):0.0];
        }
        $selected = $byQuote[$quote] ?? $byQuote['IRT'];
        return ['quote_asset'=>$quote] + $selected + ['by_quote'=>$byQuote];
    }

    private function credentialExists(PDO $pdo,string $exchange):bool{$s=$pdo->prepare('SELECT EXISTS(SELECT 1 FROM exchange_credentials WHERE exchange_name=:e)');$s->execute([':e'=>$exchange]);return(bool)$s->fetchColumn();}
    private function exchange(string $exchange):string{$exchange=strtolower(trim($exchange));if(!in_array($exchange,['bitpin','nobitex'],true))throw new \InvalidArgumentException('Unsupported exchange.');return$exchange;}
    private function number(array $input,string $key,float $default):float{if(!array_key_exists($key,$input)||$input[$key]==='')return$default;if(!is_numeric($input[$key])||!is_finite((float)$input[$key]))throw new \InvalidArgumentException($key.' must be numeric.');return(float)$input[$key];}
    private function audit(string $event,array $context=[]):void{$s=Database::connection()->prepare('INSERT INTO audit_logs (event_name,context_json,created_at) VALUES (:e,:c,UTC_TIMESTAMP())');$s->execute([':e'=>$event,':c'=>$context===[]?null:json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);}
}
