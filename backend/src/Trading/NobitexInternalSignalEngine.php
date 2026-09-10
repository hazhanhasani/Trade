<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Profit-first, score-free Nobitex signal engine.
 *
 * A market is bought when the expected move remains positive after estimated
 * taker fees, the live bid/ask spread and a volatility-based slippage reserve.
 * There is no point score, voting threshold, top-N gate or hidden model-margin
 * requirement. `score` remains zero only for legacy database compatibility.
 */
final class NobitexInternalSignalEngine
{
    // Base-tier two-sided taker fee assumptions for the two spot quote families.
    // These are deliberately kept separate because using the IRT cost for USDT
    // materially suppresses otherwise positive USDT opportunities.
    private const IRT_ROUNDTRIP_TAKER_FEE_PERCENT = 0.50;
    private const USDT_ROUNDTRIP_TAKER_FEE_PERCENT = 0.26;
    private const MAX_EXECUTABLE_SPREAD_PERCENT = 1.50;

    public function __construct(private readonly SignalEngine $base = new SignalEngine()) {}

    public function analyze(array $market, array $minutePrices, int $legacyThreshold = 60): array
    {
        unset($legacyThreshold);

        $minute = $this->clean($minutePrices, 480);
        $five = $this->aggregate($minute, 5);
        $fifteen = $this->aggregate($minute, 15);

        if (count($minute) < 60 || count($five) < 30 || count($fifteen) < 26) {
            return $this->notReady($minute, $five, $fifteen, 'insufficient_internal_mtf_history');
        }

        // SignalEngine is used only as an indicator calculator here. Its generic
        // economic decision is ignored; this engine owns the Nobitex decision.
        $one = $this->base->analyze($market + ['prices'=>$minute], 60);
        $fiveSignal = $this->base->analyze($market + ['prices'=>$five], 60);
        $fifteenSignal = $this->base->analyze($market + ['prices'=>$fifteen], 60);

        $i1 = is_array($one['indicators'] ?? null) ? $one['indicators'] : [];
        $i5 = is_array($fiveSignal['indicators'] ?? null) ? $fiveSignal['indicators'] : [];
        $i15 = is_array($fifteenSignal['indicators'] ?? null) ? $fifteenSignal['indicators'] : [];

        $spread = max(0.0, (float) ($market['spread_percent'] ?? 0.0));
        $imbalance = $this->clamp((float) ($market['orderbook_imbalance'] ?? 0.0), -1.0, 1.0);

        // Multi-timeframe directional estimate. Features are continuous; there
        // are no "N out of M" votes and no synthetic minimum score.
        $m1 = $this->clamp((float) ($i1['momentum_5_percent'] ?? 0.0), -2.0, 2.0);
        $m5 = $this->clamp((float) ($i5['momentum_5_percent'] ?? 0.0), -4.0, 4.0);
        $m15 = $this->clamp((float) ($i15['momentum_5_percent'] ?? 0.0), -6.0, 6.0);
        $momentum = ($m1 * 0.50) + ($m5 * 0.35) + ($m15 * 0.15);

        $e1 = $this->clamp((float) ($i1['ema_gap_percent'] ?? 0.0), -1.5, 1.5);
        $e5 = $this->clamp((float) ($i5['ema_gap_percent'] ?? 0.0), -2.5, 2.5);
        $e15 = $this->clamp((float) ($i15['ema_gap_percent'] ?? 0.0), -4.0, 4.0);
        $trend = ($e1 * 0.25) + ($e5 * 0.45) + ($e15 * 0.30);

        $h1 = $this->clamp((float) ($i1['macd_histogram_percent'] ?? 0.0), -0.6, 0.6);
        $h5 = $this->clamp((float) ($i5['macd_histogram_percent'] ?? 0.0), -0.8, 0.8);
        $h15 = $this->clamp((float) ($i15['macd_histogram_percent'] ?? 0.0), -1.0, 1.0);
        $macdPressure = ($h1 * 0.25) + ($h5 * 0.45) + ($h15 * 0.30);

        $t1 = $this->clamp((float) ($i1['trend_consistency'] ?? 0.5), 0.0, 1.0);
        $t5 = $this->clamp((float) ($i5['trend_consistency'] ?? 0.5), 0.0, 1.0);
        $t15 = $this->clamp((float) ($i15['trend_consistency'] ?? 0.5), 0.0, 1.0);
        $trendConsistency = ($t1 * 0.25) + ($t5 * 0.45) + ($t15 * 0.30);
        $consistencyBias = ($trendConsistency - 0.5) * 0.70;

        $rsi1 = (float) ($i1['rsi14'] ?? 50.0);
        $rsi5 = (float) ($i5['rsi14'] ?? 50.0);
        $rsiPenalty = 0.0;
        if ($rsi1 >= 78.0 || $rsi5 >= 76.0) $rsiPenalty += 0.55;
        elseif ($rsi1 >= 72.0 || $rsi5 >= 70.0) $rsiPenalty += 0.25;
        if ($rsi1 <= 22.0 && $m1 < 0.0) $rsiPenalty += 0.20;

        $flowBias = $imbalance * 0.35;

        $gross = ($momentum * 0.52)
            + ($trend * 0.34)
            + ($macdPressure * 0.75)
            + $consistencyBias
            + $flowBias
            - $rsiPenalty;

        $vol1 = max(0.0, (float) ($i1['volatility_percent'] ?? 0.0));
        $vol5 = max(0.0, (float) ($i5['volatility_percent'] ?? 0.0));
        $volatility = ($vol1 * 0.65) + ($vol5 * 0.35);

        // Economic cost only: two-sided taker fee + observed spread + a bounded
        // slippage/noise reserve. No arbitrary "model uncertainty" percentage is
        // subtracted from profitability, because that becomes a hidden entry gate.
        $baseFee = $this->baseRoundtripFeePercent($market);
        $spreadCost = min(self::MAX_EXECUTABLE_SPREAD_PERCENT, $spread);
        $slippageNoiseReserve = min(0.35, $volatility * 0.20);
        $estimatedCost = $baseFee + $spreadCost + $slippageNoiseReserve;

        $netEdge = $gross - $estimatedCost;
        $expectedNetProfit = $netEdge > 0.0;

        $ready = (bool) ($one['ready'] ?? false)
            && (bool) ($fiveSignal['ready'] ?? false)
            && (bool) ($fifteenSignal['ready'] ?? false)
            && $spread <= self::MAX_EXECUTABLE_SPREAD_PERCENT;

        // Entry is purely economic after the market is executable.
        $buyGate = $ready && $expectedNetProfit;

        // Spot exits cannot short. Negative forward edge is used only to decide
        // whether an existing position should be released before the hard stop.
        $estimatedExitCost = $estimatedCost * 0.50;
        $sellGate = $ready && $gross < -max(0.05, $estimatedExitCost);

        $action = 'hold';
        $reason = 'expected_net_profit_not_positive';
        if ($buyGate) {
            $action = 'buy';
            $reason = 'positive_expected_net_profit_after_costs';
        } elseif ($sellGate) {
            $action = 'sell';
            $reason = 'expected_forward_move_negative_after_exit_cost';
        } elseif (!$ready) {
            $reason = $spread > self::MAX_EXECUTABLE_SPREAD_PERCENT
                ? 'spread_not_executable'
                : 'market_quality_not_ready';
        }

        // Confidence is display/diagnostic metadata only. It never gates orders.
        $edgeToCost = $estimatedCost > 0.0 ? $netEdge / $estimatedCost : 0.0;
        $confidence = $ready
            ? min(100, max(0, (int) round(50.0 + ($edgeToCost * 35.0))))
            : 0;

        $reasons = [];
        foreach ([$one, $fiveSignal, $fifteenSignal] as $signal) {
            foreach ((array) ($signal['reasons'] ?? []) as $r) {
                if (is_string($r) && $r !== '') $reasons[] = $r;
            }
        }

        return [
            'ready'=>$ready,
            'score'=>0,
            'confidence'=>$confidence,
            'confidence_is_gate'=>false,
            'action'=>$action,
            'reason'=>$reason,
            'decision_model'=>'positive_expected_net_profit',
            'expected_net_profit'=>$ready && $expectedNetProfit,
            'expected_gross_move_percent'=>round($gross, 4),
            'estimated_roundtrip_cost_percent'=>round($estimatedCost, 4),
            'estimated_exit_cost_percent'=>round($estimatedExitCost, 4),
            'expected_net_edge_percent'=>round($netEdge, 4),
            'minimum_net_edge_percent'=>0.0,
            'cost_model'=>[
                'quote_asset'=>strtoupper((string) ($market['quote_asset'] ?? 'IRT')),
                'base_roundtrip_taker_fee_percent'=>round($baseFee, 4),
                'spread_cost_percent'=>round($spreadCost, 4),
                'slippage_noise_reserve_percent'=>round($slippageNoiseReserve, 4),
                'hidden_model_margin_percent'=>0.0,
            ],
            'reasons'=>array_values(array_unique($reasons)),
            'source'=>'nobitex_internal_profit_first_full_universe_v2',
            'timeframes'=>[
                '1m'=>[
                    'momentum_percent'=>round($m1,4),
                    'ema_gap_percent'=>round($e1,4),
                    'macd_histogram_percent'=>round($h1,4),
                    'rsi14'=>round($rsi1,2),
                    'samples'=>count($minute),
                ],
                '5m'=>[
                    'momentum_percent'=>round($m5,4),
                    'ema_gap_percent'=>round($e5,4),
                    'macd_histogram_percent'=>round($h5,4),
                    'rsi14'=>round($rsi5,2),
                    'samples'=>count($five),
                ],
                '15m'=>[
                    'momentum_percent'=>round($m15,4),
                    'ema_gap_percent'=>round($e15,4),
                    'macd_histogram_percent'=>round($h15,4),
                    'samples'=>count($fifteen),
                ],
            ],
            'indicators'=>[
                'momentum_blend_percent'=>round($momentum,4),
                'trend_blend_percent'=>round($trend,4),
                'macd_pressure_percent'=>round($macdPressure,4),
                'trend_consistency'=>round($trendConsistency,4),
                'volatility_percent'=>round($volatility,4),
                'orderbook_imbalance'=>round($imbalance,4),
                'spread_percent'=>round($spread,6),
                'rsi_penalty_percent'=>round($rsiPenalty,4),
            ],
            'tradingview'=>['enabled'=>false,'used'=>false,'required'=>false],
        ];
    }

