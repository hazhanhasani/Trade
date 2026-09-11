<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Cross-market Nobitex price oracle.
 *
 * For every asset it compares the local IRT spot price with the USDT spot
 * price translated through Nobitex's own USDT/IRT market. This gives the signal
 * engine an exchange-internal local-vs-global reference without depending on an
 * external price provider.
 */
final class NobitexCrossMarketPriceOracle
{
    public const MODEL = 'nobitex_spot_irt_plus_usdt_cross_rate_v1';

    private const MAX_REFERENCE_SPREAD_PERCENT = 1.50;
    private const MAX_DIRECTIONAL_ADJUSTMENT_PERCENT = 0.15;
    private const MAX_UNCERTAINTY_PERCENT = 0.12;
    private const DEAD_BAND_PERCENT = 0.15;

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $catalog = null;

    public function __construct(private readonly NobitexOrderService $orders = new NobitexOrderService()) {}

    public static function clearRuntime(): void
    {
        self::$catalog = null;
    }

    /**
     * @return array<string,mixed>
     */
    public function reference(array $market): array
    {
        $asset = strtoupper(trim((string)($market['asset'] ?? $market['exchange_asset'] ?? '')));
        $quote = strtoupper(trim((string)($market['quote_asset'] ?? 'IRT')));
        if ($asset === '' || !in_array($quote, ['IRT','USDT'], true)) {
            return self::unavailable($asset, $quote, 'unsupported_market');
        }

        try {
            if (self::$catalog === null) {
                self::$catalog = self::catalogFromResponse($this->orders->client()->allOrderBooks());
            }
        } catch (\Throwable $e) {
            return self::unavailable($asset, $quote, 'nobitex_reference_unavailable', $e->getMessage());
        }

        return self::fromCatalog($market, self::$catalog);
    }

    /**
     * Applies the Nobitex-local IRT spot + Nobitex USDT spot/global reference to
     * the already fee-aware Profit-First signal. Explicit fee/spread/slippage
     * costs are never rewritten. A bounded cross-market directional term changes
     * the forecast while basis uncertainty is added to the residual buffer.
     * Missing paired markets are neutral rather than a hard trading veto.
     *
     * @return array<string,mixed>
     */
    public static function applyToSignal(array $signal, array $market): array
    {
        if (NobitexStrategyLearning::strategyKey($signal) !== 'profit_first_v5') return $signal;

        $reference = (new self())->reference($market);
        $signal['cross_market_reference'] = $reference;
        $signal['cross_market_model'] = self::MODEL;
        $signal['cross_market_reference_ready'] = (bool)($reference['available'] ?? false)
            && (bool)($reference['quality_ready'] ?? false);

        if (!($reference['available'] ?? false)) return $signal;
        if (!is_numeric($signal['expected_gross_move_percent'] ?? null)
            || !is_numeric($signal['expected_net_edge_percent'] ?? null)
            || !is_numeric($signal['required_edge_buffer_percent'] ?? null)) {
            return $signal;
        }

        $qualityReady = (bool)($reference['quality_ready'] ?? false);
        $directional = $qualityReady
            ? self::clamp((float)($reference['directional_adjustment_percent'] ?? 0.0), -self::MAX_DIRECTIONAL_ADJUSTMENT_PERCENT, self::MAX_DIRECTIONAL_ADJUSTMENT_PERCENT)
            : 0.0;
        $uncertainty = self::clamp((float)($reference['uncertainty_percent'] ?? 0.0), 0.0, self::MAX_UNCERTAINTY_PERCENT);

        $grossBefore = (float)$signal['expected_gross_move_percent'];
        $netBefore = (float)$signal['expected_net_edge_percent'];
        $bufferBefore = max(0.0, (float)$signal['required_edge_buffer_percent']);
        $grossAfter = $grossBefore + $directional;
        $netAfter = $netBefore + $directional;
        $bufferAfter = $bufferBefore + $uncertainty;
        $tradableAfter = $netAfter - $bufferAfter;
        $ready = (bool)($signal['ready'] ?? false);
        $action = strtolower((string)($signal['action'] ?? 'hold'));
        $reason = (string)($signal['reason'] ?? '');

        $signal['expected_gross_move_percent'] = round($grossAfter, 4);
        $signal['expected_net_edge_percent'] = round($netAfter, 4);
        $signal['required_edge_buffer_percent'] = round($bufferAfter, 4);
        $signal['minimum_net_edge_percent'] = round($bufferAfter, 4);
        $signal['tradable_net_edge_percent'] = round($tradableAfter, 4);
        $signal['expected_net_profit'] = $ready && $tradableAfter > 0.0;
        $signal['cross_market_directional_adjustment_percent'] = round($directional, 4);
        $signal['cross_market_uncertainty_percent'] = round($uncertainty, 4);
        $signal['cross_market_basis_percent'] = is_numeric($reference['basis_percent'] ?? null)
            ? round((float)$reference['basis_percent'], 4) : null;

        // Re-evaluate only the economic BUY/forward-SELL edge. Structural market
        // readiness still belongs to the primary engine and cannot be overridden.
        if ($ready) {
            if ($action === 'buy' && $tradableAfter <= 0.0) {
                $action = 'hold';
                $reason = 'cross_market_reference_removed_tradable_edge';
            } elseif ($action === 'hold'
                && in_array($reason, ['edge_below_adaptive_safety_buffer','adaptive_policy_tightened_edge_below_margin'], true)
                && $tradableAfter > 0.0) {
                $action = 'buy';
                $reason = 'positive_tradable_net_edge_after_cross_market_reference';
            }

            $exitCost = max(0.0, (float)($signal['estimated_exit_cost_percent'] ?? 0.0));
            $sellTrigger = max(0.05, $exitCost);
            if ($action === 'sell' && $grossAfter >= -$sellTrigger) {
                $action = 'hold';
                $reason = 'cross_market_reference_holds_forward_sell';
            } elseif ($action === 'hold' && $tradableAfter <= 0.0 && $grossAfter < -$sellTrigger) {
                $action = 'sell';
                $reason = 'cross_market_reference_forward_sell_bias';
            }
        }

        $signal['action'] = $action;
        $signal['reason'] = $reason;
        if (is_array($signal['selected_strategy'] ?? null)) {
            $signal['selected_strategy']['entry_allowed'] = $action === 'buy';
            $signal['selected_strategy']['exit_bias'] = $action === 'sell';
            $signal['selected_strategy']['reason'] = $reason;
        }
        if (is_array($signal['cost_model'] ?? null)) {
            $signal['cost_model']['cross_market_model'] = self::MODEL;
            $signal['cost_model']['cross_market_directional_adjustment_percent'] = round($directional, 4);
            $signal['cost_model']['cross_market_uncertainty_percent'] = round($uncertainty, 4);
            $signal['cost_model']['cross_market_reference_basis_percent'] = $signal['cross_market_basis_percent'];
        }
        return $signal;
    }

