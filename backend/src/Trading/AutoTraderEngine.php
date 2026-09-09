<?php

declare(strict_types=1);

namespace Trade\Trading;

use Trade\Trading\Strategies\RsiStrategy;

final class AutoTraderEngine
{
    public function __construct(private readonly StrategyInterface $strategy = new RsiStrategy())
    {
    }

    public function analyzeAndDecide(array $market): array
    {
        $signal = $this->strategy->analyze($market);

        return [
            'asset' => $market['asset'] ?? 'GRAM',
            'strategy' => $this->strategy->name(),
            'signal' => $signal,
            'auto_execution_allowed' => $signal['signal'] !== 'hold'
        ];
    }
}
