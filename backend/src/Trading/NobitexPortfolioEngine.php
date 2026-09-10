<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;
use Trade\Exchange\NobitexClient;

/**
 * Multi-asset live portfolio engine for Nobitex spot markets.
 *
 * It scans the complete IRT/USDT universe, ranks liquid markets, opens at most
 * one new position per cron tick, manages several positions concurrently and
 * keeps one global kill switch plus per-exchange Bot/Live gates.
 */
final class NobitexPortfolioEngine
{
    public function __construct(
        private readonly NobitexOrderService $orders = new NobitexOrderService(),
        private readonly NobitexUniverseScanner $scanner = new NobitexUniverseScanner(),
        private readonly SignalEngine $signals = new SignalEngine(),
        private readonly RiskManager $risk = new RiskManager(),
    ) {}

    public function run(): array
    {
        NobitexSchema::ensure();
        $pdo = Database::connection();
        if ((int) $pdo->query("SELECT GET_LOCK('trade_nobitex_portfolio_v1',0)")->fetchColumn() !== 1) {
            return ['status'=>'skipped','exchange'=>'nobitex','reason'=>'another_nobitex_run_is_active'];
        }
        try {
            return $this->runLocked($pdo);
        } finally {
            try { $pdo->query("SELECT RELEASE_LOCK('trade_nobitex_portfolio_v1')"); } catch (\Throwable) {}
        }
    }

    public function runBootstrapIfPending(): array
    {
        NobitexSchema::ensure();
        $pdo = Database::connection();
        if (!$this->boolSetting($pdo, 'nobitex_bootstrap_first_buy_pending')) {
            return ['status'=>'not_pending','exchange'=>'nobitex'];
        }
        if ((int) $pdo->query("SELECT GET_LOCK('trade_nobitex_portfolio_v1',0)")->fetchColumn() !== 1) {
            return ['status'=>'first_buy_waiting','exchange'=>'nobitex','reason'=>'another_nobitex_run_is_active'];
        }
        try {
            return $this->bootstrapLocked($pdo);
        } finally {
            try { $pdo->query("SELECT RELEASE_LOCK('trade_nobitex_portfolio_v1')"); } catch (\Throwable) {}
        }
    }

