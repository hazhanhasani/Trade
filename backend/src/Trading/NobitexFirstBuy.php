<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Backward-compatible first-buy entry point.
 *
 * Older callers used NobitexPortfolioEngine::runBootstrapIfPending(), whose
 * historical implementation still contained a minimum-score gate. The live
 * strategy is score-free, so every compatibility call is routed through the
 * current orchestrator which retires that flag and immediately continues with
 * the normal profit-first full-universe engine.
 */
final class NobitexFirstBuy
{
    public function runIfPending(): array
    {
        $engine = new NobitexAutoTraderEngine();
        $bootstrap = $engine->runBootstrapIfPending();

        if (($bootstrap['status'] ?? '') === 'not_pending') {
            return $engine->run();
        }

        return $bootstrap;
    }
}
