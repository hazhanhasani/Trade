<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Exchange-native multi-timeframe profitability engine for Nobitex.
 *
 * Decisions are NOT gated by an arbitrary signal score. The engine estimates
 * the expected directional move from 1m/5m/15m trend, momentum, MACD, RSI,
 * order-book flow and volatility, then subtracts a conservative round-trip
 * execution-cost estimate (fees + spread + slippage/noise buffer).
 *
 * The legacy `score` field is kept at zero only because older DB/UI code still
 * expects that column. It is not used to decide BUY or SELL.
 */
final class NobitexInternalSignalEngine
{
    private const BASE_ROUNDTRIP_COST_PERCENT = 0.55;
    private const MIN_EXPECTED_NET_EDGE_PERCENT = 0.18;
    private const MAX_ENTRY_SPREAD_PERCENT = 0.85;

    public function __construct(private readonly SignalEngine $base = new SignalEngine()) {}

    public function analyze(array $market, array $minutePrices, int $legacyThreshold = 60): array
    {
        // $legacyThreshold is intentionally ignored for trade decisions. It is
        // retained only to keep the existing scanner method signature stable.
        $minute = $this->clean($minutePrices, 480);
        $five = $this->aggregate($minute, 5);
        $fifteen = $this->aggregate($minute, 15);

        if (count($minute) < 60 || count($five) < 30 || count($fifteen) < 26) {
            return [
                'ready'=>false,
                'score'=>0,
                'confidence'=>0,
                'action'=>'hold',
                'reason'=>'insufficient_internal_mtf_history',
                'decision_model'=>'expected_net_edge',
                'expected_gross_move_percent'=>0.0,
                'estimated_roundtrip_cost_percent'=>0.0,
                'expected_net_edge_percent'=>0.0,
                'minimum_net_edge_percent'=>self::MIN_EXPECTED_NET_EDGE_PERCENT,
                'reasons'=>[],
                'source'=>'nobitex_internal_profit_edge',
                'timeframes'=>[
                    '1m'=>['samples'=>count($minute)],
                    '5m'=>['samples'=>count($five)],
                    '15m'=>['samples'=>count($fifteen)],
                ],
                'indicators'=>['samples'=>count($minute)],
                'tradingview'=>['enabled'=>false,'used'=>false,'required'=>false],
            ];
        }

        // SignalEngine is used only as an indicator calculator here. Its score
        // and BUY/SELL threshold are not consumed by this engine.
        $one = $this->base->analyze($market + ['prices'=>$minute], 60);
        $fiveSignal = $this->base->analyze($market + ['prices'=>$five], 60);
        $fifteenSignal = $this->base->analyze($market + ['prices'=>$fifteen], 60);

        $i1 = is_array($one['indicators'] ?? null) ? $one['indicators'] : [];
        $i5 = is_array($fiveSignal['indicators'] ?? null) ? $fiveSignal['indicators'] : [];
        $i15 = is_array($fifteenSignal['indicators'] ?? null) ? $fifteenSignal['indicators'] : [];

        $spread = max(0.0, (float) ($market['spread_percent'] ?? 0.0));
        $imbalance = $this->clamp((float) ($market['orderbook_imbalance'] ?? 0.0), -1.0, 1.0);

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
        $volatilityBuffer = min(0.60, $volatility * 0.35);
        $spreadCost = min(1.50, $spread * 1.25);
        $estimatedCost = self::BASE_ROUNDTRIP_COST_PERCENT + $spreadCost + $volatilityBuffer;
        $netEdge = $gross - $estimatedCost;

        $ready = (bool) ($one['ready'] ?? false)
            && (bool) ($fiveSignal['ready'] ?? false)
            && (bool) ($fifteenSignal['ready'] ?? false)
            && $spread <= 1.5;

        $bullishVotes = 0;
        if ($m1 > 0.05) $bullishVotes++;
        if ($m5 > 0.10) $bullishVotes++;
        if ($e5 > 0.0) $bullishVotes++;
        if ($e15 > -0.05) $bullishVotes++;
        if ($h5 >= 0.0) $bullishVotes++;
        if ($trendConsistency >= 0.50) $bullishVotes++;
        if ($imbalance > -0.20) $bullishVotes++;

        $bearishVotes = 0;
        if ($m1 < -0.08) $bearishVotes++;
        if ($m5 < -0.12) $bearishVotes++;
        if ($e5 < 0.0) $bearishVotes++;
        if ($e15 < -0.05) $bearishVotes++;
        if ($h5 < 0.0) $bearishVotes++;
        if ($trendConsistency < 0.44) $bearishVotes++;
        if ($imbalance < -0.18) $bearishVotes++;

        $buyGate = $ready
            && $spread <= self::MAX_ENTRY_SPREAD_PERCENT
            && $netEdge >= self::MIN_EXPECTED_NET_EDGE_PERCENT
            && $bullishVotes >= 4
            && $m1 > -0.05
            && $rsi1 < 78.0;

        // Strategy exits are based on a deteriorating expected move, not a
        // negative score. Hard stop-loss/take-profit remain independent.
        $sellGate = $ready
            && $gross <= -0.15
            && $bearishVotes >= 4;

        $action = 'hold';
        $reason = 'expected_edge_not_positive_enough';
        if ($buyGate) {
            $action = 'buy';
            $reason = 'positive_expected_net_edge';
        } elseif ($sellGate) {
            $action = 'sell';
            $reason = 'expected_edge_reversal';
        } elseif (!$ready) {
            $reason = 'market_quality_not_ready';
        } elseif ($spread > self::MAX_ENTRY_SPREAD_PERCENT) {
            $reason = 'spread_cost_too_high';
        } elseif ($netEdge > 0.0) {
            $reason = 'positive_edge_below_cost_safety_margin';
        }

        $confidence = $ready
            ? min(100, max(0, (int) round((abs($netEdge) * 45.0) + (max($bullishVotes, $bearishVotes) * 5.0))))
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
            'action'=>$action,
            'reason'=>$reason,
            'decision_model'=>'expected_net_edge',
            'expected_gross_move_percent'=>round($gross, 4),
            'estimated_roundtrip_cost_percent'=>round($estimatedCost, 4),
            'expected_net_edge_percent'=>round($netEdge, 4),
            'minimum_net_edge_percent'=>self::MIN_EXPECTED_NET_EDGE_PERCENT,
            'bullish_confirmations'=>$bullishVotes,
            'bearish_confirmations'=>$bearishVotes,
            'reasons'=>array_values(array_unique($reasons)),
            'source'=>'nobitex_internal_profit_edge',
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