    private function runLocked(PDO $pdo): array
    {
        if ($blocked = $this->preflight($pdo)) return $blocked;

        $settings = $this->settings($pdo);
        $client = $this->orders->client();
        $this->reconcile($pdo, $client, $settings);
        $wallets = $client->wallets();
        $positions = $this->activePositions($pdo);

        foreach ($positions as $position) {
            if ((string) $position['status'] !== 'open') continue;
            try {
                $market = $this->scanner->snapshotSymbol($client, (string) $position['symbol'], (int) $settings['min_signal_score']);
            } catch (\Throwable $e) {
                $this->event($pdo, 'warning', 'nobitex.position.scan_failed', ['position_id'=>$position['id'],'symbol'=>$position['symbol'],'error'=>$e->getMessage()]);
                continue;
            }
            $signal = is_array($market['signal'] ?? null) ? $market['signal'] : $this->signals->analyze($market, (int) $settings['min_signal_score']);
            $signalId = $this->storeSignal($pdo, $market, $signal);
            $reason = $this->risk->exitReason((float) $market['price'], $position, (string) ($signal['action'] ?? 'hold'));
            if ($reason === null) continue;

            $availableBase = $this->walletAvailable($wallets, [(string) $position['asset']]);
            $amount = $this->floorAmount(min((float) $position['amount'], $availableBase), (int) $market['base_precision']);
            if ($amount <= 0) {
                $this->event($pdo, 'warning', 'nobitex.exit.no_balance', ['position_id'=>$position['id'],'asset'=>$position['asset']]);
                continue;
            }
            $result = $this->submitExit($pdo, $position, $market, $amount, $reason);
            $this->markSignalExecuted($pdo, $signalId, $result['local_id'] ?? null);
            return $this->summary('sell_submitted', ['position'=>$result,'exit_reason'=>$reason,'active_positions'=>count($positions)]);
        }

        if ($this->hasPendingPosition($positions)) {
            return $this->summary('waiting_order', ['active_positions'=>count($positions)]);
        }

        $maxPositions = $this->intSetting($pdo, 'nobitex_max_positions', 5, 1, 20);
        if (count($positions) >= $maxPositions) {
            return $this->summary('portfolio_full', ['active_positions'=>count($positions),'max_positions'=>$maxPositions]);
        }

        $irt = $this->walletAvailable($wallets, ['RLS','IRT']);
        $usdt = $this->walletAvailable($wallets, ['USDT']);
        $preferredQuote = $irt > 0 ? 'IRT' : 'USDT';
        if ($irt <= 0 && $usdt <= 0) {
            return $this->summary('blocked', ['reason'=>'no_quote_balance','balances'=>['IRT'=>$irt,'USDT'=>$usdt]]);
        }

        $scanLimit = $this->intSetting($pdo, 'nobitex_scan_limit', 12, 3, 20);
        $candidates = $this->scanner->rankedCandidates($client, $preferredQuote, $scanLimit, (int) $settings['min_signal_score']);
        if ($candidates === []) return $this->summary('no_trade', ['reason'=>'no_eligible_markets']);

        $activeSymbols = [];
        $activeAssets = [];
        foreach ($positions as $p) {
            $activeSymbols[strtoupper((string) $p['symbol'])] = true;
            $activeAssets[strtoupper((string) ($p['asset'] ?? ''))] = true;
        }
        $rejections = [];

        foreach ($candidates as $market) {
            $symbol = strtoupper((string) $market['symbol']);
            $asset = strtoupper((string) ($market['asset'] ?? ''));
            if (isset($activeSymbols[$symbol]) || ($asset !== '' && isset($activeAssets[$asset]))) {
                $rejections[] = ['symbol'=>$symbol,'reason'=>'already_positioned'];
                continue;
            }
            $signal = is_array($market['signal'] ?? null) ? $market['signal'] : $this->signals->analyze($market, (int) $settings['min_signal_score']);
            $signalId = $this->storeSignal($pdo, $market, $signal);
            if (!($signal['ready'] ?? false) || (string) ($signal['action'] ?? 'hold') !== 'buy') {
                $rejections[] = ['symbol'=>$symbol,'reason'=>$signal['reason'] ?? 'no_buy_signal','score'=>$signal['score'] ?? 0];
                continue;
            }

            $quoteAsset = (string) $market['quote_asset'];
            $quoteAvailable = $quoteAsset === 'IRT' ? $irt : $usdt;
            $exposure = $this->managedExposure($positions, $quoteAsset);
            $portfolio = $quoteAvailable + $exposure;
            $dailyPnl = $this->dailyPnl($pdo, $quoteAsset);
            $decision = $this->entryBudget($pdo, $market, $settings, $quoteAvailable, $portfolio, $exposure, $dailyPnl);
            if (!($decision['allowed'] ?? false)) {
                $rejections[] = ['symbol'=>$symbol,'reason'=>$decision['reason'] ?? 'risk_blocked','score'=>$signal['score'] ?? 0];
                continue;
            }

            $reference = (float) (($market['best_ask'] ?? 0) > 0 ? $market['best_ask'] : $market['price']);
            $priceCeiling = $reference * 1.008;
            $amount = $this->floorAmount(((float) $decision['budget']) / $priceCeiling, (int) $market['base_precision']);
            $orderValue = $amount * $priceCeiling;
            $minOrder = (float) ($market['min_order_quote'] ?? 0);
            if ($amount <= 0 || $orderValue <= 0 || ($minOrder > 0 && $orderValue + 0.000001 < $minOrder)) {
                $rejections[] = ['symbol'=>$symbol,'reason'=>'minimum_order_rounding'];
                continue;
            }

            $result = $this->submitEntry($pdo, $market, $amount, $priceCeiling, $settings);
            $this->markSignalExecuted($pdo, $signalId, $result['local_id'] ?? null);
            $this->setSetting($pdo, $this->symbolCooldownKey($symbol), gmdate('Y-m-d H:i:s'));
            return $this->summary('buy_submitted', [
                'selected'=>[
                    'symbol'=>$symbol,
                    'asset'=>$market['asset'],
                    'quote_asset'=>$quoteAsset,
                    'expected_net_edge_percent'=>$signal['expected_net_edge_percent'] ?? null,
                    'tradable_net_edge_percent'=>$signal['tradable_net_edge_percent'] ?? null,
                    'required_edge_buffer_percent'=>$signal['required_edge_buffer_percent'] ?? null,
                    'spread_percent'=>$market['spread_percent'] ?? null,
                ],
                'order'=>$result,
                'active_positions_before'=>count($positions),
                'max_positions'=>$maxPositions,
            ]);
        }

        return $this->summary('no_trade', [
            'reason'=>'no_candidate_passed_signal_and_risk_filters',
            'active_positions'=>count($positions),
            'top_candidates'=>$this->candidateSummary($candidates),
            'rejections'=>array_slice($rejections, 0, 12),
        ]);
    }

