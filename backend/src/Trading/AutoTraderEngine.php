<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;
use Trade\Exchange\BitpinClient;

final class AutoTraderEngine
{
    public function __construct(
        private readonly OrderService $orders = new OrderService(),
        private readonly MarketScanner $scanner = new MarketScanner(),
        private readonly SignalEngine $signals = new SignalEngine(),
        private readonly RiskManager $risk = new RiskManager(),
    ) {}

    public function run(): array
    {
        Schema::ensure();
        $pdo = Database::connection();
        if ((int)$pdo->query("SELECT GET_LOCK('trade_autotrade_live_v1',0)")->fetchColumn() !== 1) {
            return ['status'=>'skipped','reason'=>'another_autotrade_run_is_active'];
        }
        try { return $this->runLocked($pdo); }
        finally { try { $pdo->query("SELECT RELEASE_LOCK('trade_autotrade_live_v1')"); } catch (\Throwable) {} }
    }

    private function runLocked(PDO $pdo): array
    {
        $raw = Schema::settings($pdo);
        $settings = array_merge($raw, $this->risk->normalizeSettings($raw));
        if (!(bool)$settings['enabled']) return ['status'=>'disabled','asset'=>'GRAM'];
        if ($this->killSwitch($pdo)) return $this->blocked($pdo, 'kill_switch');
        if (!$this->orders->liveEnabled()) return $this->blocked($pdo, 'live_execution_disabled');

        $client = $this->orders->client();
        $market = $this->scanner->snapshot($client, (string)$settings['quote_asset']);
        $this->reconcile($pdo, $client, $market);

        $wallets = $this->spotWallets($client);
        $this->orders->syncTokens($client);
        $base = $this->walletAvailable($wallets, ['GRAM','TON']);
        $quote = $this->walletAvailable($wallets, [(string)$market['quote_asset']]);
        $portfolio = $quote + $base * (float)$market['price'];
        $signal = $this->signals->analyze($market, (int)$settings['min_signal_score']);
        $signalId = $this->storeSignal($pdo, $market, $signal);
        $position = $this->currentPosition($pdo);
        $dailyPnl = $this->dailyPnl($pdo, (string)$market['quote_asset']);

        if ($position && in_array((string)$position['status'], ['pending_open','pending_close'], true)) {
            return $this->summary('waiting_order', $market, $signal, ['position_id'=>(int)$position['id'],'daily_pnl'=>$dailyPnl]);
        }

        if ($position && $position['status'] === 'open') {
            $reason = $this->risk->exitReason((float)$market['price'], $position, (string)($signal['action'] ?? 'hold'));
            if ($reason !== null) {
                $amount = $this->floorAmount(min((float)$position['amount'], $base), (int)$market['base_precision']);
                if ($amount <= 0) return $this->blocked($pdo, 'no_base_balance_for_exit');
                $result = $this->submitExit($pdo, $position, $market, $amount, $reason);
                $this->markSignalExecuted($pdo, $signalId, $result['local_id'] ?? null);
                return $this->summary('sell_submitted', $market, $signal, $result + ['exit_reason'=>$reason]);
            }
            return $this->summary('holding_position', $market, $signal, ['position_id'=>(int)$position['id'],'daily_pnl'=>$dailyPnl]);
        }

        if (!($signal['ready'] ?? false) || ($signal['action'] ?? 'hold') !== 'buy') {
            return $this->summary('no_trade', $market, $signal, ['daily_pnl'=>$dailyPnl]);
        }

        $amount = $this->risk->calculateBaseAmount($quote, (float)$market['price'], (float)$settings['position_percent'], (int)$market['base_precision']);
        $value = $amount * (float)$market['price'];
        $openCount = (int)$pdo->query("SELECT COUNT(*) FROM autotrade_positions WHERE status IN ('pending_open','open','pending_close')")->fetchColumn();
        $decision = $this->risk->entryDecision($portfolio, $value, $base*(float)$market['price'], $dailyPnl, $openCount, $settings['last_trade_at'] ?: null, $settings);
        if (!$decision['allowed']) {
            $this->event($pdo, 'info', 'autotrade.entry.blocked', $decision);
            return $this->summary('blocked', $market, $signal, $decision);
        }

        $result = $this->submitEntry($pdo, $market, $amount, $settings);
        $this->markSignalExecuted($pdo, $signalId, $result['local_id'] ?? null);
        return $this->summary('buy_submitted', $market, $signal, $result);
    }

