<?php

declare(strict_types=1);

namespace Trade\Trading;

use Trade\Exchange\BitpinClient;

final class MarketScanner
{
    private const CANONICAL_ASSET = 'GRAM';
    private const ASSET_ALIASES = ['GRAM', 'TON'];

    public function snapshot(BitpinClient $client, string $preferredQuote = 'USDT'): array
    {
        $preferredQuote = strtoupper(trim($preferredQuote));
        if (!preg_match('/^[A-Z0-9]{2,12}$/', $preferredQuote)) {
            $preferredQuote = 'USDT';
        }

        $markets = $this->loadMarkets($client);
        $market = $this->chooseMarket($markets, $preferredQuote);
        if ($market === null) {
            throw new \RuntimeException('No tradable GRAM/TON market was found on Bitpin.');
        }

        if ($market['price'] <= 0) {
            try {
                $market['price'] = $this->tickerPrice($client->tickers(), $market['symbol']);
            } catch (\Throwable) {
            }
        }
        if ($market['price'] <= 0) {
            throw new \RuntimeException('Bitpin returned no usable price for ' . $market['symbol'] . '.');
        }

        $trades = [];
        try {
            $trades = $this->records($client->recentTrades($market['symbol']));
        } catch (\Throwable) {
        }
        $prices = $this->priceSeries($trades);
        if ($prices === []) {
            $prices = [$market['price']];
        } elseif (abs((float) end($prices) - $market['price']) > 0.00000001) {
            $prices[] = $market['price'];
        }

        $book = [];
        try {
            $book = $client->orderBook($market['symbol']);
        } catch (\Throwable) {
        }

        return [
            'asset' => self::CANONICAL_ASSET,
            'exchange_asset' => $market['base'],
            'quote_asset' => $market['quote'],
            'market_id' => $market['id'],
            'symbol' => $market['symbol'],
            'price' => $market['price'],
            'change_percent' => $market['change_percent'],
            'base_precision' => $market['base_precision'],
            'prices' => $prices,
            'orderbook_imbalance' => $this->orderBookImbalance($book),
            'observed_at' => gmdate(DATE_ATOM),
        ];
    }

    private function loadMarkets(BitpinClient $client): array
    {
        $all = [];
        for ($page = 1; $page <= 20; $page++) {
            $response = $client->markets(['page' => $page]);
            $records = $this->records($response);
            foreach ($records as $record) {
                $normalized = $this->normalizeMarket($record);
                if ($normalized !== null) {
                    $all[] = $normalized;
                }
            }
            if (array_is_list($response) || empty($response['next'])) {
                break;
            }
        }
        return $all;
    }

    private function chooseMarket(array $markets, string $preferredQuote): ?array
    {
        $candidates = array_values(array_filter($markets, static function (array $market): bool {
            return in_array($market['base'], self::ASSET_ALIASES, true) && $market['tradable'];
        }));
        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (array $a, array $b) use ($preferredQuote): int {
            $rank = static function (array $market) use ($preferredQuote): int {
                $quote = $market['quote'];
                if ($market['base'] === 'GRAM' && $quote === $preferredQuote) return 0;
                if ($market['base'] === 'TON' && $quote === $preferredQuote) return 1;
                if ($market['base'] === 'GRAM' && $quote === 'USDT') return 2;
                if ($market['base'] === 'TON' && $quote === 'USDT') return 3;
                if ($market['base'] === 'GRAM' && $quote === 'IRT') return 4;
                if ($market['base'] === 'TON' && $quote === 'IRT') return 5;
                return 10;
            };
            return $rank($a) <=> $rank($b);
        });

