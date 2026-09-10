<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

/**
 * Executes one explicitly-authorized bootstrap entry for the existing production
 * installation. It never enables trading, ignores no safety switch, and is
 * idempotent through a stable clientOrderId.
 */
final class NobitexFirstBuy
{
    public function __construct(
        private readonly NobitexOrderService $orders=new NobitexOrderService(),
        private readonly NobitexMarketScanner $scanner=new NobitexMarketScanner(),
        private readonly RiskManager $risk=new RiskManager(),
    ){}

    public function runIfPending():array
    {
        NobitexSchema::ensure();
        $pdo=Database::connection();
        if(!$this->boolSetting($pdo,'nobitex_bootstrap_first_buy_pending'))return['status'=>'not_pending','exchange'=>'nobitex'];
        if(!NobitexSchema::botEnabled('nobitex'))return$this->blocked($pdo,'bot_disabled');
        if($this->boolSetting($pdo,'kill_switch'))return$this->blocked($pdo,'kill_switch');
        if(!$this->orders->credentialsConfigured())return$this->blocked($pdo,'credentials_missing');
        if(!$this->orders->liveEnabled())return$this->blocked($pdo,'live_execution_disabled');

        $active=$pdo->query("SELECT * FROM nobitex_autotrade_positions WHERE status IN ('pending_open','open','pending_close') ORDER BY id DESC LIMIT 1")->fetch();
        if($active){
            $this->complete($pdo,'active_position_already_exists');
            return['status'=>'already_positioned','exchange'=>'nobitex','position_id'=>(int)$active['id']];
        }

        $clientOrderId=$this->setting($pdo,'nobitex_bootstrap_first_buy_id')?:'trade-first-v113';
        // If a prior HTTP response was lost after Nobitex accepted the order,
        // recover by clientOrderId instead of sending a duplicate order.
        try{
            $existing=$this->orders->normalizedOrder($this->orders->client()->orderStatus(null,$clientOrderId));
            if($this->looksLikeOrder($existing)){
                $position=$this->adoptRemoteOrder($pdo,$existing,$clientOrderId);
                $this->complete($pdo,'recovered_existing_order');
                return['status'=>'first_buy_recovered','exchange'=>'nobitex']+$position;
            }
        }catch(\Throwable){}

        $settings=array_merge(Schema::settings($pdo),$this->risk->normalizeSettings(Schema::settings($pdo)));
        $client=$this->orders->client();
        $market=$this->scanner->snapshot($client,'IRT');
        $wallets=$client->wallets();
        $base=$this->walletAvailable($wallets,['TON','GRAM','TONCOIN']);
        $quoteCodes=$market['quote_asset']==='IRT'?['RLS','IRT']:[(string)$market['quote_asset']];
        $quote=$this->walletAvailable($wallets,$quoteCodes);
        $portfolio=$quote+($base*(float)$market['price']);
        if($quote<=0)return$this->blocked($pdo,'no_quote_balance',['quote_asset'=>$market['quote_asset']]);

        $minOrder=max(0.0,(float)($market['min_order_quote']??0));
        $reference=(float)(($market['best_ask']??0)>0?$market['best_ask']:$market['price']);
        if($reference<=0)return$this->blocked($pdo,'market_price_unavailable');
        // Market orders receive a small price ceiling as Nobitex recommends.
        $priceCeiling=$reference*1.008;
        $desired=$quote*((float)$settings['position_percent']/100.0);
        $minimumWithMargin=$minOrder>0?$minOrder*1.02:0.0;
        $existingExposure=$base*(float)$market['price'];
        $maxAllowed=max(0.0,($portfolio*((float)$settings['max_position_percent']/100.0))-$existingExposure);
        $spendable=$quote*0.985;
        $target=min(max($desired,$minimumWithMargin),$maxAllowed,$spendable);

        if($minOrder>0&&$target+0.000001<$minOrder){
            return$this->blocked($pdo,'minimum_order_exceeds_risk_or_balance',[
                'quote_asset'=>$market['quote_asset'],'available_quote'=>$quote,'minimum_order'=>$minOrder,'max_allowed'=>$maxAllowed,
            ]);
        }

        $amount=$this->floorAmount($target/$priceCeiling,(int)$market['base_precision']);
        $orderValue=$amount*$priceCeiling;
        if($amount<=0||$orderValue<=0)return$this->blocked($pdo,'insufficient_balance');
        if($minOrder>0&&$orderValue+0.000001<$minOrder)return$this->blocked($pdo,'minimum_order_rounding',[
            'order_value'=>$orderValue,'minimum_order'=>$minOrder,'quote_asset'=>$market['quote_asset'],
        ]);

        $openCount=(int)$pdo->query("SELECT COUNT(*) FROM nobitex_autotrade_positions WHERE status IN ('pending_open','open','pending_close')")->fetchColumn();
        $decision=$this->risk->entryDecision($portfolio,$orderValue,$existingExposure,$this->dailyPnl($pdo,(string)$market['quote_asset']),$openCount,$this->setting($pdo,'nobitex_last_trade_at'),$settings);
        if(!$decision['allowed'])return$this->blocked($pdo,(string)$decision['reason'],$decision);

        $result=$this->orders->create([
            'symbol'=>$market['symbol'],
            'amount1'=>$amount,
            'price'=>$priceCeiling,
            'mode'=>'market',
            'type'=>'buy',
            'identifier'=>$clientOrderId,
        ],'autotrade_nobitex_first_buy');

        $remote=is_array($result['order']??null)?$result['order']:[];
        $position=$this->insertPosition($pdo,$market,$remote,$amount,$priceCeiling,$clientOrderId,$result['local_id']??null,$settings);
        $this->setSetting($pdo,'nobitex_last_trade_at',gmdate('Y-m-d H:i:s'));
        $this->complete($pdo,'submitted');
        $this->event($pdo,'info','nobitex.first_buy.submitted',[
            'position_id'=>$position['position_id'],'market'=>$market['symbol'],'quote_asset'=>$market['quote_asset'],'order_value_ceiling'=>$orderValue,'amount'=>$amount,
        ]);
        return[
            'status'=>'first_buy_submitted','exchange'=>'nobitex','market'=>$market['symbol'],'quote_asset'=>$market['quote_asset'],
            'amount'=>$amount,'price_ceiling'=>$priceCeiling,'order_value_ceiling'=>$orderValue,'minimum_order'=>$minOrder,
        ]+$position;
    }