    private function bootstrapLocked(PDO $pdo): array
    {
        if ($blocked = $this->preflight($pdo, true)) return $blocked;
        $active = $this->activePositions($pdo);
        if ($active !== []) {
            $this->completeBootstrap($pdo, 'active_position_already_exists');
            return ['status'=>'already_positioned','exchange'=>'nobitex','active_positions'=>count($active)];
        }

        $clientOrderId = $this->setting($pdo, 'nobitex_bootstrap_first_buy_id') ?: 'trade-first-v120';
        try {
            $existing = $this->orders->normalizedOrder($this->orders->client()->orderStatus(null, $clientOrderId));
            if ($this->looksLikeOrder($existing)) {
                $position = $this->adoptRemoteOrder($pdo, $existing, $clientOrderId);
                $this->completeBootstrap($pdo, 'recovered_existing_order');
                return ['status'=>'first_buy_recovered','exchange'=>'nobitex'] + $position;
            }
        } catch (\Throwable) {}

        $settings = $this->settings($pdo);
        $client = $this->orders->client();
        $wallets = $client->wallets();
        $irt = $this->walletAvailable($wallets, ['RLS','IRT']);
        $usdt = $this->walletAvailable($wallets, ['USDT']);
        $preferredQuote = $irt > 0 ? 'IRT' : 'USDT';
        if ($irt <= 0 && $usdt <= 0) return $this->bootstrapBlocked($pdo, 'no_quote_balance');

        $bootstrapScore = $this->intSetting($pdo, 'nobitex_bootstrap_min_score', 25, 10, 70);
        $scanLimit = $this->intSetting($pdo, 'nobitex_scan_limit', 12, 3, 20);
        $candidates = $this->scanner->rankedCandidates($client, $preferredQuote, $scanLimit, $bootstrapScore);
        $chosen = null;
        foreach ($candidates as $candidate) {
            $signal = $candidate['signal'] ?? [];
            if (!($signal['ready'] ?? false)) continue;
            if ((int) ($signal['score'] ?? -100) < $bootstrapScore) continue;
            if ((float) ($candidate['spread_percent'] ?? 99) > 1.5) continue;
            $quoteAvailable = $candidate['quote_asset'] === 'IRT' ? $irt : $usdt;
            if ($quoteAvailable <= 0) continue;
            $chosen = $candidate;
            break;
        }
        if ($chosen === null) {
            return $this->bootstrapBlocked($pdo, 'waiting_for_positive_market', ['top_candidates'=>$this->candidateSummary($candidates),'minimum_score'=>$bootstrapScore]);
        }

        $quoteAsset = (string) $chosen['quote_asset'];
        $quoteAvailable = $quoteAsset === 'IRT' ? $irt : $usdt;
        $budget = $this->entryBudget($pdo, $chosen, $settings, $quoteAvailable, $quoteAvailable, 0.0, 0.0, true);
        if (!($budget['allowed'] ?? false)) return $this->bootstrapBlocked($pdo, (string) ($budget['reason'] ?? 'risk_blocked'), $budget);

        $reference = (float) (($chosen['best_ask'] ?? 0) > 0 ? $chosen['best_ask'] : $chosen['price']);
        $priceCeiling = $reference * 1.008;
        $amount = $this->floorAmount(((float) $budget['budget']) / $priceCeiling, (int) $chosen['base_precision']);
        $minOrder = (float) ($chosen['min_order_quote'] ?? 0);
        $value = $amount * $priceCeiling;
        if ($amount <= 0 || ($minOrder > 0 && $value + 0.000001 < $minOrder)) {
            return $this->bootstrapBlocked($pdo, 'minimum_order_rounding', ['order_value'=>$value,'minimum_order'=>$minOrder]);
        }

        $result = $this->submitEntry($pdo, $chosen, $amount, $priceCeiling, $settings, $clientOrderId, 'autotrade_nobitex_first_buy');
        $this->setSetting($pdo, $this->symbolCooldownKey((string) $chosen['symbol']), gmdate('Y-m-d H:i:s'));
        $this->completeBootstrap($pdo, 'submitted');
        $this->event($pdo, 'info', 'nobitex.first_buy.submitted', ['symbol'=>$chosen['symbol'],'asset'=>$chosen['asset'],'score'=>$chosen['signal']['score'] ?? 0,'order'=>$result]);
        return ['status'=>'first_buy_submitted','exchange'=>'nobitex','selected'=>[
            'symbol'=>$chosen['symbol'],'asset'=>$chosen['asset'],'quote_asset'=>$chosen['quote_asset'],'score'=>$chosen['signal']['score'] ?? 0,
        ],'order'=>$result];
    }

