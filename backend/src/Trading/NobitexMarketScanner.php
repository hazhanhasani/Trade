<?php

declare(strict_types=1);

namespace Trade\Trading;

use Trade\Exchange\NobitexClient;

final class NobitexMarketScanner
{
    private const CANONICAL_ASSET = 'GRAM';
    private const ASSET_ALIASES = ['GRAM', 'TON', 'TONCOIN'];

    public function snapshot(NobitexClient $client, string $preferredQuote = 'USDT'): array
    {
        $preferredQuote = strtoupper(trim($preferredQuote));
        if (!in_array($preferredQuote, ['USDT', 'IRT'], true)) $preferredQuote = 'USDT';

        $all = $client->allOrderBooks();
        $market = $this->chooseMarket($all, $preferredQuote);
        if ($market === null) {
            throw new \RuntimeException('No GRAM/TON market was found in Nobitex order books.');
        }

        $prices = [];
        try {
            $history = $client->ohlc($market['symbol'], '15', 120);
            $closes = $history['c'] ?? [];
            if (is_array($closes)) {
                foreach ($closes as $close) {
                    $n = $this->number($close);
                    if ($n > 0) $prices[] = $n;
                }
            }
        } catch (\Throwable) {
        }
        if ($prices === [] || abs((float) end($prices) - $market['price']) > 0.00000001) {
            $prices[] = $market['price'];
        }

        $change = 0.0;
        try {
            $stats = $client->stats([
                'srcCurrency' => strtolower($market['exchange_asset']),
                'dstCurrency' => strtolower($market['quote_asset'] === 'IRT' ? 'rls' : $market['quote_asset']),
            ]);
            $change = $this->extractDayChange($stats, $market['symbol']);
        } catch (\Throwable) {
        }

        return [
            'exchange' => 'nobitex',
            'asset' => self::CANONICAL_ASSET,
            'exchange_asset' => $market['exchange_asset'],
            'quote_asset' => $market['quote_asset'],
            'market_id' => $this->compatibilityMarketId($market['symbol']),
            'symbol' => $market['symbol'],
            'price' => $market['price'],
            'change_percent' => $change,
            'base_precision' => 8,
            'prices' => array_slice($prices, -120),
            'orderbook_imbalance' => $market['imbalance'],
            'observed_at' => gmdate(DATE_ATOM),
        ];
    }

    private function chooseMarket(array $response, string $preferredQuote): ?array
    {
        $markets = [];
        foreach ($response as $symbol => $book) {
            if (!is_string($symbol) || !is_array($book) || in_array(strtolower($symbol), ['status', 'lastupdate'], true)) continue;
            $symbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', $symbol) ?? '');
            [$base, $quote] = $this->parseSymbol($symbol);
            if (!$this->isTarget($base) || !in_array($quote, ['USDT', 'IRT'], true)) continue;

            $last = $this->number($book['lastTradePrice'] ?? $book['last_trade_price'] ?? 0);
            $bestAsk = $this->levelPrice($book['asks'][0] ?? null);
            $bestBid = $this->levelPrice($book['bids'][0] ?? null);
            $price = $last > 0 ? $last : (($bestAsk > 0 && $bestBid > 0) ? ($bestAsk + $bestBid) / 2 : max($bestAsk, $bestBid));
            if ($price <= 0) continue;

            $markets[] = [
                'symbol' => $symbol,
                'exchange_asset' => $base,
                'quote_asset' => $quote,
                'price' => $price,
                'imbalance' => $this->imbalance($book),
            ];
        }

        if ($markets === []) return null;
        usort($markets, function (array $a, array $b) use ($preferredQuote): int {
            $rank = static function (array $m) use ($preferredQuote): int {
                $asset = match ($m['exchange_asset']) { 'GRAM' => 0, 'TON' => 1, 'TONCOIN' => 2, default => 3 };
                $quote = $m['quote_asset'] === $preferredQuote ? 0 : ($m['quote_asset'] === 'USDT' ? 10 : 20);
                return $quote + $asset;
            };
            return $rank($a) <=> $rank($b);
        });
        return $markets[0];
    }

    private function parseSymbol(string $symbol): array
    {
        foreach (['USDT', 'IRT'] as $quote) {
            if (str_ends_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
                return [substr($symbol, 0, -strlen($quote)), $quote];
            }
        }
        return ['', ''];
    }

    private function isTarget(string $asset): bool
    {
        return in_array(strtoupper($asset), self::ASSET_ALIASES, true);
    }

    private function levelPrice(mixed $level): float
    {
        if (!is_array($level)) return 0.0;
        return $this->number(array_is_list($level) ? ($level[0] ?? 0) : ($level['price'] ?? 0));
    }

    private function imbalance(array $book): float
    {
        $bids = is_array($book['bids'] ?? null) ? $book['bids'] : [];
        $asks = is_array($book['asks'] ?? null) ? $book['asks'] : [];
        $bv = $this->volume($bids);
        $av = $this->volume($asks);
        $total = $bv + $av;
        return $total > 0 ? max(-1.0, min(1.0, ($bv - $av) / $total)) : 0.0;
    }

    private function volume(array $levels): float
    {
        $sum = 0.0;
        foreach (array_slice($levels, 0, 20) as $level) {
            if (!is_array($level)) continue;
            $sum += $this->number(array_is_list($level) ? ($level[1] ?? 0) : ($level['amount'] ?? $level['volume'] ?? 0));
        }
        return $sum;
    }

    private function extractDayChange(array $stats, string $symbol): float
    {
        $nodes = $stats['stats'] ?? $stats['data'] ?? [];
        if (!is_array($nodes)) return 0.0;
        foreach ($nodes as $key => $row) {
            if (!is_array($row)) continue;
            $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $key) ?? '');
            if ($normalized !== '' && $normalized !== $symbol) continue;
            foreach (['dayChange', 'day_change', 'change'] as $field) {
                $v = $this->number($row[$field] ?? 0);
                if ($v !== 0.0) return $v;
            }
        }
        return 0.0;
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
