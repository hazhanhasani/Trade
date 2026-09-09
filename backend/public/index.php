<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Config;
use Trade\Database;
use Trade\Security\AppAccess;
use Trade\Trading\OrderService;
use Trade\Updater;

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

function jsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new InvalidArgumentException('JSON object expected.');
    return $decoded;
}

function requireAppToken(): void
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        respond(['ok' => false, 'error' => 'unauthorized'], 401);
    }

    $pdo = Database::connection();
    if (!AppAccess::validate($pdo, trim($m[1]))) {
        respond(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}

try {
    if ($method === 'GET' && $path === '/api/health') {
        $db = true;
        try {
            $pdo = Database::connection();
            $pdo->query('SELECT 1');
            AppAccess::bootstrapLegacy($pdo);
        } catch (Throwable) {
            $db = false;
        }
        respond([
            'ok' => $db,
            'service' => 'Trade',
            'mode' => (bool) Config::get('trading.enabled', false) ? 'live' : 'live_disabled',
            'capital_asset' => strtoupper((string) Config::get('trading.capital_asset', 'TON')),
            'version' => Updater::currentVersion(),
            'app_url' => (string) Config::get('app.url', 'https://rado-taxi.sbs'),
            'database' => $db ? 'ok' : 'error',
            'update_state' => Updater::state(),
            'time_utc' => gmdate(DATE_ATOM),
        ], $db ? 200 : 503);
    }

    if ($method === 'GET' && $path === '/api/update') {
        try {
            respond(['ok' => true, 'data' => Updater::appUpdateInfo()]);
        } catch (Throwable $e) {
            respond([
                'ok' => false,
                'error' => 'update_check_failed',
                'message' => $e->getMessage(),
                'backend_version' => Updater::currentVersion(),
            ], 503);
        }
    }

    if ($method === 'POST' && $path === '/api/pair') {
        $body = jsonBody();
        $code = trim((string) ($body['code'] ?? ''));
        if ($code === '') {
            throw new InvalidArgumentException('کد اتصال لازم است.');
        }
        $paired = AppAccess::consumePairing(Database::connection(), $code);
        respond(['ok' => true, 'data' => [
            'token' => $paired['token'],
            'token_id' => $paired['token_id'],
            'label' => $paired['label'],
            'server_url' => (string) Config::get('app.url', 'https://rado-taxi.sbs'),
        ]]);
    }

    if (is_file(dirname(__DIR__) . '/storage/maintenance.lock')) {
        respond(['ok' => false, 'error' => 'maintenance', 'message' => 'Trade is updating. Try again shortly.'], 503);
    }

    requireAppToken();
    $service = new OrderService();

    if ($method === 'GET' && $path === '/api/status') {
        $pdo = Database::connection();
        $lastRun = $pdo->query('SELECT run_id,status,started_at,finished_at FROM bot_runs ORDER BY id DESC LIMIT 1')->fetch() ?: null;
        $kill = (string) ($pdo->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn() ?: '0');
        respond(['ok' => true, 'data' => [
            'mode' => (bool) Config::get('trading.enabled', false) ? 'live' : 'live_disabled',
            'capital_asset' => strtoupper((string) Config::get('trading.capital_asset', 'TON')),
            'kill_switch' => $kill === '1',
            'credentials_configured' => (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM exchange_credentials WHERE exchange_name='bitpin')")->fetchColumn(),
            'orders_logged' => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
            'active_app_tokens' => AppAccess::activeCount($pdo),
            'backend_version' => Updater::currentVersion(),
            'update_state' => Updater::state(),
            'last_run' => $lastRun,
        ]]);
    }

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
} catch (InvalidArgumentException $e) {
    respond(['ok' => false, 'error' => 'validation_error', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()], 500);
}
