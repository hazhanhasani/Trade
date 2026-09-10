<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;
use Trade\Support\IranClock;

final class NobitexTradeTimeline
{
    public const MODEL = 'confirmed_trade_timeline_v1';

    public function snapshot(?PDO $pdo = null, int $limit = 80): array
    {
        NobitexSchema::ensure();
        $pdo ??= Database::connection();
        $limit = max(10, min(250, $limit));
        $fetch = min(300, max($limit, 80));
        $events = [];

        $buys = $pdo->query("SELECT id,symbol,asset,quote_asset,amount,entry_price,opened_at,entry_order_local_id
            FROM nobitex_autotrade_positions
            WHERE opened_at IS NOT NULL
            ORDER BY opened_at DESC,id DESC LIMIT {$fetch}")->fetchAll();
        foreach ($buys as $row) {
            $utc = (string)$row['opened_at'];
            $quote = strtoupper((string)$row['quote_asset']);
            $priceRaw = (float)$row['entry_price'];
            $amount = (float)$row['amount'];
            $price = NobitexDisplayMoney::quoteValue($priceRaw, $quote);
            $value = NobitexDisplayMoney::quoteValue($priceRaw * $amount, $quote);
            $time = IranClock::fromUtc($utc);
            $events[] = [
                'id'=>'buy:' . (int)$row['id'],
                'type'=>'buy',
                'status'=>'confirmed',
                'symbol'=>(string)$row['symbol'],
                'asset'=>(string)$row['asset'],
                'quote_asset'=>$quote,
                'display_unit'=>NobitexDisplayMoney::quoteUnit($quote),
                'amount'=>$amount,
                'price'=>$price,
                'value'=>$value,
                'pnl'=>null,
                'pnl_percent'=>null,
                'created_at_utc'=>$utc,
                'time_iran'=>$time,
                'order_local_id'=>(string)($row['entry_order_local_id'] ?? ''),
                '_sort_ts'=>(int)$time['unix'],
            ];
        }

        $sells = $pdo->query("SELECT r.id,r.position_id,r.pnl,r.net_pnl,r.pnl_percent,r.exit_price,r.amount,r.created_at,
                p.symbol,p.asset,p.quote_asset,p.entry_price,p.entry_order_local_id
            FROM nobitex_autotrade_pnl r
            JOIN nobitex_autotrade_positions p ON p.id=r.position_id
            ORDER BY r.created_at DESC,r.id DESC LIMIT {$fetch}")->fetchAll();
        foreach ($sells as $row) {
            $utc = (string)$row['created_at'];
            $quote = strtoupper((string)$row['quote_asset']);
            $priceRaw = (float)$row['exit_price'];
            $amount = (float)$row['amount'];
            $netRaw = (float)($row['net_pnl'] ?? $row['pnl'] ?? 0.0);
            $time = IranClock::fromUtc($utc);
            $events[] = [
                'id'=>'sell:' . (int)$row['id'],
                'type'=>'sell',
                'status'=>'confirmed',
                'position_id'=>(int)$row['position_id'],
                'symbol'=>(string)$row['symbol'],
                'asset'=>(string)$row['asset'],
                'quote_asset'=>$quote,
                'display_unit'=>NobitexDisplayMoney::quoteUnit($quote),
                'amount'=>$amount,
                'entry_price'=>NobitexDisplayMoney::quoteValue((float)$row['entry_price'], $quote),
                'price'=>NobitexDisplayMoney::quoteValue($priceRaw, $quote),
                'value'=>NobitexDisplayMoney::quoteValue($priceRaw * $amount, $quote),
                'pnl'=>NobitexDisplayMoney::quoteValue($netRaw, $quote),
                'pnl_percent'=>(float)$row['pnl_percent'],
                'created_at_utc'=>$utc,
                'time_iran'=>$time,
                'order_local_id'=>(string)($row['entry_order_local_id'] ?? ''),
                '_sort_ts'=>(int)$time['unix'],
            ];
        }

        usort($events, static fn(array $a, array $b): int => ($b['_sort_ts'] <=> $a['_sort_ts']) ?: strcmp((string)$b['id'], (string)$a['id']));
        $events = array_slice($events, 0, $limit);
        foreach ($events as &$event) unset($event['_sort_ts']);
        unset($event);

        return [
            'model'=>self::MODEL,
            'timezone'=>'Asia/Tehran',
            'calendar'=>'Solar Hijri',
            'generated_at_iran'=>IranClock::nowPayload(),
            'items'=>$events,
        ];
    }
}
