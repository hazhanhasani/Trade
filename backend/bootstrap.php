<?php

declare(strict_types=1);

use Trade\Config;
use Trade\Observability\ErrorReporter;
use Trade\Support\IranClock;

if (PHP_VERSION_ID < 80200) {
    throw new RuntimeException('Trade requires PHP 8.2 or newer.');
}

define('TRADE_ROOT', __DIR__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Trade\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = TRADE_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$configFile = TRADE_ROOT . '/storage/config.php';
if (is_file($configFile)) {
    Config::load($configFile);
}

try { ErrorReporter::install(); } catch (Throwable) {}

set_exception_handler(static function (Throwable $e): void {
    $requestId = bin2hex(random_bytes(8));
    try {
        ErrorReporter::captureThrowable($e, 'critical', 'uncaught_exception', ['request_id'=>$requestId]);
    } catch (Throwable) {}

    $logDir = TRADE_ROOT . '/storage';
    if (is_dir($logDir) && is_writable($logDir)) {
        $displayTime = null;
        try { $displayTime = IranClock::formatNow(); } catch (Throwable) {}
        error_log(sprintf(
            "[%s] [Iran %s] [%s] %s in %s:%d\n%s\n",
            gmdate(DATE_ATOM),
            $displayTime ?? '-',
            $requestId,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ), 3, $logDir . '/app.log');
    }

    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'internal_error',
            'request_id' => $requestId,
            'time_iran' => (static function (): ?array { try { return IranClock::nowPayload(); } catch (Throwable) { return null; } })(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});