    private function adoptRemoteOrder(PDO $pdo,array $remote,string $clientOrderId):array
    {
        $settings=array_merge(Schema::settings($pdo),$this->risk->normalizeSettings(Schema::settings($pdo)));
        $src=strtoupper((string)($remote['srcCurrency']??$remote['src_currency']??'TON'));
        $dst=strtoupper((string)($remote['dstCurrency']??$remote['dst_currency']??'RLS'));
        $quote=$dst==='RLS'?'IRT':$dst;
        $symbol=$src.($quote==='IRT'?'IRT':$quote);
        $market=['symbol'=>$symbol,'quote_asset'=>$quote,'price'=>$this->fillPrice($remote,0.0)];
        if($market['price']<=0)throw new \RuntimeException('Recovered Nobitex order has no usable price.');
        $requested=$this->num($remote['amount']??0);
        $position=$this->insertPosition($pdo,$market,$remote,$requested,$market['price'],$clientOrderId,null,$settings);
        $this->event($pdo,'warning','nobitex.first_buy.recovered',['position_id'=>$position['position_id'],'client_order_id'=>$clientOrderId]);
        return$position;
    }

    private function insertPosition(PDO $pdo,array $market,array $remote,float $requested,float $fallbackPrice,string $identifier,?string $localId,array $settings):array
    {
        $entry=$this->fillPrice($remote,$fallbackPrice);$filled=$this->fillAmount($remote,$requested);$done=$this->isDone($remote);$status=$done&&$filled>0?'open':'pending_open';
        $stmt=$pdo->prepare("INSERT INTO nobitex_autotrade_positions (symbol,asset,quote_asset,amount,entry_price,stop_loss,take_profit,status,entry_identifier,entry_order_local_id,entry_exchange_order_id,opened_at,created_at,updated_at) VALUES (:s,'GRAM',:q,:a,:e,:sl,:tp,:st,:i,:l,:x,:opened,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $stmt->execute([
            ':s'=>$market['symbol'],':q'=>$market['quote_asset'],':a'=>$filled>0?$filled:$requested,':e'=>$entry,
            ':sl'=>$this->risk->stopLoss($entry,(float)$settings['stop_loss_percent']),':tp'=>$this->risk->takeProfit($entry,(float)$settings['take_profit_percent']),
            ':st'=>$status,':i'=>$identifier,':l'=>$localId,':x'=>$this->exchangeId($remote),':opened'=>$status==='open'?gmdate('Y-m-d H:i:s'):null,
        ]);
        return['position_id'=>(int)$pdo->lastInsertId(),'position_status'=>$status,'exchange_order_id'=>$this->exchangeId($remote),'local_id'=>$localId];
    }

    private function complete(PDO $pdo,string $reason):void
    {
        $this->setSetting($pdo,'nobitex_bootstrap_first_buy_pending','0');
        $this->setSetting($pdo,'nobitex_bootstrap_first_buy_completed_at',gmdate('Y-m-d H:i:s'));
        $this->setSetting($pdo,'nobitex_bootstrap_first_buy_result',$reason);
    }

    private function blocked(PDO $pdo,string $reason,array $context=[]):array
    {
        $this->setSetting($pdo,'nobitex_bootstrap_first_buy_last_error',$reason);
        $this->event($pdo,'warning','nobitex.first_buy.blocked',array_merge(['reason'=>$reason],$context));
        return['status'=>'first_buy_blocked','exchange'=>'nobitex','reason'=>$reason]+$context;
    }

    private function walletAvailable(array $response,array $assets):float
    {
        $assets=array_map('strtoupper',$assets);$rows=$response['wallets']??$response['data']??$response;
        if(is_array($rows)&&!array_is_list($rows)&&is_array($rows['wallets']??null))$rows=$rows['wallets'];
        if(!is_array($rows))return 0.0;
        foreach($rows as$row){if(!is_array($row))continue;$asset=strtoupper((string)($row['currency']??$row['asset']??$row['currencyCode']??''));if(!in_array($asset,$assets,true))continue;foreach(['activeBalance','available','free','balance']as$key)if(array_key_exists($key,$row))return max(0.0,$this->num($row[$key]));}
        return 0.0;
    }

    private function dailyPnl(PDO $pdo,string $quote):float{$s=$pdo->prepare("SELECT COALESCE(SUM(pnl),0) FROM nobitex_autotrade_pnl WHERE quote_asset=:q AND created_at>=UTC_DATE()");$s->execute([':q'=>$quote]);return(float)$s->fetchColumn();}
    private function boolSetting(PDO $pdo,string $key):bool{return in_array(strtolower(trim((string)($this->setting($pdo,$key)??'0'))),['1','true','yes','on'],true);}
    private function setting(PDO $pdo,string $key):?string{$s=$pdo->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');$s->execute([':k'=>$key]);$v=$s->fetchColumn();return$v===false?null:(string)$v;}
    private function setSetting(PDO $pdo,string $key,string $value):void{$s=$pdo->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES (:k,:v,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");$s->execute([':k'=>$key,':v'=>$value]);}
    private function floorAmount(float $amount,int $precision):float{$precision=max(0,min(18,$precision));$f=10**$precision;return floor($amount*$f)/$f;}
    private function fillPrice(array $o,float $fallback):float{foreach(['averagePrice','average_price','price']as$k){$v=$this->num($o[$k]??0);if($v>0)return$v;}return$fallback;}
    private function fillAmount(array $o,float $fallback):float{foreach(['matchedAmount','matched_amount','filledAmount','amount']as$k){$v=$this->num($o[$k]??0);if($v>0)return$v;}return$fallback;}
    private function isDone(array $o):bool{return in_array(strtolower(trim((string)($o['status']??''))),['done','completed','filled'],true);}
    private function exchangeId(array $o):?string{$id=trim((string)($o['id']??''));return$id!==''?$id:null;}
    private function looksLikeOrder(array $o):bool{return trim((string)($o['id']??$o['clientOrderId']??''))!==''&&strtolower((string)($o['status']??''))!=='failed';}
    private function num(mixed $v):float{if(!is_numeric($v))return 0.0;$n=(float)$v;return is_finite($n)?$n:0.0;}
    private function event(PDO $pdo,string $level,string $event,array $context=[]):void{$pdo->prepare('INSERT INTO nobitex_autotrade_events (level,event_name,context_json,created_at) VALUES (:l,:e,:c,UTC_TIMESTAMP())')->execute([':l'=>$level,':e'=>$event,':c'=>$context===[]?null:json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);}
}
