<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;
use Trade\Exchange\NobitexClient;

final class NobitexAutoTraderEngine
{
    public function __construct(
        private readonly NobitexOrderService $orders = new NobitexOrderService(),
        private readonly NobitexMarketScanner $scanner = new NobitexMarketScanner(),
        private readonly SignalEngine $signals = new SignalEngine(),
        private readonly RiskManager $risk = new RiskManager(),
    ) {}

    public function run(): array
    {
        NobitexSchema::ensure();
        $pdo = Database::connection();
        if ((int) $pdo->query("SELECT GET_LOCK('trade_autotrade_nobitex_v1',0)")->fetchColumn() !== 1) {
            return ['status'=>'skipped','exchange'=>'nobitex','reason'=>'another_nobitex_run_is_active'];
        }
        try { return $this->runLocked($pdo); }
        finally { try { $pdo->query("SELECT RELEASE_LOCK('trade_autotrade_nobitex_v1')"); } catch (\Throwable) {} }
    }

    private function runLocked(PDO $pdo): array
    {
        if (!NobitexSchema::botEnabled('nobitex')) return ['status'=>'disabled','exchange'=>'nobitex','asset'=>'GRAM'];
        if ($this->killSwitch($pdo)) return $this->blocked($pdo, 'kill_switch');
        if (!$this->orders->credentialsConfigured()) return $this->blocked($pdo, 'credentials_missing');
        if (!$this->orders->liveEnabled()) return $this->blocked($pdo, 'live_execution_disabled');

        $settings = array_merge(Schema::settings($pdo), $this->risk->normalizeSettings(Schema::settings($pdo)));
        $client = $this->orders->client();
        $market = $this->scanner->snapshot($client, (string) $settings['quote_asset']);
        $this->reconcile($pdo, $client, $market, $settings);

        $wallets = $client->wallets();
        $base = $this->walletAvailable($wallets, ['TON','GRAM','TONCOIN']);
        $quoteCodes = $market['quote_asset'] === 'IRT' ? ['RLS','IRT'] : [(string) $market['quote_asset']];
        $quote = $this->walletAvailable($wallets, $quoteCodes);
        $portfolio = $quote + ($base * (float) $market['price']);
        $signal = $this->signals->analyze($market, (int) $settings['min_signal_score']);
        $signalId = $this->storeSignal($pdo, $market, $signal);
        $position = $this->currentPosition($pdo);
        $dailyPnl = $this->dailyPnl($pdo, (string) $market['quote_asset']);

        if ($position && in_array((string) $position['status'], ['pending_open','pending_close'], true)) {
            return $this->summary('waiting_order', $market, $signal, ['position_id'=>(int)$position['id'],'daily_pnl'=>$dailyPnl]);
        }

        if ($position && (string) $position['status'] === 'open') {
            $reason = $this->risk->exitReason((float) $market['price'], $position, (string) ($signal['action'] ?? 'hold'));
            if ($reason !== null) {
                $amount = $this->floorAmount(min((float) $position['amount'], $base), (int) $market['base_precision']);
                if ($amount <= 0) return $this->blocked($pdo, 'no_base_balance_for_exit');
                $result = $this->submitExit($pdo, $position, $market, $amount, $reason);
                $this->markSignalExecuted($pdo, $signalId, $result['local_id'] ?? null);
                return $this->summary('sell_submitted', $market, $signal, $result + ['exit_reason'=>$reason]);
            }
            return $this->summary('holding_position', $market, $signal, ['position_id'=>(int)$position['id'],'daily_pnl'=>$dailyPnl]);
        }

        if (!($signal['ready'] ?? false) || (string) ($signal['action'] ?? 'hold') !== 'buy') {
            return $this->summary('no_trade', $market, $signal, ['daily_pnl'=>$dailyPnl]);
        }

        $amount = $this->risk->calculateBaseAmount($quote, (float) $market['price'], (float) $settings['position_percent'], (int) $market['base_precision']);
        $value = $amount * (float) $market['price'];
        $openCount = (int) $pdo->query("SELECT COUNT(*) FROM nobitex_autotrade_positions WHERE status IN ('pending_open','open','pending_close')")->fetchColumn();
        $decision = $this->risk->entryDecision(
            $portfolio,
            $value,
            $base * (float) $market['price'],
            $dailyPnl,
            $openCount,
            $this->getSetting($pdo, 'nobitex_last_trade_at'),
            $settings
        );
        if (!$decision['allowed']) {
            $this->event($pdo, 'info', 'nobitex.entry.blocked', $decision);
            return $this->summary('blocked', $market, $signal, $decision);
        }

        $result = $this->submitEntry($pdo, $market, $amount, $settings);
        $this->markSignalExecuted($pdo, $signalId, $result['local_id'] ?? null);
        return $this->summary('buy_submitted', $market, $signal, $result);
    }

