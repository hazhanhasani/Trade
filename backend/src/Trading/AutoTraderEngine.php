<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;
use Trade\Exchange\BitpinClient;

/**
 * Multi-asset, score-free Bitpin live engine.
 *
 * Any executable IRT/USDT market can become a position when its expected net
 * edge is positive after execution costs. Capital/risk/order-state constraints
 * remain independent safety controls and are not asset scores.
 */
final class AutoTraderEngine
{
    public function __construct(
        private readonly OrderService $orders = new OrderService(),
        private readonly MarketScanner $scanner = new MarketScanner(),
        private readonly RiskManager $risk = new RiskManager(),
    ) {}

    public function run(): array
    {
        Schema::ensure();
        MarketScanner::resetProcessCache();
        $pdo=Database::connection();
        if((int)$pdo->query("SELECT GET_LOCK('trade_autotrade_live_v2',0)")->fetchColumn()!==1){
            return['status'=>'skipped','exchange'=>'bitpin','reason'=>'another_autotrade_run_is_active'];
        }
        try{return$this->runLocked($pdo);}finally{try{$pdo->query("SELECT RELEASE_LOCK('trade_autotrade_live_v2')");}catch(\Throwable){}}
    }

    private function runLocked(PDO $pdo):array
    {
        $raw=Schema::settings($pdo);$settings=array_merge($raw,$this->risk->normalizeSettings($raw));
        if(!(bool)$settings['enabled'])return['status'=>'disabled','exchange'=>'bitpin'];
        if($this->killSwitch($pdo))return$this->blocked($pdo,'kill_switch');
        if(!$this->orders->liveEnabled())return$this->blocked($pdo,'live_execution_disabled');

        $client=$this->orders->client();
        $this->reconcile($pdo,$client,$settings);
        $wallets=$this->spotWallets($client);$this->orders->syncTokens($client);
        $positions=$this->activePositions($pdo);

        // Manage every existing asset by its own market, not by a fixed GRAM/TON snapshot.
        foreach($positions as$position){
            if((string)$position['status']!=='open')continue;
            try{$market=$this->scanner->snapshotSymbol($client,(string)$position['symbol']);}
            catch(\Throwable$e){$this->event($pdo,'warning','autotrade.position.scan_failed',['position_id'=>$position['id'],'symbol'=>$position['symbol'],'error'=>$e->getMessage()]);continue;}
            $signal=is_array($market['signal']??null)?$market['signal']:['ready'=>false,'action'=>'hold'];
            $signalId=$this->storeSignal($pdo,$market,$signal);$reason=$this->risk->exitReason((float)$market['price'],$position,(string)($signal['action']??'hold'));
            if($reason===null)continue;
            $base=$this->walletAvailable($wallets,[(string)$position['asset']]);$amount=$this->floorAmount(min((float)$position['amount'],$base),(int)$market['base_precision']);
            if($amount<=0){$this->event($pdo,'warning','autotrade.exit.no_balance',['position_id'=>$position['id'],'asset'=>$position['asset']]);continue;}
            $result=$this->submitExit($pdo,$position,$market,$amount,$reason);$this->markSignalExecuted($pdo,$signalId,$result['local_id']??null);
            return$this->summary('sell_submitted',$market,$signal,$result+['exit_reason'=>$reason,'active_positions'=>count($positions)]);
        }

        if($this->hasPendingPosition($positions))return['status'=>'waiting_order','exchange'=>'bitpin','active_positions'=>count($positions),'decision_model'=>'positive_expected_net_profit'];
        $maxPositions=$this->settingInt($pdo,'bitpin_max_positions',8,1,30);
        if(count($positions)>=$maxPositions)return['status'=>'portfolio_full','exchange'=>'bitpin','active_positions'=>count($positions),'max_positions'=>$maxPositions,'decision_model'=>'positive_expected_net_profit'];

        $irt=$this->walletAvailable($wallets,['IRT','RLS']);$usdt=$this->walletAvailable($wallets,['USDT']);
        if($irt<=0&&$usdt<=0)return$this->blocked($pdo,'no_quote_balance');
        $preferred=$irt>0?'IRT':'USDT';$candidates=$this->scanner->rankedCandidates($client,$preferred);
        if($candidates===[])return['status'=>'no_trade','exchange'=>'bitpin','reason'=>'no_eligible_markets','decision_model'=>'positive_expected_net_profit'];

        $activeSymbols=[];foreach($positions as$p)$activeSymbols[strtoupper((string)$p['symbol'])]=true;
        $rejections=[];
        foreach($candidates as$market){
            $symbol=strtoupper((string)$market['symbol']);if(isset($activeSymbols[$symbol]))continue;
            $signal=is_array($market['signal']??null)?$market['signal']:['ready'=>false,'action'=>'hold'];$signalId=$this->storeSignal($pdo,$market,$signal);
            if(!($signal['ready']??false)||(string)($signal['action']??'hold')!=='buy'){$rejections[]=['symbol'=>$symbol,'reason'=>$signal['reason']??'no_buy_signal'];continue;}

            $quoteAsset=(string)$market['quote_asset'];$quoteAvailable=$quoteAsset==='IRT'?$irt:$usdt;if($quoteAvailable<=0){$rejections[]=['symbol'=>$symbol,'reason'=>'no_quote_balance'];continue;}
            $exposure=$this->managedExposure($positions,$quoteAsset);$portfolio=$quoteAvailable+$exposure;$dailyPnl=$this->dailyPnl($pdo,$quoteAsset);
            $budget=$this->entryBudget($pdo,$market,$settings,$quoteAvailable,$portfolio,$exposure,$dailyPnl);
            if($budget<=0){$rejections[]=['symbol'=>$symbol,'reason'=>'risk_or_capital_block'];continue;}

            $reference=(float)(($market['best_ask']??0)>0?$market['best_ask']:$market['price']);
            $amount=$this->floorAmount($budget/max($reference,0.000000000001),(int)$market['base_precision']);
            if($amount<=0){$rejections[]=['symbol'=>$symbol,'reason'=>'minimum_order_rounding'];continue;}

            $result=$this->submitEntry($pdo,$market,$amount,$settings);$this->markSignalExecuted($pdo,$signalId,$result['local_id']??null);$this->setSymbolCooldown($pdo,$symbol);
            return$this->summary('buy_submitted',$market,$signal,$result+['active_positions_before'=>count($positions),'max_positions'=>$maxPositions]);
        }

        return['status'=>'no_trade','exchange'=>'bitpin','reason'=>'no_candidate_passed_profit_and_risk_filters','decision_model'=>'positive_expected_net_profit','score_based_selection'=>false,'active_positions'=>count($positions),'rejections'=>array_slice($rejections,0,20)];
    }

