<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Backward-compatible one-shot bootstrap entry point.
 * v1.2+ selects the strongest eligible market across the Nobitex spot universe.
 */
final class NobitexFirstBuy
{
    public function runIfPending(): array
    {
        return (new NobitexPortfolioEngine())->runBootstrapIfPending();
    }
}
