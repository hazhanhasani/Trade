<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Backward-compatible entry point used by API/admin/cron.
 * Nobitex is multi-asset: the whole liquid IRT/USDT universe is scanned and
 * ranked, while risk limits decide which positions are actually opened.
 */
final class NobitexAutoTraderEngine
{
    public function run(): array
    {
        return (new NobitexPortfolioEngine())->run();
    }

    public function runBootstrapIfPending(): array
    {
        return (new NobitexPortfolioEngine())->runBootstrapIfPending();
    }
}
