<?php

declare(strict_types=1);

namespace Trade\Observability;

use Trade\Support\IranClock;
use Trade\Trading\TradeNotificationCenter;

final class ErrorReporter
{
    private static bool $installed = false;
    private static bool $capturing = false;
    private const FILE = 'error-events.log';

    public static function install(): void
    {
        if (self::$installed) return;
        self::$installed = true;

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) return false;
            if (!in_array($severity, [E_WARNING,E_USER_WARNING,E_USER_ERROR,E_RECOVERABLE_ERROR], true)) return false;
            self::capturePhpError($severity, $message, $file, $line);
            return false;
        });

        register_shutdown_function(static function (): void {
            $last = error_get_last();
            if (!is_array($last)) return;
            $fatal = [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR];
            if (!in_array((int)($last['type'] ?? 0), $fatal, true)) return;
            self::capturePhpError(
                (int)($last['type'] ?? E_ERROR),
                (string)($last['message'] ?? 'Fatal shutdown error'),
                (string)($last['file'] ?? ''),
                (int)($last['line'] ?? 0),
                'critical',
                'php_shutdown'
            );
        });
    }

    public static function captureThrowable(\Throwable $e, string $severity = 'error', string $component = 'runtime', array $context = []): void
    {
        self::capture([
            'severity'=>self::normalizeSeverity($severity),
            'component'=>$component,
            'message'=>$e->getMessage(),
            'class'=>$e::class,
            'file'=>$e->getFile(),
            'line'=>$e->getLine(),
            'trace'=>mb_substr($e->getTraceAsString(), 0, 6000),
            'context'=>$context,
        ]);
    }

    public static function capturePhpError(int $severity, string $message, string $file, int $line, ?string $forceSeverity = null, string $component = 'php'): void
    {
        $mapped = $forceSeverity ?? match ($severity) {
            E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR => 'critical',
            default => 'warning',
        };
        self::capture([
            'severity'=>$mapped,
            'component'=>$component,
            'message'=>$message,
            'class'=>'PHP_ERROR_' . $severity,
            'file'=>$file,
            'line'=>$line,
            'trace'=>null,
            'context'=>[],
        ]);
    }

    public static function capture(array $event): void
    {
        if (self::$capturing) return;
        self::$capturing = true;
        try {
            $severity = self::normalizeSeverity((string)($event['severity'] ?? 'error'));
            $message = mb_substr(trim((string)($event['message'] ?? 'Unknown error')), 0, 4000);
            $component = mb_substr(trim((string)($event['component'] ?? 'runtime')), 0, 120);
            $file = (string)($event['file'] ?? '');
            $line = max(0, (int)($event['line'] ?? 0));
            $fingerprint = hash('sha256', implode('|', [$component,$severity,$message,basename($file),(string)$line]));
            $payload = [
                'fingerprint'=>$fingerprint,
                'severity'=>$severity,
                'component'=>$component,
                'message'=>$message,
                'class'=>(string)($event['class'] ?? ''),
                'file'=>$file,
                'line'=>$line,
                'trace'=>$event['trace'] ?? null,
                'context'=>is_array($event['context'] ?? null) ? $event['context'] : [],
                'time_utc'=>gmdate(DATE_ATOM),
                'time_iran'=>IranClock::nowPayload(),
            ];
            self::append($payload);

            try {
                $priority = $severity === 'critical' ? 'critical' : ($severity === 'error' ? 'high' : 'normal');
                (new TradeNotificationCenter())->emit(
                    'runtime:' . substr($fingerprint,0,40) . ':' . intdiv(time(),600),
                    'system',
                    $priority,
                    $severity === 'critical' ? 'خطای بحرانی سیستم' : ($severity === 'error' ? 'خطای سیستم' : 'هشدار سیستم'),
                    mb_substr($component . ' • ' . $message, 0, 500),
                    ['fingerprint'=>$fingerprint,'component'=>$component,'file'=>basename($file),'line'=>$line]
                );
            } catch (\Throwable) {}

            if (in_array($severity, ['warning','error','critical'], true)) {
                try {
                    (new BaleSystemAlert())->queue(
                        $fingerprint,
                        $severity,
                        $severity === 'critical' ? 'خطای بحرانی Trade' : ($severity === 'error' ? 'خطای Trade' : 'هشدار Trade'),
                        $message,
                        [
                            'component'=>$component,
                            'file'=>$file !== '' ? basename($file) : null,
                            'line'=>$line > 0 ? $line : null,
                            'request_id'=>$payload['context']['request_id'] ?? null,
                            'status'=>$payload['context']['status'] ?? null,
                            'exchange'=>$payload['context']['exchange'] ?? null,
                            'symbol'=>$payload['context']['symbol'] ?? null,
                        ],
                        true
                    );
                } catch (\Throwable) {}
            }
        } finally {
            self::$capturing = false;
        }
    }

    public static function recent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $path = self::path();
        if (!is_file($path) || !is_readable($path)) return [];
        $size = (int)(filesize($path) ?: 0);
        $maxBytes = 524288;
        $start = max(0, $size - $maxBytes);
        $fh = @fopen($path, 'rb');
        if ($fh === false) return [];
        try {
            if ($start > 0) { fseek($fh, $start); fgets($fh); }
            $raw = stream_get_contents($fh);
        } finally { fclose($fh); }
        if (!is_string($raw) || $raw === '') return [];
        $rows = [];
        foreach (preg_split('/\R/', trim($raw)) ?: [] as $line) {
            if ($line === '') continue;
            $d = json_decode($line, true);
            if (is_array($d)) $rows[] = $d;
        }
        return array_slice(array_reverse($rows), 0, $limit);
    }

    public static function counts(int $minutes = 60): array
    {
        $since = time() - max(1, $minutes) * 60;
        $out = ['warning'=>0,'error'=>0,'critical'=>0,'total'=>0];
        foreach (self::recent(500) as $row) {
            $ts = isset($row['time_iran']['unix']) ? (int)$row['time_iran']['unix'] : strtotime((string)($row['time_utc'] ?? ''));
            if (!$ts || $ts < $since) continue;
            $sev = self::normalizeSeverity((string)($row['severity'] ?? 'error'));
            if (isset($out[$sev])) $out[$sev]++;
            $out['total']++;
        }
        return $out;
    }

    private static function append(array $payload): void
    {
        $dir = dirname(self::path());
        if (!is_dir($dir) || !is_writable($dir)) return;
        @file_put_contents(self::path(), json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL, FILE_APPEND|LOCK_EX);
    }

    private static function path(): string
    {
        return (defined('TRADE_ROOT') ? TRADE_ROOT : dirname(__DIR__,2)) . '/storage/' . self::FILE;
    }

    private static function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));
        return in_array($severity,['info','warning','error','critical'],true) ? $severity : 'error';
    }
}