    private function preflight(PDO $pdo, bool $bootstrap = false): ?array
    {
        if (!NobitexSchema::botEnabled('nobitex')) return $this->blocked($pdo, $bootstrap ? 'first_buy' : 'portfolio', 'bot_disabled');
        if ($this->boolSetting($pdo, 'kill_switch')) return $this->blocked($pdo, $bootstrap ? 'first_buy' : 'portfolio', 'kill_switch');
        if (!$this->orders->credentialsConfigured()) return $this->blocked($pdo, $bootstrap ? 'first_buy' : 'portfolio', 'credentials_missing');
        if (!$this->orders->liveEnabled()) return $this->blocked($pdo, $bootstrap ? 'first_buy' : 'portfolio', 'live_execution_disabled');
        return null;
    }

    private function settings(PDO $pdo): array
    {
        $base = Schema::settings($pdo);
        return array_merge($base, $this->risk->normalizeSettings($base));
    }

    private function entryBudget(PDO $pdo, array $market, array $settings, float $quoteAvailable, float $portfolio, float $exposure, float $dailyPnl, bool $bootstrap = false): array
    {
        if ($quoteAvailable <= 0 || $portfolio <= 0) return ['allowed'=>false,'reason'=>'insufficient_balance'];
        $dailyLimit = $portfolio * ((float) $settings['daily_loss_limit_percent'] / 100.0);
        if ($dailyPnl <= -$dailyLimit) return ['allowed'=>false,'reason'=>'daily_loss_limit_reached'];

        $symbol = (string) $market['symbol'];
        if (!$bootstrap) {
            $last = $this->setting($pdo, $this->symbolCooldownKey($symbol));
            if ($last) {
                $ts = strtotime($last . ' UTC');
                if ($ts !== false && time() < $ts + ((int) $settings['cooldown_minutes'] * 60)) {
                    return ['allowed'=>false,'reason'=>'symbol_cooldown_active'];
                }
            }
        }

        $portfolioMaxPct = $this->floatSetting($pdo, 'nobitex_portfolio_exposure_percent', 60.0, 10.0, 90.0);
        $capacity = max(0.0, ($portfolio * ($portfolioMaxPct / 100.0)) - $exposure);
        if ($capacity <= 0) return ['allowed'=>false,'reason'=>'portfolio_exposure_limit_reached'];

        $perPositionPct = min((float) $settings['position_percent'], (float) $settings['max_position_percent']);
        $desired = $portfolio * ($perPositionPct / 100.0);
        $perPositionCap = $portfolio * ((float) $settings['max_position_percent'] / 100.0);
        $minimum = max(0.0, (float) ($market['min_order_quote'] ?? 0));
        $minimumWithMargin = $minimum > 0 ? $minimum * 1.03 : 0.0;
        $budget = min(max($desired, $minimumWithMargin), $perPositionCap, $capacity, $quoteAvailable * 0.985);
        if ($minimum > 0 && $budget + 0.000001 < $minimum) {
            return ['allowed'=>false,'reason'=>'minimum_order_exceeds_budget','minimum_order'=>$minimum,'budget'=>$budget,'available_quote'=>$quoteAvailable];
        }
        if ($budget <= 0) return ['allowed'=>false,'reason'=>'insufficient_balance'];
        return ['allowed'=>true,'reason'=>'ok','budget'=>$budget,'portfolio'=>$portfolio,'exposure'=>$exposure,'portfolio_exposure_limit_percent'=>$portfolioMaxPct];
    }

