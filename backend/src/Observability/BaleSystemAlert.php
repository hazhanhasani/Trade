<?php

declare(strict_types=1);

namespace Trade\Observability;

use PDO;
use Trade\Config;
use Trade\Database;
use Trade\Security\Crypto;
use Trade\Support\IranClock;

final class BaleSystemAlert
{
    private const API_BASE = 'https://tapi.bale.ai';
    private const TOKEN_KEY = 'bale_bot_token_enc';
    private const CHAT_KEY = 'bale_channel_chat_id';
    private const ENABLED_KEY = 'bale_trade_notifications_enabled';

    public function ensureSchema(?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS bale_system_alert_deliveries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_key VARCHAR(191) NOT NULL,
            severity VARCHAR(16) NOT NULL,
            title VARCHAR(255) NOT NULL,
            body_text TEXT NOT NULL,
            context_json LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(1000) NULL,
            last_attempt_at DATETIME NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_bale_system_event (event_key),
            INDEX idx_bale_system_pending (status,sent_at,updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function queue(string $fingerprint, string $severity, string $title, string $body, array $context = [], bool $immediate = true, ?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        if (!$this->configured($pdo)) return;
        $this->ensureSchema($pdo);
        $severity = $this->severity($severity);

        // Fixed wall-clock buckets can send two identical warnings seconds apart
        // when the error happens on both sides of a bucket boundary. Use a true
        // sliding window instead: first occurrence is immediate, then identical
        // noise is suppressed while critical incidents can repeat sooner.
        $hash = substr(hash('sha256', $fingerprint), 0, 40);
        $windowSeconds = match ($severity) {
            'critical' => 120,
            'error' => 300,
            'warning' => 600,
            default => 900,
        };
        if ($this->recentlyQueued($pdo, $hash, $windowSeconds)) return;

        $eventKey = 'system:' . $hash . ':' . time() . ':' . substr(bin2hex(random_bytes(3)), 0, 6);
        $stmt = $pdo->prepare("INSERT INTO bale_system_alert_deliveries
            (event_key,severity,title,body_text,context_json,status,attempts,created_at,updated_at)
            VALUES (:event,:severity,:title,:body,:context,'pending',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE event_key=event_key");
        $stmt->execute([
            ':event'=>$eventKey,
            ':severity'=>$severity,
            ':title'=>mb_substr(trim($title), 0, 255),
            ':body'=>mb_substr(trim($body), 0, 4000),
            ':context'=>$context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        if ($immediate) {
            try { $this->deliver($eventKey, $pdo); } catch (\Throwable) {}
        }
    }

    public function flushPending(int $limit = 15, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        if (!$this->configured($pdo)) return ['attempted'=>0,'sent'=>0,'failed'=>0];
        $this->ensureSchema($pdo);
        $limit = max(1, min(50, $limit));
        $rows = $pdo->query("SELECT event_key FROM bale_system_alert_deliveries
            WHERE sent_at IS NULL AND attempts < 20
              AND (status IN ('pending','failed') OR (status='sending' AND last_attempt_at < (UTC_TIMESTAMP() - INTERVAL 5 MINUTE)))
              AND (last_attempt_at IS NULL OR last_attempt_at < (UTC_TIMESTAMP() - INTERVAL 30 SECOND))
            ORDER BY id ASC LIMIT {$limit}")->fetchAll();
        $out=['attempted'=>0,'sent'=>0,'failed'=>0];
        foreach ($rows as $row) {
            $out['attempted']++;
            try { $this->deliver((string)$row['event_key'], $pdo) ? $out['sent']++ : $out['failed']++; }
            catch (\Throwable) { $out['failed']++; }
        }
        return $out;
    }

    public function pendingCount(?PDO $pdo = null): int
    {
        try {
            $pdo ??= Database::connection();
            $this->ensureSchema($pdo);
            return (int)$pdo->query("SELECT COUNT(*) FROM bale_system_alert_deliveries WHERE sent_at IS NULL")->fetchColumn();
        } catch (\Throwable) { return 0; }
    }

    private function recentlyQueued(PDO $pdo, string $hash, int $seconds): bool
    {
        $seconds = max(30, min(3600, $seconds));
        $stmt = $pdo->prepare("SELECT EXISTS(
            SELECT 1 FROM bale_system_alert_deliveries
            WHERE event_key LIKE :prefix
              AND created_at >= (UTC_TIMESTAMP() - INTERVAL {$seconds} SECOND)
        )");
        $stmt->execute([':prefix'=>'system:' . $hash . ':%']);
        return (bool)$stmt->fetchColumn();
    }

    private function deliver(string $eventKey, PDO $pdo): bool
    {
        $stmt=$pdo->prepare("UPDATE bale_system_alert_deliveries SET status='sending',attempts=attempts+1,last_attempt_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
            WHERE event_key=:event AND sent_at IS NULL AND status IN ('pending','failed','sending')");
        $stmt->execute([':event'=>$eventKey]);
        if ($stmt->rowCount() < 1) return false;
        $stmt=$pdo->prepare('SELECT * FROM bale_system_alert_deliveries WHERE event_key=:event LIMIT 1');
        $stmt->execute([':event'=>$eventKey]);
        $row=$stmt->fetch();
        if (!$row) return false;
        try {
            $text=$this->format($row);
            $this->send($pdo,$text);
            $pdo->prepare("UPDATE bale_system_alert_deliveries SET status='sent',last_error=NULL,sent_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id")
                ->execute([':id'=>$row['id']]);
            return true;
        } catch (\Throwable $e) {
            $pdo->prepare("UPDATE bale_system_alert_deliveries SET status='failed',last_error=:error,updated_at=UTC_TIMESTAMP() WHERE id=:id")
                ->execute([':error'=>mb_substr($e->getMessage(),0,1000),':id'=>$row['id']]);
            throw $e;
        }
    }

    private function format(array $row): string
    {
        $severity=strtolower((string)$row['severity']);
        $icon=match($severity){'critical'=>'🚨','error'=>'🔴','warning'=>'🟠',default=>'🔵'};
        $label=match($severity){'critical'=>'بحرانی','error'=>'خطا','warning'=>'هشدار',default=>'اطلاع'};
        $lines=[
            $icon . ' Trade — ' . $label,
            'عنوان: ' . (string)$row['title'],
            'جزئیات: ' . (string)$row['body_text'],
            'زمان ایران: ' . IranClock::formatUtc((string)$row['created_at']),
        ];
        $context=json_decode((string)($row['context_json']??''),true);
        if (is_array($context)) {
            foreach (['component'=>'بخش','exchange'=>'صرافی','symbol'=>'بازار','request_id'=>'شناسه','status'=>'وضعیت'] as $k=>$fa) {
                if (isset($context[$k]) && is_scalar($context[$k]) && trim((string)$context[$k])!=='') $lines[]=$fa . ': ' . mb_substr((string)$context[$k],0,180);
            }
        }
        return mb_substr(implode("\n",$lines),0,4000);
    }

    private function send(PDO $pdo, string $text): void
    {
        $tokenEnc=$this->setting($pdo,self::TOKEN_KEY);
        $chat=trim((string)($this->setting($pdo,self::CHAT_KEY)??''));
        if ($tokenEnc===null || $tokenEnc==='' || $chat==='') throw new \RuntimeException('Bale system alerts are not configured.');
        $token=Crypto::decrypt($tokenEnc,(string)Config::require('app.encryption_key'));
        if (!preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{10,220}$/',$token)) throw new \RuntimeException('Invalid Bale token.');
        if (!extension_loaded('curl')) throw new \RuntimeException('cURL is unavailable.');
        $ch=curl_init(self::API_BASE.'/bot'.$token.'/sendMessage');
        if ($ch===false) throw new \RuntimeException('Unable to initialize Bale request.');
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>8,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
            CURLOPT_POSTFIELDS=>json_encode(['chat_id'=>$chat,'text'=>$text],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        try { $raw=curl_exec($ch); $err=curl_error($ch); $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); }
        finally { curl_close($ch); }
        if ($raw===false) throw new \RuntimeException('Bale network error: '.$err);
        $json=json_decode((string)$raw,true);
        if ($code<200 || $code>=300 || !is_array($json) || !($json['ok']??false)) throw new \RuntimeException('Bale alert delivery failed (HTTP '.$code.').');
    }

    private function configured(PDO $pdo): bool
    {
        $enabled=in_array(strtolower(trim((string)($this->setting($pdo,self::ENABLED_KEY)??'0'))),['1','true','yes','on'],true);
        return $enabled && trim((string)($this->setting($pdo,self::TOKEN_KEY)??''))!=='' && trim((string)($this->setting($pdo,self::CHAT_KEY)??''))!=='';
    }

    private function setting(PDO $pdo,string $key):?string
    {
        $s=$pdo->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');$s->execute([':k'=>$key]);$v=$s->fetchColumn();return$v===false?null:(string)$v;
    }

    private function severity(string $severity): string
    {
        $severity=strtolower(trim($severity));
        return in_array($severity,['info','warning','error','critical'],true)?$severity:'error';
    }
}
