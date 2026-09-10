<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Pure, fail-closed order notional guard.
 *
 * The service accepts both `amount` and the legacy `amount1` alias. The guard
 * always works on the normalized amount and on the final execution price bound,
 * so an Execution Planner reprice cannot silently bypass max_order_value.
 */
final class NobitexOrderValueGuard
{
    public const MODEL = 'resolved_order_value_guard_v2';

    public static function amountFromInput(array $input): float
    {
        $value = $input['amount'] ?? $input['amount1'] ?? null;
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value <= 0.0) {
            throw new \InvalidArgumentException('amount must be positive.');
        }
        return (float)$value;
    }

    /**
     * Returns the strongest deterministic execution-price bound available for
     * max_order_value. An unpriced stop-market order has no deterministic fill
     * ceiling, therefore a configured cap must fail closed for that mode.
     */
    public static function priceBound(string $mode, ?float $price, array $input = []): ?float
    {
        $mode = strtolower(trim($mode));
        if ($mode === 'stop_market') return null;

        $candidates = [];
        if ($price !== null && is_finite($price) && $price > 0.0) $candidates[] = $price;

        if (in_array($mode, ['stop_limit', 'oco'], true)) {
            foreach (['stopPrice', 'price_stop', 'stopLimitPrice', 'price_limit_oco', 'price_limit'] as $key) {
                if (!isset($input[$key]) || !is_numeric($input[$key])) continue;
                $value = (float)$input[$key];
                if (is_finite($value) && $value > 0.0) $candidates[] = $value;
            }
        }

        return $candidates === [] ? null : max($candidates);
    }

    /** @return array<string,mixed> */
    public static function assess(float $amount, ?float $priceBound, float $maxValue): array
    {
        if (!is_finite($amount) || $amount <= 0.0) {
            return [
                'model'=>self::MODEL,
                'enabled'=>$maxValue > 0.0,
                'allowed'=>false,
                'reason'=>'invalid_order_amount',
                'amount'=>$amount,
                'price_bound'=>$priceBound,
                'order_value'=>null,
                'max_order_value'=>$maxValue,
            ];
        }

        if (!is_finite($maxValue) || $maxValue <= 0.0) {
            return [
                'model'=>self::MODEL,
                'enabled'=>false,
                'allowed'=>true,
                'reason'=>'max_order_value_disabled',
                'amount'=>$amount,
                'price_bound'=>$priceBound,
                'order_value'=>$priceBound !== null && is_finite($priceBound) && $priceBound > 0.0
                    ? $amount * $priceBound
                    : null,
                'max_order_value'=>max(0.0, is_finite($maxValue) ? $maxValue : 0.0),
            ];
        }

        if ($priceBound === null || !is_finite($priceBound) || $priceBound <= 0.0) {
            return [
                'model'=>self::MODEL,
                'enabled'=>true,
                'allowed'=>false,
                'reason'=>'max_order_value_price_bound_unavailable',
                'amount'=>$amount,
                'price_bound'=>$priceBound,
                'order_value'=>null,
                'max_order_value'=>$maxValue,
            ];
        }

        $orderValue = $amount * $priceBound;
        $epsilon = max(0.000000001, abs($maxValue) * 0.000000000001);
        $allowed = is_finite($orderValue) && $orderValue <= $maxValue + $epsilon;

        return [
            'model'=>self::MODEL,
            'enabled'=>true,
            'allowed'=>$allowed,
            'reason'=>$allowed ? 'within_max_order_value' : 'max_order_value_exceeded',
            'amount'=>$amount,
            'price_bound'=>$priceBound,
            'order_value'=>is_finite($orderValue) ? $orderValue : null,
            'max_order_value'=>$maxValue,
        ];
    }

    /** @return array<string,mixed> */
    public static function assertWithinLimit(float $amount, ?float $priceBound, float $maxValue): array
    {
        $assessment = self::assess($amount, $priceBound, $maxValue);
        if ($assessment['allowed'] ?? false) return $assessment;

        $reason = (string)($assessment['reason'] ?? 'max_order_value_guard_failed');
        if ($reason === 'max_order_value_price_bound_unavailable') {
            throw new \RuntimeException('Configured max_order_value cannot be enforced without a deterministic execution price bound.');
        }
        if ($reason === 'max_order_value_exceeded') {
            throw new \RuntimeException('Order exceeds configured max_order_value.');
        }
        throw new \RuntimeException('Order value safety guard rejected the order: ' . $reason);
    }
}