    private function submitEntry(PDO $pdo, array $market, float $amount, float $priceCeiling, array $settings, ?string $identifier = null, string $source = 'autotrade_nobitex_portfolio'): array
    {
        $identifier ??= 'nb' . gmdate('ymdHis') . substr(bin2hex(random_bytes(5)), 0, 10);
        $result = $this->orders->create([
            'symbol'=>$market['symbol'],'amount1'=>$amount,'price'=>$priceCeiling,'mode'=>'market','type'=>'buy','identifier'=>$identifier,
        ], $source);
        $remote = is_array($result['order'] ?? null) ? $result['order'] : [];
        $entry = $this->fillPrice($remote, (float) $market['price']);
        $filled = $this->fillAmount($remote, $amount);
        $status = $this->isDone($remote) && $filled > 0 ? 'open' : 'pending_open';
        $stmt = $pdo->prepare("INSERT INTO nobitex_autotrade_positions (symbol,asset,quote_asset,amount,entry_price,stop_loss,take_profit,status,entry_identifier,entry_order_local_id,entry_exchange_order_id,opened_at,created_at,updated_at) VALUES (:s,:asset,:q,:a,:e,:sl,:tp,:st,:i,:l,:x,:opened,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $stmt->execute([
            ':s'=>$market['symbol'],':asset'=>$market['asset'],':q'=>$market['quote_asset'],':a'=>$filled > 0 ? $filled : $amount,':e'=>$entry,
            ':sl'=>$this->risk->stopLoss($entry,(float)$settings['stop_loss_percent']),':tp'=>$this->risk->takeProfit($entry,(float)$settings['take_profit_percent']),
            ':st'=>$status,':i'=>$identifier,':l'=>$result['local_id'] ?? null,':x'=>$this->exchangeId($remote),':opened'=>$status === 'open' ? gmdate('Y-m-d H:i:s') : null,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->setSetting($pdo, 'nobitex_last_trade_at', gmdate('Y-m-d H:i:s'));
        $this->event($pdo, 'info', 'nobitex.portfolio.buy_submitted', ['position_id'=>$id,'symbol'=>$market['symbol'],'asset'=>$market['asset'],'amount'=>$amount,'price_ceiling'=>$priceCeiling]);
        return ['position_id'=>$id,'local_id'=>$result['local_id'] ?? null,'exchange_order_id'=>$this->exchangeId($remote),'amount'=>$amount,'position_status'=>$status];
    }

    private function submitExit(PDO $pdo, array $position, array $market, float $amount, string $reason): array
    {
        $identifier = 'ns' . gmdate('ymdHis') . substr(bin2hex(random_bytes(5)), 0, 10);
        $reference = (float) (($market['best_bid'] ?? 0) > 0 ? $market['best_bid'] : $market['price']);
        $result = $this->orders->create([
            'symbol'=>$market['symbol'],'amount1'=>$amount,'price'=>max(0.00000001,$reference * 0.992),'mode'=>'market','type'=>'sell','identifier'=>$identifier,
        ], 'autotrade_nobitex_portfolio');
        $remote = is_array($result['order'] ?? null) ? $result['order'] : [];
        $stmt = $pdo->prepare("UPDATE nobitex_autotrade_positions SET status='pending_close',exit_identifier=:i,exit_order_local_id=:l,exit_exchange_order_id=:x,updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='open'");
        $stmt->execute([':i'=>$identifier,':l'=>$result['local_id'] ?? null,':x'=>$this->exchangeId($remote),':id'=>$position['id']]);
        $this->setSetting($pdo, 'nobitex_last_trade_at', gmdate('Y-m-d H:i:s'));
        if ($this->isDone($remote)) $this->closePosition($pdo, (int) $position['id'], $remote, (float) $market['price']);
        $this->event($pdo, 'info', 'nobitex.portfolio.sell_submitted', ['position_id'=>$position['id'],'symbol'=>$position['symbol'],'reason'=>$reason,'amount'=>$amount]);
        return ['position_id'=>(int)$position['id'],'local_id'=>$result['local_id'] ?? null,'exchange_order_id'=>$this->exchangeId($remote),'amount'=>$amount];
    }

    private function reconcile(PDO $pdo, NobitexClient $client, array $settings): void
    {
        $rows = $pdo->query("SELECT * FROM nobitex_autotrade_positions WHERE status IN ('pending_open','pending_close') ORDER BY id ASC LIMIT 30")->fetchAll();
        foreach ($rows as $p) {
            $remoteId = (string) ($p['status'] === 'pending_open' ? ($p['entry_exchange_order_id'] ?? '') : ($p['exit_exchange_order_id'] ?? ''));
            $identifier = (string) ($p['status'] === 'pending_open' ? ($p['entry_identifier'] ?? '') : ($p['exit_identifier'] ?? ''));
            try {
                $response = $remoteId !== '' ? $client->orderStatus($remoteId) : $client->orderStatus(null, $identifier);
            } catch (\Throwable $e) {
                $this->event($pdo, 'warning', 'nobitex.reconcile.error', ['position_id'=>$p['id'],'error'=>$e->getMessage()]);
                continue;
            }
            $order = $this->orders->normalizedOrder($response);
            if ($order === []) continue;
            $state = strtolower(trim((string) ($order['status'] ?? '')));
            $matched = $this->fillAmount($order, 0.0);
            if ($p['status'] === 'pending_open') {
                if ($this->isDone($order) || (in_array($state,['canceled','cancelled'],true) && $matched > 0)) {
                    $price = $this->fillPrice($order, (float) $p['entry_price']);
                    $amount = $matched > 0 ? $matched : (float) $p['amount'];
                    $stmt = $pdo->prepare("UPDATE nobitex_autotrade_positions SET status='open',amount=:a,entry_price=:e,stop_loss=:sl,take_profit=:tp,entry_exchange_order_id=:x,opened_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id");
                    $stmt->execute([':a'=>$amount,':e'=>$price,':sl'=>$this->risk->stopLoss($price,(float)$settings['stop_loss_percent']),':tp'=>$this->risk->takeProfit($price,(float)$settings['take_profit_percent']),':x'=>$this->exchangeId($order),':id'=>$p['id']]);
                } elseif (in_array($state,['canceled','cancelled','rejected','failed'],true)) {
                    $pdo->prepare("UPDATE nobitex_autotrade_positions SET status='failed',updated_at=UTC_TIMESTAMP() WHERE id=:id")->execute([':id'=>$p['id']]);
                }
            } else {
                if ($this->isDone($order)) {
                    $this->closePosition($pdo, (int) $p['id'], $order, (float) $p['entry_price']);
                } elseif (in_array($state,['canceled','cancelled'],true)) {
                    if ($matched > 0) $this->closePosition($pdo, (int) $p['id'], $order, (float) $p['entry_price'], true);
                    else $pdo->prepare("UPDATE nobitex_autotrade_positions SET status='open',exit_identifier=NULL,exit_order_local_id=NULL,exit_exchange_order_id=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id")->execute([':id'=>$p['id']]);
                }
            }
        }
    }

    private function closePosition(PDO $pdo, int $id, array $order, float $fallback, bool $partial = false): void
    {
        $stmt = $pdo->prepare('SELECT * FROM nobitex_autotrade_positions WHERE id=:id LIMIT 1');
        $stmt->execute([':id'=>$id]);
        $p = $stmt->fetch();
        if (!$p || $p['status'] === 'closed') return;
        $exit = $this->fillPrice($order, $fallback);
        $matched = $this->fillAmount($order, (float) $p['amount']);
        $amount = min((float) $p['amount'], max(0.0, $matched));
        if ($amount <= 0) $amount = (float) $p['amount'];
        $entry = (float) $p['entry_price'];
        $pnl = ($exit - $entry) * $amount;
        $pct = $entry > 0 ? (($exit - $entry) / $entry) * 100.0 : 0.0;
        $remaining = max(0.0, (float) $p['amount'] - $amount);
        Database::transaction(function (PDO $tx) use ($id,$p,$exit,$amount,$pnl,$pct,$remaining,$partial,$order): void {
            if ($partial && $remaining > 0.00000001) {
                $tx->prepare("UPDATE nobitex_autotrade_positions SET status='open',amount=:remaining,realized_pnl=COALESCE(realized_pnl,0)+:p,exit_identifier=NULL,exit_order_local_id=NULL,exit_exchange_order_id=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id")->execute([':remaining'=>$remaining,':p'=>$pnl,':id'=>$id]);
            } else {
                $tx->prepare("UPDATE nobitex_autotrade_positions SET status='closed',exit_price=:e,realized_pnl=COALESCE(realized_pnl,0)+:p,exit_exchange_order_id=:x,closed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id")->execute([':e'=>$exit,':p'=>$pnl,':x'=>$this->exchangeId($order),':id'=>$id]);
            }
            $tx->prepare("INSERT INTO nobitex_autotrade_pnl (position_id,pnl,pnl_percent,quote_asset,entry_price,exit_price,amount,created_at) VALUES (:id,:p,:pct,:q,:en,:ex,:a,UTC_TIMESTAMP())")->execute([':id'=>$id,':p'=>$pnl,':pct'=>$pct,':q'=>$p['quote_asset'],':en'=>$p['entry_price'],':ex'=>$exit,':a'=>$amount]);
        });
        $this->event($pdo, 'info', 'nobitex.position.closed', ['position_id'=>$id,'symbol'=>$p['symbol'],'pnl'=>$pnl,'pnl_percent'=>$pct,'partial'=>$partial && $remaining > 0]);
    }

    private function adoptRemoteOrder(PDO $pdo, array $remote, string $identifier): array
    {
        $settings = $this->settings($pdo);
        $asset = strtoupper((string) ($remote['srcCurrency'] ?? $remote['src_currency'] ?? ''));
        $dst = strtoupper((string) ($remote['dstCurrency'] ?? $remote['dst_currency'] ?? 'RLS'));
        $quote = $dst === 'RLS' ? 'IRT' : $dst;
        if ($asset === '' || !in_array($quote, ['IRT','USDT'], true)) throw new \RuntimeException('Recovered Nobitex order has unknown market.');
        $symbol = $asset . $quote;
        $price = $this->fillPrice($remote, 0.0);
        if ($price <= 0) throw new \RuntimeException('Recovered Nobitex order has no usable price.');
        $market = ['symbol'=>$symbol,'asset'=>$asset,'quote_asset'=>$quote,'price'=>$price];
        $requested = $this->number($remote['amount'] ?? 0);
        $result = $this->insertRecoveredPosition($pdo, $market, $remote, $requested, $price, $identifier, $settings);
        $this->event($pdo, 'warning', 'nobitex.first_buy.recovered', $result);
        return $result;
    }

    private function insertRecoveredPosition(PDO $pdo, array $market, array $remote, float $requested, float $fallbackPrice, string $identifier, array $settings): array
    {
        $entry = $this->fillPrice($remote, $fallbackPrice);
        $filled = $this->fillAmount($remote, $requested);
        $status = $this->isDone($remote) && $filled > 0 ? 'open' : 'pending_open';
        $stmt = $pdo->prepare("INSERT INTO nobitex_autotrade_positions (symbol,asset,quote_asset,amount,entry_price,stop_loss,take_profit,status,entry_identifier,entry_exchange_order_id,opened_at,created_at,updated_at) VALUES (:s,:asset,:q,:a,:e,:sl,:tp,:st,:i,:x,:opened,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $stmt->execute([
            ':s'=>$market['symbol'],':asset'=>$market['asset'],':q'=>$market['quote_asset'],':a'=>$filled > 0 ? $filled : $requested,':e'=>$entry,
            ':sl'=>$this->risk->stopLoss($entry,(float)$settings['stop_loss_percent']),':tp'=>$this->risk->takeProfit($entry,(float)$settings['take_profit_percent']),
            ':st'=>$status,':i'=>$identifier,':x'=>$this->exchangeId($remote),':opened'=>$status === 'open' ? gmdate('Y-m-d H:i:s') : null,
        ]);
        return ['position_id'=>(int)$pdo->lastInsertId(),'position_status'=>$status,'exchange_order_id'=>$this->exchangeId($remote)];
    }

