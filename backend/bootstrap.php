<?php

declare(strict_types=1);

use Trade\Config;

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

set_exception_handler(static function (Throwable $e): void {
    $requestId = bin2hex(random_bytes(8));
    $logDir = TRADE_ROOT . '/storage';
    if (is_dir($logDir) && is_writable($logDir)) {
        error_log(sprintf(
            "[%s] [%s] %s in %s:%d\n%s\n",
            date(DATE_ATOM),
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
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});
