<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Canonical Nobitex order-fill parsing.
 *
 * Nobitex `amount` is the requested order amount, not the executed amount.
 * Actual execution is reported in `matchedAmount` (or equivalent aliases).
 * We only fall back to the requested amount for a terminal fully-filled order
 * when the exchange omitted an explicit matched field.
 */
final class NobitexOrderFill
{
    public static function status(array $order): string
    {
        return strtolower(trim((string)($order['status'] ?? '')));
    }

    public static function isDone(array $order): bool
    {
        return in_array(self::status($order), ['done', 'completed', 'filled'], true);
    }

    public static function isTerminal(array $order): bool
    {
        return self::isDone($order)
            || in_array(self::status($order), ['canceled', 'cancelled', 'rejected', 'failed'], true);
    }

    public static function requestedAmount(array $order, float $fallback = 0.0): float
    {
        foreach (['amount', 'requestedAmount', 'requested_amount', 'orderAmount', 'order_amount'] as $key) {
            $value = self::number($order[$key] ?? null);
            if ($value > 0.0) return $value;
        }
        return max(0.0, $fallback);
    }

    public static function matchedAmount(array $order, float $terminalDoneFallback = 0.0): float
    {
        foreach (['matchedAmount', 'matched_amount', 'filledAmount', 'filled_amount', 'executedAmount', 'executed_amount'] as $key) {
            if (!array_key_exists($key, $order)) continue;
            $value = self::number($order[$key]);
            return max(0.0, $value);
        }

        if (self::isDone($order)) {
            $requested = self::requestedAmount($order, $terminalDoneFallback);
            if ($requested > 0.0) return $requested;
        }

        return 0.0;
    }

    public static function averagePrice(array $order, float $fallback = 0.0): float
    {
        foreach (['averagePrice', 'average_price'] as $key) {
            $value = self::number($order[$key] ?? null);
            if ($value > 0.0) return $value;
        }

        // Limit orders may expose only their price in old/persisted snapshots.
        // Use it only when there is confirmed matched quantity or a done state.
        $price = self::number($order['price'] ?? null);
        if ($price > 0.0 && (self::matchedAmount($order, 0.0) > 0.0 || self::isDone($order))) {
            return $price;
        }

        return max(0.0, $fallback);
    }

    public static function fillRatio(array $order, float $requestedFallback = 0.0, float $doneMatchedFallback = 0.0): float
    {
        $requested = self::requestedAmount($order, $requestedFallback);
        if ($requested <= 0.0) return 0.0;
        $matched = self::matchedAmount($order, $doneMatchedFallback > 0.0 ? $doneMatchedFallback : $requested);
        return max(0.0, min(1.0, $matched / $requested));
    }

    private static function number(mixed $value): float
    {
        if (!is_numeric($value)) return 0.0;
        $number = (float)$value;
        return is_finite($number) ? $number : 0.0;
    }
}
