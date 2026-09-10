<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;
use Trade\Exchange\NobitexClient;

/**
 * Reconciles Nobitex trading costs and maintains fee-aware marks for open positions.
 *
 * Actual exchange fees are preferred. Nobitex charges the fee from the asset received:
 * buy fees are therefore converted from base asset to quote using the fill price,
 * while sell fees are already in the quote asset. When an exact fee is not available,
 * the current base-tier market/taker rates are used conservatively.
 */
final class NobitexTradeAccounting
{
    private const DEFAULT_IRT_TAKER_RATE = 0.0025;
    private const DEFAULT_USDT_TAKER_RATE = 0.0013;

    /** @var array<string,array> */
    private array $orderCache = [];

    public function sync(): array
    {
        NobitexSchema::ensure();
        $pdo = Database::connection();
        $client = null;
        try {
            $orders = new NobitexOrderService();
            if ($orders->credentialsConfigured()) $client = $orders->client();
        } catch (\Throwable) {
            $client = null;
        }

        $open = $this->syncOpenPositions($pdo, $client);
        $closed = $this->syncRealizedRows($pdo, $client);

        return ['open_positions_marked'=>$open,'realized_rows_accounted'=>$closed];
    }

    private function syncOpenPositions(PDO $pdo, ?NobitexClient $client): int
    {
        $rows = $pdo->query("SELECT * FROM nobitex_autotrade_positions WHERE status='open' ORDER BY id ASC LIMIT 20")->fetchAll();
        $updated = 0;
        foreach ($rows as $p) {
            $entry = $this->num($p['entry_price'] ?? 0);
            $amount = $this->num($p['amount'] ?? 0);
            if ($entry <= 0 || $amount <= 0) continue;

            $signal = $this->latestSignal($pdo, (string)$p['symbol']);
            $current = $this->num($signal['price'] ?? 0);
            if ($current <= 0) continue;
            $details = is_array($signal['details'] ?? null) ? $signal['details'] : [];

            $entryOrder = $this->orderSnapshot(
                $pdo,
                $client,
                (string)($p['entry_order_local_id'] ?? ''),
                (string)($p['entry_exchange_order_id'] ?? '')
            );
            $entryFee = $this->feeForOrder($entryOrder, 'buy', (string)$p['quote_asset'], $entry, $amount);
            $entryFeeQuote = $entryFee['quote_fee'];

            $exitRate = $this->takerRate($pdo, (string)$p['quote_asset']);
            $exitFeeEstimate = $current * $amount * $exitRate;
            $gross = ($current - $entry) * $amount;
            $net = $gross - $entryFeeQuote - $exitFeeEstimate;
            $costBasis = ($entry * $amount) + $entryFeeQuote;
            $netPct = $costBasis > 0 ? ($net / $costBasis) * 100.0 : 0.0;

            $peak = max($entry, $current, $this->num($p['peak_price'] ?? 0));
            $highestNetPct = max($netPct, $this->num($p['highest_net_pnl_percent'] ?? -999999));
            $existingTrail = $this->num($p['trailing_stop'] ?? 0);
            $trail = $existingTrail;

            if ($this->boolSetting($pdo, 'nobitex_trailing_enabled', true)) {
                $activation = $this->floatSetting($pdo, 'nobitex_trailing_activation_net_percent', 0.85, 0.10, 20.0);
                $baseDistance = $this->floatSetting($pdo, 'nobitex_trailing_distance_percent', 0.65, 0.10, 10.0);
                $profitLock = $this->floatSetting($pdo, 'nobitex_profit_lock_net_percent', 0.15, 0.0, 10.0);
                $volatility = $this->num($details['indicators']['volatility_percent'] ?? 0);
                $distance = max($baseDistance, min(1.75, $volatility * 0.50));

                if ($highestNetPct >= $activation) {
                    $requiredNet = ($entry * $amount) * ($profitLock / 100.0);
                    $lockFloor = (($entry * $amount) + $entryFeeQuote + $requiredNet)
                        / max(0.000000000001, $amount * (1.0 - $exitRate));
                    $trailCandidate = $peak * (1.0 - ($distance / 100.0));
                    $trail = max($existingTrail, $lockFloor, $trailCandidate);
                }
            }

            $stmt = $pdo->prepare(
                "UPDATE nobitex_autotrade_positions SET
                    mark_price=:mark,peak_price=:peak,trailing_stop=:trail,
                    entry_fee_quote=:entry_fee,entry_fee_source=:entry_source,
                    estimated_exit_fee_quote=:exit_fee,
                    unrealized_gross_pnl=:gross,unrealized_net_pnl=:net,
                    unrealized_net_pnl_percent=:net_pct,highest_net_pnl_percent=:highest,
                    updated_at=UTC_TIMESTAMP()
                 WHERE id=:id"
            );
            $stmt->execute([
                ':mark'=>$current, ':peak'=>$peak, ':trail'=>$trail > 0 ? $trail : null,
                ':entry_fee'=>$entryFeeQuote, ':entry_source'=>$entryFee['source'], ':exit_fee'=>$exitFeeEstimate,
                ':gross'=>$gross, ':net'=>$net, ':net_pct'=>$netPct, ':highest'=>$highestNetPct, ':id'=>$p['id'],
            ]);
            $updated++;
        }
        return $updated;
    }

