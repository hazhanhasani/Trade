<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * A candidate-level Portfolio Intelligence rejection.
 *
 * This is deliberately distinct from an intelligence subsystem failure. The
 * auto-trader may skip this one symbol and inspect the next ranked opportunity,
 * while infrastructure/guard errors remain fail-closed and stop automated BUYs.
 */
final class NobitexCandidateRejectedException extends \RuntimeException
{
    public function __construct(
        private readonly string $symbol,
        private readonly string $reason,
        private readonly array $assessment = [],
    ) {
        parent::__construct('Portfolio intelligence rejected candidate ' . $symbol . ': ' . $reason);
    }

    public function symbol(): string
    {
        return $this->symbol;
    }

    public function reasonCode(): string
    {
        return $this->reason;
    }

    public function assessment(): array
    {
        return $this->assessment;
    }
}