    /**
     * Converts /v3/orderbook/all into normalized symbol mids/spreads.
     * Kept public so CI can validate triangulation deterministically.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function catalogFromResponse(array $response): array
    {
        $rows = is_array($response['data'] ?? null) ? $response['data'] : $response;
        if (!is_array($rows)) return [];

        $out = [];
        foreach ($rows as $rawSymbol => $book) {
            if (!is_string($rawSymbol) || !is_array($book)) continue;
            $symbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', $rawSymbol) ?? '');
            if ($symbol === '') continue;
            $quote = str_ends_with($symbol, 'USDT') ? 'USDT' : (str_ends_with($symbol, 'IRT') ? 'IRT' : '');
            if ($quote === '' || strlen($symbol) <= strlen($quote)) continue;
            $base = substr($symbol, 0, -strlen($quote));
            if ($base === '') continue;

            $mid = self::bookMid($book);
            if ($mid <= 0.0) continue;
            $spread = self::bookSpreadPercent($book, $mid);
            $out[$symbol] = [
                'symbol'=>$symbol,
                'base'=>$base,
                'quote'=>$quote,
                'mid'=>$mid,
                'spread_percent'=>$spread,
            ];
        }
        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $catalog
     * @return array<string,mixed>
     */
    public static function fromCatalog(array $market, array $catalog): array
    {
        $asset = strtoupper(trim((string)($market['asset'] ?? $market['exchange_asset'] ?? '')));
        $quote = strtoupper(trim((string)($market['quote_asset'] ?? 'IRT')));
        if ($asset === '' || !in_array($quote, ['IRT','USDT'], true)) {
            return self::unavailable($asset, $quote, 'unsupported_market');
        }

        $local = $catalog[$asset . 'IRT'] ?? null;
        $global = $catalog[$asset . 'USDT'] ?? null;
        $usdtIrt = $catalog['USDTIRT'] ?? null;
        if (!is_array($local) || !is_array($global) || !is_array($usdtIrt)) {
            return self::unavailable($asset, $quote, 'paired_market_missing');
        }

        $localPrice = self::finitePositive($local['mid'] ?? 0);
        $globalPrice = self::finitePositive($global['mid'] ?? 0);
        $usdtIrtRate = self::finitePositive($usdtIrt['mid'] ?? 0);
        if ($localPrice <= 0.0 || $globalPrice <= 0.0 || $usdtIrtRate <= 0.0) {
            return self::unavailable($asset, $quote, 'invalid_reference_price');
        }

        $candidate = self::candidateMid($market);
        if ($candidate <= 0.0) {
            $candidate = $quote === 'IRT' ? $localPrice : $globalPrice;
        }
        $implied = $quote === 'IRT'
            ? $globalPrice * $usdtIrtRate
            : $localPrice / $usdtIrtRate;
        if ($implied <= 0.0) return self::unavailable($asset, $quote, 'invalid_implied_price');

        // Positive basis means the market being traded is expensive versus the
        // other Nobitex spot leg; negative means it is relatively discounted.
        $basis = (($candidate - $implied) / $implied) * 100.0;
        $maxSpread = max(
            max(0.0, (float)($local['spread_percent'] ?? 0.0)),
            max(0.0, (float)($global['spread_percent'] ?? 0.0)),
            max(0.0, (float)($usdtIrt['spread_percent'] ?? 0.0))
        );
        $qualityReady = $maxSpread <= self::MAX_REFERENCE_SPREAD_PERCENT;

        $directional = 0.0;
        if ($qualityReady && abs($basis) > self::DEAD_BAND_PERCENT) {
            $directional = self::clamp(
                -$basis * 0.08,
                -self::MAX_DIRECTIONAL_ADJUSTMENT_PERCENT,
                self::MAX_DIRECTIONAL_ADJUSTMENT_PERCENT
            );
        }

        // Large local/global dislocations are useful information, but also less
        // certain. Treat the excess divergence as residual forecast uncertainty
        // rather than pretending it is free arbitrage.
        $uncertainty = max(0.0, abs($basis) - 0.50) * 0.04;
        if (!$qualityReady) $uncertainty += 0.05;
        $uncertainty = min(self::MAX_UNCERTAINTY_PERCENT, $uncertainty);

        return [
            'available'=>true,
            'source'=>self::MODEL,
            'asset'=>$asset,
            'candidate_quote'=>$quote,
            'local_spot_symbol'=>$asset . 'IRT',
            'global_spot_symbol'=>$asset . 'USDT',
            'conversion_symbol'=>'USDTIRT',
            'candidate_price'=>round($candidate, 12),
            'local_spot_price_irt'=>round($localPrice, 12),
            'global_spot_price_usdt'=>round($globalPrice, 12),
            'usdt_irt_rate'=>round($usdtIrtRate, 12),
            'cross_implied_candidate_price'=>round($implied, 12),
            'basis_percent'=>round($basis, 6),
            'directional_adjustment_percent'=>round($directional, 6),
            'uncertainty_percent'=>round($uncertainty, 6),
            'max_reference_spread_percent'=>round($maxSpread, 6),
            'quality_ready'=>$qualityReady,
            'interpretation'=>$basis > self::DEAD_BAND_PERCENT
                ? 'candidate_premium_to_cross_market'
                : ($basis < -self::DEAD_BAND_PERCENT ? 'candidate_discount_to_cross_market' : 'cross_market_aligned'),
        ];
    }

