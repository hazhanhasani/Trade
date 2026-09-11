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
        $trace = $e->getTrace();
        $context['caller'] ??= self::callerFromTrace($trace);
        self::capture([
            'severity'=>self::normalizeSeverity($severity),
            'component'=>$component,
            'message'=>$e->getMessage(),
            'class'=>$e::class,
            'file'=>$e->getFile(),
            'line'=>$e->getLine(),
            'trace'=>self::sanitizeTrace($e->getTraceAsString()),
            'context'=>$context,
        ]);
    }

    /**
     * Application-level diagnostic log. It is persisted locally and mirrored to
     * Bale immediately when Bale notifications are configured.
     */
    public static function log(string $message, string $component = 'runtime_log', array $context = [], string $severity = 'info'): void
    {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $bt[1] ?? $bt[0] ?? [];
        $context['caller'] ??= self::frameName($caller);
        self::capture([
            'severity'=>self::normalizeSeverity($severity),
            'component'=>$component,
            'message'=>$message,
            'class'=>'APPLICATION_LOG',
            'file'=>(string)($caller['file'] ?? ''),
            'line'=>(int)($caller['line'] ?? 0),
            'trace'=>null,
            'context'=>$context,
        ]);
    }

    public static function capturePhpError(int $severity, string $message, string $file, int $line, ?string $forceSeverity = null, string $component = 'php'): void
    {
        $mapped = $forceSeverity ?? match ($severity) {
            E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR => 'critical',
            E_WARNING,E_USER_WARNING,E_CORE_WARNING,E_COMPILE_WARNING => 'warning',
            E_NOTICE,E_USER_NOTICE,E_DEPRECATED,E_USER_DEPRECATED,E_STRICT => 'info',
            default => 'warning',
        };
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        self::capture([
            'severity'=>$mapped,
            'component'=>$component,
            'message'=>$message,
            'class'=>self::phpSeverityName($severity),
            'file'=>$file,
            'line'=>$line,
            'trace'=>self::traceFromFrames($bt),
            'context'=>[
                'error_type'=>self::phpSeverityName($severity),
                'error_code'=>$severity,
                'caller'=>self::callerFromTrace($bt),
            ],
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
            $absoluteFile = (string)($event['file'] ?? '');
            $file = self::relativeFile($absoluteFile);
            $line = max(0, (int)($event['line'] ?? 0));
            $class = mb_substr((string)($event['class'] ?? ''), 0, 240);
            $trace = isset($event['trace']) && is_string($event['trace']) ? mb_substr(self::sanitizeTrace($event['trace']), 0, 6000) : null;
            $eventContext = is_array($event['context'] ?? null) ? $event['context'] : [];
            $diagnostic = self::explain($message, $class, $component, $severity);
            $context = array_merge(self::runtimeContext(), $eventContext);
            $context['diagnosis'] ??= $diagnostic['diagnosis'];
            $context['action'] ??= $diagnostic['action'];
            $fingerprint = hash('sha256', implode('|', [$component,$severity,$message,$file,(string)$line]));
            $payload = [
                'fingerprint'=>$fingerprint,
                'severity'=>$severity,
                'component'=>$component,
                'message'=>$message,
                'class'=>$class,
                'file'=>$file,
                'absolute_file'=>$absoluteFile,
                'line'=>$line,
                'trace'=>$trace,
                'context'=>$context,
                'time_utc'=>gmdate(DATE_ATOM),
                'time_iran'=>IranClock::nowPayload(),
            ];
            self::append($payload);

            try {
                $priority = $severity === 'critical' ? 'critical' : ($severity === 'error' ? 'high' : 'normal');
                $title = match ($severity) {
                    'critical' => 'خطای بحرانی سیستم',
                    'error' => 'خطای سیستم',
                    'warning' => 'هشدار سیستم',
                    default => 'لاگ فنی سیستم',
                };
                (new TradeNotificationCenter())->emit(
                    'runtime:' . substr($fingerprint,0,40) . ':' . intdiv(time(),600),
                    'system',
                    $priority,
                    $title,
                    mb_substr($component . ' • ' . $message, 0, 500),
                    ['fingerprint'=>$fingerprint,'component'=>$component,'file'=>$file,'line'=>$line]
                );
            } catch (\Throwable) {}

            // Mirror every captured diagnostic level to Bale, including notices,
            // deprecations and explicit info logs. Exact path + line are included.
            try {
                $baleTitle = match ($severity) {
                    'critical' => 'خطای بحرانی Trade',
                    'error' => 'خطای Trade',
                    'warning' => 'هشدار Trade',
                    default => 'لاگ فنی Trade',
                };
                (new BaleSystemAlert())->queue(
                    $fingerprint,
                    $severity,
                    $baleTitle,
                    $message,
                    [
                        'fingerprint'=>substr($fingerprint,0,16),
                        'component'=>$component,
                        'file'=>$file !== '' ? $file : null,
                        'line'=>$line > 0 ? $line : null,
                        'error_type'=>$context['error_type'] ?? ($class !== '' ? $class : null),
                        'diagnosis'=>$context['diagnosis'] ?? null,
                        'action'=>$context['action'] ?? null,
                        'caller'=>$context['caller'] ?? null,
                        'pid'=>$context['pid'] ?? null,
                        'sapi'=>$context['sapi'] ?? null,
                        'request'=>$context['request'] ?? null,
                        'method'=>$context['method'] ?? null,
                        'run_id'=>$context['run_id'] ?? null,
                        'request_id'=>$context['request_id'] ?? null,
                        'status'=>$context['status'] ?? null,
                        'exchange'=>$context['exchange'] ?? null,
                        'symbol'=>$context['symbol'] ?? null,
                        'trace'=>$trace !== null ? mb_substr($trace,0,1200) : null,
                    ],
                    true
                );
            } catch (\Throwable) {}
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
        foreach (preg_split('/\R/', trim($raw)) ?: [] as $logLine) {
            if ($logLine === '') continue;
            $d = json_decode($logLine, true);
            if (is_array($d)) $rows[] = $d;
        }
        return array_slice(array_reverse($rows), 0, $limit);
    }

    public static function counts(int $minutes = 60): array
    {
        $since = time() - max(1, $minutes) * 60;
        $out = ['info'=>0,'warning'=>0,'error'=>0,'critical'=>0,'total'=>0];
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

    private static function relativeFile(string $file): string
    {
        if ($file === '') return '';
        $normalized = str_replace('\\','/',$file);
        $root = str_replace('\\','/', (string)(defined('TRADE_ROOT') ? TRADE_ROOT : dirname(__DIR__,2)));
        if ($root !== '' && str_starts_with($normalized, rtrim($root,'/') . '/')) {
            return 'backend/' . ltrim(substr($normalized, strlen(rtrim($root,'/'))), '/');
        }
        return $normalized;
    }

    private static function runtimeContext(): array
    {
        $request = '';
        if (isset($_SERVER['REQUEST_URI'])) {
            $request = (string)(parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '');
        }
        return [
            'pid'=>getmypid() ?: null,
            'sapi'=>PHP_SAPI,
            'php_version'=>PHP_VERSION,
            'request'=>$request !== '' ? $request : null,
            'method'=>isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : null,
            'memory_bytes'=>memory_get_usage(true),
        ];
    }

    private static function explain(string $message, string $class, string $component, string $severity): array
    {
        $m = strtolower($message);
        if (str_contains($m,'array to string conversion')) {
            return ['diagnosis'=>'یک مقدار آرایه‌ای در جایی استفاده شده که PHP انتظار رشته داشته است.','action'=>'فایل و خط اعلام‌شده را بررسی کن؛ قبل از اتصال/نمایش مقدار، نوع داده را با is_array یا تبدیل صریح کنترل کن.'];
        }
        if (str_contains($m,'undefined array key') || str_contains($m,'undefined index')) {
            return ['diagnosis'=>'کد به کلیدی از آرایه دسترسی زده که در داده ورودی وجود نداشته است.','action'=>'در محل دقیق اعلام‌شده از ??، isset یا اعتبارسنجی ساختار پاسخ قبل از دسترسی استفاده کن.'];
        }
        if (str_contains($m,'deprecated') || str_contains(strtolower($class),'deprecated')) {
            return ['diagnosis'=>'کد از قابلیت منسوخ PHP یا کتابخانه استفاده می‌کند و در نسخه‌های آینده ممکن است بشکند.','action'=>'همان فراخوانی در فایل/خط اعلام‌شده را با API جدید جایگزین کن.'];
        }
        if (str_contains($m,'curl error 28') || str_contains($m,'timed out') || str_contains($m,'timeout')) {
            return ['diagnosis'=>'درخواست شبکه در مهلت تعیین‌شده پاسخ کامل نگرفته است.','action'=>'مقصد، زمان پاسخ، DNS/Route و timeout همان درخواست را بررسی کن؛ خطا را به‌عنوان پاسخ معتبر معامله تلقی نکن.'];
        }
        if (str_contains($m,'http 429') || str_contains($m,'too many requests') || str_contains($m,'rate limit')) {
            return ['diagnosis'=>'سرویس مقصد تعداد درخواست‌ها را بیش از حد مجاز تشخیص داده است.','action'=>'فراخوانی‌های تکراری را تجمیع/Cache کن و تا پایان backoff سفارش جدید وابسته به همان API را محدود کن.'];
        }
        if (str_contains($m,'wrong ip') || str_contains($m,'http 406')) {
            return ['diagnosis'=>'درخواست API از IP مورد انتظار سرویس عبور نکرده یا با محدودیت IP کلید سازگار نیست.','action'=>'IP گزارش‌شده توسط خود صرافی را با Allowed IP کلید API تطبیق بده و مسیر NAT/Proxy را بررسی کن.'];
        }
        if (str_contains($m,'invalid choices') || str_contains($m,'http 400')) {
            return ['diagnosis'=>'API یکی از پارامترهای درخواست را نامعتبر تشخیص داده است.','action'=>'پارامترها و نماد بازار ثبت‌شده در Context را با قرارداد API مقصد مقایسه کن؛ مقدار را حدس یا تبدیل خودکار نکن.'];
        }
        if (str_contains($m,'sqlstate') || str_contains(strtolower($class),'pdo')) {
            return ['diagnosis'=>'خطا در لایه پایگاه‌داده رخ داده است.','action'=>'SQL، schema/migration و پارامترهای Query در فایل و خط اعلام‌شده را بررسی کن.'];
        }
        if (str_contains($m,'insufficient_balance')) {
            return ['diagnosis'=>'موجودی Quote لازم برای اجرای سفارش کافی نبوده است.','action'=>'Quote بازار، موجودی آزاد و حداقل ارزش سفارش را بررسی کن.'];
        }
        if ($class === 'APPLICATION_LOG') {
            return ['diagnosis'=>'این یک رویداد فنی ثبت‌شده توسط خود Trade است، نه Exception PHP.','action'=>'وضعیت و Context همین پیام را برای دنبال‌کردن مسیر اجرای ربات استفاده کن.'];
        }
        return [
            'diagnosis'=>'رویداد در بخش '.$component.' با سطح '.$severity.' ثبت شده است؛ پیام اصلی و محل دقیق منبع در همین اعلان آمده است.',
            'action'=>'ابتدا فایل، خط و Caller اعلام‌شده را بررسی کن و سپس Context/Trace را با همان Run ID تطبیق بده.',
        ];
    }

    private static function phpSeverityName(int $severity): string
    {
        return match ($severity) {
            E_ERROR=>'E_ERROR', E_WARNING=>'E_WARNING', E_PARSE=>'E_PARSE', E_NOTICE=>'E_NOTICE',
            E_CORE_ERROR=>'E_CORE_ERROR', E_CORE_WARNING=>'E_CORE_WARNING', E_COMPILE_ERROR=>'E_COMPILE_ERROR',
            E_COMPILE_WARNING=>'E_COMPILE_WARNING', E_USER_ERROR=>'E_USER_ERROR', E_USER_WARNING=>'E_USER_WARNING',
            E_USER_NOTICE=>'E_USER_NOTICE', E_STRICT=>'E_STRICT', E_RECOVERABLE_ERROR=>'E_RECOVERABLE_ERROR',
            E_DEPRECATED=>'E_DEPRECATED', E_USER_DEPRECATED=>'E_USER_DEPRECATED', default=>'PHP_ERROR_'.$severity,
        };
    }

    private static function frameName(array $frame): ?string
    {
        $function = trim((string)($frame['function'] ?? ''));
        if ($function === '') return null;
        return trim((string)($frame['class'] ?? '') . (string)($frame['type'] ?? '') . $function);
    }

    private static function callerFromTrace(array $trace): ?string
    {
        foreach ($trace as $frame) {
            if (!is_array($frame)) continue;
            $name = self::frameName($frame);
            if ($name === null || str_contains($name, self::class)) continue;
            return mb_substr($name,0,240);
        }
        return null;
    }

    private static function traceFromFrames(array $trace): ?string
    {
        $lines = [];
        foreach (array_slice($trace,0,8) as $i=>$frame) {
            if (!is_array($frame)) continue;
            $file = self::relativeFile((string)($frame['file'] ?? ''));
            $line = (int)($frame['line'] ?? 0);
            $name = self::frameName($frame) ?? '{main}';
            $lines[] = '#'.$i.' '.$file.($line>0?':'.$line:'').' '.$name;
        }
        return $lines === [] ? null : implode("\n",$lines);
    }

    private static function sanitizeTrace(string $trace): string
    {
        $root = str_replace('\\','/', (string)(defined('TRADE_ROOT') ? TRADE_ROOT : dirname(__DIR__,2)));
        $trace = str_replace('\\','/',$trace);
        if ($root !== '') $trace = str_replace(rtrim($root,'/').'/', 'backend/', $trace);
        return $trace;
    }
}
