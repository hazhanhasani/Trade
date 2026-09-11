<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexSymbolPolicy;

function expectSymbolPolicy(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach (['100KFLOKI','1KBONK','1MPEPE','1KSHIB','10KABC','100MXYZ'] as $base) {
    expectSymbolPolicy(
        !NobitexSymbolPolicy::isOrderApiCompatibleBase($base),
        "Scaled public alias {$base} must be blocked from authenticated order execution."
    );
}

foreach (['BTC','ETH','FLOKI','BONK','PEPE','SHIB','1INCH','AAVE'] as $base) {
    expectSymbolPolicy(
        NobitexSymbolPolicy::isOrderApiCompatibleBase($base),
        "Regular asset {$base} must remain executable."
    );
}

expectSymbolPolicy(
    NobitexSymbolPolicy::REJECTION_REASON === 'nobitex_order_api_multiplier_alias_unsupported',
    'Stable rejection reason changed unexpectedly.'
);

$scanner = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexUniverseScanner.php') ?: '';
$orderService = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexOrderService.php') ?: '';

expectSymbolPolicy(
    substr_count($scanner, 'NobitexSymbolPolicy::isOrderApiCompatibleBase') >= 2,
    'Universe scanner must exclude unsupported aliases before analysis/execution.'
);
expectSymbolPolicy(
    str_contains($orderService, 'NobitexSymbolPolicy::isOrderApiCompatibleBase'),
    'Order service must retain a second fail-safe before authenticated submission.'
);
expectSymbolPolicy(
    str_contains($orderService, 'NobitexCandidateRejectedException'),
    'Automated unsupported aliases must become candidate rejections, not cron failures.'
);

fwrite(STDOUT, "Nobitex symbol policy regression tests passed.\n");
