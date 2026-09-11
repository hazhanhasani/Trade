<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexPortfolioSnapshotCache;

function expectPortfolioCache(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

expectPortfolioCache(
    NobitexPortfolioSnapshotCache::isRateLimited(new RuntimeException('Nobitex HTTP 429 — too many requests')),
    'HTTP 429 must be recognized as a rate-limit condition.'
);
expectPortfolioCache(
    !NobitexPortfolioSnapshotCache::isRateLimited(new RuntimeException('Nobitex HTTP 400 — Invalid choices')),
    'HTTP 400 must not be classified as a rate-limit condition.'
);

$cache = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexPortfolioSnapshotCache.php') ?: '';
$api = file_get_contents(dirname(__DIR__) . '/public/index.php') ?: '';
$commandCenter = file_get_contents(dirname(__DIR__) . '/src/Trading/TradeCommandCenter.php') ?: '';

foreach (['FRESH_SECONDS = 15','STALE_SECONDS = 600','REFRESH_LEASE_SECONDS = 20','FOR UPDATE','stale_rate_limited'] as $needle) {
    expectPortfolioCache(str_contains($cache, $needle), "Portfolio snapshot cache is missing safety primitive: {$needle}");
}
expectPortfolioCache(
    str_contains($api, 'NobitexPortfolioSnapshotCache'),
    'Public API must use the shared portfolio snapshot cache.'
);
expectPortfolioCache(
    str_contains($commandCenter, 'NobitexPortfolioSnapshotCache'),
    'Command Center must share the same portfolio snapshot cache.'
);

fwrite(STDOUT, "Nobitex portfolio snapshot cache regression tests passed.\n");
