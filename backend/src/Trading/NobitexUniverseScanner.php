<?php

declare(strict_types=1);

namespace Trade\Trading;

use Trade\Exchange\NobitexClient;

/**
 * Discovers and ranks the whole Nobitex spot universe instead of hard-coding one asset.
 * IRT is preferred, USDT is the automatic fallback. Only liquid, reasonably tight
 * books are promoted to OHLC analysis so the 60 req/min history limit is respected.
 */
final class NobitexUniverseScanner
{
    private const QUOTES = ['IRT', 'USDT'];
    private const EXCLUDED_BASES = ['IRT','RLS','USDT','USDC','DAI','TUSD','BUSD','FDUSD'];

    public function __construct(private readonly SignalEngine $signals = new SignalEngine()) {}

    public function rankedCandidates(
        NobitexClient $client,
        string $preferredQuote = 'IRT',
        int $limit = 12,
        int $threshold = 60
    ): array {
        $preferredQuote = $this->quote($preferredQuote);
        $limit = max(3, min(20, $limit));
        $all = $client->allOrderBooks();
        try { $stats = $client->stats(); } catch (\Throwable) { $stats = []; }
        try { $options = $client->options(); } catch (\Throwable) { $options = []; }

        $markets = $this->marketsFromAll($all, $stats, $options, $preferredQuote);
        usort($markets, static fn(array $a, array $b): int => ($b['pre_score'] <=> $a['pre_score']));
        $markets = array_slice($markets, 0, min(20, max($limit + 4, $limit)));

        $ranked = [];
        foreach ($markets as $market) {
            $prices = [];
            try {
                $history = $client->ohlc((string) $market['symbol'], '15', 96);
                $closes = $history['c'] ?? [];
                if (is_array($closes)) {
                    foreach ($closes as $close) {
                        $n = $this->number($close);
                        if ($n > 0) $prices[] = $n;
                    }
                }
            } catch (\Throwable) {}
            if ($prices === [] || abs((float) end($prices) - (float) $market['price']) > 0.00000001) {
                $prices[] = (float) $market['price'];
            }
            $market['prices'] = array_slice($prices, -96);
            $signal = $this->signals->analyze($market, $threshold);
            $market['signal'] = $signal;

            $liquidityBonus = min(15.0, max(0.0, log10(max(1.0, (float) $market['depth_quote'])) * 2.0));
            $spreadPenalty = min(25.0, max(0.0, (float) $market['spread_percent'] * 10.0));
            $preferredBonus = $market['quote_asset'] === $preferredQuote ? 4.0 : 0.0;
            $readinessPenalty = ($signal['ready'] ?? false) ? 0.0 : 35.0;
            $market['opportunity_score'] = round(
                (float) ($signal['score'] ?? 0) + $liquidityBonus + $preferredBonus - $spreadPenalty - $readinessPenalty,
                4
            );
            $ranked[] = $market;
        }

        usort($ranked, static function (array $a, array $b): int {
            $aBuy = (($a['signal']['action'] ?? '') === 'buy') ? 1 : 0;
            $bBuy = (($b['signal']['action'] ?? '') === 'buy') ? 1 : 0;
            if ($aBuy !== $bBuy) return $bBuy <=> $aBuy;
            return ($b['opportunity_score'] <=> $a['opportunity_score']);
        });
        return array_slice($ranked, 0, $limit);
    }

    public function snapshotSymbol(NobitexClient $client, string $symbol, int $threshold = 60): array
    {
        $symbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', $symbol) ?? '');
        [$base, $quote] = $this->parseSymbol($symbol);
        if ($base === '' || $quote === '') throw new \InvalidArgumentException('Invalid Nobitex market symbol.');
        $response = $client->orderBook($symbol);
        $book = $this->extractBook($response, $symbol);
        try { $stats = $client->stats(['srcCurrency'=>strtolower($base),'dstCurrency'=>strtolower($quote === 'IRT' ? 'rls' : $quote)]); } catch (\Throwable) { $stats = []; }
        try { $options = $client->options(); } catch (\Throwable) { $options = []; }
        $market = $this->market($symbol, $book, $stats, $options, $quote);
        if ($market === null) throw new \RuntimeException('Nobitex market is unavailable: ' . $symbol);

        $prices = [];
        try {
            $history = $client->ohlc($symbol, '15', 96);
            $closes = $history['c'] ?? [];
            if (is_array($closes)) {
                foreach ($closes as $close) {
                    $n = $this->number($close);
                    if ($n > 0) $prices[] = $n;
                }
            }
        } catch (\Throwable) {}
        if ($prices === [] || abs((float) end($prices) - (float) $market['price']) > 0.00000001) $prices[] = (float) $market['price'];
        $market['prices'] = array_slice($prices, -96);
        $market['signal'] = $this->signals->analyze($market, $threshold);
        return $market;
    }

    private function marketsFromAll(array $response, array $stats, array $options, string $preferredQuote): array
    {
        $rows = $response;
        if (is_array($response['data'] ?? null)) $rows = $response['data'];
        $out = [];
        foreach ($rows as $rawSymbol => $book) {
            if (!is_string($rawSymbol) || !is_array($book)) continue;
            $symbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', $rawSymbol) ?? '');
            [$base, $quote] = $this->parseSymbol($symbol);
            if ($base === '' || $quote === '' || in_array($base, self::EXCLUDED_BASES, true)) continue;
            $market = $this->market($symbol, $book, $stats, $options, $preferredQuote);
            if ($market !== null) $out[] = $market;
        }
        return $out;
    }

    private function market(string $symbol, array $book, array $stats, array $options, string $preferredQuote): ?array
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
        if ($depth > 0 && $minOrder > 0 && $depth < ($minOrder * 2.0)) return null;

        $change = $this->dayChange($stats, $base, $quote, $symbol);
        $imbalance = $this->imbalance($book);
        $liquidityComponent = log10(max(1.0, $depth)) * 3.0;
        $trendComponent = max(-10.0, min(10.0, $change)) * 0.45;
        $spreadPenalty = $spread * 12.0;
        $preferredBonus = $quote === $preferredQuote ? 4.0 : 0.0;

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
            'change_percent'=>$change,
            'orderbook_imbalance'=>$imbalance,
            'base_precision'=>$this->amountPrecision($options, $symbol, 8),
            'min_order_quote'=>$minOrder,
            'pre_score'=>round($liquidityComponent + $trendComponent + $preferredBonus - $spreadPenalty, 4),
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
