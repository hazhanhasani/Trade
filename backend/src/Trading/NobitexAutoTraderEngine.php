<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Backward-compatible entry point used by API/admin/cron.
 * The implementation is now the multi-asset Nobitex portfolio engine.
 */
final class NobitexAutoTraderEngine
{
    public function run(): array
    {
        return (new NobitexPortfolioEngine())->run();
    }
}
