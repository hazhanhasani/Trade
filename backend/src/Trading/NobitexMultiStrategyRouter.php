<?php

declare(strict_types=1);

namespace Trade\Trading;

use Trade\Trading\Strategy\BreakoutStrategy;
use Trade\Trading\Strategy\HighVolatilityMomentumStrategy;
use Trade\Trading\Strategy\MeanReversionStrategy;
use Trade\Trading\Strategy\NobitexStrategyInterface;
use Trade\Trading\Strategy\TrendMomentumStrategy;

final class NobitexMultiStrategyRouter
{
    /** @var list<NobitexStrategyInterface> */
    private array $strategies;

    public function __construct(?array $strategies = null)
    {
        $this->strategies = $strategies ?? [
            new TrendMomentumStrategy(),
            new BreakoutStrategy(),
            new MeanReversionStrategy(),
            new HighVolatilityMomentumStrategy(),
        ];
    }

    public function route(array $context, array $regime): array
    {
        $evaluations = [];
        foreach ($this->strategies as $strategy) {
            if (!$strategy instanceof NobitexStrategyInterface) continue;
            $evaluations[$strategy->key()] = $strategy->evaluate($context, $regime);
        }

        $regimeName = (string)($regime['regime'] ?? NobitexMarketRegimeDetector::UNCERTAIN);
        $preferred = match ($regimeName) {
            NobitexMarketRegimeDetector::TRENDING_UP,
            NobitexMarketRegimeDetector::TRENDING_DOWN => 'trend_momentum_v1',
            NobitexMarketRegimeDetector::BREAKOUT_UP,
            NobitexMarketRegimeDetector::BREAKOUT_DOWN => 'breakout_v1',
            NobitexMarketRegimeDetector::RANGING => 'mean_reversion_v1',
            NobitexMarketRegimeDetector::HIGH_VOLATILITY => 'high_volatility_momentum_v1',
            default => null,
        };

        $selected = $preferred !== null && isset($evaluations[$preferred])
            ? $evaluations[$preferred]
            : [
                'key'=>'none',
                'eligible'=>false,
                'entry_allowed'=>false,
                'exit_bias'=>in_array($regimeName, [NobitexMarketRegimeDetector::TRENDING_DOWN,NobitexMarketRegimeDetector::BREAKOUT_DOWN], true),
                'gross_edge_percent'=>0.0,
                'confidence'=>0,
                'reason'=>'uncertain_regime_no_entry_strategy',
                'holding_horizon_minutes'=>0,
                'diagnostics'=>[],
            ];

        return [
            'selected'=>$selected,
            'preferred_strategy'=>$preferred,
            'regime'=>$regimeName,
            'entry_enabled'=>(bool)($regime['entry_enabled'] ?? false),
            'evaluations'=>$evaluations,
        ];
    }
}
