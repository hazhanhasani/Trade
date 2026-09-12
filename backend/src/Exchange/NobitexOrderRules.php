<?php

declare(strict_types=1);

namespace Trade\Exchange;

/**
 * Deterministic spot-order normalization from Nobitex /v2/options.
 *
 * Nobitex exposes amountPrecisions and pricePrecisions as step sizes, not just
 * decimal-place counts. A step can therefore be 0.1, 10, 1000, etc. Every
 * amount/price is floored to the exact exchange step so a safety bound is never
 * loosened by rounding up.
 */
final class NobitexOrderRules
{
    /** @return array{payload:array<string,mixed>,rules:array<string,mixed>,valid:bool,reason:?string} */
    public static function prepare(array $payload, array $options): array
    {
        $src = strtoupper(trim((string)($payload['srcCurrency'] ?? '')));
        $dst = strtoupper(trim((string)($payload['dstCurrency'] ?? '')));
        if ($src === '' || $dst === '') {
            return ['payload'=>$payload,'rules'=>[],'valid'=>false,'reason'=>'missing_market_pair'];
        }

        $quote = $dst === 'RLS' ? 'IRT' : $dst;
        $symbol = $src . $quote;
        $nobitex = self::nobitexOptions($options);
        $amountStep = self::stepFor((array)($nobitex['amountPrecisions'] ?? []), $symbol);
        $priceStep = self::stepFor((array)($nobitex['pricePrecisions'] ?? []), $symbol);
        $minOrder = self::minOrder($nobitex, $quote);

        $originalAmount = self::positiveNumber($payload['amount'] ?? null);
        if ($originalAmount <= 0.0) {
            return [
                'payload'=>$payload,
                'rules'=>['symbol'=>$symbol,'amount_step'=>$amountStep,'price_step'=>$priceStep,'min_order_quote'=>$minOrder],
                'valid'=>false,
                'reason'=>'invalid_amount',
            ];
        }

        $normalized = $payload;
        $amount = $amountStep > 0.0 ? self::floorToStep($originalAmount, $amountStep) : $originalAmount;
        if ($amount <= 0.0) {
            return [
                'payload'=>$payload,
                'rules'=>[
                    'symbol'=>$symbol,
                    'amount_step'=>$amountStep,
                    'price_step'=>$priceStep,
                    'min_order_quote'=>$minOrder,
                    'original_amount'=>$originalAmount,
                ],
                'valid'=>false,
                'reason'=>'amount_below_exchange_step',
            ];
        }
        $normalized['amount'] = self::decimal($amount, $amountStep);

        $price = 0.0;
        $originalPrice = self::positiveNumber($payload['price'] ?? null);
        if ($originalPrice > 0.0) {
            $price = $priceStep > 0.0 ? self::floorToStep($originalPrice, $priceStep) : $originalPrice;
            if ($price <= 0.0) {
                return [
                    'payload'=>$payload,
                    'rules'=>[
                        'symbol'=>$symbol,
                        'amount_step'=>$amountStep,
                        'price_step'=>$priceStep,
                        'min_order_quote'=>$minOrder,
                        'original_price'=>$originalPrice,
                    ],
                    'valid'=>false,
                    'reason'=>'price_below_exchange_step',
                ];
            }
            $normalized['price'] = self::decimal($price, $priceStep);
        }

        foreach (['stopPrice','stopLimitPrice'] as $field) {
            $original = self::positiveNumber($payload[$field] ?? null);
            if ($original <= 0.0) continue;
            $value = $priceStep > 0.0 ? self::floorToStep($original, $priceStep) : $original;
            if ($value > 0.0) $normalized[$field] = self::decimal($value, $priceStep);
        }

        $estimated = $price > 0.0 ? $amount * $price : null;
        $belowMinimum = $estimated !== null && $minOrder > 0.0 && $estimated + self::epsilon($minOrder) < $minOrder;

        return [
            'payload'=>$normalized,
            'valid'=>true,
            'reason'=>null,
            'rules'=>[
                'symbol'=>$symbol,
                'quote_asset'=>$quote,
                'amount_step'=>$amountStep,
                'price_step'=>$priceStep,
                'min_order_quote'=>$minOrder,
                'original_amount'=>$originalAmount,
                'normalized_amount'=>$amount,
                'amount_changed'=>abs($amount-$originalAmount) > self::epsilon(max(1.0,$originalAmount)),
                'original_price'=>$originalPrice > 0.0 ? $originalPrice : null,
                'normalized_price'=>$price > 0.0 ? $price : null,
                'price_changed'=>$originalPrice > 0.0 && abs($price-$originalPrice) > self::epsilon(max(1.0,$originalPrice)),
                'estimated_order_value'=>$estimated,
                'below_minimum'=>$belowMinimum,
                'source'=>'nobitex_v2_options',
            ],
        ];
    }

    private static function nobitexOptions(array $options): array
    {
        if (is_array($options['nobitex'] ?? null)) return $options['nobitex'];
        if (is_array($options['data']['nobitex'] ?? null)) return $options['data']['nobitex'];
        return [];
    }

    private static function stepFor(array $rows, string $symbol): float
    {
        foreach ([$symbol, strtoupper($symbol), strtolower($symbol)] as $key) {
            $step = self::positiveNumber($rows[$key] ?? null);
            if ($step > 0.0) return $step;
        }
        return 0.0;
    }

    private static function minOrder(array $nobitex, string $quote): float
    {
        $rows = is_array($nobitex['minOrders'] ?? null) ? $nobitex['minOrders'] : [];
        foreach ($quote === 'IRT' ? ['rls','RLS','irt','IRT'] : ['usdt','USDT'] as $key) {
            $value = self::positiveNumber($rows[$key] ?? null);
            if ($value > 0.0) return $value;
        }
        return $quote === 'IRT' ? 3000000.0 : ($quote === 'USDT' ? 11.0 : 0.0);
    }

    private static function floorToStep(float $value, float $step): float
    {
        if ($value <= 0.0 || $step <= 0.0) return $value;
        $units = floor(($value / $step) + 1e-10);
        return max(0.0, $units * $step);
    }

    private static function decimal(float $value, float $step): string
    {
        $precision = self::stepPrecision($step);
        $formatted = number_format($value, $precision, '.', '');
        if ($precision === 0) return $formatted;
        return rtrim(rtrim($formatted, '0'), '.');
    }

    private static function stepPrecision(float $step): int
    {
        if ($step <= 0.0) return 12;
        if ($step >= 1.0) return 0;
        $text = sprintf('%.18F', $step);
        $fraction = rtrim(substr(strrchr($text, '.'), 1), '0');
        return max(0, min(18, strlen($fraction)));
    }

    private static function positiveNumber(mixed $value): float
    {
        if (!is_numeric($value)) return 0.0;
        $number = (float)$value;
        return is_finite($number) && $number > 0.0 ? $number : 0.0;
    }

    private static function epsilon(float $scale): float
    {
        return max(1e-12, abs($scale) * 1e-12);
    }
}
