<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

/** Reduction-only emergency executor. It never submits BUY orders. */
final class NobitexEmergencyController
{
    public function enforce(?PDO $pdo=null):array
    {
        $pdo??=Database::connection();NobitexSchema::ensure();
        $mode=$this->mode($pdo);
        if($mode!=='graceful_close')return['status'=>'idle','mode'=>$mode];
        $orders=new NobitexOrderService();
        if(!$orders->credentialsConfigured()||!$orders->liveEnabled())return['status'=>'blocked','mode'=>$mode,'reason'=>'live_execution_unavailable'];

        $pending=(int)$pdo->query("SELECT COUNT(*) FROM nobitex_autotrade_positions WHERE status IN ('pending_open','pending_close')")->fetchColumn();
        if($pending>0)return['status'=>'waiting_reconcile','mode'=>$mode,'pending_positions'=>$pending];
        $rows=$pdo->query("SELECT * FROM nobitex_autotrade_positions WHERE status='open' ORDER BY id ASC LIMIT 30")->fetchAll();
        if($rows===[]){
            $this->setSetting($pdo,'trade_emergency_mode','pause_buys');
            $this->event($pdo,'info','trade.emergency.graceful_close_complete',['remaining'=>0]);
            return['status'=>'complete','mode'=>'pause_buys','closed_all'=>true];
        }

        $client=$orders->client();$wallets=$client->wallets();$scanner=new NobitexUniverseScanner();$candidates=[];
        foreach($rows as$row){
            try{$market=$scanner->snapshotSymbol($client,(string)$row['symbol'],60);}catch(\Throwable){continue;}
            $entry=max(0.0,(float)$row['entry_price']);$mark=max(0.0,(float)($market['price']??0));
            $netPct=is_numeric($row['unrealized_net_pnl_percent']??null)?(float)$row['unrealized_net_pnl_percent']:($entry>0&&$mark>0?(($mark-$entry)/$entry)*100:0.0);
            $row['_market']=$market;$row['_net_pct']=$netPct;
            $row['_score']=NobitexCapacityManager::victimScore([
                'forward_edge_percent'=>$this->edge((array)($market['signal']??[])),
                'unrealized_net_pnl_percent'=>$netPct,
                'spread_percent'=>(float)($market['spread_percent']??0),
                'estimated_exit_cost_percent'=>(float)($market['signal']['estimated_exit_cost_percent']??0),
                'volatility_percent'=>(float)($market['signal']['indicators']['volatility_percent']??0),
                'depth_quote'=>(float)($market['depth_quote']??0),
                'opened_at'=>$row['opened_at']??null,
            ]);
            $candidates[]=$row;
        }
        if($candidates===[])return['status'=>'deferred','mode'=>$mode,'reason'=>'no_scannable_position'];
        usort($candidates,static fn(array$a,array$b):int=>((float)$a['_score'])<=>((float)$b['_score']));$victim=$candidates[0];$market=$victim['_market'];
        $asset=strtoupper((string)$victim['asset']);$available=$this->walletAvailable($wallets,$asset);$amount=min(max(0.0,(float)$victim['amount']),$available);
        $precision=max(0,min(18,(int)($market['base_precision']??8)));$factor=10**$precision;$amount=floor($amount*$factor)/$factor;
        if($amount<=0)return['status'=>'deferred','mode'=>$mode,'reason'=>'balance_unavailable','position_id'=>(int)$victim['id']];
        $reference=(float)(($market['best_bid']??0)>0?$market['best_bid']:($market['price']??0));if($reference<=0)return['status'=>'deferred','mode'=>$mode,'reason'=>'price_unavailable'];
        $buffer=NobitexCapacityManager::dynamicExitBufferPercent($market,$victim);$bound=max(0.00000001,$reference*(1-$buffer/100));
        $identifier='emg'.(int)$victim['id'].'a'.$this->attempt($pdo,(int)$victim['id']);
        $created=$orders->create(['symbol'=>(string)$victim['symbol'],'amount1'=>$amount,'price'=>$bound,'mode'=>'market','type'=>'sell','identifier'=>substr($identifier,0,32)],'autotrade_nobitex_emergency_close');
        $remote=is_array($created['order']??null)?$created['order']:[];$exchangeId=trim((string)($remote['id']??''));
        $stmt=$pdo->prepare("UPDATE nobitex_autotrade_positions SET status='pending_close',exit_identifier=:i,exit_order_local_id=:l,exit_exchange_order_id=:x,updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='open'");
        $stmt->execute([':i'=>substr($identifier,0,32),':l'=>$created['local_id']??null,':x'=>$exchangeId!==''?$exchangeId:null,':id'=>(int)$victim['id']]);
        $this->event($pdo,'warning','trade.emergency.graceful_sell_submitted',['position_id'=>(int)$victim['id'],'symbol'=>$victim['symbol'],'amount'=>$amount,'reference'=>$reference,'price_bound'=>$bound,'buffer_percent'=>$buffer,'remaining_before'=>count($rows)]);
        return['status'=>'sell_submitted','mode'=>$mode,'position_id'=>(int)$victim['id'],'symbol'=>$victim['symbol'],'amount'=>$amount,'remaining_before'=>count($rows),'remaining_after_submit'=>max(0,count($rows)-1),'order'=>$created];
    }

    private function mode(PDO$pdo):string{$m=strtolower(trim((string)($this->setting($pdo,'trade_emergency_mode')??'normal')));return in_array($m,['normal','pause_buys','graceful_close','full_stop'],true)?$m:'normal';}
    private function edge(array$s):float{foreach(['tradable_net_edge_percent','effective_tradable_net_edge_percent','expected_net_edge_percent']as$k)if(is_numeric($s[$k]??null))return(float)$s[$k];return 0.0;}
    private function attempt(PDO$pdo,int$id):int{$s=$pdo->prepare("SELECT COUNT(*) FROM orders WHERE exchange_name='nobitex' AND source='autotrade_nobitex_emergency_close' AND identifier LIKE :p");$s->execute([':p'=>'emg'.$id.'a%']);return max(1,(int)$s->fetchColumn()+1);}
    private function walletAvailable(array$response,string$asset):float{$aliases=$asset==='GRAM'||$asset==='TONCOIN'?['TON','GRAM','TONCOIN']:[$asset];$rows=$response['wallets']??$response['data']??$response;if(is_array($rows)&&!array_is_list($rows)&&is_array($rows['wallets']??null))$rows=$rows['wallets'];if(!is_array($rows))return 0.0;foreach($rows as$row){if(!is_array($row))continue;$code=strtoupper((string)($row['currency']??$row['asset']??$row['currencyCode']??''));if(!in_array($code,$aliases,true))continue;foreach(['activeBalance','available','free','balance']as$k)if(is_numeric($row[$k]??null))return max(0.0,(float)$row[$k]);}return 0.0;}
    private function setting(PDO$pdo,string$key):?string{$s=$pdo->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');$s->execute([':k'=>$key]);$v=$s->fetchColumn();return$v===false?null:(string)$v;}
    private function setSetting(PDO$pdo,string$key,string$value):void{$s=$pdo->prepare("INSERT INTO settings(key_name,value_text,updated_at)VALUES(:k,:v,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");$s->execute([':k'=>$key,':v'=>$value]);}
    private function event(PDO$pdo,string$level,string$name,array$ctx):void{$pdo->prepare('INSERT INTO nobitex_autotrade_events(level,event_name,context_json,created_at)VALUES(:l,:n,:c,UTC_TIMESTAMP())')->execute([':l'=>$level,':n'=>$name,':c'=>json_encode($ctx,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);}
}
