<?php

declare(strict_types=1);

namespace Trade\Trading;

use Trade\Exchange\NobitexClient;

/**
 * Keeps Nobitex execution/risk arithmetic in the exchange-native RLS unit,
 * while exposing IRT/Toman display fields to clients.
 *
 * Nobitex's rial markets use RLS internally. The product UI displays Toman,
 * so every RLS-denominated display value must be divided by 10 exactly once.
 */
final class NobitexPortfolioValuation
{
    private const RLS_PER_TOMAN = 10.0;

    public function snapshot(NobitexClient $client, array $wallets, array $positions): array
    {
        $cashRls = $this->walletRlsAvailable($wallets);
        $usdt = $this->walletAvailable($wallets, ['USDT']);

        // Position prices for logical IRT markets are still exchange-native RLS.
        $notionalRaw = ['IRT'=>0.0,'USDT'=>0.0];
        foreach ($positions as $position) {
            if (!is_array($position)) continue;
            $quote = strtoupper((string)($position['quote_asset'] ?? ''));
            if (!isset($notionalRaw[$quote])) continue;
            $mark = $this->number($position['mark_price'] ?? 0);
            if ($mark <= 0) $mark = $this->number($position['entry_price'] ?? 0);
            $amount = max(0.0, $this->number($position['amount'] ?? 0));
            $notionalRaw[$quote] += $amount * max(0.0, $mark);
        }

        $needsConversion = $usdt > 0.0 || $notionalRaw['USDT'] > 0.0;
        $usdtToRls = $needsConversion ? $this->usdtToRls($client) : 0.0;
        $conversionReady = !$needsConversion || $usdtToRls > 0.0;

        $cashTotalRls = $cashRls + ($usdtToRls > 0.0 ? $usdt * $usdtToRls : 0.0);
        $exposureRls = $notionalRaw['IRT'] + ($usdtToRls > 0.0 ? $notionalRaw['USDT'] * $usdtToRls : 0.0);
        $portfolioRls = $cashTotalRls + $exposureRls;

        $cashToman = $this->rlsToToman($cashTotalRls);
        $exposureToman = $this->rlsToToman($exposureRls);
        $portfolioToman = $this->rlsToToman($portfolioRls);
        $irtCashToman = $this->rlsToToman($cashRls);
        $irtNotionalToman = $this->rlsToToman($notionalRaw['IRT']);

        return [
            'model'=>'global_quote_normalization_v2_toman_display',
            'numeraire'=>'IRT',
            'display_unit'=>'TOMAN',
            'internal_numeraire'=>'RLS',
            'rls_per_irt'=>self::RLS_PER_TOMAN,
            'conversion_ready'=>$conversionReady,

            // Public/display conversion rate and values (Toman / IRT semantics).
            'usdt_to_irt_rate'=>$usdtToRls > 0.0 ? round($this->rlsToToman($usdtToRls), 8) : null,
            'cash_by_quote'=>['IRT'=>round($irtCashToman,8),'USDT'=>round($usdt,8)],
            'active_notional_by_quote'=>['IRT'=>round($irtNotionalToman,8),'USDT'=>round($notionalRaw['USDT'],8)],
            'cash_irt'=>round($cashToman,8),
            'exposure_irt'=>round($exposureToman,8),
            'portfolio_value_irt'=>round($portfolioToman,8),

            // Exchange-native fields for execution/risk calculations only.
            'usdt_to_rls_rate'=>$usdtToRls > 0.0 ? round($usdtToRls, 8) : null,
            'cash_rls'=>round($cashTotalRls,8),
            'exposure_rls'=>round($exposureRls,8),
            'portfolio_value_rls'=>round($portfolioRls,8),

            'exposure_percent'=>$portfolioRls > 0.0 ? round(($exposureRls / $portfolioRls) * 100.0, 4) : 0.0,
            'generated_at'=>gmdate(DATE_ATOM),
        ];
    }

    /**
     * Convert a Toman/IRT display amount to the requested logical quote.
     */
    public function quoteEquivalent(array $valuation, string $quote, float $irtValue): ?float
    {
        $quote = strtoupper($quote);
        if ($quote === 'IRT') return max(0.0, $irtValue);
        if ($quote !== 'USDT') return null;
        $rate = $this->number($valuation['usdt_to_irt_rate'] ?? 0);
        return $rate > 0.0 ? max(0.0, $irtValue / $rate) : null;
    }

    private function usdtToRls(NobitexClient $client): float
    {
        try {
            $response = $client->orderBook('USDTIRT');
            $book = is_array($response['USDTIRT'] ?? null)
                ? $response['USDTIRT']
                : (is_array($response['data']['USDTIRT'] ?? null)
                    ? $response['data']['USDTIRT']
                    : (is_array($response['data'] ?? null) ? $response['data'] : $response));
            $ask = $this->levelPrice($book['asks'][0] ?? null);
            $bid = $this->levelPrice($book['bids'][0] ?? null);
            $last = $this->number($book['lastTradePrice'] ?? $book['last_trade_price'] ?? $book['lastPrice'] ?? 0);
            if ($ask > 0.0 && $bid > 0.0 && $ask >= $bid) return ($ask + $bid) / 2.0;
            if ($last > 0.0) return $last;
            return max($ask, $bid);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * Return the rial wallet in exchange-native RLS. If a future response uses
     * IRT directly, normalize it back to RLS before risk arithmetic.
     */
    private function walletRlsAvailable(array $response): float
    {
        $rows = $this->walletRows($response);
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $asset = strtoupper((string)($row['currency'] ?? $row['asset'] ?? $row['currencyCode'] ?? ''));
            if (!in_array($asset, ['RLS','IRT'], true)) continue;
            $value = $this->walletRowValue($row);
            if ($value === null) continue;
            return $asset === 'IRT' ? max(0.0, $value * self::RLS_PER_TOMAN) : max(0.0, $value);
        }
        return 0.0;
    }

    private function walletAvailable(array $response, array $assets): float
    {
        $assets = array_map('strtoupper', $assets);
        foreach ($this->walletRows($response) as $row) {
            if (!is_array($row)) continue;
            $asset = strtoupper((string)($row['currency'] ?? $row['asset'] ?? $row['currencyCode'] ?? ''));
            if (!in_array($asset, $assets, true)) continue;
            $value = $this->walletRowValue($row);
            if ($value !== null) return max(0.0, $value);
        }
        return 0.0;
    }

    private function walletRows(array $response): array
    {
        $rows = $response['wallets'] ?? $response['data'] ?? $response;
        if (is_array($rows) && !array_is_list($rows) && is_array($rows['wallets'] ?? null)) $rows = $rows['wallets'];
        return is_array($rows) ? $rows : [];
    }

    private function walletRowValue(array $row): ?float
    {
        foreach (['activeBalance','available','free','balance'] as $key) {
            if (array_key_exists($key, $row)) return $this->number($row[$key]);
        }
        return null;
    }

    private function rlsToToman(float $value): float
    {
        return $value / self::RLS_PER_TOMAN;
    }

    private function levelPrice(mixed $level): float
    {
        if (!is_array($level)) return 0.0;
        return $this->number(array_is_list($level) ? ($level[0] ?? 0) : ($level['price'] ?? 0));
    }

    private function number(mixed $value): float
    {
        if (!is_numeric($value)) return 0.0;
        $n = (float)$value;
        return is_finite($n) ? $n : 0.0;
    }
}
