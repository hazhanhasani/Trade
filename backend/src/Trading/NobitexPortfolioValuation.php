<?php

declare(strict_types=1);

namespace Trade\Trading;

use Trade\Exchange\NobitexClient;

/**
 * Normalizes Nobitex IRT + USDT quote capital into one IRT numeraire.
 * This prevents the old per-quote exposure caps from independently consuming
 * the same portfolio risk budget.
 */
final class NobitexPortfolioValuation
{
    public function snapshot(NobitexClient $client, array $wallets, array $positions): array
    {
        $irt = $this->walletAvailable($wallets, ['RLS','IRT']);
        $usdt = $this->walletAvailable($wallets, ['USDT']);
        $notional = ['IRT'=>0.0,'USDT'=>0.0];
        foreach ($positions as $position) {
            if (!is_array($position)) continue;
            $quote = strtoupper((string)($position['quote_asset'] ?? ''));
            if (!isset($notional[$quote])) continue;
            $mark = $this->number($position['mark_price'] ?? 0);
            if ($mark <= 0) $mark = $this->number($position['entry_price'] ?? 0);
            $amount = max(0.0, $this->number($position['amount'] ?? 0));
            $notional[$quote] += $amount * max(0.0, $mark);
        }

        $needsConversion = $usdt > 0.0 || $notional['USDT'] > 0.0;
        $rate = $needsConversion ? $this->usdtToIrt($client) : 0.0;
        $conversionReady = !$needsConversion || $rate > 0.0;

        $cashIrt = $irt + ($rate > 0.0 ? $usdt * $rate : 0.0);
        $exposureIrt = $notional['IRT'] + ($rate > 0.0 ? $notional['USDT'] * $rate : 0.0);
        $totalIrt = $cashIrt + $exposureIrt;

        return [
            'model'=>'global_quote_normalization_v1',
            'numeraire'=>'IRT',
            'conversion_ready'=>$conversionReady,
            'usdt_to_irt_rate'=>$rate > 0.0 ? round($rate, 8) : null,
            'cash_by_quote'=>['IRT'=>round($irt,8),'USDT'=>round($usdt,8)],
            'active_notional_by_quote'=>['IRT'=>round($notional['IRT'],8),'USDT'=>round($notional['USDT'],8)],
            'cash_irt'=>round($cashIrt,8),
            'exposure_irt'=>round($exposureIrt,8),
            'portfolio_value_irt'=>round($totalIrt,8),
            'exposure_percent'=>$totalIrt > 0.0 ? round(($exposureIrt / $totalIrt) * 100.0, 4) : 0.0,
            'generated_at'=>gmdate(DATE_ATOM),
        ];
    }

    public function quoteEquivalent(array $valuation, string $quote, float $irtValue): ?float
    {
        $quote = strtoupper($quote);
        if ($quote === 'IRT') return max(0.0, $irtValue);
        if ($quote !== 'USDT') return null;
        $rate = $this->number($valuation['usdt_to_irt_rate'] ?? 0);
        return $rate > 0.0 ? max(0.0, $irtValue / $rate) : null;
    }

    private function usdtToIrt(NobitexClient $client): float
    {
        try {
            $response = $client->orderBook('USDTIRT');
            $book = is_array($response['USDTIRT'] ?? null) ? $response['USDTIRT'] : (is_array($response['data']['USDTIRT'] ?? null) ? $response['data']['USDTIRT'] : (is_array($response['data'] ?? null) ? $response['data'] : $response));
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

    private function walletAvailable(array $response, array $assets): float
    {
        $assets = array_map('strtoupper', $assets);
        $rows = $response['wallets'] ?? $response['data'] ?? $response;
        if (is_array($rows) && !array_is_list($rows) && is_array($rows['wallets'] ?? null)) $rows = $rows['wallets'];
        if (!is_array($rows)) return 0.0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $asset = strtoupper((string)($row['currency'] ?? $row['asset'] ?? $row['currencyCode'] ?? ''));
            if (!in_array($asset, $assets, true)) continue;
            foreach (['activeBalance','available','free','balance'] as $key) {
                if (array_key_exists($key, $row)) return max(0.0, $this->number($row[$key]));
            }
        }
        return 0.0;
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