    private function submitEntry(PDO $pdo, array $market, float $amount, array $settings): array
    {
        $identifier = 'nb' . gmdate('ymdHis') . substr(bin2hex(random_bytes(5)), 0, 10);
        $result = $this->orders->create([
            'symbol'=>$market['symbol'], 'amount1'=>$amount, 'price'=>$market['price'],
            'mode'=>'market', 'type'=>'buy', 'identifier'=>$identifier,
        ], 'autotrade_nobitex');
        $remote = is_array($result['order'] ?? null) ? $result['order'] : [];
        $entry = $this->fillPrice($remote, (float) $market['price']);
        $filled = $this->fillAmount($remote, $amount);
        $done = $this->isDone($remote);
        $status = $done && $filled > 0 ? 'open' : 'pending_open';
        $stmt = $pdo->prepare("INSERT INTO nobitex_autotrade_positions (symbol,asset,quote_asset,amount,entry_price,stop_loss,take_profit,status,entry_identifier,entry_order_local_id,entry_exchange_order_id,opened_at,created_at,updated_at) VALUES (:s,'GRAM',:q,:a,:e,:sl,:tp,:st,:i,:l,:x,:opened,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $stmt->execute([
            ':s'=>$market['symbol'], ':q'=>$market['quote_asset'], ':a'=>$filled > 0 ? $filled : $amount,
            ':e'=>$entry, ':sl'=>$this->risk->stopLoss($entry,(float)$settings['stop_loss_percent']),
            ':tp'=>$this->risk->takeProfit($entry,(float)$settings['take_profit_percent']), ':st'=>$status,
            ':i'=>$identifier, ':l'=>$result['local_id'] ?? null, ':x'=>$this->exchangeId($remote),
            ':opened'=>$status === 'open' ? gmdate('Y-m-d H:i:s') : null,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->setSetting($pdo, 'nobitex_last_trade_at', gmdate('Y-m-d H:i:s'));
        $this->event($pdo, 'info', 'nobitex.buy.submitted', ['position_id'=>$id,'amount'=>$amount,'price'=>$market['price']]);
        return ['position_id'=>$id,'local_id'=>$result['local_id'] ?? null,'exchange_order_id'=>$this->exchangeId($remote),'amount'=>$amount,'position_status'=>$status];
    }

    private function submitExit(PDO $pdo, array $position, array $market, float $amount, string $reason): array
    {
        $identifier = 'ns' . gmdate('ymdHis') . substr(bin2hex(random_bytes(5)), 0, 10);
        $result = $this->orders->create([
            'symbol'=>$market['symbol'], 'amount1'=>$amount, 'price'=>$market['price'],
            'mode'=>'market', 'type'=>'sell', 'identifier'=>$identifier,
        ], 'autotrade_nobitex');
        $remote = is_array($result['order'] ?? null) ? $result['order'] : [];
        $stmt = $pdo->prepare("UPDATE nobitex_autotrade_positions SET status='pending_close',exit_identifier=:i,exit_order_local_id=:l,exit_exchange_order_id=:x,updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='open'");
        $stmt->execute([':i'=>$identifier,':l'=>$result['local_id'] ?? null,':x'=>$this->exchangeId($remote),':id'=>$position['id']]);
        $this->setSetting($pdo, 'nobitex_last_trade_at', gmdate('Y-m-d H:i:s'));
        if ($this->isDone($remote)) $this->closePosition($pdo,(int)$position['id'],$remote,(float)$market['price']);
        $this->event($pdo,'info','nobitex.sell.submitted',['position_id'=>$position['id'],'reason'=>$reason,'amount'=>$amount]);
        return ['position_id'=>(int)$position['id'],'local_id'=>$result['local_id'] ?? null,'exchange_order_id'=>$this->exchangeId($remote),'amount'=>$amount];
    }

    private function reconcile(PDO $pdo, NobitexClient $client, array $market, array $settings): void
    {
        $rows = $pdo->query("SELECT * FROM nobitex_autotrade_positions WHERE status IN ('pending_open','pending_close') ORDER BY id ASC LIMIT 20")->fetchAll();
        foreach ($rows as $p) {
            $remoteId = (string) ($p['status'] === 'pending_open' ? ($p['entry_exchange_order_id'] ?? '') : ($p['exit_exchange_order_id'] ?? ''));
            $identifier = (string) ($p['status'] === 'pending_open' ? ($p['entry_identifier'] ?? '') : ($p['exit_identifier'] ?? ''));
            try {
                $response = $remoteId !== '' ? $client->orderStatus($remoteId) : $client->orderStatus(null, $identifier);
            } catch (\Throwable $e) {
                $this->event($pdo,'warning','nobitex.reconcile.error',['position_id'=>$p['id'],'error'=>$e->getMessage()]);
                continue;
            }
            $order = $this->orders->normalizedOrder($response);
            if ($order === []) continue;
            $state = strtolower(trim((string) ($order['status'] ?? '')));
            $matched = $this->fillAmount($order, 0.0);

            if ($p['status'] === 'pending_open') {
                if ($this->isDone($order) || (in_array($state,['canceled','cancelled'],true) && $matched > 0)) {
                    $price = $this->fillPrice($order,(float)$p['entry_price']);
                    $amount = $matched > 0 ? $matched : (float)$p['amount'];
                    $stmt = $pdo->prepare("UPDATE nobitex_autotrade_positions SET status='open',amount=:a,entry_price=:e,stop_loss=:sl,take_profit=:tp,entry_exchange_order_id=:x,opened_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id");
                    $stmt->execute([':a'=>$amount,':e'=>$price,':sl'=>$this->risk->stopLoss($price,(float)$settings['stop_loss_percent']),':tp'=>$this->risk->takeProfit($price,(float)$settings['take_profit_percent']),':x'=>$this->exchangeId($order),':id'=>$p['id']]);
                } elseif (in_array($state,['canceled','cancelled','rejected','failed'],true)) {
                    $pdo->prepare("UPDATE nobitex_autotrade_positions SET status='failed',updated_at=UTC_TIMESTAMP() WHERE id=:id")->execute([':id'=>$p['id']]);
                }
            } else {
                if ($this->isDone($order)) {
                    $this->closePosition($pdo,(int)$p['id'],$order,(float)$market['price']);
                } elseif (in_array($state,['canceled','cancelled'],true)) {
                    if ($matched > 0) $this->closePosition($pdo,(int)$p['id'],$order,(float)$market['price'], true);
                    else $pdo->prepare("UPDATE nobitex_autotrade_positions SET status='open',exit_identifier=NULL,exit_order_local_id=NULL,exit_exchange_order_id=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id")->execute([':id'=>$p['id']]);
                }
            }
        }
    }

    private function closePosition(PDO $pdo, int $id, array $order, float $fallback, bool $partial = false): void
    {
        $stmt=$pdo->prepare('SELECT * FROM nobitex_autotrade_positions WHERE id=:id LIMIT 1'); $stmt->execute([':id'=>$id]); $p=$stmt->fetch();
        if (!$p || $p['status'] === 'closed') return;
        $exit=$this->fillPrice($order,$fallback); $matched=$this->fillAmount($order,(float)$p['amount']);
        $amount=min((float)$p['amount'],max(0.0,$matched)); if($amount<=0)$amount=(float)$p['amount'];
        $entry=(float)$p['entry_price']; $pnl=($exit-$entry)*$amount; $pct=$entry>0?(($exit-$entry)/$entry)*100:0;
        $remaining=max(0.0,(float)$p['amount']-$amount);
        Database::transaction(function(PDO $tx) use($id,$p,$exit,$amount,$pnl,$pct,$remaining,$partial,$order):void {
            if ($partial && $remaining > 0.00000001) {
                $tx->prepare("UPDATE nobitex_autotrade_positions SET status='open',amount=:remaining,realized_pnl=COALESCE(realized_pnl,0)+:p,exit_identifier=NULL,exit_order_local_id=NULL,exit_exchange_order_id=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id")->execute([':remaining'=>$remaining,':p'=>$pnl,':id'=>$id]);
            } else {
                $tx->prepare("UPDATE nobitex_autotrade_positions SET status='closed',exit_price=:e,realized_pnl=COALESCE(realized_pnl,0)+:p,exit_exchange_order_id=:x,closed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id")->execute([':e'=>$exit,':p'=>$pnl,':x'=>$this->exchangeId($order),':id'=>$id]);
            }
            $tx->prepare("INSERT INTO nobitex_autotrade_pnl (position_id,pnl,pnl_percent,quote_asset,entry_price,exit_price,amount,created_at) VALUES (:id,:p,:pct,:q,:en,:ex,:a,UTC_TIMESTAMP())")->execute([':id'=>$id,':p'=>$pnl,':pct'=>$pct,':q'=>$p['quote_asset'],':en'=>$p['entry_price'],':ex'=>$exit,':a'=>$amount]);
        });
        $this->event($pdo,'info','nobitex.position.closed',['position_id'=>$id,'pnl'=>$pnl,'pnl_percent'=>$pct,'partial'=>$partial && $remaining>0]);
    }

    private function walletAvailable(array $response, array $assets): float
    {
        $assets = array_map('strtoupper',$assets);
        $rows = $response['wallets'] ?? $response['data'] ?? $response;
        if (!is_array($rows)) return 0.0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $asset = strtoupper((string) ($row['currency'] ?? $row['asset'] ?? $row['currencyCode'] ?? ''));
            if (!in_array($asset,$assets,true)) continue;
            foreach (['activeBalance','available','free','balance'] as $key) {
                if (array_key_exists($key,$row)) return max(0.0,$this->num($row[$key]));
            }
        }
        return 0.0;
    }

