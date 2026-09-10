<?php

declare(strict_types=1);

namespace Trade\Trading;

use Trade\Exchange\NobitexClient;

/**
 * Full-universe Nobitex spot scanner.
 *
 * No score, rank threshold or top-N shortlist is allowed to decide which asset
 * gets analyzed. Every executable IRT/USDT market receives the same 1m/5m/15m
 * profitability analysis. Sorting is only used to decide execution order when
 * several markets are profitable at the same time.
 */
final class NobitexUniverseScanner
{
    private const QUOTES = ['IRT', 'USDT'];
    private const EXCLUDED_BASES = ['IRT','RLS','USDT','USDC','DAI','TUSD','BUSD','FDUSD'];

    /** @var array<string,array> */
    private static array $processSnapshotCache = [];
    /** @var array<string,array> */
    private static array $processUniverseCache = [];

    public function __construct(private readonly NobitexInternalSignalEngine $signals = new NobitexInternalSignalEngine()) {}

    public static function resetProcessCache(): void
    {
        self::$processSnapshotCache = [];
        self::$processUniverseCache = [];
    }

    public function rankedCandidates(
        NobitexClient $client,
        string $preferredQuote = 'IRT',
        int $legacyLimit = 20,
        int $legacyThreshold = 60
    ): array {
        // Both legacy arguments are intentionally ignored. They remain in the
        // signature so old callers do not break while score/top-N behavior is gone.
        unset($legacyLimit, $legacyThreshold);

        $preferredQuote = $this->quote($preferredQuote);
        $cacheKey = $preferredQuote;
        if (isset(self::$processUniverseCache[$cacheKey])) {
            return self::$processUniverseCache[$cacheKey];
        }

        $all = $client->allOrderBooks();
        try { $stats = $client->stats(); } catch (\Throwable) { $stats = []; }
        try { $options = $client->options(); } catch (\Throwable) { $options = []; }

        $markets = $this->marketsFromAll($all, $stats, $options);
        $universeSize = count($markets);
        if ($markets === []) {
            self::$processUniverseCache[$cacheKey] = [];
            return [];
        }

        $symbols = array_values(array_map(
            static fn(array $market): string => (string) $market['symbol'],
            $markets
        ));

        // Concurrently request all histories. No market is dropped because it
        // failed a liquidity score or was outside an arbitrary top-N limit.
        $histories = $client->ohlcMany($symbols, '1', 480, 8);

        $analyzed = [];
        foreach ($markets as $market) {
            $symbol = (string) $market['symbol'];
            $history = is_array($histories[$symbol] ?? null) ? $histories[$symbol] : [];
            $minutePrices = $this->minutePricesFromResponse($history, (float) $market['price']);
            $market['prices'] = $minutePrices;
            $market['analysis_resolution'] = '1m';
            $market['universe_size'] = $universeSize;
            $market['deep_scan_size'] = $universeSize;
            $market['full_universe_analysis'] = true;

            $signal = $this->signals->analyze($market, $minutePrices);
            $market['signal'] = $signal;
            $market['expected_gross_move_percent'] = round((float) ($signal['expected_gross_move_percent'] ?? 0.0), 4);
            $market['estimated_roundtrip_cost_percent'] = round((float) ($signal['estimated_roundtrip_cost_percent'] ?? 0.0), 4);
            $market['expected_net_edge_percent'] = round((float) ($signal['expected_net_edge_percent'] ?? 0.0), 4);
            $market['expected_net_profit'] = (bool) ($signal['expected_net_profit'] ?? false);

            $analyzed[] = $market;
            self::$processSnapshotCache[$symbol] = $market;
        }

        // This sort does not decide eligibility. Every executable market above
        // has already been analyzed. It only chooses which profitable order is
        // submitted first if capital/risk limits prevent simultaneous entries.
        usort($analyzed, static function (array $a, array $b) use ($preferredQuote): int {
            $aBuy = (($a['signal']['action'] ?? '') === 'buy') ? 1 : 0;
            $bBuy = (($b['signal']['action'] ?? '') === 'buy') ? 1 : 0;
            if ($aBuy !== $bBuy) return $bBuy <=> $aBuy;

            $aReady = (bool) ($a['signal']['ready'] ?? false);
            $bReady = (bool) ($b['signal']['ready'] ?? false);
            if ($aReady !== $bReady) return $bReady <=> $aReady;

            $aEdge = (float) ($a['expected_net_edge_percent'] ?? -999.0);
            $bEdge = (float) ($b['expected_net_edge_percent'] ?? -999.0);
            if (abs($aEdge - $bEdge) > 0.000001) return $bEdge <=> $aEdge;

            $aPreferred = (($a['quote_asset'] ?? '') === $preferredQuote) ? 1 : 0;
            $bPreferred = (($b['quote_asset'] ?? '') === $preferredQuote) ? 1 : 0;
            if ($aPreferred !== $bPreferred) return $bPreferred <=> $aPreferred;

            return ((float) ($b['depth_quote'] ?? 0.0)) <=> ((float) ($a['depth_quote'] ?? 0.0));
        });

        self::$processUniverseCache[$cacheKey] = $analyzed;
        return $analyzed;
    }