    private function syncRealizedRows(PDO $pdo, ?NobitexClient $client): int
    {
        $rows = $pdo->query(
            "SELECT r.*,p.symbol,p.quote_asset AS position_quote,p.entry_order_local_id,p.entry_exchange_order_id,
                    p.exit_order_local_id,p.exit_exchange_order_id
             FROM nobitex_autotrade_pnl r
             JOIN nobitex_autotrade_positions p ON p.id=r.position_id
             WHERE r.accounted_at IS NULL
                OR (r.fee_source='estimated_retry' AND r.accounted_at <= (UTC_TIMESTAMP() - INTERVAL 30 MINUTE))
             ORDER BY r.id ASC LIMIT 50"
        )->fetchAll();

        $updated = 0;
        $positions = [];
        foreach ($rows as $r) {
            $amount = $this->num($r['amount'] ?? 0);
            $entry = $this->num($r['entry_price'] ?? 0);
            $exit = $this->num($r['exit_price'] ?? 0);
            if ($amount <= 0 || $entry <= 0 || $exit <= 0) continue;
            $quote = (string)($r['position_quote'] ?? $r['quote_asset'] ?? 'IRT');

            $gross = $r['gross_pnl'] !== null
                ? $this->num($r['gross_pnl'])
                : (($exit - $entry) * $amount);

            $entryOrder = $this->orderSnapshot(
                $pdo,$client,(string)($r['entry_order_local_id'] ?? ''),(string)($r['entry_exchange_order_id'] ?? '')
            );
            $exitOrder = $this->orderSnapshot(
                $pdo,$client,(string)($r['exit_order_local_id'] ?? ''),(string)($r['exit_exchange_order_id'] ?? '')
            );

            $entryFee = $this->allocatedFee($entryOrder, 'buy', $quote, $entry, $amount, $amount, $pdo);
            $exitFee = $this->allocatedFee($exitOrder, 'sell', $quote, $exit, $amount, $amount, $pdo);
            $fees = $entryFee['quote_fee'] + $exitFee['quote_fee'];
            $net = $gross - $fees;
            $costBasis = ($entry * $amount) + $entryFee['quote_fee'];
            $pct = $costBasis > 0 ? ($net / $costBasis) * 100.0 : 0.0;

            $actual = $entryFee['source'] === 'actual' && $exitFee['source'] === 'actual';
            $retryable = (!$actual) && (($r['entry_exchange_order_id'] ?? '') !== '' || ($r['exit_exchange_order_id'] ?? '') !== '');
            $source = $actual ? 'actual' : ($retryable ? 'estimated_retry' : 'estimated_final');

            // Native PDO/MySQL prepared statements do not allow one named placeholder
            // to be reused multiple times in the same statement. Keep distinct names
            // for the two columns even though both intentionally receive $net.
            $stmt = $pdo->prepare(
                "UPDATE nobitex_autotrade_pnl SET gross_pnl=:gross,entry_fee_quote=:entry_fee,
                    exit_fee_quote=:exit_fee,total_fees_quote=:fees,net_pnl=:net_pnl,pnl=:pnl_value,pnl_percent=:pct,
                    fee_source=:source,accounted_at=UTC_TIMESTAMP() WHERE id=:id"
            );
            $stmt->execute([
                ':gross'=>$gross, ':entry_fee'=>$entryFee['quote_fee'], ':exit_fee'=>$exitFee['quote_fee'],
                ':fees'=>$fees, ':net_pnl'=>$net, ':pnl_value'=>$net, ':pct'=>$pct, ':source'=>$source, ':id'=>$r['id'],
            ]);
            $positions[(int)$r['position_id']] = true;
            $updated++;
        }

        foreach (array_keys($positions) as $positionId) $this->refreshPositionTotals($pdo, $positionId);
        return $updated;
    }

