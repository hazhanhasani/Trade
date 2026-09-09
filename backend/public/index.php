<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Trading\OrderService;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if (!Config::installed()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'not_installed', 'install' => '/install/']);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function requireAppToken(): void
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        respond(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    $expectedHash = (string) Config::require('app.api_token_hash');
    $actualHash = hash('sha256', trim($m[1]));
    if (!hash_equals($expectedHash, $actualHash)) {
        respond(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}

function jsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new InvalidArgumentException('JSON object expected.');
    }
    return $data;
}

if ($method === 'GET' && $path === '/api/health') {
    $db = true;
    try {
        Database::connection()->query('SELECT 1');
    } catch (Throwable) {
        $db = false;
    }
    respond([
        'ok' => $db,
        'service' => 'Trade',
        'version' => '0.1.0',
        'database' => $db ? 'ok' : 'error',
        'time_utc' => gmdate(DATE_ATOM),
    ], $db ? 200 : 503);
}

requireAppToken();
$service = new OrderService();

if ($method === 'GET' && $path === '/api/markets') {
    $client = $service->client();
    respond(['ok' => true, 'data' => $client->markets($_GET)]);
}

if ($method === 'GET' && $path === '/api/wallets') {
    $client = $service->client();
    $data = $client->wallets($_GET);
    $service->syncTokens($client);
    respond(['ok' => true, 'data' => $data]);
}

if ($method === 'GET' && $path === '/api/orders') {
    $client = $service->client();
    $data = $client->orders($_GET);
    $service->syncTokens($client);
    respond(['ok' => true, 'data' => $data]);
}

if ($method === 'POST' && $path === '/api/orders') {
    respond(['ok' => true, 'data' => $service->create(jsonBody(), 'android_or_api')], 201);
}

if ($method === 'DELETE' && preg_match('#^/api/orders/([^/]+)$#', $path, $m)) {
    respond(['ok' => true, 'data' => $service->cancel($m[1])]);
}

if ($method === 'POST' && $path === '/api/kill-switch') {
    $body = jsonBody();
    $enabled = filter_var($body['enabled'] ?? true, FILTER_VALIDATE_BOOL);
    $stmt = Database::connection()->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES ('kill_switch',:v,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");
    $stmt->execute([':v' => $enabled ? '1' : '0']);
    respond(['ok' => true, 'kill_switch' => $enabled]);
}

respond(['ok' => false, 'error' => 'not_found'], 404);