    /** @return array<string,mixed> */
    private static function unavailable(string $asset, string $quote, string $reason, ?string $error = null): array
    {
        $out = [
            'available'=>false,
            'source'=>self::MODEL,
            'asset'=>$asset,
            'candidate_quote'=>$quote,
            'reason'=>$reason,
            'directional_adjustment_percent'=>0.0,
            'uncertainty_percent'=>0.0,
        ];
        if ($error !== null && trim($error) !== '') $out['error'] = mb_substr(trim($error), 0, 180);
        return $out;
    }

    private static function candidateMid(array $market): float
    {
        $ask = self::finitePositive($market['best_ask'] ?? 0);
        $bid = self::finitePositive($market['best_bid'] ?? 0);
        if ($ask > 0.0 && $bid > 0.0 && $ask >= $bid) return ($ask + $bid) / 2.0;
        return self::finitePositive($market['price'] ?? 0);
    }

    private static function bookMid(array $book): float
    {
        $ask = self::levelPrice($book['asks'][0] ?? null);
        $bid = self::levelPrice($book['bids'][0] ?? null);
        if ($ask > 0.0 && $bid > 0.0 && $ask >= $bid) return ($ask + $bid) / 2.0;
        $last = self::finitePositive($book['lastTradePrice'] ?? $book['last_trade_price'] ?? $book['lastPrice'] ?? 0);
        return $last > 0.0 ? $last : max($ask, $bid);
    }

    private static function bookSpreadPercent(array $book, float $mid): float
    {
        $ask = self::levelPrice($book['asks'][0] ?? null);
        $bid = self::levelPrice($book['bids'][0] ?? null);
        if ($mid <= 0.0 || $ask <= 0.0 || $bid <= 0.0 || $ask < $bid) return 99.0;
        return (($ask - $bid) / $mid) * 100.0;
    }

    private static function levelPrice(mixed $level): float
    {
        if (!is_array($level)) return 0.0;
        return self::finitePositive(array_is_list($level) ? ($level[0] ?? 0) : ($level['price'] ?? 0));
    }

    private static function finitePositive(mixed $value): float
    {
        if (!is_numeric($value)) return 0.0;
        $n = (float)$value;
        return is_finite($n) && $n > 0.0 ? $n : 0.0;
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        if (!is_finite($value)) return 0.0;
        return max($min, min($max, $value));
    }
}
