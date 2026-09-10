<?php

declare(strict_types=1);

namespace Trade\Integrations;

use PDO;
use Trade\Config;
use Trade\Database;
use Trade\Security\Crypto;

final class BaleTradeNotifier
{
    private const API_BASE = 'https://tapi.bale.ai';
    private const TOKEN_KEY = 'bale_bot_token_enc';
    private const CHAT_KEY = 'bale_channel_chat_id';
    private const ENABLED_KEY = 'bale_trade_notifications_enabled';
    private const START_KEY = 'bale_trade_sync_started_at';

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
            'sync_started_at'=>$this->setting($pdo, self::START_KEY),
            'pending_deliveries'=>$pending,
            'failed_deliveries'=>$failed,
            'last_delivery'=>$last,
        ];
    }

    public function saveAndTest(string $tokenInput, string $chatId, bool $enabled = true, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $this->ensureSchema($pdo);
        $before = $this->status($pdo);
        $chatId = $this->normalizeChatId($chatId);
        $token = trim($tokenInput);
        if ($token === '') $token = $this->configuredToken($pdo); else $this->validateToken($token);

        $bot = $this->call($token, 'getMe');
        $test = $this->call($token, 'sendMessage', [
            'chat_id'=>$chatId,
            'text'=>"✅ اتصال Trade به کانال بله با موفقیت برقرار شد.\nاز این پس خرید و فروش‌های تأییدشده در این کانال ارسال می‌شوند.",
        ]);

        $encKey = (string)Config::require('app.encryption_key');
        $this->writeSetting($pdo, self::TOKEN_KEY, Crypto::encrypt($token, $encKey));
        $this->writeSetting($pdo, self::CHAT_KEY, $chatId);
        $this->writeSetting($pdo, self::ENABLED_KEY, $enabled ? '1' : '0');
        if (!($before['configured'] ?? false) || (!(bool)($before['enabled'] ?? false) && $enabled) || $this->setting($pdo, self::START_KEY) === null) {
            $this->writeSetting($pdo, self::START_KEY, gmdate('Y-m-d H:i:s'));
        }
        $pdo->exec("UPDATE bale_trade_deliveries SET status='pending',attempts=0,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE sent_at IS NULL");
        return [
            'ok'=>true,
            'enabled'=>$enabled,
            'chat_id'=>$chatId,
            'bot'=>$bot['result'] ?? null,
            'test_message_id'=>$test['result']['message_id'] ?? null,
        ];
    }

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
        $wasEnabled = $this->boolSetting($pdo, self::ENABLED_KEY);
        if ($enabled) {
            $status = $this->status($pdo);
            if (!($status['configured'] ?? false)) throw new \RuntimeException('ابتدا توکن بازوی بله و شناسه کانال را ذخیره و تست کنید.');
            if (!$wasEnabled) $this->writeSetting($pdo, self::START_KEY, gmdate('Y-m-d H:i:s'));
        }
        $this->writeSetting($pdo, self::ENABLED_KEY, $enabled ? '1' : '0');
    }

    public function disconnect(?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        $pdo->exec("DELETE FROM settings WHERE key_name IN ('bale_bot_token_enc','bale_channel_chat_id','bale_trade_notifications_enabled','bale_trade_sync_started_at')");
    }

    public function syncConfirmedTrades(int $limit = 50, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $this->ensureSchema($pdo);
        $limit = max(1, min(100, $limit));
        if (!$this->boolSetting($pdo, self::ENABLED_KEY)) return ['buys_queued'=>0,'sells_queued'=>0];
        if (!(bool)($this->status($pdo)['configured'] ?? false)) return ['buys_queued'=>0,'sells_queued'=>0];
        $since = trim((string)($this->setting($pdo, self::START_KEY) ?? ''));
        if ($since === '') {
            $since = gmdate('Y-m-d H:i:s');
            $this->writeSetting($pdo, self::START_KEY, $since);
            return ['buys_queued'=>0,'sells_queued'=>0];
        }

        $reasons = $this->exitReasons($pdo, $since);
        $buysQueued = 0;
        $sellsQueued = 0;

        $stmt = $pdo->prepare("SELECT id,symbol,asset,quote_asset,amount,entry_price,entry_order_local_id,opened_at
            FROM nobitex_autotrade_positions
            WHERE opened_at IS NOT NULL AND opened_at>=:since
            ORDER BY id ASC LIMIT {$limit}");
        $stmt->execute([':since'=>$since]);
        foreach ($stmt->fetchAll() as $row) {
            $eventKey = 'nobitex-buy-position:' . (int)$row['id'];
            if ($this->deliveryExists($pdo, $eventKey)) continue;
            $meta = $this->signalMeta($pdo, (string)($row['entry_order_local_id'] ?? ''));
            $this->queueConfirmedTrade($eventKey, 'buy', [
                'position_id'=>(int)$row['id'],
                'symbol'=>(string)$row['symbol'],
                'asset'=>(string)$row['asset'],
                'quote_asset'=>(string)$row['quote_asset'],
                'amount'=>(float)$row['amount'],
                'entry_price'=>(float)$row['entry_price'],
                'strategy_key'=>$meta['strategy_key'] ?? null,
                'market_regime'=>$meta['market_regime'] ?? null,
                'tradable_net_edge_percent'=>$meta['tradable_net_edge_percent'] ?? null,
                'time_iran'=>self::iranFromUtc((string)$row['opened_at']),
            ], $pdo, false);
            $buysQueued++;
        }

        $stmt = $pdo->prepare("SELECT r.id AS pnl_id,r.position_id,r.pnl,r.pnl_percent,r.net_pnl,r.exit_price,r.amount,r.created_at,
                    p.symbol,p.asset,p.quote_asset,p.entry_price,p.entry_order_local_id
            FROM nobitex_autotrade_pnl r
            JOIN nobitex_autotrade_positions p ON p.id=r.position_id
            WHERE r.created_at>=:since
            ORDER BY r.id ASC LIMIT {$limit}");
        $stmt->execute([':since'=>$since]);
        foreach ($stmt->fetchAll() as $row) {
            $eventKey = 'nobitex-sell-pnl:' . (int)$row['pnl_id'];
            if ($this->deliveryExists($pdo, $eventKey)) continue;
            $meta = $this->signalMeta($pdo, (string)($row['entry_order_local_id'] ?? ''));
            $positionId = (int)$row['position_id'];
            $this->queueConfirmedTrade($eventKey, 'sell', [
                'position_id'=>$positionId,
                'symbol'=>(string)$row['symbol'],
                'asset'=>(string)$row['asset'],
                'quote_asset'=>(string)$row['quote_asset'],
                'amount'=>(float)$row['amount'],
                'entry_price'=>(float)$row['entry_price'],
                'exit_price'=>(float)$row['exit_price'],
                'pnl'=>(float)$row['pnl'],
                'net_pnl'=>$row['net_pnl'] !== null ? (float)$row['net_pnl'] : (float)$row['pnl'],
                'pnl_percent'=>(float)$row['pnl_percent'],
                'strategy_key'=>$meta['strategy_key'] ?? null,
                'market_regime'=>$meta['market_regime'] ?? null,
                'exit_reason'=>$reasons[$positionId] ?? 'autotrade_exit',
                'time_iran'=>self::iranFromUtc((string)$row['created_at']),
            ], $pdo, false);
            $sellsQueued++;
        }

        return ['buys_queued'=>$buysQueued,'sells_queued'=>$sellsQueued];
    }

    public function queueConfirmedTrade(string $eventKey, string $tradeType, array $trade, ?PDO $pdo = null, bool $attemptImmediate = true): void
    {
        $pdo ??= Database::connection();
        if (!$this->boolSetting($pdo, self::ENABLED_KEY)) return;
        $status = $this->status($pdo);
        if (!($status['configured'] ?? false)) return;
        $tradeType = strtolower(trim($tradeType));
        if (!in_array($tradeType, ['buy','sell'], true)) throw new \InvalidArgumentException('Unsupported Bale trade type.');
        $eventKey = trim($eventKey);
        if ($eventKey === '' || strlen($eventKey) > 191) throw new \InvalidArgumentException('Invalid Bale delivery event key.');

        $payload = json_encode($trade, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt = $pdo->prepare("INSERT INTO bale_trade_deliveries (event_key,trade_type,payload_json,status,attempts,created_at,updated_at)
            VALUES (:event_key,:trade_type,:payload,'pending',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE event_key=event_key");
        $stmt->execute([':event_key'=>$eventKey,':trade_type'=>$tradeType,':payload'=>$payload]);
        if ($attemptImmediate) $this->deliverEvent($eventKey, $pdo);
    }

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
                if ($this->deliverEvent((string)$row['event_key'], $pdo)) $out['sent']++; else $out['failed']++;
            } catch (\Throwable) {
                $out['failed']++;
            }
        }
        return $out;
    }

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
                'صرافی: نوبیتکس',
                'بازار: ' . ($asset !== '' ? $asset : $symbol) . ' / ' . $displayUnit,
                'مقدار: ' . self::formatNumber($amount, 8) . ($asset !== '' ? ' ' . $asset : ''),
                'قیمت خرید: ' . self::formatPrice($price, $quote) . ' ' . $displayUnit,
                'ارزش معامله: ' . self::formatQuote($value, $quote) . ' ' . $displayUnit,
            ];
            if ($strategy !== '') $lines[] = 'استراتژی: ' . $strategy;
            if ($regime !== '') $lines[] = 'وضعیت بازار: ' . $regime;
            if (isset($trade['tradable_net_edge_percent']) && is_numeric($trade['tradable_net_edge_percent'])) {
                $lines[] = 'سود مورد انتظار پس از هزینه‌ها: ' . self::formatNumber((float)$trade['tradable_net_edge_percent'], 3) . '٪';
            }
            $lines[] = 'زمان ایران: ' . $time;
            return implode("\n", $lines);
        }

        $pnl = (float)($trade['net_pnl'] ?? $trade['pnl'] ?? 0.0);
        $pnlPct = (float)($trade['pnl_percent'] ?? 0.0);
        $reason = trim((string)($trade['exit_reason'] ?? ''));
        $lines = [
            '🔴 فروش انجام شد',
            'صرافی: نوبیتکس',
            'بازار: ' . ($asset !== '' ? $asset : $symbol) . ' / ' . $displayUnit,
            'مقدار: ' . self::formatNumber($amount, 8) . ($asset !== '' ? ' ' . $asset : ''),
            'قیمت خرید: ' . self::formatPrice($entry, $quote) . ' ' . $displayUnit,
            'قیمت فروش: ' . self::formatPrice($price, $quote) . ' ' . $displayUnit,
            'ارزش فروش: ' . self::formatQuote($value, $quote) . ' ' . $displayUnit,
            'سود/زیان: ' . ($pnl >= 0 ? '+' : '') . self::formatQuote($pnl, $quote) . ' ' . $displayUnit . ' (' . ($pnlPct >= 0 ? '+' : '') . self::formatNumber($pnlPct, 3) . '٪)',
        ];
        if ($reason !== '') $lines[] = 'دلیل خروج: ' . self::exitReasonFa($reason);
        if ($strategy !== '') $lines[] = 'استراتژی ورود: ' . $strategy;
        $lines[] = 'زمان ایران: ' . $time;
        return implode("\n", $lines);
    }

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

    private function signalMeta(PDO $pdo, string $localId): array
    {
        if ($localId === '') return [];
        $stmt = $pdo->prepare('SELECT details_json FROM nobitex_autotrade_signals WHERE order_local_id=:local ORDER BY id DESC LIMIT 1');
        $stmt->execute([':local'=>$localId]);
        $details = json_decode((string)($stmt->fetchColumn() ?: ''), true);
        if (!is_array($details)) return [];
        return [
            'strategy_key'=>(string)($details['strategy_key'] ?? $details['selected_strategy']['key'] ?? ''),
            'market_regime'=>(string)($details['market_regime']['regime'] ?? $details['regime'] ?? ''),
            'tradable_net_edge_percent'=>isset($details['tradable_net_edge_percent']) && is_numeric($details['tradable_net_edge_percent']) ? (float)$details['tradable_net_edge_percent'] : null,
        ];
    }

    private function exitReasons(PDO $pdo, string $since): array
    {
        $stmt = $pdo->prepare("SELECT context_json FROM nobitex_autotrade_events WHERE event_name='nobitex.portfolio.sell_submitted' AND created_at>=:since ORDER BY id DESC LIMIT 250");
        $stmt->execute([':since'=>$since]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $ctx = json_decode((string)($row['context_json'] ?? ''), true);
            if (!is_array($ctx)) continue;
            $id = (int)($ctx['position_id'] ?? 0);
            if ($id > 0 && !isset($out[$id])) $out[$id] = (string)($ctx['reason'] ?? 'autotrade_exit');
        }
        return $out;
    }

    private function deliveryExists(PDO $pdo, string $eventKey): bool
    {
        $stmt = $pdo->prepare('SELECT EXISTS(SELECT 1 FROM bale_trade_deliveries WHERE event_key=:event_key)');
        $stmt->execute([':event_key'=>$eventKey]);
        return (bool)$stmt->fetchColumn();
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
        if (!preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{10,220}$/', $token)) throw new \InvalidArgumentException('فرمت توکن بازوی بله معتبر نیست.');
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
        $stmt = $pdo->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES (:key,:value,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");
        $stmt->execute([':key'=>$key,':value'=>$value]);
    }

    /** Monetary totals/PnL: keep IRT output compact and in Toman. */
    private static function formatQuote(float $value, string $quote): string
    {
        if ($quote === 'IRT') $value /= 10.0;
        return number_format($value, $quote === 'IRT' ? 0 : 4, '.', ',');
    }

    /**
     * Unit price needs adaptive precision. Rounding a 1.46 Toman token to
     * "1 Toman" makes a confirmed trade message economically misleading.
     */
    private static function formatPrice(float $value, string $quote): string
    {
        if ($quote === 'IRT') {
            $value /= 10.0;
            $abs = abs($value);
            $decimals = $abs >= 1000.0 ? 0 : ($abs >= 10.0 ? 2 : ($abs >= 1.0 ? 4 : ($abs >= 0.01 ? 6 : 8)));
            return self::formatNumber($value, $decimals);
        }
        $abs = abs($value);
        $decimals = $abs >= 1000.0 ? 4 : ($abs >= 1.0 ? 6 : 8);
        return self::formatNumber($value, $decimals);
    }

    private static function formatNumber(float $value, int $decimals): string
    {
        $out = number_format($value, $decimals, '.', ',');
        return rtrim(rtrim($out, '0'), '.');
    }

    private static function iranNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d H:i:s');
    }

    private static function iranFromUtc(string $utc): string
    {
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return self::iranNow();
        }
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
        $normalized = strtolower(trim(str_replace(['-', ' '], '_', $reason)));
        return match ($normalized) {
            'stop_loss'=>'حد ضرر',
            'take_profit'=>'حد سود',
            'trailing_stop'=>'حد ضرر متحرک',
            'trailing_profit_lock'=>'قفل سود متحرک / برگشت از اوج',
            'profit_lock'=>'قفل سود',
            'profit_giveback'=>'قفل سود / برگشت از اوج',
            'stale_capital_release'=>'آزادسازی سرمایه راکد',
            'signal_reversal'=>'تغییر جهت سیگنال',
            'rotation'=>'تعویض با فرصت بهتر',
            'autotrade_exit'=>'خروج خودکار ربات',
            default=>str_replace('_', ' ', $normalized),
        };
    }
}