    public function snapshotSymbol(NobitexClient $client, string $symbol, int $legacyThreshold = 60): array
    {
        unset($legacyThreshold);
        $symbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', $symbol) ?? '');
        if (isset(self::$processSnapshotCache[$symbol])) {
            return self::$processSnapshotCache[$symbol];
        }

        [$base, $quote] = $this->parseSymbol($symbol);
        if ($base === '' || $quote === '') throw new \InvalidArgumentException('Invalid Nobitex market symbol.');

        $response = $client->orderBook($symbol);
        $book = $this->extractBook($response, $symbol);
        try { $stats = $client->stats(['srcCurrency'=>strtolower($base),'dstCurrency'=>strtolower($quote === 'IRT' ? 'rls' : $quote)]); } catch (\Throwable) { $stats = []; }
        try { $options = $client->options(); } catch (\Throwable) { $options = []; }

        $market = $this->market($symbol, $book, $stats, $options);
        if ($market === null) throw new \RuntimeException('Nobitex market is unavailable: ' . $symbol);

        $minutePrices = $this->minuteHistory($client, $symbol, (float) $market['price']);
        $market['prices'] = $minutePrices;
        $market['analysis_resolution'] = '1m';
        $market['full_universe_analysis'] = false;

        $signal = $this->signals->analyze($market, $minutePrices);
        $market['signal'] = $signal;
        $market['expected_gross_move_percent'] = round((float) ($signal['expected_gross_move_percent'] ?? 0.0), 4);
        $market['estimated_roundtrip_cost_percent'] = round((float) ($signal['estimated_roundtrip_cost_percent'] ?? 0.0), 4);
        $market['expected_net_edge_percent'] = round((float) ($signal['expected_net_edge_percent'] ?? 0.0), 4);
        $market['expected_net_profit'] = (bool) ($signal['expected_net_profit'] ?? false);

        self::$processSnapshotCache[$symbol] = $market;
        return $market;
    }

    private function minuteHistory(NobitexClient $client, string $symbol, float $lastPrice): array
    {
        try {
            return $this->minutePricesFromResponse($client->ohlc($symbol, '1', 480), $lastPrice);
        } catch (\Throwable) {
            return $lastPrice > 0 ? [$lastPrice] : [];
        }
    }

    private function minutePricesFromResponse(array $history, float $lastPrice): array
    {
        $prices = [];
        $closes = $history['c'] ?? [];
        if (is_array($closes)) {
            foreach ($closes as $close) {
                $n = $this->number($close);
                if ($n > 0) $prices[] = $n;
            }
        }

        if ($lastPrice > 0 && ($prices === [] || abs((float) end($prices) - $lastPrice) > 0.00000001)) {
            $prices[] = $lastPrice;
        }
        return array_slice($prices, -480);
    }