    private function baseRoundtripFeePercent(array $market): float
    {
        $quote = strtoupper(trim((string) ($market['quote_asset'] ?? 'IRT')));
        return $quote === 'USDT'
            ? self::USDT_ROUNDTRIP_TAKER_FEE_PERCENT
            : self::IRT_ROUNDTRIP_TAKER_FEE_PERCENT;
    }

    private function notReady(array $minute, array $five, array $fifteen, string $reason): array
    {
        return [
            'ready'=>false,
            'score'=>0,
            'confidence'=>0,
            'confidence_is_gate'=>false,
            'action'=>'hold',
            'reason'=>$reason,
            'decision_model'=>'positive_expected_net_profit',
            'expected_net_profit'=>false,
            'expected_gross_move_percent'=>0.0,
            'estimated_roundtrip_cost_percent'=>0.0,
            'estimated_exit_cost_percent'=>0.0,
            'expected_net_edge_percent'=>0.0,
            'minimum_net_edge_percent'=>0.0,
            'cost_model'=>[],
            'reasons'=>[],
            'source'=>'nobitex_internal_profit_first_full_universe_v2',
            'timeframes'=>[
                '1m'=>['samples'=>count($minute)],
                '5m'=>['samples'=>count($five)],
                '15m'=>['samples'=>count($fifteen)],
            ],
            'indicators'=>['samples'=>count($minute)],
            'tradingview'=>['enabled'=>false,'used'=>false,'required'=>false],
        ];
    }

    private function clean(array $prices, int $limit): array
    {
        $out = [];
        foreach ($prices as $price) {
            if (!is_numeric($price)) continue;
            $n = (float) $price;
            if (is_finite($n) && $n > 0) $out[] = $n;
        }
        return array_slice($out, -$limit);
    }

    private function aggregate(array $minute, int $minutes): array
    {
        if ($minute === [] || $minutes <= 1) return $minute;
        $usable = intdiv(count($minute), $minutes) * $minutes;
        if ($usable < $minutes) return [];
        $aligned = array_slice($minute, -$usable);
        $out = [];
        for ($i = $minutes - 1, $n = count($aligned); $i < $n; $i += $minutes) {
            $out[] = (float) $aligned[$i];
        }
        return $out;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        if (!is_finite($value)) return $min;
        return max($min, min($max, $value));
    }
}
