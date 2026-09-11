<?php

declare(strict_types=1);

namespace Trade\Trading\Strategy;

use Trade\Trading\NobitexMarketRegimeDetector;

/**
 * A deliberately strict strategy for HIGH_VOLATILITY regimes.
 *
 * High volatility is not an unconditional dead zone, but a positive edge is
 * actionable only when the move is directional, aligned across timeframes and
 * not obviously exhausted. The main signal engine still requires positive
 * tradable net edge after fees, spread, slippage and uncertainty buffer.
 */
final class HighVolatilityMomentumStrategy implements NobitexStrategyInterface
{
    public function key(): string { return 'high_volatility_momentum_v2'; }

    public function evaluate(array $context, array $regime): array
    {
        $name = (string)($regime['regime'] ?? '');
        $eligible = $name === NobitexMarketRegimeDetector::HIGH_VOLATILITY;
        $metrics = is_array($regime['metrics'] ?? null) ? $regime['metrics'] : [];
        $i1 = is_array($context['indicators']['1m'] ?? null) ? $context['indicators']['1m'] : [];
        $i5 = is_array($context['indicators']['5m'] ?? null) ? $context['indicators']['5m'] : [];
        $i15 = is_array($context['indicators']['15m'] ?? null) ? $context['indicators']['15m'] : [];

        $m1 = $this->clamp((float)($i1['momentum_5_percent'] ?? 0.0), -4.0, 4.0);
        $m5 = $this->clamp((float)($i5['momentum_5_percent'] ?? 0.0), -7.0, 7.0);
        $m15 = $this->clamp((float)($i15['momentum_5_percent'] ?? 0.0), -10.0, 10.0);
        $e1 = $this->clamp((float)($i1['ema_gap_percent'] ?? 0.0), -2.5, 2.5);
        $e5 = $this->clamp((float)($i5['ema_gap_percent'] ?? 0.0), -4.0, 4.0);
        $e15 = $this->clamp((float)($i15['ema_gap_percent'] ?? 0.0), -6.0, 6.0);
        $h1 = $this->clamp((float)($i1['macd_histogram_percent'] ?? 0.0), -1.0, 1.0);
        $h5 = $this->clamp((float)($i5['macd_histogram_percent'] ?? 0.0), -1.5, 1.5);
        $h15 = $this->clamp((float)($i15['macd_histogram_percent'] ?? 0.0), -2.0, 2.0);
        $rsi1 = (float)($i1['rsi14'] ?? 50.0);
        $rsi5 = (float)($i5['rsi14'] ?? 50.0);
        $imbalance = $this->clamp((float)($context['market']['orderbook_imbalance'] ?? 0.0), -1.0, 1.0);

        $upAlignment = $this->clamp((float)($metrics['up_alignment'] ?? 0.0), 0.0, 1.0);
        $downAlignment = $this->clamp((float)($metrics['down_alignment'] ?? 0.0), 0.0, 1.0);
        $efficiency = $this->clamp((float)($metrics['efficiency_ratio'] ?? 0.0), 0.0, 1.0);
        $directionalMove = $this->clamp((float)($metrics['directional_move_percent'] ?? 0.0), -15.0, 15.0);
        $volatility = max(0.0, (float)($metrics['volatility_percent'] ?? 0.0));

        $momentum = ($m1 * 0.34) + ($m5 * 0.42) + ($m15 * 0.24);
        $trend = ($e1 * 0.18) + ($e5 * 0.44) + ($e15 * 0.38);
        $macd = ($h1 * 0.15) + ($h5 * 0.45) + ($h15 * 0.40);

        $exhaustion = 0.0;
        if ($rsi1 >= 82.0) $exhaustion += 0.80;
        elseif ($rsi1 >= 77.0) $exhaustion += 0.40;
        if ($rsi5 >= 80.0) $exhaustion += 0.55;
        elseif ($rsi5 >= 74.0) $exhaustion += 0.24;

        // Extra penalty prevents pure noise from looking profitable just because
        // one short timeframe printed a large candle.
        $noisePenalty = max(0.0, (0.38 - $efficiency) * 1.50)
            + max(0.0, (0.72 - $upAlignment) * 0.90)
            + max(0.0, -$imbalance) * 0.25;

        $rawGross = ($momentum * 0.48)
            + ($trend * 0.34)
            + ($macd * 0.70)
            + (max(0.0, $directionalMove) * 0.16)
            + (max(0.0, $imbalance) * 0.28)
            - $exhaustion
            - $noisePenalty;

        $entryChecks = [
            'up_alignment_below_0_66'=>$upAlignment >= 0.66,
            'efficiency_below_0_30'=>$efficiency >= 0.30,
            'directional_move_below_0_20'=>$directionalMove > 0.20,
            'momentum_1m_not_positive'=>$m1 > 0.0,
            'momentum_5m_not_positive'=>$m5 > 0.0,
            'momentum_15m_negative'=>$m15 >= 0.0,
            'orderbook_imbalance_adverse'=>$imbalance > -0.20,
            'rsi_1m_exhausted'=>$rsi1 < 84.0,
            'rsi_5m_exhausted'=>$rsi5 < 82.0,
        ];
        $failedEntryGuards = [];
        foreach ($entryChecks as $guard => $passed) {
            if (!$passed) $failedEntryGuards[] = $guard;
        }
        $directionalUp = $failedEntryGuards === [];

        $directionalDown = $downAlignment >= 0.66
            && $directionalMove < -0.20
            && ($m1 < 0.0 || $m5 < 0.0);

        // A positive model estimate must never be exposed as an actionable
        // strategy edge when the directional gate itself failed. Keep the raw
        // estimate in diagnostics for forensics, but cap the advertised edge at
        // zero until every directional condition is satisfied.
        $gross = $directionalUp ? $rawGross : min(0.0, $rawGross);

        $entry = $eligible && $directionalUp && $gross > 0.0;
        $exit = $eligible && ($directionalDown || $gross < 0.0);

        $confidence = (int)round($this->clamp(
            ((float)($regime['confidence'] ?? 0) * 0.45)
                + ($upAlignment * 20.0)
                + ($efficiency * 15.0)
                + min(15.0, max(0.0, $directionalMove) * 4.0)
                + max(0.0, $imbalance) * 5.0,
            0.0,
            100.0
        ));

        $reason = 'regime_not_high_volatility';
        if ($eligible) {
            if ($entry) $reason = 'directional_high_volatility_momentum';
            elseif ($directionalDown) $reason = 'high_volatility_downside_bias';
            elseif (!$directionalUp) $reason = $this->primaryGuardReason($failedEntryGuards);
            else $reason = 'high_volatility_edge_not_positive';
        }

        return [
            'key'=>$this->key(),
            'eligible'=>$eligible,
            'entry_allowed'=>$entry,
            'exit_bias'=>$exit,
            'gross_edge_percent'=>round(is_finite($gross) ? $gross : 0.0, 4),
            'confidence'=>$confidence,
            'reason'=>$reason,
            'holding_horizon_minutes'=>60,
            'diagnostics'=>[
                'raw_model_gross_edge_percent'=>round(is_finite($rawGross) ? $rawGross : 0.0, 4),
                'actionable_gross_edge_percent'=>round(is_finite($gross) ? $gross : 0.0, 4),
                'directional_up'=>$directionalUp,
                'directional_down'=>$directionalDown,
                'failed_entry_guards'=>$failedEntryGuards,
                'entry_checks'=>$entryChecks,
                'momentum_1m_percent'=>round($m1,4),
                'momentum_5m_percent'=>round($m5,4),
                'momentum_15m_percent'=>round($m15,4),
                'rsi_1m'=>round($rsi1,4),
                'rsi_5m'=>round($rsi5,4),
                'momentum_blend_percent'=>round($momentum,4),
                'trend_blend_percent'=>round($trend,4),
                'macd_pressure_percent'=>round($macd,4),
                'up_alignment'=>round($upAlignment,4),
                'down_alignment'=>round($downAlignment,4),
                'efficiency_ratio'=>round($efficiency,4),
                'directional_move_percent'=>round($directionalMove,4),
                'volatility_percent'=>round($volatility,4),
                'orderbook_imbalance'=>round($imbalance,4),
                'exhaustion_penalty_percent'=>round($exhaustion,4),
                'noise_penalty_percent'=>round($noisePenalty,4),
            ],
        ];
    }

    /** @param list<string> $failed */
    private function primaryGuardReason(array $failed): string
    {
        $priority = [
            'up_alignment_below_0_66'=>'high_volatility_alignment_below_threshold',
            'efficiency_below_0_30'=>'high_volatility_efficiency_below_threshold',
            'directional_move_below_0_20'=>'high_volatility_directional_move_below_threshold',
            'momentum_5m_not_positive'=>'high_volatility_momentum_5m_not_positive',
            'momentum_15m_negative'=>'high_volatility_momentum_15m_negative',
            'momentum_1m_not_positive'=>'high_volatility_momentum_1m_not_positive',
            'orderbook_imbalance_adverse'=>'high_volatility_orderbook_adverse',
            'rsi_1m_exhausted'=>'high_volatility_rsi_1m_exhausted',
            'rsi_5m_exhausted'=>'high_volatility_rsi_5m_exhausted',
        ];
        foreach ($priority as $guard => $reason) {
            if (in_array($guard, $failed, true)) return $reason;
        }
        return 'high_volatility_not_directional_enough';
    }

    private function clamp(float $value, float $min, float $max): float
    {
        if (!is_finite($value)) return $min;
        return max($min, min($max, $value));
    }
}
