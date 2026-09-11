<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;
use Trade\Exchange\NobitexHttpException;

/**
 * Shared observability cache for full-wallet valuation.
 *
 * Trading/risk code continues to use live exchange reads. This cache is for
 * Admin/API/Android observability paths that may be polled frequently and would
 * otherwise duplicate wallet + order-book requests across PHP-FPM processes.
 */
final class NobitexPortfolioSnapshotCache
{
    private const FRESH_SECONDS = 15;
    private const STALE_SECONDS = 600;
    private const REFRESH_LEASE_SECONDS = 20;
    private static ?array $processCache = null;
    private static int $processCachedAt = 0;

    public function snapshot(?PDO $pdo = null, bool $force = false): array
    {
        $pdo ??= Database::connection();
        $this->ensureSchema($pdo);

        if (!$force && self::$processCache !== null && time() - self::$processCachedAt <= self::FRESH_SECONDS) {
            return $this->decorate(self::$processCache, 'process_fresh', time() - self::$processCachedAt);
        }

        $row = $this->load($pdo);
        $cached = $this->decode($row['snapshot_json'] ?? null);
        $age = $this->ageSeconds((string)($row['generated_at'] ?? ''));
        if (!$force && $cached !== null && $age <= self::FRESH_SECONDS) {
            self::$processCache = $cached;
            self::$processCachedAt = time() - $age;
            return $this->decorate($cached, 'shared_fresh', $age);
        }

        $claim = $this->claimRefresh($pdo, $force);
        if (!$claim['claimed']) {
            if ($cached !== null && $age <= self::STALE_SECONDS) {
                return $this->decorate($cached, 'shared_refresh_in_progress', $age, 'refresh_in_progress');
            }
            // No usable cache exists. A single caller must refresh even if the
            // lease state is inconsistent/stale.
            $claim = ['claimed'=>true];
        }

        try {
            $service = new NobitexOrderService();
            if (!$service->credentialsConfigured()) {
                $this->releaseRefresh($pdo);
                return ['status'=>'unavailable','reason'=>'credentials_missing','cache_state'=>'none'];
            }

            $client = $service->client();
            $wallets = $client->wallets();
            $positions = $pdo->query(
                "SELECT symbol,asset,quote_asset,amount,entry_price,mark_price,status
                 FROM nobitex_autotrade_positions
                 WHERE status IN ('pending_open','open','pending_close')
                 ORDER BY id ASC LIMIT 30"
            )->fetchAll();

            $snapshot = ['status'=>'ok'] + (new NobitexPortfolioValuation())->snapshot($client, $wallets, $positions);
            $this->store($pdo, $snapshot);
            self::$processCache = $snapshot;
            self::$processCachedAt = time();
            return $this->decorate($snapshot, 'refreshed', 0);
        } catch (\Throwable $e) {
            $this->releaseRefresh($pdo);

            if ($cached !== null && $age <= self::STALE_SECONDS) {
                return $this->decorate(
                    $cached,
                    self::isRateLimited($e) ? 'stale_rate_limited' : 'stale_refresh_failed',
                    $age,
                    self::isRateLimited($e) ? 'nobitex_rate_limited' : 'refresh_failed',
                    $e->getMessage()
                );
            }

            if (self::isRateLimited($e)) {
                return [
                    'status'=>'deferred',
                    'reason'=>'nobitex_rate_limited',
                    'cache_state'=>'empty_rate_limited',
                    'message'=>mb_substr($e->getMessage(), 0, 240),
                ];
            }
            throw $e;
        }
    }

    public static function isRateLimited(\Throwable $e): bool
    {
        if ($e instanceof NobitexHttpException && $e->statusCode === 429) return true;
        return preg_match('/Nobitex HTTP\s+429\b/i', $e->getMessage()) === 1;
    }

    private function ensureSchema(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS nobitex_portfolio_snapshot_cache (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                snapshot_json LONGTEXT NULL,
                generated_at DATETIME NULL,
                refreshing_until DATETIME NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "INSERT IGNORE INTO nobitex_portfolio_snapshot_cache
                (id,snapshot_json,generated_at,refreshing_until,updated_at)
             VALUES (1,NULL,NULL,NULL,UTC_TIMESTAMP())"
        );
    }

    private function load(PDO $pdo): array
    {
        $row = $pdo->query(
            'SELECT snapshot_json,generated_at,refreshing_until FROM nobitex_portfolio_snapshot_cache WHERE id=1 LIMIT 1'
        )->fetch();
        return is_array($row) ? $row : [];
    }

    private function claimRefresh(PDO $pdo, bool $force): array
    {
        return Database::transaction(function (PDO $tx) use ($force): array {
            $row = $tx->query(
                'SELECT generated_at,refreshing_until FROM nobitex_portfolio_snapshot_cache WHERE id=1 FOR UPDATE'
            )->fetch() ?: [];

            $generatedAge = $this->ageSeconds((string)($row['generated_at'] ?? ''));
            if (!$force && $generatedAge <= self::FRESH_SECONDS) {
                return ['claimed'=>false,'reason'=>'became_fresh'];
            }

            $refreshUntil = trim((string)($row['refreshing_until'] ?? ''));
            $refreshTs = $refreshUntil !== '' ? (strtotime($refreshUntil . ' UTC') ?: 0) : 0;
            if (!$force && $refreshTs > time()) {
                return ['claimed'=>false,'reason'=>'lease_active'];
            }

            $stmt = $tx->prepare(
                "UPDATE nobitex_portfolio_snapshot_cache
                 SET refreshing_until=:until,updated_at=UTC_TIMESTAMP() WHERE id=1"
            );
            $stmt->execute([':until'=>gmdate('Y-m-d H:i:s', time() + self::REFRESH_LEASE_SECONDS)]);
            return ['claimed'=>true];
        });
    }

    private function store(PDO $pdo, array $snapshot): void
    {
        $stmt = $pdo->prepare(
            "UPDATE nobitex_portfolio_snapshot_cache
             SET snapshot_json=:json,generated_at=UTC_TIMESTAMP(),refreshing_until=NULL,updated_at=UTC_TIMESTAMP()
             WHERE id=1"
        );
        $stmt->execute([
            ':json'=>json_encode($snapshot, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function releaseRefresh(PDO $pdo): void
    {
        try {
            $pdo->exec(
                "UPDATE nobitex_portfolio_snapshot_cache
                 SET refreshing_until=NULL,updated_at=UTC_TIMESTAMP() WHERE id=1"
            );
        } catch (\Throwable) {}
    }

    private function decode(mixed $json): ?array
    {
        if (!is_string($json) || trim($json) === '') return null;
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function ageSeconds(string $generatedAt): int
    {
        $generatedAt = trim($generatedAt);
        if ($generatedAt === '') return PHP_INT_MAX;
        $ts = strtotime($generatedAt . ' UTC');
        return $ts === false ? PHP_INT_MAX : max(0, time() - $ts);
    }

    private function decorate(array $snapshot, string $state, int $age, ?string $reason = null, ?string $error = null): array
    {
        $snapshot['cache_state'] = $state;
        $snapshot['cache_age_seconds'] = max(0, $age);
        if ($reason !== null) $snapshot['cache_reason'] = $reason;
        if ($error !== null) $snapshot['refresh_error'] = mb_substr($error, 0, 240);
        return $snapshot;
    }
}