    private function marketsFromAll(array $response, array $stats, array $options): array
    {
        $rows = $response;
        if (is_array($response['data'] ?? null)) $rows = $response['data'];

        $out = [];
        foreach ($rows as $rawSymbol => $book) {
            if (!is_string($rawSymbol) || !is_array($book)) continue;
            $symbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', $rawSymbol) ?? '');
            [$base, $quote] = $this->parseSymbol($symbol);
            if ($base === '' || $quote === '' || in_array($base, self::EXCLUDED_BASES, true)) continue;

            $market = $this->market($symbol, $book, $stats, $options);
            if ($market !== null) $out[] = $market;
        }
        return $out;
    }

    /**
     * Executability filter only. It rejects malformed books, crossed/very-wide
     * books and markets whose visible depth cannot cover even a minimal order.
     * It does not rank assets by momentum, popularity or a synthetic score.
     */
    private function market(string $symbol, array $book, array $stats, array $options): ?array
    {
        [$base, $quote] = $this->parseSymbol($symbol);
        if ($base === '' || $quote === '') return null;

        $last = $this->number($book['lastTradePrice'] ?? $book['last_trade_price'] ?? $book['lastPrice'] ?? 0);
        $bestAsk = $this->levelPrice($book['asks'][0] ?? null);
        $bestBid = $this->levelPrice($book['bids'][0] ?? null);
        $price = $last > 0 ? $last : (($bestAsk > 0 && $bestBid > 0) ? (($bestAsk + $bestBid) / 2.0) : max($bestAsk, $bestBid));
        if ($price <= 0 || $bestAsk <= 0 || $bestBid <= 0) return null;

        $spread = (($bestAsk - $bestBid) / max($price, 0.00000001)) * 100.0;
        if ($spread < 0 || $spread > 3.0) return null;

        $bidDepth = $this->depthQuote(is_array($book['bids'] ?? null) ? $book['bids'] : []);
        $askDepth = $this->depthQuote(is_array($book['asks'] ?? null) ? $book['asks'] : []);
        $depth = min($bidDepth, $askDepth);
        $minOrder = $this->minimumOrder($options, $quote);
        if ($depth <= 0 || ($minOrder > 0 && $depth < ($minOrder * 2.0))) return null;

        return [
            'exchange'=>'nobitex',
            'asset'=>$base,
            'exchange_asset'=>$base,
            'quote_asset'=>$quote,
            'symbol'=>$symbol,
            'market_id'=>$this->compatibilityMarketId($symbol),
            'price'=>$price,
            'best_ask'=>$bestAsk,
            'best_bid'=>$bestBid,
            'spread_percent'=>round($spread, 6),
            'depth_quote'=>$depth,
            'change_percent'=>$this->dayChange($stats, $base, $quote, $symbol),
            'orderbook_imbalance'=>$this->imbalance($book),
            'base_precision'=>$this->amountPrecision($options, $symbol, 8),
            'min_order_quote'=>$minOrder,
            'observed_at'=>gmdate(DATE_ATOM),
        ];
    }

    private function extractBook(array $response, string $symbol): array
    {
        if (is_array($response[$symbol] ?? null)) return $response[$symbol];
        if (is_array($response['data'][$symbol] ?? null)) return $response['data'][$symbol];
        if (is_array($response['data'] ?? null) && (isset($response['data']['asks']) || isset($response['data']['bids']))) return $response['data'];
        return $response;
    }

    private function dayChange(array $stats, string $base, string $quote, string $symbol): float
    {
        $rows = $stats['stats'] ?? $stats['data'] ?? $stats;
        if (!is_array($rows)) return 0.0;
        $wanted = [$symbol, $base . ($quote === 'IRT' ? 'RLS' : $quote)];

        foreach ($rows as $key => $row) {
            if (!is_array($row)) continue;
            $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $key) ?? '');
            if ($normalized !== '' && !in_array($normalized, $wanted, true)) continue;

