<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Multi-strategy, regime-aware Nobitex signal engine.
 *
 * Market structure is classified first, then exactly one primary strategy is
 * routed for the current regime. The selected forecast still has to survive the
 * same profit-first execution economics used by v5: fees, spread, liquidity,
 * slippage, adverse flow and an adaptive uncertainty buffer. Confidence is
 * diagnostic only and never replaces positive tradable Net Edge.
 */
final class NobitexInternalSignalEngine
{
    private const IRT_ROUNDTRIP_TAKER_FEE_PERCENT = 0.50;
    private const USDT_ROUNDTRIP_TAKER_FEE_PERCENT = 0.26;
    private const MAX_EXECUTABLE_SPREAD_PERCENT = 1.50;
    private const MIN_DYNAMIC_SPREAD_PERCENT = 0.35;
    private const MIN_LIQUIDITY_MULTIPLE = 4.0;
    private const TARGET_LIQUIDITY_MULTIPLE = 16.0;
    private const MIN_EDGE_BUFFER_PERCENT = 0.20;
    private const MAX_EDGE_BUFFER_PERCENT = 1.35;

    public function __construct(
        private readonly SignalEngine $base = new SignalEngine(),
        private readonly NobitexMarketRegimeDetector $regimes = new NobitexMarketRegimeDetector(),
        private readonly NobitexMultiStrategyRouter $router = new NobitexMultiStrategyRouter(),
    ) {}