    private function activePositions(PDO $pdo): array
    {
        return $pdo->query("SELECT * FROM nobitex_autotrade_positions WHERE status IN ('pending_open','open','pending_close') ORDER BY id ASC LIMIT 30")->fetchAll();
    }

    private function hasPendingPosition(array $positions): bool
    {
        foreach ($positions as $p) if (in_array((string) $p['status'], ['pending_open','pending_close'], true)) return true;
        return false;
    }

    private function managedExposure(array $positions, string $quoteAsset): float
    {
        $sum = 0.0;
        foreach ($positions as $p) {
            if ((string) $p['quote_asset'] !== $quoteAsset) continue;
            $sum += max(0.0, (float) $p['amount']) * max(0.0, (float) $p['entry_price']);
        }
        return $sum;
    }

    private function walletAvailable(array $response, array $assets): float
    {
        $assets = array_map('strtoupper', $assets);
        $rows = $response['wallets'] ?? $response['data'] ?? $response;
        if (is_array($rows) && !array_is_list($rows) && is_array($rows['wallets'] ?? null)) $rows = $rows['wallets'];
        if (!is_array($rows)) return 0.0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $asset = strtoupper((string) ($row['currency'] ?? $row['asset'] ?? $row['currencyCode'] ?? ''));
            if (!in_array($asset, $assets, true)) continue;
            foreach (['activeBalance','available','free','balance'] as $key) {
                if (array_key_exists($key, $row)) return max(0.0, $this->number($row[$key]));
            }
        }
        return 0.0;
    }

    private function storeSignal(PDO $pdo, array $market, array $signal): int
    {
        $stmt = $pdo->prepare("INSERT INTO nobitex_autotrade_signals (symbol,asset,quote_asset,action,score,price,details_json,executed,created_at) VALUES (:s,:asset,:q,:a,:sc,:p,:d,0,UTC_TIMESTAMP())");
        $stmt->execute([
            ':s'=>$market['symbol'],':asset'=>$market['asset'],':q'=>$market['quote_asset'],':a'=>$signal['action'] ?? 'hold',':sc'=>$signal['score'] ?? 0,':p'=>$market['price'],
            ':d'=>json_encode($signal + ['opportunity_score'=>$market['opportunity_score'] ?? null,'spread_percent'=>$market['spread_percent'] ?? null,'depth_quote'=>$market['depth_quote'] ?? null], JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function markSignalExecuted(PDO $pdo, int $id, ?string $local): void
    {
        $pdo->prepare('UPDATE nobitex_autotrade_signals SET executed=1,order_local_id=:l WHERE id=:id')->execute([':l'=>$local,':id'=>$id]);
    }

    private function candidateSummary(array $candidates): array
    {
        $out = [];
        foreach (array_slice($candidates, 0, 8) as $c) {
            $out[] = [
                'symbol'=>$c['symbol'] ?? null,'asset'=>$c['asset'] ?? null,'quote_asset'=>$c['quote_asset'] ?? null,
                'signal'=>$c['signal']['action'] ?? 'hold',
                'expected_net_edge_percent'=>$c['signal']['expected_net_edge_percent'] ?? null,
                'tradable_net_edge_percent'=>$c['signal']['tradable_net_edge_percent'] ?? null,
                'required_edge_buffer_percent'=>$c['signal']['required_edge_buffer_percent'] ?? null,
                'spread_percent'=>$c['spread_percent'] ?? null,
            ];
        }
        return $out;
    }

    private function dailyPnl(PDO $pdo, string $quote): float
    {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(pnl),0) FROM nobitex_autotrade_pnl WHERE quote_asset=:q AND created_at>=UTC_DATE()");
        $stmt->execute([':q'=>$quote]);
        return (float) $stmt->fetchColumn();
    }

    private function completeBootstrap(PDO $pdo, string $reason): void
    {
        $this->setSetting($pdo, 'nobitex_bootstrap_first_buy_pending', '0');
        $this->setSetting($pdo, 'nobitex_bootstrap_first_buy_completed_at', gmdate('Y-m-d H:i:s'));
        $this->setSetting($pdo, 'nobitex_bootstrap_first_buy_result', $reason);
    }

    private function bootstrapBlocked(PDO $pdo, string $reason, array $context = []): array
    {
        $this->setSetting($pdo, 'nobitex_bootstrap_first_buy_last_error', $reason);
        $this->event($pdo, 'warning', 'nobitex.first_buy.waiting', ['reason'=>$reason] + $context);
        return ['status'=>'first_buy_waiting','exchange'=>'nobitex','reason'=>$reason] + $context;
    }

    private function blocked(PDO $pdo, string $scope, string $reason): array
    {
        $this->event($pdo, 'warning', 'nobitex.' . $scope . '.blocked', ['reason'=>$reason]);
        return ['status'=>'blocked','exchange'=>'nobitex','reason'=>$reason,'asset_scope'=>'all_spot'];
    }

    private function summary(string $status, array $extra = []): array
    {
        return ['status'=>$status,'exchange'=>'nobitex','asset_scope'=>'all_spot','quote_priority'=>['IRT','USDT'],'time_utc'=>gmdate(DATE_ATOM)] + $extra;
    }

    private function symbolCooldownKey(string $symbol): string
    {
        return 'nobitex_last_trade_' . substr(sha1(strtoupper($symbol)), 0, 16);
    }

    private function intSetting(PDO $pdo, string $key, int $default, int $min, int $max): int
    {
        $v = $this->setting($pdo, $key);
        if ($v === null || !is_numeric($v)) return $default;
        return max($min, min($max, (int) $v));
    }

    private function floatSetting(PDO $pdo, string $key, float $default, float $min, float $max): float
    {
        $v = $this->setting($pdo, $key);
        if ($v === null || !is_numeric($v)) return $default;
        return max($min, min($max, (float) $v));
    }

    private function boolSetting(PDO $pdo, string $key): bool
    {
        return in_array(strtolower(trim((string) ($this->setting($pdo, $key) ?? '0'))), ['1','true','yes','on'], true);
    }

    private function setting(PDO $pdo, string $key): ?string
    {
        $stmt = $pdo->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');
        $stmt->execute([':k'=>$key]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    private function setSetting(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES (:k,:v,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");
        $stmt->execute([':k'=>$key,':v'=>$value]);
    }

    private function fillPrice(array $order, float $fallback): float
    {
        foreach (['averagePrice','average_price','price'] as $key) {
            $v = $this->number($order[$key] ?? 0);
            if ($v > 0) return $v;
        }
        return $fallback;
    }

    private function fillAmount(array $order, float $fallback): float
    {
        foreach (['matchedAmount','matched_amount','filledAmount','amount'] as $key) {
            $v = $this->number($order[$key] ?? 0);
            if ($v > 0) return $v;
        }
        return $fallback;
    }

    private function isDone(array $order): bool
    {
        return in_array(strtolower(trim((string) ($order['status'] ?? ''))), ['done','completed','filled'], true);
    }

    private function exchangeId(array $order): ?string
    {
        $id = trim((string) ($order['id'] ?? ''));
        return $id !== '' ? $id : null;
    }

    private function looksLikeOrder(array $order): bool
    {
        return trim((string) ($order['id'] ?? $order['clientOrderId'] ?? '')) !== '' && strtolower((string) ($order['status'] ?? '')) !== 'failed';
    }

    private function floorAmount(float $amount, int $precision): float
    {
        $precision = max(0, min(18, $precision));
        $factor = 10 ** $precision;
        return floor($amount * $factor) / $factor;
    }

    private function number(mixed $value): float
    {
        if (!is_numeric($value)) return 0.0;
        $n = (float) $value;
        return is_finite($n) ? $n : 0.0;
    }

    private function event(PDO $pdo, string $level, string $event, array $context = []): void
    {
        $pdo->prepare('INSERT INTO nobitex_autotrade_events (level,event_name,context_json,created_at) VALUES (:l,:e,:c,UTC_TIMESTAMP())')->execute([
            ':l'=>$level,':e'=>$event,':c'=>$context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
    }
}
