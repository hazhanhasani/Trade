<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

/**
 * Performs at most one bounded reprice for automated pending BUY limits.
 * Replacement is allowed only after the previous remote order is confirmed
 * terminal with zero fill. The local position temporarily becomes `replacing`
 * so global exposure and duplicate-asset guards do not double count a true
 * replacement as a second position.
 */
final class NobitexExecutionRepriceService
{
    private static bool $ensured=false;

    public function reconcile(?PDO $pdo=null):array
    {
        $pdo??=Database::connection();$this->ensure($pdo);
        $after=$this->settingInt($pdo,'nobitex_execution_reprice_after_seconds',45,30,120);
        $rows=$pdo->query(
            "SELECT p.*,o.order_mode,o.request_json,o.source
             FROM nobitex_autotrade_positions p
             JOIN orders o ON o.local_id=p.entry_order_local_id
             WHERE p.status='pending_open' AND o.exchange_name='nobitex' AND o.order_mode='limit'
               AND o.source LIKE 'autotrade_nobitex%' AND p.updated_at <= (UTC_TIMESTAMP() - INTERVAL {$after} SECOND)
             ORDER BY p.id ASC LIMIT 3"
        )->fetchAll();
        if($rows===[])return['status'=>'idle','checked'=>0];

        $orders=new NobitexOrderService();$client=$orders->client();$scanner=new NobitexUniverseScanner();$planner=new NobitexExecutionPlanner();$results=[];
        foreach($rows as $p){
            $positionId=(int)$p['id'];
            $meta=$this->repriceMeta($pdo,$positionId,(string)($p['request_json']??''));
            if((int)$meta['reprice_count']>=(int)$meta['max_reprices']){$results[]=['position_id'=>$positionId,'status'=>'limit_reached'];continue;}
            $remoteId=trim((string)($p['entry_exchange_order_id']??''));$identifier=trim((string)($p['entry_identifier']??''));
            try{$response=$remoteId!==''?$client->orderStatus($remoteId):$client->orderStatus(null,$identifier);$remote=$orders->normalizedOrder($response);}catch(\Throwable $e){$results[]=['position_id'=>$positionId,'status'=>'status_error','error'=>mb_substr($e->getMessage(),0,180)];continue;}
            $state=strtolower(trim((string)($remote['status']??'')));$filled=$this->fillAmount($remote,0.0);
            if(in_array($state,['done','completed','filled'],true)||$filled>0){$results[]=['position_id'=>$positionId,'status'=>'already_filled_or_partial'];continue;}

            try{if($remoteId!=='')$client->cancelOrder($remoteId);else$client->cancelOrder(null,$identifier);}catch(\Throwable $e){$results[]=['position_id'=>$positionId,'status'=>'cancel_failed','error'=>mb_substr($e->getMessage(),0,180)];continue;}

            // Never replace on an assumed cancellation. A network/status failure
            // after the cancel request leaves the original pending row untouched;
            // generic reconciliation can safely retry on the next tick.
            try{$response=$remoteId!==''?$client->orderStatus($remoteId):$client->orderStatus(null,$identifier);$remote=$orders->normalizedOrder($response);}catch(\Throwable $e){$results[]=['position_id'=>$positionId,'status'=>'cancel_confirmation_failed','error'=>mb_substr($e->getMessage(),0,180)];continue;}
            $state=strtolower(trim((string)($remote['status']??'')));$filled=$this->fillAmount($remote,0.0);
            if($filled>0){$results[]=['position_id'=>$positionId,'status'=>'partial_after_cancel'];continue;}
            if(!in_array($state,['canceled','cancelled','rejected','failed'],true)){$results[]=['position_id'=>$positionId,'status'=>'cancel_pending'];continue;}

            try{$market=$scanner->snapshotSymbol($client,(string)$p['symbol']);$signal=is_array($market['signal']??null)?$market['signal']:[];}catch(\Throwable $e){$this->failPosition($pdo,$positionId);$results[]=['position_id'=>$positionId,'status'=>'market_refresh_failed','error'=>mb_substr($e->getMessage(),0,180)];continue;}
            $candidate=$p;$candidate['execution_reprice_count']=$meta['reprice_count'];$candidate['execution_max_reprices']=$meta['max_reprices'];$candidate['execution_hard_price_limit']=$meta['hard_price_limit'];
            $assessment=$planner->canReprice($candidate,$market,$signal,'buy');
            if(!($assessment['allowed']??false)){$this->failPosition($pdo,$positionId);$results[]=['position_id'=>$positionId,'status'=>'not_replaced','reason'=>$assessment['reason']??'reprice_blocked'];continue;}
            $plan=$assessment['plan'];$newIdentifier='nr'.gmdate('ymdHis').substr(bin2hex(random_bytes(4)),0,8);

            $claim=$pdo->prepare("UPDATE nobitex_autotrade_positions SET status='replacing',updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='pending_open'");
            $claim->execute([':id'=>$positionId]);
            if($claim->rowCount()!==1){$results[]=['position_id'=>$positionId,'status'=>'state_changed_before_reprice'];continue;}

            try{
                $request=json_decode((string)($p['request_json']??''),true);$entryContext=is_array($request)&&is_array($request['_trade_entry_context']??null)?$request['_trade_entry_context']:[];
                $created=$orders->create(['symbol'=>$p['symbol'],'amount1'=>(float)$p['amount'],'price'=>(float)$plan['limit_price'],'mode'=>'limit','type'=>'buy','identifier'=>$newIdentifier,'_trade_entry_context'=>$entryContext],'autotrade_nobitex_reprice');
                $newRemote=is_array($created['order']??null)?$created['order']:[];
                $stmt=$pdo->prepare("UPDATE nobitex_autotrade_positions SET status='pending_open',entry_identifier=:i,entry_order_local_id=:l,entry_exchange_order_id=:x,entry_price=:price,created_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='replacing'");
                $stmt->execute([':i'=>$newIdentifier,':l'=>$created['local_id']??null,':x'=>$this->exchangeId($newRemote),':price'=>(float)$plan['limit_price'],':id'=>$positionId]);
                if($stmt->rowCount()!==1)throw new \RuntimeException('Local reprice state could not be finalized.');
                $pdo->prepare('UPDATE nobitex_execution_reprices SET reprice_count=reprice_count+1,last_repriced_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE position_id=:id')->execute([':id'=>$positionId]);
                $results[]=['position_id'=>$positionId,'status'=>'replaced','plan'=>$plan,'local_id'=>$created['local_id']??null];
            }catch(\Throwable $e){$this->failPosition($pdo,$positionId,true);$results[]=['position_id'=>$positionId,'status'=>'replace_failed','error'=>mb_substr($e->getMessage(),0,180)];}
        }
        return['status'=>'processed','checked'=>count($rows),'results'=>$results];
    }

