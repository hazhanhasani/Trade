<?php

declare(strict_types=1);

namespace Trade\Integrations;

use PDO;
use Trade\Config;
use Trade\Database;
use Trade\Security\Crypto;

/**
 * Outbound-only Bale channel integration for confirmed trade notifications.
 *
 * - Bot token is encrypted at rest with the application encryption key.
 * - Deliveries are idempotent by event_key.
 * - Failed deliveries remain queued for retry and never block trading.
 */
final class BaleTradeNotifier
{
    private const API_BASE = 'https://tapi.bale.ai';
    private const TOKEN_KEY = 'bale_bot_token_enc';
    private const CHAT_KEY = 'bale_channel_chat_id';
    private const ENABLED_KEY = 'bale_trade_notifications_enabled';

    public function ensureSchema(?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS bale_trade_deliveries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_key VARCHAR(191) NOT NULL,
            trade_type VARCHAR(12) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(1000) NULL,
            last_attempt_at DATETIME NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_bale_trade_event (event_key),
            INDEX idx_bale_delivery_pending (status,sent_at,updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** @return array<string,mixed> */
    public function status(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $this->ensureSchema($pdo);
        $token = $this->setting($pdo, self::TOKEN_KEY);
        $chat = trim((string)($this->setting($pdo, self::CHAT_KEY) ?? ''));
        $enabled = $this->boolSetting($pdo, self::ENABLED_KEY);
        $pending = (int)$pdo->query("SELECT COUNT(*) FROM bale_trade_deliveries WHERE sent_at IS NULL AND status IN ('pending','failed','sending')")->fetchColumn();
        $failed = (int)$pdo->query("SELECT COUNT(*) FROM bale_trade_deliveries WHERE sent_at IS NULL AND status='failed'")->fetchColumn();
        $last = $pdo->query("SELECT status,last_error,last_attempt_at,sent_at FROM bale_trade_deliveries ORDER BY id DESC LIMIT 1")->fetch() ?: null;

        return [
            'enabled'=>$enabled,
            'configured'=>$token !== null && $token !== '' && $chat !== '',
            'token_configured'=>$token !== null && $token !== '',
            'chat_id'=>$chat,
            'pending_deliveries'=>$pending,
            'failed_deliveries'=>$failed,
            'last_delivery'=>$last,
        ];
    }

    /**
     * Tests the bot + destination first, then persists the token encrypted.
     * Passing an empty token reuses the currently stored token.
     *
     * @return array<string,mixed>
     */
    public function saveAndTest(string $tokenInput, string $chatId, bool $enabled = true, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $this->ensureSchema($pdo);
        $chatId = $this->normalizeChatId($chatId);
        $token = trim($tokenInput);
        if ($token === '') {
            $token = $this->configuredToken($pdo);
        } else {
            $this->validateToken($token);
        }

        $bot = $this->call($token, 'getMe');
        $test = $this->call($token, 'sendMessage', [
            'chat_id'=>$chatId,
            'text'=>"✅ اتصال Trade به کانال بله با موفقیت برقرار شد.\nاز این پس خرید و فروش‌های تأییدشده در این کانال ارسال می‌شوند.",
        ]);

        $encKey = (string)Config::require('app.encryption_key');
        $this->writeSetting($pdo, self::TOKEN_KEY, Crypto::encrypt($token, $encKey));
        $this->writeSetting($pdo, self::CHAT_KEY, $chatId);
        $this->writeSetting($pdo, self::ENABLED_KEY, $enabled ? '1' : '0');
        // A corrected token/channel should make previous unsent rows retryable.
        $pdo->exec("UPDATE bale_trade_deliveries SET status='pending',attempts=0,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE sent_at IS NULL");

        return [
            'ok'=>true,
            'enabled'=>$enabled,
            'chat_id'=>$chatId,
            'bot'=>$bot['result'] ?? null,
            'test_message_id'=>$test['result']['message_id'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    public function testConfigured(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $token = $this->configuredToken($pdo);
        $chat = $this->normalizeChatId((string)($this->setting($pdo, self::CHAT_KEY) ?? ''));
        $bot = $this->call($token, 'getMe');
        $message = $this->call($token, 'sendMessage', [
            'chat_id'=>$chat,
            'text'=>"🧪 پیام آزمایشی Trade\nاتصال بازو و کانال بله سالم است.",
        ]);
        return ['ok'=>true,'bot'=>$bot['result'] ?? null,'message_id'=>$message['result']['message_id'] ?? null];
    }

    public function setEnabled(bool $enabled, ?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        if ($enabled) {
            $status = $this->status($pdo);
            if (!($status['configured'] ?? false)) {
                throw new \RuntimeException('ابتدا توکن بازوی بله و شناسه کانال را ذخیره و تست کنید.');
            }
        }
        $this->writeSetting($pdo, self::ENABLED_KEY, $enabled ? '1' : '0');
    }

    public function disconnect(?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        $stmt = $pdo->prepare('DELETE FROM settings WHERE key_name IN (:token_key,:chat_key,:enabled_key)');
        // MySQL native prepares do not expand IN lists safely; use explicit keys.
        $pdo->exec("DELETE FROM settings WHERE key_name IN ('bale_bot_token_enc','bale_channel_chat_id','bale_trade_notifications_enabled')");
    }

    /**
     * Queue a confirmed trade and try immediate delivery.
     * Duplicate event keys are intentionally ignored.
     *
     * @param array<string,mixed> $trade
     */
    public function queueConfirmedTrade(string $eventKey, string $tradeType, array $trade, ?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        if (!$this->boolSetting($pdo, self::ENABLED_KEY)) return;
        $status = $this->status($pdo);
        if (!($status['configured'] ?? false)) return;

        $tradeType = strtolower(trim($tradeType));
        if (!in_array($tradeType, ['buy','sell'], true)) {
            throw new \InvalidArgumentException('Unsupported Bale trade type.');
        }
        $eventKey = trim($eventKey);
        if ($eventKey === '' || strlen($eventKey) > 191) {
            throw new \InvalidArgumentException('Invalid Bale delivery event key.');
        }

        $payload = json_encode($trade, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt = $pdo->prepare("INSERT INTO bale_trade_deliveries (event_key,trade_type,payload_json,status,attempts,created_at,updated_at)
            VALUES (:event_key,:trade_type,:payload,'pending',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE event_key=event_key");
        $stmt->execute([':event_key'=>$eventKey,':trade_type'=>$tradeType,':payload'=>$payload]);
        $this->deliverEvent($eventKey, $pdo);
    }

    /** @return array{attempted:int,sent:int,failed:int} */
    public function flushPending(int $limit = 10, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $this->ensureSchema($pdo);
        $limit = max(1, min(25, $limit));
        if (!$this->boolSetting($pdo, self::ENABLED_KEY)) return ['attempted'=>0,'sent'=>0,'failed'=>0];
        $status = $this->status($pdo);
        if (!($status['configured'] ?? false)) return ['attempted'=>0,'sent'=>0,'failed'=>0];

        $rows = $pdo->query("SELECT event_key FROM bale_trade_deliveries
            WHERE sent_at IS NULL
              AND attempts < 12
              AND (status IN ('pending','failed') OR (status='sending' AND last_attempt_at < (UTC_TIMESTAMP() - INTERVAL 5 MINUTE)))
              AND (last_attempt_at IS NULL OR last_attempt_at < (UTC_TIMESTAMP() - INTERVAL 45 SECOND))
            ORDER BY id ASC LIMIT {$limit}")->fetchAll();
        $out = ['attempted'=>0,'sent'=>0,'failed'=>0];
        foreach ($rows as $row) {
            $out['attempted']++;
            try {
                if ($this->deliverEvent((string)$row['event_key'], $pdo)) $out['sent']++;
                else $out['failed']++;
            } catch (\Throwable) {
                $out['failed']++;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $trade */
    public static function formatTradeMessage(string $tradeType, array $trade): string
    {
        $tradeType = strtolower($tradeType);
        $quote = strtoupper((string)($trade['quote_asset'] ?? 'IRT'));
        $asset = strtoupper((string)($trade['asset'] ?? ''));
        $symbol = strtoupper((string)($trade['symbol'] ?? ($asset . $quote)));
        $amount = max(0.0, (float)($trade['amount'] ?? 0));
        $price = max(0.0, (float)($trade[$tradeType === 'sell' ? 'exit_price' : 'entry_price'] ?? $trade['price'] ?? 0));
        $entry = max(0.0, (float)($trade['entry_price'] ?? 0));
        $value = $price * $amount;
        $displayUnit = $quote === 'IRT' ? 'تومان' : $quote;
        $strategy = self::strategyFa((string)($trade['strategy_key'] ?? ''));
        $regime = self::regimeFa((string)($trade['market_regime'] ?? ''));
        $time = (string)($trade['time_iran'] ?? self::iranNow());

        if ($tradeType === 'buy') {
            $lines = [
                '🟢 خرید انجام شد',
                'بازار: ' . ($asset !== '' ? $asset : $symbol) . ' / ' . $displayUnit,
                'مقدار: ' . self::formatNumber($amount, 8) . ($asset !== '' ? ' ' . $asset : ''),
                'قیمت خرید: ' . self::formatQuote($price, $quote) . ' ' . $displayUnit,
                'ارزش معامله: ' . self::formatQuote($value, $quote) . ' ' . $displayUnit,
            ];
            if ($strategy !== '') $lines[] = 'استراتژی: ' . $strategy;
            if ($regime !== '') $lines[] = 'وضعیت بازار: ' . $regime;
            if (isset($trade['tradable_net_edge_percent']) && is_numeric($trade['tradable_net_edge_percent'])) {
                $lines[] = 'سود مورد انتظار پس از هزینه‌ها: ' . self::formatNumber((float)$trade['tradable_net_edge_percent'], 3) . '٪';
            }
            $lines[] = 'زمان: ' . $time;
            return implode("\n", $lines);
        }

        $pnl = (float)($trade['net_pnl'] ?? $trade['pnl'] ?? 0.0);
        $pnlPct = (float)($trade['pnl_percent'] ?? 0.0);
        $reason = trim((string)($trade['exit_reason'] ?? ''));
        $lines = [
            '🔴 فروش انجام شد',
            'بازار: ' . ($asset !== '' ? $asset : $symbol) . ' / ' . $displayUnit,
            'مقدار: ' . self::formatNumber($amount, 8) . ($asset !== '' ? ' ' . $asset : ''),
            'قیمت خرید: ' . self::formatQuote($entry, $quote) . ' ' . $displayUnit,
            'قیمت فروش: ' . self::formatQuote($price, $quote) . ' ' . $displayUnit,
            'ارزش فروش: ' . self::formatQuote($value, $quote) . ' ' . $displayUnit,
            'سود/زیان: ' . ($pnl >= 0 ? '+' : '') . self::formatQuote($pnl, $quote) . ' ' . $displayUnit . ' (' . ($pnlPct >= 0 ? '+' : '') . self::formatNumber($pnlPct, 3) . '٪)',
        ];
        if ($reason !== '') $lines[] = 'دلیل خروج: ' . self::exitReasonFa($reason);
        if ($strategy !== '') $lines[] = 'استراتژی ورود: ' . $strategy;
        $lines[] = 'زمان: ' . $time;
        return implode("\n", $lines);
    }

    /** @return array<string,mixed> */
    private function call(string $token, string $method, array $payload = []): array
    {
        $this->validateToken($token);
        if (!extension_loaded('curl')) throw new \RuntimeException('افزونه cURL روی هاست فعال نیست.');
        $url = self::API_BASE . '/bot' . $token . '/' . $method;
        $ch = curl_init($url);
        if ($ch === false) throw new \RuntimeException('امکان ساخت درخواست بله وجود ندارد.');
        $body = json_encode($payload, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch, [
            CURLOPT_POST=>true,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>10,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
            CURLOPT_POSTFIELDS=>$body,
        ]);
        try {
            $raw = curl_exec($ch);
            if ($raw === false) throw new \RuntimeException('ارتباط با API بله برقرار نشد: ' . curl_error($ch));
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($ch);
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) throw new \RuntimeException('پاسخ API بله معتبر نیست.');
        if ($code < 200 || $code >= 300 || !($decoded['ok'] ?? false)) {
            $description = trim((string)($decoded['description'] ?? 'خطای نامشخص بله'));
            throw new \RuntimeException('بله: ' . mb_substr($description, 0, 500));
        }
        return $decoded;
    }

    private function deliverEvent(string $eventKey, PDO $pdo): bool
    {
        $this->ensureSchema($pdo);
        $stmt = $pdo->prepare("UPDATE bale_trade_deliveries SET status='sending',attempts=attempts+1,last_attempt_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
            WHERE event_key=:event_key AND sent_at IS NULL
              AND (status IN ('pending','failed') OR (status='sending' AND last_attempt_at < (UTC_TIMESTAMP() - INTERVAL 5 MINUTE)))");
        $stmt->execute([':event_key'=>$eventKey]);
        if ($stmt->rowCount() !== 1) return false;

        $stmt = $pdo->prepare('SELECT * FROM bale_trade_deliveries WHERE event_key=:event_key LIMIT 1');
        $stmt->execute([':event_key'=>$eventKey]);
        $row = $stmt->fetch();
        if (!$row) return false;

        try {
            $token = $this->configuredToken($pdo);
            $chat = $this->normalizeChatId((string)($this->setting($pdo, self::CHAT_KEY) ?? ''));
            $payload = json_decode((string)$row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) throw new \RuntimeException('Invalid Bale delivery payload.');
            $text = self::formatTradeMessage((string)$row['trade_type'], $payload);
            if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 3990) . '…';
            $this->call($token, 'sendMessage', ['chat_id'=>$chat,'text'=>$text]);
            $pdo->prepare("UPDATE bale_trade_deliveries SET status='sent',last_error=NULL,sent_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id")
                ->execute([':id'=>$row['id']]);
            return true;
        } catch (\Throwable $e) {
            $pdo->prepare("UPDATE bale_trade_deliveries SET status='failed',last_error=:error,updated_at=UTC_TIMESTAMP() WHERE id=:id")
                ->execute([':error'=>mb_substr($e->getMessage(), 0, 1000),':id'=>$row['id']]);
            throw $e;
        }
    }

    private function configuredToken(PDO $pdo): string
    {
        $enc = trim((string)($this->setting($pdo, self::TOKEN_KEY) ?? ''));
        if ($enc === '') throw new \RuntimeException('توکن بازوی بله تنظیم نشده است.');
        $key = (string)Config::require('app.encryption_key');
        $token = Crypto::decrypt($enc, $key);
        $this->validateToken($token);
        return $token;
    }

    private function validateToken(string $token): void
    {
        if (!preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{10,220}$/', $token)) {
            throw new \InvalidArgumentException('فرمت توکن بازوی بله معتبر نیست.');
        }
    }

    private function normalizeChatId(string $chatId): string
    {
        $chatId = trim($chatId);
        if ($chatId === '') throw new \InvalidArgumentException('شناسه یا نام کاربری کانال بله لازم است.');
        if (preg_match('/^-?[0-9]{3,30}$/', $chatId)) return $chatId;
        if (preg_match('/^@[A-Za-z0-9_]{4,64}$/', $chatId)) return $chatId;
        throw new \InvalidArgumentException('کانال را به‌صورت @channelusername یا شناسه عددی وارد کنید.');
    }

    private function setting(PDO $pdo, string $key): ?string
    {
        $stmt = $pdo->prepare('SELECT value_text FROM settings WHERE key_name=:key LIMIT 1');
        $stmt->execute([':key'=>$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string)$value;
    }

    private function boolSetting(PDO $pdo, string $key): bool
    {
        return in_array(strtolower(trim((string)($this->setting($pdo, $key) ?? '0'))), ['1','true','yes','on'], true);
    }

    private function writeSetting(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES (:key,:value,UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");
        $stmt->execute([':key'=>$key,':value'=>$value]);
    }

    private static function formatQuote(float $value, string $quote): string
    {
        if ($quote === 'IRT') $value /= 10.0; // Nobitex internal quote values are RLS; UI/channel is Toman.
        $decimals = $quote === 'IRT' ? 0 : 4;
        return number_format($value, $decimals, '.', ',');
    }

    private static function formatNumber(float $value, int $decimals): string
    {
        $out = number_format($value, $decimals, '.', ',');
        return rtrim(rtrim($out, '0'), '.');
    }

    private static function iranNow(): string
    {
        $date = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran'));
        return $date->format('Y-m-d H:i:s');
    }

    private static function strategyFa(string $key): string
    {
        return match ($key) {
            'trend_momentum_v1'=>'روند و مومنتوم',
            'breakout_v1'=>'شکست محدوده',
            'mean_reversion_v1'=>'بازگشت به میانگین',
            default=>trim($key),
        };
    }

    private static function regimeFa(string $key): string
    {
        return match ($key) {
            'trending_up'=>'روند صعودی',
            'trending_down'=>'روند نزولی',
            'breakout_up'=>'شکست صعودی',
            'breakout_down'=>'شکست نزولی',
            'ranging'=>'بازار رنج',
            'high_volatility'=>'نوسان شدید',
            'uncertain'=>'نامطمئن',
            default=>trim($key),
        };
    }

    private static function exitReasonFa(string $reason): string
    {
        return match ($reason) {
            'stop_loss'=>'حد ضرر',
            'take_profit'=>'حد سود',
            'trailing_stop'=>'حد ضرر متحرک',
            'profit_giveback'=>'قفل سود / برگشت از اوج',
            'stale_capital_release'=>'آزادسازی سرمایه راکد',
            'signal_reversal'=>'تغییر جهت سیگنال',
            'rotation'=>'تعویض با فرصت بهتر',
            default=>str_replace('_', ' ', $reason),
        };
    }
}
