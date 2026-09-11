<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Converts an approved quote budget into an exchange-valid base amount.
 * Normal sizing remains floor-rounded. If one precision step makes an otherwise
 * valid BUY miss the exchange minimum, it may round up exactly one step while
 * remaining inside the portfolio engine's hard risk/balance cap.
 */
final class NobitexOrderSizing
{
    public static function forEntry(float $budget, float $hardCap, float $price, float $minimumOrder, int $precision): array
    {
        $budget = max(0.0, $budget);
        $hardCap = max(0.0, $hardCap);
        $price = max(0.0, $price);
        $minimumOrder = max(0.0, $minimumOrder);
        $precision = max(0, min(18, $precision));

        if (!is_finite($budget) || !is_finite($hardCap) || !is_finite($price) || !is_finite($minimumOrder) || $price <= 0.0 || $hardCap <= 0.0) {
            return ['allowed'=>false,'reason'=>'invalid_sizing_inputs'];
        }

        $effectiveBudget = min($budget, $hardCap);
        $factor = 10 ** $precision;
        $step = 1.0 / $factor;
        $targetAmount = $effectiveBudget / $price;
        $floorAmount = floor(($targetAmount * $factor) + 1.0e-12) / $factor;
        $floorValue = $floorAmount * $price;
        $epsilon = max(1.0e-6, $hardCap * 1.0e-12);

        if ($floorAmount > 0.0 && ($minimumOrder <= 0.0 || $floorValue + $epsilon >= $minimumOrder)) {
            return ['allowed'=>true,'reason'=>'floor_amount_valid','amount'=>$floorAmount,'order_value'=>$floorValue,'rounded_up'=>false,'precision_step'=>$step];
        }
        if ($minimumOrder <= 0.0) {
            return ['allowed'=>false,'reason'=>'rounded_amount_is_zero','required_order_value'=>0.0];
        }

        $minimumAmount = ceil((($minimumOrder / $price) * $factor) - 1.0e-12) / $factor;
        $minimumValue = $minimumAmount * $price;
        if ($minimumAmount > $floorAmount + $step + 1.0e-12) {
            return ['allowed'=>false,'reason'=>'minimum_order_requires_more_than_one_precision_step','required_order_value'=>$minimumValue,'floor_order_value'=>$floorValue,'precision_step'=>$step];
        }
        if ($minimumValue > $hardCap + $epsilon) {
            return ['allowed'=>false,'reason'=>'minimum_order_rounding_exceeds_hard_cap','required_order_value'=>$minimumValue,'hard_entry_cap'=>$hardCap,'precision_step'=>$step];
        }

        return ['allowed'=>true,'reason'=>'rounded_up_one_step_to_exchange_minimum','amount'=>$minimumAmount,'order_value'=>$minimumValue,'rounded_up'=>true,'planned_budget'=>$budget,'hard_entry_cap'=>$hardCap,'precision_step'=>$step];
    }
}
