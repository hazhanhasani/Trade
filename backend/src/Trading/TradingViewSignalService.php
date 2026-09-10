<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Config;
use Trade\Database;
use Trade\Security\Crypto;

/**
 * TradingView is a secondary decision layer, not an execution API.
 * Webhook alerts are persisted and fused with exchange-native signals before
 * RiskManager can authorize a real order.
 */
final class TradingViewSignalService
{
    private static bool $ensured = false;

    private const OFFICIAL_WEBHOOK_IPS = [
        '52.89.214.238',
        '34.212.75.30',
        '54.218.53.128',
        '52.32.178.7',
    ];

    public function ensure(): void
    {
        if (self::$ensured) return;
        NobitexSchema::ensure();
        $pdo = Database::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS tradingview_signals (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(190) NOT NULL,
            symbol VARCHAR(80) NOT NULL,
            asset VARCHAR(24) NOT NULL,
            quote_asset VARCHAR(24) NULL,
            provider VARCHAR(80) NULL,
            action VARCHAR(12) NOT NULL,
            timeframe VARCHAR(24) NOT NULL,
            confidence DECIMAL(8,4) NOT NULL DEFAULT 0,
            confirmations SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            price DECIMAL(36,18) NULL,
            payload_json LONGTEXT NULL,
            source_ip VARCHAR(64) NULL,
            source_verified TINYINT(1) NOT NULL DEFAULT 0,
            signal_at DATETIME NULL,
            received_at DATETIME NOT NULL,
            UNIQUE KEY uq_tradingview_event (event_id),
            INDEX idx_tradingview_asset_received (asset,received_at),
            INDEX idx_tradingview_action_received (action,received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $defaults = [
            'tradingview_enabled' => '0',
            'tradingview_mode' => 'assist',
            'tradingview_signal_ttl_seconds' => '480',
            'tradingview_min_confidence' => '65',
            'tradingview_required_confirmations' => '3',
            'tradingview_score_weight' => '22',
            'tradingview_instant_recheck' => '1',
            'tradingview_require_known_ip' => '0',
        ];
        foreach ($defaults as $key => $value) $this->seedSetting($pdo, $key, $value);
        self::$ensured = true;
    }

    public function generateSecret(): string
    {
        $this->ensure();
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $enc = Crypto::encrypt($secret, (string) Config::require('app.encryption_key'));
        $this->writeSetting(Database::connection(), 'tradingview_webhook_secret_enc', $enc);
        return $secret;
    }

    public function secretConfigured(): bool
    {
        $this->ensure();
        return $this->setting('tradingview_webhook_secret_enc') !== '';
    }

    public function webhookUrl(): ?string
    {
        $secret = $this->secret();
        if ($secret === null) return null;
        $base = rtrim((string) Config::get('app.url', ''), '/');
        if (!str_starts_with($base, 'https://')) return null;
        return $base . '/webhooks/tradingview/' . rawurlencode($secret);
    }

    public function saveSettings(array $input): array
    {
        $this->ensure();
        $pdo = Database::connection();
        $enabled = $this->boolValue($input['enabled'] ?? null, $this->boolSetting('tradingview_enabled'));
        $mode = strtolower(trim((string) ($input['mode'] ?? $this->setting('tradingview_mode', 'assist'))));
        if (!in_array($mode, ['assist', 'confirm'], true)) {
            throw new \InvalidArgumentException('TradingView mode must be assist or confirm.');
        }
        $ttl = $this->boundedInt($input['ttl_seconds'] ?? $this->setting('tradingview_signal_ttl_seconds', '480'), 60, 3600, 480);
        $confidence = $this->boundedInt($input['min_confidence'] ?? $this->setting('tradingview_min_confidence', '65'), 40, 95, 65);
        $confirmations = $this->boundedInt($input['required_confirmations'] ?? $this->setting('tradingview_required_confirmations', '3'), 1, 4, 3);
        $weight = $this->boundedInt($input['score_weight'] ?? $this->setting('tradingview_score_weight', '22'), 0, 40, 22);
        $instant = $this->boolValue($input['instant_recheck'] ?? null, $this->boolSetting('tradingview_instant_recheck', true));
        $knownIp = $this->boolValue($input['require_known_ip'] ?? null, $this->boolSetting('tradingview_require_known_ip'));

        $values = [
            'tradingview_enabled' => $enabled ? '1' : '0',
            'tradingview_mode' => $mode,
            'tradingview_signal_ttl_seconds' => (string) $ttl,
            'tradingview_min_confidence' => (string) $confidence,
            'tradingview_required_confirmations' => (string) $confirmations,
            'tradingview_score_weight' => (string) $weight,
            'tradingview_instant_recheck' => $instant ? '1' : '0',
            'tradingview_require_known_ip' => $knownIp ? '1' : '0',
        ];
        foreach ($values as $key => $value) $this->writeSetting($pdo, $key, $value);
        return $this->status(false);
    }

    public function status(bool $includeWebhookUrl = false): array
    {
        $this->ensure();
        $pdo = Database::connection();
        $last = $pdo->query("SELECT event_id,symbol,asset,quote_asset,provider,action,timeframe,confidence,confirmations,price,source_verified,signal_at,received_at FROM tradingview_signals ORDER BY id DESC LIMIT 1")->fetch() ?: null;
        $lastAge = null;
        if ($last && !empty($last['received_at'])) {
            $ts = strtotime((string) $last['received_at'] . ' UTC');
            if ($ts !== false) $lastAge = max(0, time() - $ts);
        }
        $ttl = $this->boundedInt($this->setting('tradingview_signal_ttl_seconds', '480'), 60, 3600, 480);
        $counts = $pdo->query("SELECT COUNT(*) AS total,
            SUM(CASE WHEN action='buy' THEN 1 ELSE 0 END) AS buys,
            SUM(CASE WHEN action='sell' THEN 1 ELSE 0 END) AS sells
            FROM tradingview_signals WHERE received_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)")->fetch() ?: [];

        $out = [
            'enabled' => $this->boolSetting('tradingview_enabled'),
            'mode' => $this->setting('tradingview_mode', 'assist'),
            'secret_configured' => $this->secretConfigured(),
            'signal_ttl_seconds' => $ttl,
            'min_confidence' => $this->boundedInt($this->setting('tradingview_min_confidence', '65'), 40, 95, 65),
            'required_confirmations' => $this->boundedInt($this->setting('tradingview_required_confirmations', '3'), 1, 4, 3),
            'score_weight' => $this->boundedInt($this->setting('tradingview_score_weight', '22'), 0, 40, 22),
            'instant_recheck' => $this->boolSetting('tradingview_instant_recheck', true),
            'require_known_ip' => $this->boolSetting('tradingview_require_known_ip'),
            'last_signal' => $last,
            'last_signal_age_seconds' => $lastAge,
            'fresh' => $lastAge !== null && $lastAge <= $ttl,
            'signals_24h' => [
                'total' => (int) ($counts['total'] ?? 0),
                'buy' => (int) ($counts['buys'] ?? 0),
                'sell' => (int) ($counts['sells'] ?? 0),
            ],
        ];
        if ($includeWebhookUrl) $out['webhook_url'] = $this->webhookUrl();
        return $out;
    }

    public function publicStatus(): array
    {
        $s = $this->status(false);
        unset($s['secret_configured']);
        return $s;
    }

    public function ingest(string $token, array $payload, ?string $sourceIp = null): array
    {
        $this->ensure();
        $secret = $this->secret();
        if ($secret === null || $token === '' || !hash_equals($secret, $token)) {
            throw new TradingViewWebhookException('unauthorized', 401);
        }

        $sourceIp = $this->safeIp($sourceIp ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
        $sourceVerified = in_array($sourceIp, self::OFFICIAL_WEBHOOK_IPS, true);
        if ($this->boolSetting('tradingview_require_known_ip') && !$sourceVerified) {
            throw new TradingViewWebhookException('source_ip_not_allowed', 403);
        }

        $action = strtolower(trim((string) ($payload['action'] ?? $payload['signal'] ?? '')));
        if (!in_array($action, ['buy', 'sell', 'hold'], true)) {
            throw new TradingViewWebhookException('invalid_action', 422);
        }
        $symbol = $this->safeSymbol((string) ($payload['symbol'] ?? $payload['ticker'] ?? $payload['tickerid'] ?? ''));
        $asset = $this->safeAsset((string) ($payload['asset'] ?? $payload['base'] ?? $payload['base_asset'] ?? ''));
        $quote = $this->safeAsset((string) ($payload['quote'] ?? $payload['quote_asset'] ?? ''), true);
        if ($asset === '') $asset = $this->deriveAsset($symbol, $quote);
        if ($asset === '') throw new TradingViewWebhookException('asset_required', 422);
        $timeframe = $this->safeTimeframe((string) ($payload['timeframe'] ?? $payload['interval'] ?? '1'));
        $confidence = $this->boundedFloat($payload['confidence'] ?? $payload['score'] ?? 0, 0.0, 100.0, 0.0);
        $confirmations = $this->boundedInt($payload['confirmations'] ?? 1, 1, 4, 1);
        $price = $this->positiveFloat($payload['price'] ?? $payload['close'] ?? null);
        $provider = trim((string) ($payload['provider'] ?? $payload['exchange'] ?? 'TradingView'));
        $provider = mb_substr(preg_replace('/[^A-Za-z0-9._:-]/', '', $provider) ?? 'TradingView', 0, 80);
        $signalAt = $this->signalTime($payload['signal_time'] ?? $payload['time'] ?? $payload['bar_time'] ?? null);

        $eventRaw = trim((string) ($payload['event_id'] ?? ''));
        $eventId = $eventRaw !== ''
            ? hash('sha256', mb_substr($eventRaw, 0, 500))
            : hash('sha256', json_encode([$symbol,$asset,$action,$timeframe,$signalAt,$price,$confidence,$confirmations], JSON_THROW_ON_ERROR));

        $pdo = Database::connection();
        $stmt = $pdo->prepare("INSERT IGNORE INTO tradingview_signals
            (event_id,symbol,asset,quote_asset,provider,action,timeframe,confidence,confirmations,price,payload_json,source_ip,source_verified,signal_at,received_at)
            VALUES (:event,:symbol,:asset,:quote,:provider,:action,:tf,:confidence,:confirmations,:price,:payload,:ip,:verified,:signal_at,UTC_TIMESTAMP())");
        $stmt->execute([
            ':event'=>$eventId,
            ':symbol'=>$symbol,
            ':asset'=>$asset,
            ':quote'=>$quote !== '' ? $quote : null,
            ':provider'=>$provider !== '' ? $provider : null,
            ':action'=>$action,
            ':tf'=>$timeframe,
            ':confidence'=>$confidence,
            ':confirmations'=>$confirmations,
            ':price'=>$price,
            ':payload'=>json_encode($this->sanitizedPayload($payload), JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            ':ip'=>$sourceIp !== '' ? $sourceIp : null,
            ':verified'=>$sourceVerified ? 1 : 0,
            ':signal_at'=>$signalAt,
        ]);
        $accepted = $stmt->rowCount() > 0;
        if (random_int(1, 30) === 1) {
            try { $pdo->exec("DELETE FROM tradingview_signals WHERE received_at < (UTC_TIMESTAMP() - INTERVAL 14 DAY)"); } catch (\Throwable) {}
        }

        return [
            'accepted'=>$accepted,
            'duplicate'=>!$accepted,
            'event_id'=>$eventId,
            'asset'=>$asset,
            'symbol'=>$symbol,
            'action'=>$action,
            'confidence'=>$confidence,
            'confirmations'=>$confirmations,
            'source_verified'=>$sourceVerified,
            'instant_recheck'=>$accepted && $action !== 'hold' && $this->boolSetting('tradingview_instant_recheck', true),
            'received_at'=>gmdate(DATE_ATOM),
        ];
    }

    public function fuse(array $market, array $signal, int $threshold): array
    {
        $this->ensure();
        $threshold = max(35, min(90, $threshold));
        if (!$this->boolSetting('tradingview_enabled')) {
            $signal['tradingview'] = ['enabled'=>false,'used'=>false];
            return $signal;
        }

        $asset = $this->safeAsset((string) ($market['asset'] ?? $market['exchange_asset'] ?? ''));
        if ($asset === '') $asset = $this->deriveAsset((string) ($market['symbol'] ?? ''), (string) ($market['quote_asset'] ?? ''));
        $tv = $this->consensus($asset);
        $mode = $this->setting('tradingview_mode', 'assist');
        $weight = $this->boundedInt($this->setting('tradingview_score_weight', '22'), 0, 40, 22);
        $localAction = strtolower((string) ($signal['action'] ?? 'hold'));
        $localScore = (int) ($signal['score'] ?? 0);
        $signal['tradingview'] = $tv + ['enabled'=>true,'mode'=>$mode,'used'=>false];

        if (!($tv['qualified'] ?? false)) {
            if ($mode === 'confirm' && $localAction === 'buy') {
                $signal['action'] = 'hold';
                $signal['reason'] = 'tradingview_confirmation_missing';
                $signal['tradingview']['used'] = true;
            }
            return $signal;
        }

        $tvAction = (string) ($tv['action'] ?? 'hold');
        $confidenceFactor = max(0.0, min(1.0, ((float) ($tv['confidence'] ?? 0)) / 100.0));
        $adjustment = (int) round($weight * $confidenceFactor);
        if ($tvAction === 'sell') $adjustment *= -1;
        if ($tvAction === 'hold') $adjustment = 0;
        $fusedScore = max(-100, min(100, $localScore + $adjustment));
        $signal['score'] = $fusedScore;
        $signal['confidence'] = min(100, max((int) ($signal['confidence'] ?? 0), (int) round(((float) ($tv['confidence'] ?? 0) + abs($fusedScore)) / 2.0)));
        $signal['tradingview']['score_adjustment'] = $adjustment;
        $signal['tradingview']['used'] = true;

        if ($localAction === 'buy' && $tvAction === 'sell') {
            $signal['action'] = 'hold';
            $signal['reason'] = 'tradingview_conflict';
            return $signal;
        }
        if ($mode === 'confirm' && $localAction === 'buy' && $tvAction !== 'buy') {
            $signal['action'] = 'hold';
            $signal['reason'] = 'tradingview_buy_not_confirmed';
            return $signal;
        }

        // TradingView may promote an almost-ready setup, but it never bypasses
        // market-quality checks or the downstream RiskManager.
        if ($tvAction === 'buy' && ($signal['ready'] ?? false) && $fusedScore >= $threshold) {
            if (!$this->localStronglyBearish($signal) && in_array($localAction, ['buy','hold'], true)) {
                $signal['action'] = 'buy';
                $signal['reason'] = $localAction === 'buy' ? 'tradingview_buy_confirmed' : 'tradingview_promoted_buy';
            }
        }

        // High-confidence 4-timeframe SELL may request an early strategy exit.
        // Stop-loss/take-profit remain hard rules in RiskManager.
        if ($tvAction === 'sell' && ($tv['confidence'] ?? 0) >= max(75, $this->minConfidence() + 5)
            && ($tv['confirmations'] ?? 0) >= min(4, $this->requiredConfirmations() + 1)) {
            $signal['action'] = 'sell';
            $signal['reason'] = 'tradingview_exit_confirmed';
        }

        return $signal;
    }

    public function consensus(string $asset): array
    {
        $this->ensure();
        $asset = $this->safeAsset($asset);
        if ($asset === '') return ['available'=>false,'qualified'=>false,'reason'=>'asset_missing'];
        $ttl = $this->boundedInt($this->setting('tradingview_signal_ttl_seconds', '480'), 60, 3600, 480);
        $stmt = Database::connection()->prepare("SELECT action,timeframe,confidence,confirmations,symbol,provider,price,source_verified,received_at
            FROM tradingview_signals
            WHERE asset=:asset AND received_at >= (UTC_TIMESTAMP() - INTERVAL {$ttl} SECOND)
            ORDER BY id DESC LIMIT 24");
        $stmt->execute([':asset'=>$asset]);
        $rows = $stmt->fetchAll();
        if ($rows === []) return ['available'=>false,'qualified'=>false,'asset'=>$asset,'reason'=>'no_fresh_signal'];

        // Repeated heartbeat alerts from the same timeframe count once.
        $byTimeframe = [];
        foreach ($rows as $row) {
            $tf = (string) ($row['timeframe'] ?? '1');
            if (!isset($byTimeframe[$tf])) $byTimeframe[$tf] = $row;
        }
        $buy = 0.0; $sell = 0.0; $hold = 0.0;
        $maxConfirmations = 0; $maxConfidence = 0.0; $verified = false; $latest = null;
        foreach ($byTimeframe as $row) {
            $confidence = max(0.0, min(100.0, (float) ($row['confidence'] ?? 0)));
            $confirmations = max(1, min(4, (int) ($row['confirmations'] ?? 1)));
            $w = max(0.25, $confidence / 100.0) * max(1.0, $confirmations / 2.0) * $this->timeframeWeight((string) ($row['timeframe'] ?? '1'));
            $action = (string) ($row['action'] ?? 'hold');
            if ($action === 'buy') $buy += $w;
            elseif ($action === 'sell') $sell += $w;
            else $hold += $w;
            $maxConfirmations = max($maxConfirmations, $confirmations);
            $maxConfidence = max($maxConfidence, $confidence);
            $verified = $verified || (bool) ($row['source_verified'] ?? false);
            if ($latest === null) $latest = $row;
        }
        $action = 'hold';
        if ($buy > $sell * 1.15 && $buy > $hold) $action = 'buy';
        elseif ($sell > $buy * 1.15 && $sell > $hold) $action = 'sell';
        $totalDirectional = $buy + $sell;
        $directionalConfidence = $totalDirectional > 0 ? (max($buy, $sell) / $totalDirectional) * 100.0 : 0.0;
        $confidence = min(100.0, ($directionalConfidence * 0.55) + ($maxConfidence * 0.45));
        $qualified = $action !== 'hold'
            && $maxConfirmations >= $this->requiredConfirmations()
            && $confidence >= $this->minConfidence();
        $age = null;
        if ($latest && !empty($latest['received_at'])) {
            $ts = strtotime((string) $latest['received_at'] . ' UTC');
            if ($ts !== false) $age = max(0, time() - $ts);
        }
        return [
            'available'=>true,
            'qualified'=>$qualified,
            'asset'=>$asset,
            'action'=>$action,
            'confidence'=>round($confidence, 2),
            'confirmations'=>$maxConfirmations,
            'distinct_timeframes'=>count($byTimeframe),
            'age_seconds'=>$age,
            'source_symbol'=>$latest['symbol'] ?? null,
            'provider'=>$latest['provider'] ?? null,
            'source_verified'=>$verified,
            'votes'=>['buy'=>round($buy,3),'sell'=>round($sell,3),'hold'=>round($hold,3)],
            'reason'=>$qualified ? 'qualified' : 'below_tradingview_threshold',
        ];
    }

    public function shouldInstantRecheck(array $ingestResult): bool
    {
        return (bool) ($ingestResult['instant_recheck'] ?? false)
            && $this->boolSetting('tradingview_enabled')
            && NobitexSchema::botEnabled('nobitex');
    }

    public static function requestSourceIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private function secret(): ?string
    {
        $this->ensure();
        $enc = $this->setting('tradingview_webhook_secret_enc');
        if ($enc === '') return null;
        try {
            return Crypto::decrypt($enc, (string) Config::require('app.encryption_key'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function localStronglyBearish(array $signal): bool
    {
        $i = is_array($signal['indicators'] ?? null) ? $signal['indicators'] : [];
        return (float) ($i['ema_gap_percent'] ?? 0) < -0.20
            || (float) ($i['momentum_5_percent'] ?? 0) < -0.70
            || (float) ($i['macd_histogram_percent'] ?? 0) < -0.10;
    }

    private function timeframeWeight(string $timeframe): float
    {
        $tf = strtoupper(trim($timeframe));
        return match ($tf) {
            '1', '1M' => 0.85,
            '3', '3M' => 0.95,
            '5', '5M' => 1.00,
            '15', '15M' => 1.15,
            '30', '30M' => 1.25,
            '60', '1H' => 1.35,
            '240', '4H' => 1.50,
            'D', '1D' => 1.60,
            default => 1.0,
        };
    }

    private function deriveAsset(string $symbol, string $quote = ''): string
    {
        $symbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', $symbol) ?? '');
        $quote = strtoupper(preg_replace('/[^A-Z0-9]/', '', $quote) ?? '');
        if ($quote !== '' && str_ends_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
            return $this->safeAsset(substr($symbol, 0, -strlen($quote)));
        }
        foreach (['USDT','USDC','USD','IRT','RLS','BTC','ETH','EUR'] as $suffix) {
            if (str_ends_with($symbol, $suffix) && strlen($symbol) > strlen($suffix)) {
                return $this->safeAsset(substr($symbol, 0, -strlen($suffix)));
            }
        }
        return $this->safeAsset($symbol);
    }

    private function safeSymbol(string $value): string
    {
        $v = strtoupper(trim($value));
        $v = preg_replace('/[^A-Z0-9._:-]/', '', $v) ?? '';
        return mb_substr($v, 0, 80);
    }

    private function safeAsset(string $value, bool $allowEmpty = false): string
    {
        $v = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($value)) ?? '');
        if ($v === '' && $allowEmpty) return '';
        if ($v === '' || strlen($v) > 24) return '';
        return $v;
    }

    private function safeTimeframe(string $value): string
    {
        $v = strtoupper(trim($value));
        if (!preg_match('/^[0-9]{1,4}[SMHDW]?$|^[DWM]$/', $v)) return '1';
        return mb_substr($v, 0, 24);
    }

    private function safeIp(string $value): string
    {
        $value = trim($value);
        return filter_var($value, FILTER_VALIDATE_IP) ? $value : '';
    }

    private function signalTime(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) {
            $n = (float) $value;
            if ($n > 20000000000) $n /= 1000.0;
            $ts = (int) floor($n);
        } else {
            $parsed = strtotime((string) $value);
            if ($parsed === false) return null;
            $ts = $parsed;
        }
        if ($ts <= 0 || $ts > time() + 600 || $ts < time() - 86400) return null;
        return gmdate('Y-m-d H:i:s', $ts);
    }

    private function sanitizedPayload(array $payload): array
    {
        $allowed = ['version','action','signal','symbol','ticker','tickerid','asset','base','base_asset','quote','quote_asset','provider','exchange','timeframe','interval','confidence','score','confirmations','price','close','signal_time','time','bar_time','event_id','rsi','ema_fast','ema_slow','macd_histogram','atr_percent'];
        $out = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $payload)) continue;
            $value = $payload[$key];
            if (is_scalar($value) || $value === null) $out[$key] = is_string($value) ? mb_substr($value, 0, 500) : $value;
        }
        return $out;
    }

    private function positiveFloat(mixed $value): ?float
    {
        if (!is_numeric($value)) return null;
        $n = (float) $value;
        return is_finite($n) && $n > 0 ? $n : null;
    }

    private function boundedFloat(mixed $value, float $min, float $max, float $default): float
    {
        if (!is_numeric($value)) return $default;
        $n = (float) $value;
        if (!is_finite($n)) return $default;
        return max($min, min($max, $n));
    }

    private function boundedInt(mixed $value, int $min, int $max, int $default): int
    {
        if (!is_numeric($value)) return $default;
        return max($min, min($max, (int) $value));
    }

    private function boolValue(mixed $value, bool $default = false): bool
    {
        if ($value === null) return $default;
        if (is_bool($value)) return $value;
        return in_array(strtolower(trim((string) $value)), ['1','true','yes','on'], true);
    }

    private function minConfidence(): int
    {
        return $this->boundedInt($this->setting('tradingview_min_confidence', '65'), 40, 95, 65);
    }

    private function requiredConfirmations(): int
    {
        return $this->boundedInt($this->setting('tradingview_required_confirmations', '3'), 1, 4, 3);
    }

    private function boolSetting(string $key, bool $default = false): bool
    {
        $value = $this->setting($key, $default ? '1' : '0');
        return in_array(strtolower(trim($value)), ['1','true','yes','on'], true);
    }

    private function setting(string $key, string $default = ''): string
    {
        $stmt = Database::connection()->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');
        $stmt->execute([':k'=>$key]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $default : (string) $value;
    }

    private function seedSetting(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES (:k,:v,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=value_text");
        $stmt->execute([':k'=>$key, ':v'=>$value]);
    }

    private function writeSetting(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES (:k,:v,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");
        $stmt->execute([':k'=>$key, ':v'=>$value]);
    }
}

final class TradingViewWebhookException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode)
    {
        parent::__construct($message);
    }
}
