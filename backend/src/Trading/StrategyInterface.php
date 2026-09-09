<?php

declare(strict_types=1);

namespace Trade\Trading;

interface StrategyInterface
{
    public function name(): string;

    public function analyze(array $market): array;
}