    private function submitEntry(PDO $pdo, array $market, float $amount, array $settings): array
    {
        $identifier = 'auto-buy-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4));
        $result = $this->orders->create(['market'=>(int)$market['market_id'],'amount1'=>$amount,'price'=>(float)$market['price'],'mode'=>'market','type'=>'buy','identifier'=>$identifier], 'autotrade');
        $remote = is_array($result['exchange'] ?? null) ? $result['exchange'] : [];
        $entry = $this->fillPrice($remote, (float)$market['price']);
        $filled = $this->fillAmount($remote, $amount);
        $status = $this->isFilled($remote) && $filled > 0 ? 'open' : 'pending_open';
        $stmt = $pdo->prepare("INSERT INTO autotrade_positions (market_id,symbol,asset,quote_asset,amount,entry_price,stop_loss,take_profit,status,entry_identifier,entry_order_local_id,entry_exchange_order_id,opened_at,created_at,updated_at) VALUES (:m,:s,'GRAM',:q,:a,:e,:sl,:tp,:st,:i,:l,:x,:o,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $stmt->execute([':m'=>$market['market_id'],':s'=>$market['symbol'],':q'=>$market['quote_asset'],':a'=>$filled>0?$filled:$amount,':e'=>$entry,':sl'=>$this->risk->stopLoss($entry,(float)$settings['stop_loss_percent']),':tp'=>$this->risk->takeProfit($entry,(float)$settings['take_profit_percent']),':st'=>$status,':i'=>$identifier,':l'=>$result['local_id']??null,':x'=>$this->exchangeId($remote),':o'=>$status==='open'?gmdate('Y-m-d H:i:s'):null]);
        $pdo->exec('UPDATE autotrade_settings SET last_trade_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=1');
        $id=(int)$pdo->lastInsertId();
        $this->event($pdo,'info','autotrade.buy.submitted',['position_id'=>$id,'amount'=>$amount,'price'=>$market['price']]);
        return ['position_id'=>$id,'local_id'=>$result['local_id']??null,'exchange_order_id'=>$this->exchangeId($remote),'amount'=>$amount,'position_status'=>$status];
    }

    private function submitExit(PDO $pdo, array $position, array $market, float $amount, string $reason): array
    {
        $identifier='auto-sell-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4));
        $result=$this->orders->create(['market'=>(int)$market['market_id'],'amount1'=>$amount,'price'=>(float)$market['price'],'mode'=>'market','type'=>'sell','identifier'=>$identifier], 'autotrade');
        $remote=is_array($result['exchange']??null)?$result['exchange']:[];
        $stmt=$pdo->prepare("UPDATE autotrade_positions SET status='pending_close',exit_identifier=:i,exit_order_local_id=:l,exit_exchange_order_id=:x,updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='open'");
        $stmt->execute([':i'=>$identifier,':l'=>$result['local_id']??null,':x'=>$this->exchangeId($remote),':id'=>$position['id']]);
        $pdo->exec('UPDATE autotrade_settings SET last_trade_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=1');
        if ($this->isFilled($remote)) $this->closePosition($pdo,(int)$position['id'],$remote,(float)$market['price']);
        $this->event($pdo,'info','autotrade.sell.submitted',['position_id'=>$position['id'],'reason'=>$reason,'amount'=>$amount]);
        return ['position_id'=>(int)$position['id'],'local_id'=>$result['local_id']??null,'exchange_order_id'=>$this->exchangeId($remote),'amount'=>$amount];
    }

    private function reconcile(PDO $pdo, BitpinClient $client, array $market): void
    {
        $rows=$pdo->query("SELECT * FROM autotrade_positions WHERE status IN ('pending_open','pending_close') ORDER BY id ASC LIMIT 20")->fetchAll();
        foreach($rows as $p){
            $identifier=$p['status']==='pending_open'?(string)$p['entry_identifier']:(string)$p['exit_identifier'];
            if($identifier==='') continue;
            try { $resp=$client->orders(['identifier'=>$identifier,'page'=>1]); } catch(\Throwable $e){ $this->event($pdo,'warning','autotrade.reconcile.error',['position_id'=>$p['id'],'error'=>$e->getMessage()]); continue; }
            $order=$this->firstRecord($resp); if(!$order) continue;
            $local=(string)($p['status']==='pending_open'?($p['entry_order_local_id']??''):($p['exit_order_local_id']??''));
            if($this->isFailed($order)){
                $this->syncLocal($pdo,$local,'failed',$order);
                if($p['status']==='pending_open') $pdo->prepare("UPDATE autotrade_positions SET status='failed',updated_at=UTC_TIMESTAMP() WHERE id=:id")->execute([':id'=>$p['id']]);
                else $pdo->prepare("UPDATE autotrade_positions SET status='open',exit_identifier=NULL,exit_order_local_id=NULL,exit_exchange_order_id=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id")->execute([':id'=>$p['id']]);
                continue;
            }
            if(!$this->isFilled($order)) continue;
            $this->syncLocal($pdo,$local,'filled',$order);
            if($p['status']==='pending_open'){
                $price=$this->fillPrice($order,(float)$p['entry_price']); $amount=$this->fillAmount($order,(float)$p['amount']);
                $settings=$this->risk->normalizeSettings(Schema::settings($pdo));
                $stmt=$pdo->prepare("UPDATE autotrade_positions SET status='open',amount=:a,entry_price=:e,stop_loss=:sl,take_profit=:tp,entry_exchange_order_id=:x,opened_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id");
                $stmt->execute([':a'=>$amount,':e'=>$price,':sl'=>$this->risk->stopLoss($price,$settings['stop_loss_percent']),':tp'=>$this->risk->takeProfit($price,$settings['take_profit_percent']),':x'=>$this->exchangeId($order),':id'=>$p['id']]);
            } else $this->closePosition($pdo,(int)$p['id'],$order,(float)$market['price']);
        }
        $this->orders->syncTokens($client);
    }

    private function closePosition(PDO $pdo,int $id,array $order,float $fallback): void
    {
        $stmt=$pdo->prepare('SELECT * FROM autotrade_positions WHERE id=:id LIMIT 1'); $stmt->execute([':id'=>$id]); $p=$stmt->fetch();
        if(!$p || $p['status']==='closed') return;
        $exit=$this->fillPrice($order,$fallback); $amount=min((float)$p['amount'],max(0.0,$this->fillAmount($order,(float)$p['amount']))); if($amount<=0)$amount=(float)$p['amount'];
        $entry=(float)$p['entry_price']; $pnl=($exit-$entry)*$amount; $pct=$entry>0?(($exit-$entry)/$entry)*100:0;
        Database::transaction(function(PDO $tx) use($id,$p,$exit,$amount,$entry,$pnl,$pct,$order):void{
            $tx->prepare("UPDATE autotrade_positions SET status='closed',exit_price=:e,realized_pnl=:p,exit_exchange_order_id=:x,closed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id")->execute([':e'=>$exit,':p'=>$pnl,':x'=>$this->exchangeId($order),':id'=>$id]);
            $tx->prepare("INSERT INTO autotrade_pnl (position_id,pnl,pnl_percent,quote_asset,entry_price,exit_price,amount,created_at) VALUES (:id,:p,:pct,:q,:en,:ex,:a,UTC_TIMESTAMP())")->execute([':id'=>$id,':p'=>$pnl,':pct'=>$pct,':q'=>$p['quote_asset'],':en'=>$entry,':ex'=>$exit,':a'=>$amount]);
        });
        $this->event($pdo,'info','autotrade.position.closed',['position_id'=>$id,'pnl'=>$pnl,'pnl_percent'=>$pct]);
    }

    private function spotWallets(BitpinClient $c): array { try{return $c->wallets(['service'=>'spot','limit'=>250]);}catch(\Throwable){return $c->wallets();} }
    private function walletAvailable(array $r,array $assets):float{ $assets=array_map('strtoupper',$assets); foreach($this->records($r) as $x){$a=strtoupper((string)($x['asset']??$x['currency']['code']??$x['currency_code']??''));if(!in_array($a,$assets,true))continue;if(array_key_exists('available',$x))return max(0,$this->num($x['available']));if(array_key_exists('free',$x))return max(0,$this->num($x['free']));return max(0,$this->num($x['balance']??0));}return 0; }
    private function currentPosition(PDO $p):?array{$r=$p->query("SELECT * FROM autotrade_positions WHERE status IN ('pending_open','open','pending_close') ORDER BY id DESC LIMIT 1")->fetch();return $r?:null;}
    private function dailyPnl(PDO $p,string $q):float{$s=$p->prepare("SELECT COALESCE(SUM(pnl),0) FROM autotrade_pnl WHERE quote_asset=:q AND created_at>=UTC_DATE()");$s->execute([':q'=>$q]);return(float)$s->fetchColumn();}
    private function killSwitch(PDO $p):bool{return(string)($p->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn()?:'0')==='1';}
    private function blocked(PDO $p,string $reason):array{$this->event($p,'warning','autotrade.blocked.'.$reason);return['status'=>'blocked','reason'=>$reason,'asset'=>'GRAM'];}
    private function storeSignal(PDO $p,array $m,array $s):int{$q=$p->prepare("INSERT INTO autotrade_signals (market_id,symbol,asset,quote_asset,action,score,price,details_json,executed,created_at) VALUES (:m,:s,'GRAM',:q,:a,:sc,:p,:d,0,UTC_TIMESTAMP())");$q->execute([':m'=>$m['market_id'],':s'=>$m['symbol'],':q'=>$m['quote_asset'],':a'=>$s['action']??'hold',':sc'=>$s['score']??0,':p'=>$m['price'],':d'=>json_encode($s,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);return(int)$p->lastInsertId();}
    private function markSignalExecuted(PDO $p,int $id,?string $local):void{$p->prepare('UPDATE autotrade_signals SET executed=1,order_local_id=:l WHERE id=:id')->execute([':l'=>$local,':id'=>$id]);}
    private function firstRecord(array $r):?array{if(isset($r['id'])&&(isset($r['state'])||isset($r['status'])))return$r;$x=$this->records($r);return$x[0]??null;}
    private function records(array $r):array{if(array_is_list($r))return array_values(array_filter($r,'is_array'));foreach(['results','data','items']as$k)if(isset($r[$k])&&is_array($r[$k])&&array_is_list($r[$k]))return array_values(array_filter($r[$k],'is_array'));return[];}
    private function fillPrice(array $o,float $f):float{foreach(['average_user_price','average_price','price']as$k){$v=$this->num($o[$k]??0);if($v>0)return$v;}return$f;}
    private function fillAmount(array $o,float $f):float{foreach(['exchanged1','dealed_base_amount','executed_amount','base_amount','fulfilled_amount','filled_amount','amount1']as$k){$v=$this->num($o[$k]??0);if($v>0)return$v;}return$f;}
    private function state(array $o):string{return strtolower(trim((string)($o['state']??$o['status']??'')));}
    private function isFilled(array $o):bool{if(in_array($this->state($o),['closed','filled','fully_filled','completed','done'],true))return true;$f=$this->num($o['dealed_base_amount']??$o['executed_amount']??$o['exchanged1']??$o['filled_amount']??0);$r=$this->num($o['remaining_amount']??$o['remain_amount']??-1);return$f>0&&$r===0.0;}
    private function isFailed(array $o):bool{return in_array($this->state($o),['cancelled','canceled','rejected','failed','expired'],true);}
    private function exchangeId(array $o):?string{$id=trim((string)($o['id']??$o['order_id']??''));return$id!==''?$id:null;}
    private function syncLocal(PDO $p,string $local,string $status,array $remote):void{if($local==='')return;$p->prepare('UPDATE orders SET status=:s,response_json=:r,updated_at=UTC_TIMESTAMP() WHERE local_id=:l')->execute([':s'=>$status,':r'=>json_encode($remote,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':l'=>$local]);}
    private function floorAmount(float $a,int $p):float{$p=max(0,min(18,$p));$f=10**$p;return floor($a*$f)/$f;}
    private function num(mixed $v):float{if(!is_numeric($v))return 0;$n=(float)$v;return is_finite($n)?$n:0;}
    private function event(PDO $p,string $level,string $event,array $c=[]):void{$p->prepare('INSERT INTO autotrade_events (level,event_name,context_json,created_at) VALUES (:l,:e,:c,UTC_TIMESTAMP())')->execute([':l'=>$level,':e'=>$event,':c'=>$c===[]?null:json_encode($c,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);}
    private function summary(string $status,array $m,array $s,array $extra=[]):array{return array_merge(['status'=>$status,'asset'=>'GRAM','exchange_asset'=>$m['exchange_asset'],'market'=>$m['symbol'],'market_id'=>$m['market_id'],'quote_asset'=>$m['quote_asset'],'price'=>$m['price'],'signal'=>['action'=>$s['action']??'hold','score'=>$s['score']??0,'ready'=>$s['ready']??false,'indicators'=>$s['indicators']??[]],'time_utc'=>gmdate(DATE_ATOM)],$extra);}
}