    private function entryBudget(PDO $pdo,array $market,array $settings,float $available,float $portfolio,float $exposure,float $dailyPnl):float
    {
        if($available<=0||$portfolio<=0)return 0.0;
        $maxDaily=$portfolio*((float)$settings['daily_loss_limit_percent']/100.0);if($dailyPnl<=-$maxDaily)return 0.0;
        $symbol=(string)$market['symbol'];if($this->symbolCooldownActive($pdo,$symbol,(int)$settings['cooldown_minutes']))return 0.0;
        $perTrade=$portfolio*((float)$settings['position_percent']/100.0);$maxPosition=$portfolio*((float)$settings['max_position_percent']/100.0);$budget=min($available,$perTrade,$maxPosition);
        $maxExposurePercent=$this->settingFloat($pdo,'bitpin_portfolio_exposure_percent',60.0,10.0,95.0);$remaining=max(0.0,($portfolio*($maxExposurePercent/100.0))-$exposure);
        return max(0.0,min($budget,$remaining));
    }

    private function submitEntry(PDO $pdo,array $market,float $amount,array $settings):array
    {
        $identifier='auto-buy-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4));
        $result=$this->orders->create(['symbol'=>$market['symbol'],'base_amount'=>$amount,'reference_price'=>(float)$market['price'],'order_type'=>'market','side'=>'buy','identifier'=>$identifier],'autotrade');
        $remote=is_array($result['exchange']??null)?$result['exchange']:[];$entry=$this->fillPrice($remote,(float)$market['price']);$filled=$this->fillAmount($remote,$amount);$status=$this->isFilled($remote)&&$filled>0?'open':'pending_open';
        $stmt=$pdo->prepare("INSERT INTO autotrade_positions(market_id,symbol,asset,quote_asset,amount,entry_price,stop_loss,take_profit,status,entry_identifier,entry_order_local_id,entry_exchange_order_id,opened_at,created_at,updated_at)VALUES(:m,:s,:asset,:q,:a,:e,:sl,:tp,:st,:i,:l,:x,:o,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $stmt->execute([':m'=>$market['market_id'],':s'=>$market['symbol'],':asset'=>$market['asset'],':q'=>$market['quote_asset'],':a'=>$filled>0?$filled:$amount,':e'=>$entry,':sl'=>$this->risk->stopLoss($entry,(float)$settings['stop_loss_percent']),':tp'=>$this->risk->takeProfit($entry,(float)$settings['take_profit_percent']),':st'=>$status,':i'=>$identifier,':l'=>$result['local_id']??null,':x'=>$this->exchangeId($remote),':o'=>$status==='open'?gmdate('Y-m-d H:i:s'):null]);
        $pdo->exec('UPDATE autotrade_settings SET last_trade_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=1');$id=(int)$pdo->lastInsertId();$this->event($pdo,'info','autotrade.buy.submitted',['position_id'=>$id,'symbol'=>$market['symbol'],'amount'=>$amount,'price'=>$market['price']]);
        return['position_id'=>$id,'local_id'=>$result['local_id']??null,'exchange_order_id'=>$this->exchangeId($remote),'amount'=>$amount,'position_status'=>$status];
    }

    private function submitExit(PDO $pdo,array $position,array $market,float $amount,string $reason):array
    {
        $identifier='auto-sell-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4));$result=$this->orders->create(['symbol'=>$market['symbol'],'base_amount'=>$amount,'reference_price'=>(float)$market['price'],'order_type'=>'market','side'=>'sell','identifier'=>$identifier],'autotrade');$remote=is_array($result['exchange']??null)?$result['exchange']:[];
        $pdo->prepare("UPDATE autotrade_positions SET status='pending_close',exit_identifier=:i,exit_order_local_id=:l,exit_exchange_order_id=:x,updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='open'")->execute([':i'=>$identifier,':l'=>$result['local_id']??null,':x'=>$this->exchangeId($remote),':id'=>$position['id']]);$pdo->exec('UPDATE autotrade_settings SET last_trade_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=1');if($this->isFilled($remote))$this->closePosition($pdo,(int)$position['id'],$remote,(float)$market['price']);$this->event($pdo,'info','autotrade.sell.submitted',['position_id'=>$position['id'],'symbol'=>$market['symbol'],'reason'=>$reason,'amount'=>$amount]);return['position_id'=>(int)$position['id'],'local_id'=>$result['local_id']??null,'exchange_order_id'=>$this->exchangeId($remote),'amount'=>$amount];
    }

    private function reconcile(PDO $pdo,BitpinClient $client,array $settings):void
    {
        $rows=$pdo->query("SELECT * FROM autotrade_positions WHERE status IN ('pending_open','pending_close') ORDER BY id ASC LIMIT 40")->fetchAll();
        foreach($rows as$p){$identifier=$p['status']==='pending_open'?(string)$p['entry_identifier']:(string)$p['exit_identifier'];if($identifier==='')continue;try{$resp=$client->orders(['identifier'=>$identifier,'page'=>1]);}catch(\Throwable$e){$this->event($pdo,'warning','autotrade.reconcile.error',['position_id'=>$p['id'],'error'=>$e->getMessage()]);continue;}$order=$this->firstRecord($resp);if(!$order)continue;$local=(string)($p['status']==='pending_open'?($p['entry_order_local_id']??''):($p['exit_order_local_id']??''));
            if($this->isFailed($order)){$this->syncLocal($pdo,$local,'failed',$order);if($p['status']==='pending_open')$pdo->prepare("UPDATE autotrade_positions SET status='failed',updated_at=UTC_TIMESTAMP() WHERE id=:id")->execute([':id'=>$p['id']]);else$pdo->prepare("UPDATE autotrade_positions SET status='open',exit_identifier=NULL,exit_order_local_id=NULL,exit_exchange_order_id=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id")->execute([':id'=>$p['id']]);continue;}
            if(!$this->isFilled($order))continue;$this->syncLocal($pdo,$local,'filled',$order);
            if($p['status']==='pending_open'){$price=$this->fillPrice($order,(float)$p['entry_price']);$amount=$this->fillAmount($order,(float)$p['amount']);$pdo->prepare("UPDATE autotrade_positions SET status='open',amount=:a,entry_price=:e,stop_loss=:sl,take_profit=:tp,entry_exchange_order_id=:x,opened_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id")->execute([':a'=>$amount,':e'=>$price,':sl'=>$this->risk->stopLoss($price,(float)$settings['stop_loss_percent']),':tp'=>$this->risk->takeProfit($price,(float)$settings['take_profit_percent']),':x'=>$this->exchangeId($order),':id'=>$p['id']]);}else$this->closePosition($pdo,(int)$p['id'],$order,(float)$p['entry_price']);
        }$this->orders->syncTokens($client);
    }

    private function closePosition(PDO $pdo,int $id,array $order,float $fallback):void
    {
        $s=$pdo->prepare('SELECT * FROM autotrade_positions WHERE id=:id LIMIT 1');$s->execute([':id'=>$id]);$p=$s->fetch();if(!$p||$p['status']==='closed')return;$exit=$this->fillPrice($order,$fallback);$amount=min((float)$p['amount'],max(0.0,$this->fillAmount($order,(float)$p['amount'])));if($amount<=0)$amount=(float)$p['amount'];$entry=(float)$p['entry_price'];$pnl=($exit-$entry)*$amount;$pct=$entry>0?(($exit-$entry)/$entry)*100:0;
        Database::transaction(function(PDO$tx)use($id,$p,$exit,$amount,$entry,$pnl,$pct,$order):void{$tx->prepare("UPDATE autotrade_positions SET status='closed',exit_price=:e,realized_pnl=:p,exit_exchange_order_id=:x,closed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id")->execute([':e'=>$exit,':p'=>$pnl,':x'=>$this->exchangeId($order),':id'=>$id]);$tx->prepare("INSERT INTO autotrade_pnl(position_id,pnl,pnl_percent,quote_asset,entry_price,exit_price,amount,created_at)VALUES(:id,:p,:pct,:q,:en,:ex,:a,UTC_TIMESTAMP())")->execute([':id'=>$id,':p'=>$pnl,':pct'=>$pct,':q'=>$p['quote_asset'],':en'=>$entry,':ex'=>$exit,':a'=>$amount]);});$this->event($pdo,'info','autotrade.position.closed',['position_id'=>$id,'symbol'=>$p['symbol'],'pnl'=>$pnl,'pnl_percent'=>$pct]);
    }

    private function activePositions(PDO $pdo):array{return$pdo->query("SELECT * FROM autotrade_positions WHERE status IN ('pending_open','open','pending_close') ORDER BY id ASC")->fetchAll();}
    private function hasPendingPosition(array $positions):bool{foreach($positions as$p)if(in_array((string)$p['status'],['pending_open','pending_close'],true))return true;return false;}
    private function managedExposure(array $positions,string $quote):float{$sum=0.0;foreach($positions as$p)if((string)$p['quote_asset']===$quote)$sum+=(float)$p['amount']*(float)$p['entry_price'];return$sum;}
    private function spotWallets(BitpinClient $c):array{try{return$c->wallets(['service'=>'spot','limit'=>250]);}catch(\Throwable){return$c->wallets();}}
    private function walletAvailable(array $r,array $assets):float{$assets=array_map('strtoupper',$assets);foreach($this->records($r)as$x){$a=strtoupper((string)($x['asset']??$x['currency']['code']??$x['currency_code']??''));if(!in_array($a,$assets,true))continue;if(array_key_exists('available',$x))return max(0,$this->num($x['available']));if(array_key_exists('free',$x))return max(0,$this->num($x['free']));return max(0,$this->num($x['balance']??0));}return 0;}
    private function dailyPnl(PDO $p,string $q):float{$s=$p->prepare("SELECT COALESCE(SUM(pnl),0) FROM autotrade_pnl WHERE quote_asset=:q AND created_at>=UTC_DATE()");$s->execute([':q'=>$q]);return(float)$s->fetchColumn();}
    private function symbolCooldownActive(PDO $pdo,string $symbol,int $minutes):bool{$key='bitpin_cooldown_'.substr(sha1($symbol),0,20);$s=$pdo->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');$s->execute([':k'=>$key]);$v=trim((string)($s->fetchColumn()?:''));if($v==='')return false;$t=strtotime($v.' UTC');return$t!==false&&time()<$t+($minutes*60);}
    private function setSymbolCooldown(PDO $pdo,string $symbol):void{$key='bitpin_cooldown_'.substr(sha1($symbol),0,20);$s=$pdo->prepare("INSERT INTO settings(key_name,value_text,updated_at)VALUES(:k,:v,UTC_TIMESTAMP())ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");$s->execute([':k'=>$key,':v'=>gmdate('Y-m-d H:i:s')]);}
    private function settingInt(PDO $pdo,string $key,int $default,int $min,int $max):int{$s=$pdo->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');$s->execute([':k'=>$key]);$v=$s->fetchColumn();return max($min,min($max,is_numeric($v)?(int)$v:$default));}
    private function settingFloat(PDO $pdo,string $key,float $default,float $min,float $max):float{$s=$pdo->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');$s->execute([':k'=>$key]);$v=$s->fetchColumn();return max($min,min($max,is_numeric($v)?(float)$v:$default));}
    private function killSwitch(PDO $p):bool{return(string)($p->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn()?:'0')==='1';}
    private function blocked(PDO $p,string $reason):array{$this->event($p,'warning','autotrade.blocked.'.$reason);return['status'=>'blocked','exchange'=>'bitpin','reason'=>$reason,'decision_model'=>'positive_expected_net_profit'];}
    private function storeSignal(PDO $p,array $m,array $s):int{$q=$p->prepare("INSERT INTO autotrade_signals(market_id,symbol,asset,quote_asset,action,score,price,details_json,executed,created_at)VALUES(:m,:s,:asset,:q,:a,0,:p,:d,0,UTC_TIMESTAMP())");$q->execute([':m'=>$m['market_id'],':s'=>$m['symbol'],':asset'=>$m['asset'],':q'=>$m['quote_asset'],':a'=>$s['action']??'hold',':p'=>$m['price'],':d'=>json_encode($s,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);return(int)$p->lastInsertId();}
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
    private function event(PDO $p,string $level,string $event,array $c=[]):void{$p->prepare('INSERT INTO autotrade_events(level,event_name,context_json,created_at)VALUES(:l,:e,:c,UTC_TIMESTAMP())')->execute([':l'=>$level,':e'=>$event,':c'=>$c===[]?null:json_encode($c,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);}
    private function summary(string $status,array $m,array $s,array $extra=[]):array{return array_merge(['status'=>$status,'exchange'=>'bitpin','asset'=>$m['asset'],'exchange_asset'=>$m['exchange_asset'],'market'=>$m['symbol'],'market_id'=>$m['market_id'],'quote_asset'=>$m['quote_asset'],'price'=>$m['price'],'decision_model'=>'positive_expected_net_profit','score_based_selection'=>false,'signal'=>['action'=>$s['action']??'hold','ready'=>$s['ready']??false,'expected_net_edge_percent'=>$s['expected_net_edge_percent']??null,'estimated_roundtrip_cost_percent'=>$s['estimated_roundtrip_cost_percent']??null,'confidence'=>$s['confidence']??0,'indicators'=>$s['indicators']??[]],'time_utc'=>gmdate(DATE_ATOM)],$extra);}
}