    private function refreshPositionTotals(PDO $pdo, int $positionId): void
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(gross_pnl),0) gross,COALESCE(SUM(total_fees_quote),0) fees,COALESCE(SUM(net_pnl),0) net
             FROM nobitex_autotrade_pnl WHERE position_id=:id"
        );
        $stmt->execute([':id'=>$positionId]);
        $sum = $stmt->fetch() ?: ['gross'=>0,'fees'=>0,'net'=>0];
        // Same native-PDO rule here: do not reuse :net in two assignments.
        $pdo->prepare(
            "UPDATE nobitex_autotrade_positions SET gross_realized_pnl=:gross,total_fees_quote=:fees,
                net_realized_pnl=:net_realized,realized_pnl=:realized_value,updated_at=UTC_TIMESTAMP() WHERE id=:id"
        )->execute([
            ':gross'=>$sum['gross'], ':fees'=>$sum['fees'], ':net_realized'=>$sum['net'],
            ':realized_value'=>$sum['net'], ':id'=>$positionId,
        ]);
    }

    private function latestSignal(PDO $pdo, string $symbol): array
    {
        $stmt = $pdo->prepare("SELECT price,details_json FROM nobitex_autotrade_signals WHERE symbol=:s ORDER BY id DESC LIMIT 1");
        $stmt->execute([':s'=>$symbol]);
        $row = $stmt->fetch() ?: [];
        if ($row !== []) {
            $decoded = json_decode((string)($row['details_json'] ?? ''), true);
            $row['details'] = is_array($decoded) ? $decoded : [];
        }
        return $row;
    }

    private function orderSnapshot(PDO $pdo, ?NobitexClient $client, string $localId, string $exchangeId): array
    {
        $cacheKey = $exchangeId !== '' ? 'x:'.$exchangeId : 'l:'.$localId;
        if ($cacheKey !== 'l:' && isset($this->orderCache[$cacheKey])) return $this->orderCache[$cacheKey];

        $order = [];
        if ($localId !== '') {
            $stmt = $pdo->prepare("SELECT response_json FROM orders WHERE local_id=:id AND exchange_name='nobitex' LIMIT 1");
            $stmt->execute([':id'=>$localId]);
            $decoded = json_decode((string)($stmt->fetchColumn() ?: ''), true);
            if (is_array($decoded)) {
                if (is_array($decoded['order'] ?? null)) $order = $decoded['order'];
                elseif (is_array($decoded['orders'][0] ?? null)) $order = $decoded['orders'][0];
                else $order = $decoded;
            }
        }

        if ($client !== null && $exchangeId !== '' && ctype_digit($exchangeId)) {
            $state = strtolower(trim((string)($order['status'] ?? '')));
            $fee = $this->num($order['fee'] ?? 0);
            if ($fee <= 0 || !in_array($state, ['done','completed','filled'], true)) {
                try {
                    $fresh = $client->orderStatus($exchangeId);
                    if (is_array($fresh['order'] ?? null)) $order = $fresh['order'];
                    elseif (is_array($fresh)) $order = $fresh;
                } catch (\Throwable) {
                    // Keep the persisted order and fall back to a conservative estimate.
                }
            }
        }

        if ($cacheKey !== 'l:') $this->orderCache[$cacheKey] = $order;
        return $order;
    }

    private function allocatedFee(array $order, string $side, string $quote, float $price, float $rowAmount, float $fallbackAmount, PDO $pdo): array
    {
        $fee = $this->feeForOrder($order, $side, $quote, $price, $fallbackAmount);
        if ($fee['source'] === 'actual') {
            $matched = $this->matchedAmount($order);
            if ($matched > 0) $fee['quote_fee'] *= min(1.0, $rowAmount / $matched);
            return $fee;
        }
        $fee['quote_fee'] = $price * $rowAmount * $this->takerRate($pdo, $quote);
        return $fee;
    }

    private function feeForOrder(array $order, string $side, string $quote, float $fallbackPrice, float $fallbackAmount): array
    {
        $raw = $this->num($order['fee'] ?? 0);
        $price = $this->fillPrice($order, $fallbackPrice);
        if ($raw > 0 && $price > 0) {
            return ['quote_fee'=>$side === 'buy' ? $raw * $price : $raw, 'source'=>'actual'];
        }
        $rate = strtoupper($quote) === 'USDT' ? self::DEFAULT_USDT_TAKER_RATE : self::DEFAULT_IRT_TAKER_RATE;
        return ['quote_fee'=>$price * max(0.0, $fallbackAmount) * $rate, 'source'=>'estimated'];
    }

    private function matchedAmount(array $order): float
    {
        foreach (['matchedAmount','matched_amount','filledAmount','amount'] as $key) {
            $v = $this->num($order[$key] ?? 0);
            if ($v > 0) return $v;
        }
        return 0.0;
    }

    private function fillPrice(array $order, float $fallback): float
    {
        foreach (['averagePrice','average_price','price'] as $key) {
            $v = $this->num($order[$key] ?? 0);
            if ($v > 0) return $v;
        }
        return $fallback;
    }

    private function takerRate(PDO $pdo, string $quote): float
    {
        $key = strtoupper($quote) === 'USDT' ? 'nobitex_taker_fee_usdt_percent' : 'nobitex_taker_fee_irt_percent';
        $default = strtoupper($quote) === 'USDT' ? 0.13 : 0.25;
        return $this->floatSetting($pdo, $key, $default, 0.0, 2.0) / 100.0;
    }

    private function floatSetting(PDO $pdo, string $key, float $default, float $min, float $max): float
    {
        $stmt = $pdo->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');
        $stmt->execute([':k'=>$key]);
        $v = $stmt->fetchColumn();
        if (!is_numeric($v)) return $default;
        return max($min, min($max, (float)$v));
    }

    private function boolSetting(PDO $pdo, string $key, bool $default): bool
    {
        $stmt = $pdo->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');
        $stmt->execute([':k'=>$key]);
        $v = $stmt->fetchColumn();
        if ($v === false) return $default;
        return in_array(strtolower(trim((string)$v)), ['1','true','yes','on'], true);
    }

    private function num(mixed $value): float
    {
        if (!is_numeric($value)) return 0.0;
        $n = (float)$value;
        return is_finite($n) ? $n : 0.0;
    }
}
