<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Trade\Config;
use Trade\Security\AndroidSigningService;
use Trade\Security\GitHubOidcVerifier;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive', true);

if (!Config::installed()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'not_installed']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'oidc_token_required']);
    exit;
}

try {
    $claims = (new GitHubOidcVerifier())->verify(trim($match[1]));
    $bundle = (new AndroidSigningService())->bundleForTrustedWorkflow();

    echo json_encode([
        'ok' => true,
        'repository' => (string) ($claims['repository'] ?? ''),
        'workflow_ref' => (string) ($claims['workflow_ref'] ?? ''),
        'run_id' => (string) ($claims['run_id'] ?? ''),
        'signing' => $bundle,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'oidc_rejected',
        'message' => mb_substr($e->getMessage(), 0, 240),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