        return $candidates[0];
    }

    private function normalizeMarket(array $row): ?array
    {
        $id = (int) ($row['id'] ?? $row['market_id'] ?? 0);
        $base = strtoupper((string) ($row['base'] ?? $row['currency1']['code'] ?? $row['base_currency']['code'] ?? ''));
        $quote = strtoupper((string) ($row['quote'] ?? $row['currency2']['code'] ?? $row['quote_currency']['code'] ?? ''));
        $symbol = strtoupper((string) ($row['symbol'] ?? $row['code'] ?? (($base && $quote) ? $base . '_' . $quote : '')));
        if ($id <= 0 || $base === '' || $quote === '' || $symbol === '') {
            return null;
        }

        $price = $this->number($row['price'] ?? $row['price_info']['price'] ?? $row['order_book_info']['price'] ?? $row['last_price'] ?? 0);
        $change = $this->number($row['change_percent'] ?? $row['daily_change_percent'] ?? $row['price_info']['change'] ?? $row['order_book_info']['change'] ?? 0);
        $precision = (int) ($row['base_amount_precision'] ?? $row['currency1']['decimal_amount'] ?? $row['base_currency']['decimal_amount'] ?? 8);

        return [
            'id' => $id,
            'base' => $base,
            'quote' => $quote,
            'symbol' => $symbol,
            'tradable' => !array_key_exists('tradable', $row) || (bool) $row['tradable'],
            'price' => $price,
            'change_percent' => $change,
            'base_precision' => max(0, min(18, $precision)),
        ];
    }

    private function tickerPrice(array $response, string $symbol): float
    {
        foreach ($this->records($response) as $row) {
            $code = strtoupper((string) ($row['symbol'] ?? $row['code'] ?? ''));
            if ($code !== $symbol) continue;
            $price = $this->number($row['price'] ?? $row['last_price'] ?? 0);
            if ($price > 0) return $price;
        }
        return 0.0;
    }

    private function priceSeries(array $trades): array
    {
        $series = [];
        foreach ($trades as $row) {
            $price = $this->number($row['price'] ?? $row['average_price'] ?? 0);
            if ($price <= 0) continue;
            $time = strtotime((string) ($row['created_at'] ?? $row['time'] ?? '')) ?: 0;
            $series[] = ['time' => $time, 'price' => $price];
        }
        if ($series === []) return [];

        $hasTimes = count(array_filter($series, static fn(array $x): bool => $x['time'] > 0)) >= 2;
        if ($hasTimes) {
            usort($series, static fn(array $a, array $b): int => $a['time'] <=> $b['time']);
        } else {
            $series = array_reverse($series);
        }
        return array_values(array_map(static fn(array $x): float => (float) $x['price'], array_slice($series, -120)));
    }

    private function orderBookImbalance(array $book): float
    {
        $bids = $book['bids'] ?? $book['buy'] ?? $book['orders']['bids'] ?? [];
        $asks = $book['asks'] ?? $book['sell'] ?? $book['orders']['asks'] ?? [];
        $bidVolume = $this->bookVolume(is_array($bids) ? $bids : []);
        $askVolume = $this->bookVolume(is_array($asks) ? $asks : []);
        $total = $bidVolume + $askVolume;
        if ($total <= 0) return 0.0;
        return max(-1.0, min(1.0, ($bidVolume - $askVolume) / $total));
    }

    private function bookVolume(array $levels): float
    {
        $sum = 0.0;
        foreach (array_slice($levels, 0, 20) as $level) {
            if (is_array($level) && array_is_list($level)) {
                $sum += $this->number($level[1] ?? 0);
            } elseif (is_array($level)) {
                $sum += $this->number($level['amount'] ?? $level['volume'] ?? $level['size'] ?? 0);
            }
        }
        return $sum;
    }

    private function records(array $response): array
    {
        if (array_is_list($response)) return array_values(array_filter($response, 'is_array'));
        foreach (['results', 'data', 'items'] as $key) {
            if (isset($response[$key]) && is_array($response[$key]) && array_is_list($response[$key])) {
                return array_values(array_filter($response[$key], 'is_array'));
            }
        }
        return [];
    }

    private function number(mixed $value): float
    {
        if (!is_numeric($value)) return 0.0;
        $number = (float) $value;
        return is_finite($number) ? $number : 0.0;
    }
}