    private function repriceMeta(PDO $pdo,int $id,string $requestJson):array
    {
        $s=$pdo->prepare('SELECT reprice_count,max_reprices,hard_price_limit FROM nobitex_execution_reprices WHERE position_id=:id LIMIT 1');$s->execute([':id'=>$id]);$r=$s->fetch();if($r)return$r;
        $request=json_decode($requestJson,true);$plan=is_array($request)&&is_array($request['_trade_execution_plan']??null)?$request['_trade_execution_plan']:[];
        $max=max(0,min(1,(int)($plan['max_reprices']??0)));$hard=max(0.0,$this->number($plan['hard_price_limit']??0));
        if($max>0&&$hard>0.0){$stmt=$pdo->prepare("INSERT IGNORE INTO nobitex_execution_reprices (position_id,reprice_count,max_reprices,hard_price_limit,created_at,updated_at) VALUES (:id,0,:max,:hard,UTC_TIMESTAMP(),UTC_TIMESTAMP())");$stmt->execute([':id'=>$id,':max'=>$max,':hard'=>$hard]);}
        return['reprice_count'=>0,'max_reprices'=>$max,'hard_price_limit'=>$hard];
    }

    private function failPosition(PDO $pdo,int $id,bool $allowReplacing=false):void
    {
        $states=$allowReplacing?"('pending_open','replacing')":"('pending_open')";$pdo->prepare("UPDATE nobitex_autotrade_positions SET status='failed',updated_at=UTC_TIMESTAMP() WHERE id=:id AND status IN {$states}")->execute([':id'=>$id]);
    }
    private function fillAmount(array $order,float $fallback):float{return NobitexOrderFill::matchedAmount($order,$fallback);}
    private function exchangeId(array $order):?string{$id=trim((string)($order['id']??''));return$id!==''?$id:null;}
    private function number(mixed $v):float{return is_numeric($v)&&is_finite((float)$v)?(float)$v:0.0;}
    private function settingInt(PDO $pdo,string $key,int $default,int $min,int $max):int{$s=$pdo->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');$s->execute([':k'=>$key]);$v=$s->fetchColumn();return is_numeric($v)?max($min,min($max,(int)$v)):$default;}
    private function ensure(PDO $pdo):void{if(self::$ensured)return;$pdo->exec("CREATE TABLE IF NOT EXISTS nobitex_execution_reprices (position_id BIGINT UNSIGNED PRIMARY KEY,reprice_count TINYINT UNSIGNED NOT NULL DEFAULT 0,max_reprices TINYINT UNSIGNED NOT NULL DEFAULT 1,hard_price_limit DECIMAL(36,18) NOT NULL DEFAULT 0,last_repriced_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,CONSTRAINT fk_nobitex_reprice_position FOREIGN KEY(position_id) REFERENCES nobitex_autotrade_positions(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");self::$ensured=true;}
}
