<?php

declare(strict_types=1);

namespace Trade;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;
    private static int $transactionDepth = 0;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = (string) Config::require('database.host');
        $port = (int) Config::get('database.port', 3306);
        $name = (string) Config::require('database.name');
        $user = (string) Config::require('database.user');
        $pass = (string) Config::get('database.password', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Retention/index maintenance may execute DELETE/ALTER TABLE and must not
        // add latency or metadata locks to normal web/API requests. cPanel cron
        // and other CLI entry points still perform the cheap once-per-day due check.
        if (PHP_SAPI === 'cli') {
            try { \Trade\Support\DatabaseMaintenance::runIfDue(self::$pdo); } catch (\Throwable) {}
        }

        return self::$pdo;
    }

    /**
     * Transaction wrapper with deterministic nested savepoints.
     *
     * Trading/accounting code frequently composes helpers that are transactional
     * on their own. Native PDO does not support beginTransaction() inside an
     * active transaction, so nested calls use SAVEPOINT instead of failing or
     * rolling back an unrelated outer unit of work.
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $outermost = self::$transactionDepth === 0;
        $savepoint = 'trade_sp_' . (self::$transactionDepth + 1);

        if ($outermost) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        self::$transactionDepth++;
        try {
            $result = $callback($pdo);
            self::$transactionDepth--;

            if ($outermost) {
                if ($pdo->inTransaction()) $pdo->commit();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (\Throwable $e) {
            self::$transactionDepth = max(0, self::$transactionDepth - 1);

            if ($pdo->inTransaction()) {
                if ($outermost) {
                    $pdo->rollBack();
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    try { $pdo->exec('RELEASE SAVEPOINT ' . $savepoint); } catch (\Throwable) {}
                }
            }
            throw $e;
        }
    }
}