    private function currentPosition(PDO $pdo): ?array { $r=$pdo->query("SELECT * FROM nobitex_autotrade_positions WHERE status IN ('pending_open','open','pending_close') ORDER BY id DESC LIMIT 1")->fetch(); return $r?:null; }
    private function dailyPnl(PDO $pdo,string $q):float{ $s=$pdo->prepare("SELECT COALESCE(SUM(pnl),0) FROM nobitex_autotrade_pnl WHERE quote_asset=:q AND created_at>=UTC_DATE()");$s->execute([':q'=>$q]);return(float)$s->fetchColumn(); }
    private function killSwitch(PDO $pdo):bool{return(string)($pdo->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn()?:'0')==='1';}
    private function blocked(PDO $pdo,string $reason):array{$this->event($pdo,'warning','nobitex.blocked.'.$reason);return['status'=>'blocked','exchange'=>'nobitex','reason'=>$reason,'asset'=>'GRAM'];}
    private function storeSignal(PDO $pdo,array $m,array $s):int{$q=$pdo->prepare("INSERT INTO nobitex_autotrade_signals (symbol,asset,quote_asset,action,score,price,details_json,executed,created_at) VALUES (:s,'GRAM',:q,:a,:sc,:p,:d,0,UTC_TIMESTAMP())");$q->execute([':s'=>$m['symbol'],':q'=>$m['quote_asset'],':a'=>$s['action']??'hold',':sc'=>$s['score']??0,':p'=>$m['price'],':d'=>json_encode($s,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);return(int)$pdo->lastInsertId();}
    private function markSignalExecuted(PDO $pdo,int $id,?string $local):void{$pdo->prepare('UPDATE nobitex_autotrade_signals SET executed=1,order_local_id=:l WHERE id=:id')->execute([':l'=>$local,':id'=>$id]);}
    private function fillPrice(array $o,float $fallback):float{foreach(['averagePrice','average_price','price']as$k){$v=$this->num($o[$k]??0);if($v>0)return$v;}return$fallback;}
    private function fillAmount(array $o,float $fallback):float{foreach(['matchedAmount','matched_amount','filledAmount','amount']as$k){$v=$this->num($o[$k]??0);if($v>0)return$v;}return$fallback;}
    private function isDone(array $o):bool{return in_array(strtolower(trim((string)($o['status']??''))),['done','completed','filled'],true);}
    private function exchangeId(array $o):?string{$id=trim((string)($o['id']??''));return$id!==''?$id:null;}
    private function floorAmount(float $a,int $p):float{$p=max(0,min(18,$p));$f=10**$p;return floor($a*$f)/$f;}
    private function num(mixed $v):float{if(!is_numeric($v))return 0.0;$n=(float)$v;return is_finite($n)?$n:0.0;}
    private function event(PDO $pdo,string $level,string $event,array $c=[]):void{$pdo->prepare('INSERT INTO nobitex_autotrade_events (level,event_name,context_json,created_at) VALUES (:l,:e,:c,UTC_TIMESTAMP())')->execute([':l'=>$level,':e'=>$event,':c'=>$c===[]?null:json_encode($c,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);}
    private function getSetting(PDO $pdo,string $key):?string{$s=$pdo->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');$s->execute([':k'=>$key]);$v=$s->fetchColumn();return$v===false?null:(string)$v;}
    private function setSetting(PDO $pdo,string $key,string $value):void{$s=$pdo->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES (:k,:v,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");$s->execute([':k'=>$key,':v'=>$value]);}
    private function summary(string $status,array $m,array $s,array $extra=[]):array{return array_merge(['status'=>$status,'exchange'=>'nobitex','asset'=>'GRAM','exchange_asset'=>$m['exchange_asset'],'market'=>$m['symbol'],'quote_asset'=>$m['quote_asset'],'price'=>$m['price'],'signal'=>['action'=>$s['action']??'hold','score'=>$s['score']??0,'ready'=>$s['ready']??false,'indicators'=>$s['indicators']??[]],'time_utc'=>gmdate(DATE_ATOM)],$extra);}
}
