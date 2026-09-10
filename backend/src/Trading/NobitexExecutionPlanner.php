<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Chooses Market vs bounded marketable-Limit execution from spread, depth and
 * signal execution quality. It never widens risk limits after submission.
 */
final class NobitexExecutionPlanner
{
    public function plan(array $market, array $signal, float $amount, string $side): array
    {
        $side = strtolower($side) === 'sell' ? 'sell' : 'buy';
        $bestAsk = $this->number($market['best_ask'] ?? 0);
        $bestBid = $this->number($market['best_bid'] ?? 0);
        $reference = $side === 'buy' ? $bestAsk : $bestBid;
        if ($reference <= 0.0) $reference = $this->number($market['price'] ?? 0);
        $spread = max(0.0, $this->number($market['spread_percent'] ?? 0));
        $depth = max(0.0, $this->number($market['depth_quote'] ?? 0));
        $orderValue = max(0.0, $amount * max($reference, 0.0));
        $depthMultiple = $orderValue > 0.0 ? $depth / $orderValue : 0.0;
        $quality = max(0.0, min(100.0, $this->number($signal['execution_quality_score'] ?? 50)));
        $imbalance = max(-1.0, min(1.0, $this->number($market['orderbook_imbalance'] ?? 0)));

        $supportiveImbalance = $side === 'buy' ? $imbalance : -$imbalance;
        $marketEligible = $spread <= 0.12 && $depthMultiple >= 24.0 && $quality >= 72.0 && $supportiveImbalance >= -0.30;

        $maxSlipPct = max(0.08, min(0.35, 0.08 + ($spread * 0.70) + max(0.0, (72.0 - $quality) / 500.0)));
        $marketableOffsetPct = max(0.02, min(0.12, max(0.02, $spread * 0.20)));

        if ($side === 'buy') {
            $hardLimit = $reference * (1.0 + ($maxSlipPct / 100.0));
            $limitPrice = min($hardLimit, $reference * (1.0 + ($marketableOffsetPct / 100.0)));
        } else {
            $hardLimit = $reference * (1.0 - ($maxSlipPct / 100.0));
            $limitPrice = max($hardLimit, $reference * (1.0 - ($marketableOffsetPct / 100.0)));
        }

        return [
            'model'=>'execution_quality_v2',
            'mode'=>$marketEligible ? 'market' : 'limit',
            'side'=>$side,
            'reference_price'=>$reference,
            'limit_price'=>max(0.00000001, $limitPrice),
            'hard_price_limit'=>max(0.00000001, $hardLimit),
            'max_slippage_percent'=>round($maxSlipPct, 4),
            'spread_percent'=>round($spread, 4),
            'depth_multiple'=>round($depthMultiple, 3),
            'execution_quality_score'=>round($quality, 2),
            'orderbook_imbalance'=>round($imbalance, 4),
            'reprice_policy'=>$marketEligible ? 'none' : 'one_bounded_reprice',
            'max_reprices'=>$marketEligible ? 0 : 1,
            'reason'=>$marketEligible ? 'deep_tight_book_market_execution' : 'bounded_marketable_limit_execution',
        ];
    }

    public function canReprice(array $position, array $market, array $signal, string $side): array
    {
        $count = max(0, (int)($position['execution_reprice_count'] ?? 0));
        $max = max(0, (int)($position['execution_max_reprices'] ?? 1));
        if ($count >= $max) return ['allowed'=>false,'reason'=>'execution_reprice_limit_reached'];
        if (($signal['ready'] ?? false) !== true) return ['allowed'=>false,'reason'=>'signal_not_ready_for_reprice'];
        if ($side === 'buy' && (string)($signal['action'] ?? 'hold') !== 'buy') return ['allowed'=>false,'reason'=>'buy_signal_expired'];
        if ($side === 'sell' && (string)($signal['action'] ?? 'hold') === 'buy') return ['allowed'=>false,'reason'=>'sell_reprice_not_supported_by_signal'];

        $amount = max(0.0, $this->number($position['amount'] ?? 0));
        $plan = $this->plan($market, $signal, $amount, $side);
        $originalHard = $this->number($position['execution_hard_price_limit'] ?? 0);
        if ($originalHard <= 0.0) return ['allowed'=>false,'reason'=>'original_execution_bound_missing'];

        $candidate = $this->number($plan['limit_price'] ?? 0);
        $within = $side === 'buy' ? $candidate <= $originalHard : $candidate >= $originalHard;
        if (!$within) return ['allowed'=>false,'reason'=>'market_moved_beyond_original_execution_bound','plan'=>$plan];
        return ['allowed'=>true,'reason'=>'bounded_reprice_allowed','plan'=>$plan];
    }

    private function number(mixed $value): float
    {
        if (!is_numeric($value)) return 0.0;
        $n=(float)$value;
        return is_finite($n)?$n:0.0;
    }
}