    public function analyze(array $market, array $minutePrices, int $legacyThreshold = 60): array
    {
        unset($legacyThreshold);

        $minute = $this->clean($minutePrices, 480);
        $five = $this->aggregate($minute, 5);
        $fifteen = $this->aggregate($minute, 15);
        if (count($minute) < 60 || count($five) < 30 || count($fifteen) < 26) {
            return $this->notReady($minute, $five, $fifteen, 'insufficient_internal_mtf_history');
        }

        $one = $this->base->analyze($market + ['prices'=>$minute], 60);
        $fiveSignal = $this->base->analyze($market + ['prices'=>$five], 60);
        $fifteenSignal = $this->base->analyze($market + ['prices'=>$fifteen], 60);
        $i1 = is_array($one['indicators'] ?? null) ? $one['indicators'] : [];
        $i5 = is_array($fiveSignal['indicators'] ?? null) ? $fiveSignal['indicators'] : [];
        $i15 = is_array($fifteenSignal['indicators'] ?? null) ? $fifteenSignal['indicators'] : [];

        $context = [
            'market'=>$market,
            'prices'=>['1m'=>$minute,'5m'=>$five,'15m'=>$fifteen],
            'indicators'=>['1m'=>$i1,'5m'=>$i5,'15m'=>$i15],
        ];
        $regime = $this->regimes->detect($market, $minute, $i1, $i5, $i15);
        $route = $this->router->route($context, $regime);
        $selected = is_array($route['selected'] ?? null) ? $route['selected'] : [];
        $strategyKey = (string)($selected['key'] ?? 'none');
        $strategyGross = (float)($selected['gross_edge_percent'] ?? 0.0);
        $strategyConfidence = max(0, min(100, (int)($selected['confidence'] ?? 0)));
        $regimeConfidence = max(0, min(100, (int)($regime['confidence'] ?? 0)));

        $spread = max(0.0, (float)($market['spread_percent'] ?? 0.0));
        $imbalance = $this->clamp((float)($market['orderbook_imbalance'] ?? 0.0), -1.0, 1.0);
        $depthQuote = max(0.0, (float)($market['depth_quote'] ?? 0.0));
        $minimumOrder = max(0.0, (float)($market['min_order_quote'] ?? 0.0));
        $liquidityMultiple = $minimumOrder > 0.0 ? $depthQuote / $minimumOrder : self::TARGET_LIQUIDITY_MULTIPLE;
        $liquidityReady = $minimumOrder <= 0.0 || $liquidityMultiple >= self::MIN_LIQUIDITY_MULTIPLE;
        $liquidityCoverage = $this->clamp(
            ($liquidityMultiple - self::MIN_LIQUIDITY_MULTIPLE) / (self::TARGET_LIQUIDITY_MULTIPLE - self::MIN_LIQUIDITY_MULTIPLE),
            0.0,
            1.0
        );

        $m1 = $this->clamp((float)($i1['momentum_5_percent'] ?? 0.0), -2.0, 2.0);
        $m5 = $this->clamp((float)($i5['momentum_5_percent'] ?? 0.0), -4.0, 4.0);
        $m15 = $this->clamp((float)($i15['momentum_5_percent'] ?? 0.0), -6.0, 6.0);
        $e1 = $this->clamp((float)($i1['ema_gap_percent'] ?? 0.0), -1.5, 1.5);
        $e5 = $this->clamp((float)($i5['ema_gap_percent'] ?? 0.0), -2.5, 2.5);
        $e15 = $this->clamp((float)($i15['ema_gap_percent'] ?? 0.0), -4.0, 4.0);
        $h1 = $this->clamp((float)($i1['macd_histogram_percent'] ?? 0.0), -0.6, 0.6);
        $h5 = $this->clamp((float)($i5['macd_histogram_percent'] ?? 0.0), -0.8, 0.8);
        $h15 = $this->clamp((float)($i15['macd_histogram_percent'] ?? 0.0), -1.0, 1.0);
        $t1 = $this->clamp((float)($i1['trend_consistency'] ?? 0.5), 0.0, 1.0);
        $t5 = $this->clamp((float)($i5['trend_consistency'] ?? 0.5), 0.0, 1.0);
        $t15 = $this->clamp((float)($i15['trend_consistency'] ?? 0.5), 0.0, 1.0);
        $rsi1 = (float)($i1['rsi14'] ?? 50.0);
        $rsi5 = (float)($i5['rsi14'] ?? 50.0);
        $vol1 = max(0.0, (float)($i1['volatility_percent'] ?? 0.0));
        $vol5 = max(0.0, (float)($i5['volatility_percent'] ?? 0.0));
        $volatility = ($vol1 * 0.65) + ($vol5 * 0.35);

        $direction1 = $this->direction($m1);
        $direction5 = $this->direction($m5);
        $direction15 = $this->direction($m15);
        $positiveFrames = ($direction1 > 0 ? 1 : 0) + ($direction5 > 0 ? 1 : 0) + ($direction15 > 0 ? 1 : 0);
        $disagreementPenalty = 0.0;
        if ($direction1 !== 0 && $direction5 !== 0 && $direction1 !== $direction5) $disagreementPenalty += 0.12;
        if ($direction5 !== 0 && $direction15 !== 0 && $direction5 !== $direction15) $disagreementPenalty += 0.16;
        if ($direction1 > 0 && $direction5 < 0 && $direction15 < 0) $disagreementPenalty += 0.12;

        $dynamicSpreadLimit = $this->clamp(
            self::MIN_DYNAMIC_SPREAD_PERCENT + ($volatility * 0.65),
            self::MIN_DYNAMIC_SPREAD_PERCENT,
            self::MAX_EXECUTABLE_SPREAD_PERCENT
        );
        $baseFee = $this->baseRoundtripFeePercent($market);
        $spreadCost = min(self::MAX_EXECUTABLE_SPREAD_PERCENT, $spread);
        $volatilitySlippageReserve = min(1.25, $volatility * 0.15);
        $liquiditySlippageReserve = $liquidityReady ? (1.0 - $liquidityCoverage) * 0.35 : 0.75;
        $adverseFlowReserve = $imbalance < 0.0 ? min(0.20, abs($imbalance) * 0.20) : 0.0;
        $estimatedCost = $baseFee + $spreadCost + $volatilitySlippageReserve + $liquiditySlippageReserve + $adverseFlowReserve;

        $netEdge = $strategyGross - $estimatedCost;
        $regimeUncertainty = (1.0 - ($regimeConfidence / 100.0)) * 0.25;
        $strategyUncertainty = (1.0 - ($strategyConfidence / 100.0)) * 0.18;
        $edgeBuffer = $this->clamp(
            0.20
                + min(0.30, $estimatedCost * 0.30)
                + min(0.35, $volatility * 0.08)
                + min(0.20, $disagreementPenalty * 0.60)
                + $regimeUncertainty
                + $strategyUncertainty,
            self::MIN_EDGE_BUFFER_PERCENT,
            self::MAX_EDGE_BUFFER_PERCENT
        );
        $tradableNetEdge = $netEdge - $edgeBuffer;

        $executionReady = $liquidityReady
            && $spread <= $dynamicSpreadLimit
            && is_finite($strategyGross)
            && is_finite($estimatedCost)
            && is_finite($tradableNetEdge);
        $entryAllowed = (bool)($regime['entry_enabled'] ?? false)
            && (bool)($selected['eligible'] ?? false)
            && (bool)($selected['entry_allowed'] ?? false)
            && $strategyKey !== 'none';
        $buyGate = $executionReady && $entryAllowed && $tradableNetEdge > 0.0;
        $estimatedExitCost = max($baseFee * 0.50, ($spreadCost * 0.50) + ($volatilitySlippageReserve * 0.50));
        $exitBias = (bool)($selected['exit_bias'] ?? false);
        $sellGate = $executionReady && $exitBias && $strategyGross < -max(0.05, $estimatedExitCost * 0.60);

        $action = 'hold';
        $reason = 'strategy_entry_conditions_not_met';
        if (!$executionReady) {
            if (!$liquidityReady) $reason = 'liquidity_not_executable';
            elseif ($spread > $dynamicSpreadLimit) $reason = 'spread_not_executable';
            else $reason = 'market_quality_not_ready';
        } elseif ($buyGate) {
            $action = 'buy';
            $reason = 'multi_strategy_positive_tradable_edge';
        } elseif ($sellGate) {
            $action = 'sell';
            $reason = 'multi_strategy_exit_bias_after_costs';
        } elseif ($strategyKey === 'none') {
            $reason = 'no_strategy_for_market_regime';
        } elseif (!$entryAllowed) {
            $reason = (string)($selected['reason'] ?? 'strategy_entry_conditions_not_met');
        } elseif ($tradableNetEdge <= 0.0) {
            $reason = 'strategy_edge_below_execution_costs';
        }

        $spreadQuality = $dynamicSpreadLimit > 0.0 ? 1.0 - $this->clamp($spread / $dynamicSpreadLimit, 0.0, 1.0) : 0.0;
        $flowQuality = 1.0 - max(0.0, -$imbalance);
        $trendConsistency = ($t1 * 0.20) + ($t5 * 0.45) + ($t15 * 0.35);
        $qualityScore = (int)round(100.0 * $this->clamp(
            ($liquidityCoverage * 0.35) + ($spreadQuality * 0.30) + ($flowQuality * 0.15) + ($trendConsistency * 0.10) + (($positiveFrames / 3.0) * 0.10),
            0.0,
            1.0
        ));
        $edgeToCost = $estimatedCost > 0.0 ? $tradableNetEdge / $estimatedCost : $tradableNetEdge;
        $edgeConfidence = $executionReady ? min(100, max(0, (int)round(50.0 + ($edgeToCost * 35.0)))) : 0;
        $confidence = $executionReady
            ? (int)round(($strategyConfidence * 0.40) + ($regimeConfidence * 0.25) + ($edgeConfidence * 0.20) + ($qualityScore * 0.15))
            : 0;

        $reasons = [];
        foreach ([$one,$fiveSignal,$fifteenSignal] as $signal) {
            foreach ((array)($signal['reasons'] ?? []) as $r) if (is_string($r) && $r !== '') $reasons[] = $r;
        }

        $strategyCandidates = [];
        foreach ((array)($route['evaluations'] ?? []) as $candidate) {
            if (!is_array($candidate)) continue;
            $strategyCandidates[] = [
                'key'=>$candidate['key'] ?? 'unknown',
                'eligible'=>(bool)($candidate['eligible'] ?? false),
                'entry_allowed'=>(bool)($candidate['entry_allowed'] ?? false),
                'exit_bias'=>(bool)($candidate['exit_bias'] ?? false),
                'gross_edge_percent'=>round((float)($candidate['gross_edge_percent'] ?? 0.0),4),
                'confidence'=>(int)($candidate['confidence'] ?? 0),
                'reason'=>$candidate['reason'] ?? null,
                'holding_horizon_minutes'=>(int)($candidate['holding_horizon_minutes'] ?? 0),
            ];
        }

        return [
            'ready'=>$executionReady,
            'score'=>0,
            'confidence'=>$confidence,
            'confidence_is_gate'=>false,
            'execution_quality_score'=>$qualityScore,
            'action'=>$action,
            'reason'=>$reason,
            'decision_model'=>'multi_strategy_regime_router_net_edge_v1',
            'strategy_key'=>$strategyKey,
            'expected_net_profit'=>$executionReady && $entryAllowed && $tradableNetEdge > 0.0,
            'expected_gross_move_percent'=>round($strategyGross,4),
            'raw_expected_gross_move_percent'=>round($strategyGross,4),
            'regime_risk_penalty_percent'=>round($regimeUncertainty,4),
            'regime_uncertainty_buffer_percent'=>round($regimeUncertainty,4),
            'strategy_uncertainty_buffer_percent'=>round($strategyUncertainty,4),
            'timeframe_disagreement_penalty_percent'=>round($disagreementPenalty,4),
            'exhaustion_penalty_percent'=>round((float)($selected['diagnostics']['exhaustion_penalty_percent'] ?? 0.0),4),
            'estimated_roundtrip_cost_percent'=>round($estimatedCost,4),
            'estimated_exit_cost_percent'=>round($estimatedExitCost,4),
            'expected_net_edge_percent'=>round($netEdge,4),
            'required_edge_buffer_percent'=>round($edgeBuffer,4),
            'tradable_net_edge_percent'=>round($tradableNetEdge,4),
            'minimum_net_edge_percent'=>round($edgeBuffer,4),
            'market_regime'=>$regime,
            'selected_strategy'=>[
                'key'=>$strategyKey,
                'eligible'=>(bool)($selected['eligible'] ?? false),
                'entry_allowed'=>(bool)($selected['entry_allowed'] ?? false),
                'exit_bias'=>$exitBias,
                'gross_edge_percent'=>round($strategyGross,4),
                'confidence'=>$strategyConfidence,
                'reason'=>$selected['reason'] ?? null,
                'holding_horizon_minutes'=>(int)($selected['holding_horizon_minutes'] ?? 0),
                'diagnostics'=>is_array($selected['diagnostics'] ?? null) ? $selected['diagnostics'] : [],
            ],
            'strategy_router'=>[
                'preferred_strategy'=>$route['preferred_strategy'] ?? null,
                'entry_enabled'=>(bool)($route['entry_enabled'] ?? false),
                'regime'=>$route['regime'] ?? ($regime['regime'] ?? 'uncertain'),
            ],
            'strategy_candidates'=>$strategyCandidates,
            'execution_quality'=>[
                'depth_quote'=>round($depthQuote,8),
                'minimum_order_quote'=>round($minimumOrder,8),
                'liquidity_multiple'=>round($liquidityMultiple,4),
                'minimum_liquidity_multiple'=>self::MIN_LIQUIDITY_MULTIPLE,
                'target_liquidity_multiple'=>self::TARGET_LIQUIDITY_MULTIPLE,
                'liquidity_coverage'=>round($liquidityCoverage,4),
                'dynamic_max_spread_percent'=>round($dynamicSpreadLimit,4),
                'positive_timeframe_count'=>$positiveFrames,
                'orderbook_imbalance'=>round($imbalance,4),
            ],
            'cost_model'=>[
                'quote_asset'=>strtoupper((string)($market['quote_asset'] ?? 'IRT')),
                'base_roundtrip_taker_fee_percent'=>round($baseFee,4),
                'spread_cost_percent'=>round($spreadCost,4),
                'volatility_slippage_reserve_percent'=>round($volatilitySlippageReserve,4),
                'liquidity_slippage_reserve_percent'=>round($liquiditySlippageReserve,4),
                'adverse_flow_reserve_percent'=>round($adverseFlowReserve,4),
                'regime_uncertainty_buffer_percent'=>round($regimeUncertainty,4),
                'strategy_uncertainty_buffer_percent'=>round($strategyUncertainty,4),
                'adaptive_forecast_buffer_percent'=>round($edgeBuffer,4),
                'volatility_hard_gate'=>false,
            ],
            'reasons'=>array_values(array_unique($reasons)),
            'source'=>'nobitex_multi_strategy_regime_engine_v1',
            'timeframes'=>[
                '1m'=>['momentum_percent'=>round($m1,4),'ema_gap_percent'=>round($e1,4),'macd_histogram_percent'=>round($h1,4),'rsi14'=>round($rsi1,2),'samples'=>count($minute)],
                '5m'=>['momentum_percent'=>round($m5,4),'ema_gap_percent'=>round($e5,4),'macd_histogram_percent'=>round($h5,4),'rsi14'=>round($rsi5,2),'samples'=>count($five)],
                '15m'=>['momentum_percent'=>round($m15,4),'ema_gap_percent'=>round($e15,4),'macd_histogram_percent'=>round($h15,4),'samples'=>count($fifteen)],
            ],
            'indicators'=>[
                'volatility_percent'=>round($volatility,4),
                'orderbook_imbalance'=>round($imbalance,4),
                'spread_percent'=>round($spread,6),
                'timeframe_disagreement_penalty_percent'=>round($disagreementPenalty,4),
                'regime'=>(string)($regime['regime'] ?? 'uncertain'),
                'strategy'=>$strategyKey,
            ],
            'tradingview'=>['enabled'=>false,'used'=>false,'required'=>false],
        ];
    }

