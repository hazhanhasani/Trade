<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Chooses Market vs bounded marketable-Limit execution from spread, depth,
 * signal execution quality and the post-cost tradable edge. The planner never
 * widens the original hard slippage bound after submission.
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
        $tradableEdge = $this->number($signal['tradable_net_edge_percent'] ?? 0.0);
        $signalReady = ($signal['ready'] ?? false) === true;
        $signalAction = strtolower((string)($signal['action'] ?? 'hold'));

        $supportiveImbalance = $side === 'buy' ? $imbalance : -$imbalance;
        $maxSlipPct = max(0.08, min(0.35, 0.08 + ($spread * 0.70) + max(0.0, (72.0 - $quality) / 500.0)));
        $marketableOffsetPct = max(0.02, min(0.12, max(0.02, $spread * 0.20)));
        $edgeAfterMaxSlippage = $tradableEdge - $maxSlipPct;

        // Original strict fast-fill path.
        $strictMarketEligible = $spread <= 0.12
            && $depthMultiple >= 24.0
            && $quality >= 72.0
            && $supportiveImbalance >= -0.30;

        // Final live-fill path for an already-approved BUY. This does not create
        // a BUY signal and cannot bypass strategy/risk checks. It only chooses a
        // market execution when the existing post-cost edge has enough room to
        // absorb the planner's worst allowed slippage while the book is still
        // liquid and not materially adverse.
        $edgeBackedBuyMarketEligible = $side === 'buy'
            && $signalReady
            && $signalAction === 'buy'
            && $tradableEdge > 0.0
            && $edgeAfterMaxSlippage >= 0.12
            && $spread <= 0.20
            && $depthMultiple >= 12.0
            && $quality >= 65.0
            && $supportiveImbalance >= -0.20;

        $marketEligible = $strictMarketEligible || $edgeBackedBuyMarketEligible;

        if ($side === 'buy') {
            $hardLimit = $reference * (1.0 + ($maxSlipPct / 100.0));
            $limitPrice = min($hardLimit, $reference * (1.0 + ($marketableOffsetPct / 100.0)));
        } else {
            $hardLimit = $reference * (1.0 - ($maxSlipPct / 100.0));
            $limitPrice = max($hardLimit, $reference * (1.0 - ($marketableOffsetPct / 100.0)));
        }

        $reason = 'bounded_marketable_limit_execution';
        if ($strictMarketEligible) {
            $reason = 'deep_tight_book_market_execution';
        } elseif ($edgeBackedBuyMarketEligible) {
            $reason = 'positive_edge_live_fill_market_execution';
        }

        return [
            'model'=>'execution_quality_v2',
            'live_fill_policy'=>'positive_edge_live_fill_v1',
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
            'tradable_net_edge_percent'=>round($tradableEdge, 4),
            'edge_after_max_slippage_percent'=>round($edgeAfterMaxSlippage, 4),
            'strict_market_eligible'=>$strictMarketEligible,
            'edge_backed_market_eligible'=>$edgeBackedBuyMarketEligible,
            'reprice_policy'=>$marketEligible ? 'none' : 'one_bounded_reprice',
            'max_reprices'=>$marketEligible ? 0 : 1,
            'reason'=>$reason,
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