            foreach (['dayChange','day_change','change','percentChange'] as $field) {
                if (!array_key_exists($field, $row)) continue;
                $v = $this->number($row[$field]);
                if ($v !== 0.0) return $v;
            }
        }
        return 0.0;
    }

    private function minimumOrder(array $options, string $quote): float
    {
        $n = $this->nobitexOptions($options);
        $mins = is_array($n['minOrders'] ?? null) ? $n['minOrders'] : [];
        foreach ($quote === 'IRT' ? ['rls','RLS','irt','IRT'] : ['usdt','USDT'] as $key) {
            $v = $this->number($mins[$key] ?? 0);
            if ($v > 0) return $v;
        }
        return $quote === 'IRT' ? 3000000.0 : 11.0;
    }

    private function amountPrecision(array $options, string $symbol, int $fallback): int
    {
        $n = $this->nobitexOptions($options);
        $rows = is_array($n['amountPrecisions'] ?? null) ? $n['amountPrecisions'] : [];
        foreach ([$symbol, strtolower($symbol), strtoupper($symbol)] as $key) {
            if (array_key_exists($key, $rows)) return $this->precisionFromStep($rows[$key], $fallback);
        }
        return $fallback;
    }

    private function nobitexOptions(array $options): array
    {
        if (is_array($options['nobitex'] ?? null)) return $options['nobitex'];
        if (is_array($options['data']['nobitex'] ?? null)) return $options['data']['nobitex'];
        return [];
    }

    private function precisionFromStep(mixed $value, int $fallback): int
    {
        if (!is_numeric($value)) return $fallback;
        $s = strtolower(trim((string) $value));
        if (str_contains($s, 'e-')) {
            $parts = explode('e-', $s, 2);
            return max(0, min(18, (int) ($parts[1] ?? $fallback)));
        }
        if (!str_contains($s, '.')) return 0;
        $decimals = rtrim(substr(strrchr($s, '.'), 1), '0');
        return max(0, min(18, strlen($decimals)));
    }

    private function parseSymbol(string $symbol): array
    {
        foreach (self::QUOTES as $quote) {
            if (str_ends_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
                return [substr($symbol, 0, -strlen($quote)), $quote];
            }
        }
        return ['', ''];
    }

    private function quote(string $quote): string
    {
        $quote = strtoupper(trim($quote));
        return in_array($quote, self::QUOTES, true) ? $quote : 'IRT';
    }

    private function levelPrice(mixed $level): float
    {
        if (!is_array($level)) return 0.0;
        return $this->number(array_is_list($level) ? ($level[0] ?? 0) : ($level['price'] ?? 0));
    }

    private function depthQuote(array $levels): float
    {
        $sum = 0.0;
        foreach (array_slice($levels, 0, 20) as $level) {
            if (!is_array($level)) continue;
            $price = $this->number(array_is_list($level) ? ($level[0] ?? 0) : ($level['price'] ?? 0));
            $amount = $this->number(array_is_list($level) ? ($level[1] ?? 0) : ($level['amount'] ?? $level['volume'] ?? 0));
            if ($price > 0 && $amount > 0) $sum += $price * $amount;
        }
        return $sum;
    }

    private function imbalance(array $book): float
    {
        $bid = $this->depthQuote(is_array($book['bids'] ?? null) ? $book['bids'] : []);
        $ask = $this->depthQuote(is_array($book['asks'] ?? null) ? $book['asks'] : []);
        $total = $bid + $ask;
        return $total > 0 ? max(-1.0, min(1.0, ($bid - $ask) / $total)) : 0.0;
    }

    private function compatibilityMarketId(string $symbol): int
    {
        return (int) (sprintf('%u', crc32('nobitex:' . $symbol)) ?: 1);
    }

    private function number(mixed $value): float
    {
        if (!is_numeric($value)) return 0.0;
        $n = (float) $value;
        return is_finite($n) ? $n : 0.0;
    }
}