    private function baseRoundtripFeePercent(array $market): float
    {
        return strtoupper(trim((string)($market['quote_asset'] ?? 'IRT'))) === 'USDT'
            ? self::USDT_ROUNDTRIP_TAKER_FEE_PERCENT
            : self::IRT_ROUNDTRIP_TAKER_FEE_PERCENT;
    }

    private function notReady(array $minute, array $five, array $fifteen, string $reason): array
    {
        return [
            'ready'=>false,'score'=>0,'confidence'=>0,'confidence_is_gate'=>false,'execution_quality_score'=>0,
            'action'=>'hold','reason'=>$reason,'decision_model'=>'multi_strategy_regime_router_net_edge_v1','strategy_key'=>'none',
            'expected_net_profit'=>false,'expected_gross_move_percent'=>0.0,'raw_expected_gross_move_percent'=>0.0,
            'regime_risk_penalty_percent'=>0.0,'regime_uncertainty_buffer_percent'=>0.0,'strategy_uncertainty_buffer_percent'=>0.0,
            'timeframe_disagreement_penalty_percent'=>0.0,'exhaustion_penalty_percent'=>0.0,
            'estimated_roundtrip_cost_percent'=>0.0,'estimated_exit_cost_percent'=>0.0,'expected_net_edge_percent'=>0.0,
            'required_edge_buffer_percent'=>0.0,'tradable_net_edge_percent'=>0.0,'minimum_net_edge_percent'=>0.0,
            'market_regime'=>['regime'=>NobitexMarketRegimeDetector::UNCERTAIN,'confidence'=>0,'entry_enabled'=>false,'metrics'=>['reason'=>$reason]],
            'selected_strategy'=>['key'=>'none','eligible'=>false,'entry_allowed'=>false,'exit_bias'=>false,'gross_edge_percent'=>0.0,'confidence'=>0,'reason'=>$reason,'holding_horizon_minutes'=>0,'diagnostics'=>[]],
            'strategy_router'=>['preferred_strategy'=>null,'entry_enabled'=>false,'regime'=>NobitexMarketRegimeDetector::UNCERTAIN],
            'strategy_candidates'=>[],'execution_quality'=>[],'cost_model'=>[],'reasons'=>[],
            'source'=>'nobitex_multi_strategy_regime_engine_v1',
            'timeframes'=>['1m'=>['samples'=>count($minute)],'5m'=>['samples'=>count($five)],'15m'=>['samples'=>count($fifteen)]],
            'indicators'=>['samples'=>count($minute)],'tradingview'=>['enabled'=>false,'used'=>false,'required'=>false],
        ];
    }

    private function clean(array $prices, int $limit): array
    {
        $out=[];
        foreach($prices as $price){if(!is_numeric($price))continue;$n=(float)$price;if(is_finite($n)&&$n>0)$out[]=$n;}
        return array_slice($out,-$limit);
    }

    private function aggregate(array $minute, int $minutes): array
    {
        if($minute===[]||$minutes<=1)return $minute;
        $usable=intdiv(count($minute),$minutes)*$minutes;
        if($usable<$minutes)return [];
        $aligned=array_slice($minute,-$usable);$out=[];
        for($i=$minutes-1,$n=count($aligned);$i<$n;$i+=$minutes)$out[]=(float)$aligned[$i];
        return $out;
    }

    private function direction(float $value): int
    {
        if($value>0.03)return 1;if($value<-0.03)return -1;return 0;
    }

    private function clamp(float $value,float $min,float $max):float
    {
        if(!is_finite($value))return $min;return max($min,min($max,$value));
    }
}
