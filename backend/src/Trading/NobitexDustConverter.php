<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;
use Trade\Observability\ErrorReporter;
use Trade\Support\IranClock;

/**
 * Converts only Trade-owned residual/dust balances to IRT/Toman.
 *
 * The old converter scanned every idle wallet asset, which could accidentally
 * touch a user's manually held coin. v2 is deliberately narrower: it only acts
 * on rows that Trade itself has already classified as status=dust. The row is
 * moved to dust_converting while an exchange order is pending, so neither the
 * residual manager nor another cron can submit a duplicate SELL.
 */
final class NobitexDustConverter
{
    public const MODEL = 'nobitex_managed_dust_to_toman_v2';
    private const ENABLED_KEY = 'nobitex_managed_dust_auto_convert';
    private const MAX_TOMAN_KEY = 'nobitex_dust_max_toman';
    private const MIN_TOMAN_KEY = 'nobitex_dust_min_toman';
    private const COOLDOWN_KEY = 'nobitex_dust_cooldown_hours';
    private const LAST_RUN_KEY = 'nobitex_dust_last_run_utc';
    private const EPS = 0.000000000001;
    private const MAX_CONVERSIONS_PER_RUN = 3;

    public function status(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        return [
            'model'=>self::MODEL,
            'enabled'=>$this->boolSetting($pdo,self::ENABLED_KEY,true),
            'min_toman'=>$this->numberSetting($pdo,self::MIN_TOMAN_KEY,0.0,0.0,500000.0),
            'max_toman'=>$this->numberSetting($pdo,self::MAX_TOMAN_KEY,500000.0,1000.0,5000000.0),
            // Kept for backwards-compatible settings/UI display. Managed dust is
            // checked every cron because pending orders are idempotently tracked.
            'cooldown_hours'=>(int)$this->numberSetting($pdo,self::COOLDOWN_KEY,1,1,168),
            'last_run_utc'=>$this->setting($pdo,self::LAST_RUN_KEY),
            'safety'=>'trade_owned_dust_only_to_irt_no_user_idle_assets_max_3_per_run',
        ];
    }

    public function configure(bool $enabled, float $minToman, float $maxToman, int $cooldownHours = 1, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $minToman=max(0.0,min(500000.0,$minToman));
        $maxToman=max(1000.0,min(5000000.0,$maxToman));
        if($minToman>=$maxToman)throw new \InvalidArgumentException('حداقل دارایی خرد باید از حداکثر کمتر باشد.');
        $cooldownHours=max(1,min(168,$cooldownHours));
        $this->write($pdo,self::ENABLED_KEY,$enabled?'1':'0');
        $this->write($pdo,self::MIN_TOMAN_KEY,(string)$minToman);
        $this->write($pdo,self::MAX_TOMAN_KEY,(string)$maxToman);
        $this->write($pdo,self::COOLDOWN_KEY,(string)$cooldownHours);
        return $this->status($pdo);
    }

    public function runIfDue(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        // Managed dust is safe to inspect every tick. The order/row reservation
        // below is the real duplicate guard; a wall-clock cooldown would leave a
        // visible leftover coin in the wallet for hours after a normal SELL.
        return $this->run($pdo);
    }

    public function run(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $status=$this->status($pdo);
        if(!$status['enabled'])return['status'=>'disabled']+$status;
        $kill=(string)($pdo->query("SELECT value_text FROM settings WHERE key_name='kill_switch' LIMIT 1")->fetchColumn()?:'0');
        if($kill==='1')return['status'=>'blocked','reason'=>'kill_switch']+$status;
        if(!NobitexSchema::botEnabled('nobitex'))return['status'=>'blocked','reason'=>'nobitex_bot_disabled']+$status;

        $service=new NobitexOrderService();
        if(!$service->credentialsConfigured())return['status'=>'blocked','reason'=>'nobitex_credentials_missing']+$status;
        if(!$service->liveEnabled())return['status'=>'blocked','reason'=>'nobitex_live_disabled']+$status;
        $client=$service->client();

        $pending=$this->reconcilePendingConversions($pdo,$service,$client);
        $groups=$this->managedDustGroups($pdo);
        if($groups===[]){
            $this->write($pdo,self::LAST_RUN_KEY,gmdate('Y-m-d H:i:s'));
            return ['status'=>'completed','model'=>self::MODEL,'converted'=>[],'skipped'=>[],'failed'=>[],'pending_reconciliation'=>$pending,'managed_dust_groups'=>0,'time_iran'=>IranClock::nowPayload()];
        }

        $walletResponse=$client->wallets();
        $books=$client->allOrderBooks();
        $converted=[];$skipped=[];$failed=[];

        foreach($groups as $asset=>$group){
            if(count($converted)>=self::MAX_CONVERSIONS_PER_RUN)break;
            $asset=NobitexPositionReconciler::canonicalAsset((string)$asset);
            if($asset===''||in_array($asset,['IRT','RLS','USDT'],true))continue;
            $rows=(array)($group['rows']??[]);
            $managedAmount=max(0.0,(float)($group['amount']??0.0));
            if($rows===[]||$managedAmount<=self::EPS)continue;

            $symbol=$asset.'IRT';
            if($this->assetBusy($pdo,$asset,$symbol)){
                $skipped[]=['asset'=>$asset,'symbol'=>$symbol,'reason'=>'asset_has_active_position_or_pending_order'];
                continue;
            }

            $available=$this->walletAvailable($walletResponse,$asset);
            $target=min($managedAmount,$available);
            if($target<=self::EPS){
                $skipped[]=['asset'=>$asset,'symbol'=>$symbol,'reason'=>'managed_dust_not_available_in_wallet'];
                continue;
            }

            $book=$this->book($books,$symbol);
            $bestBid=$this->bestBid($book);
            if($bestBid<=0){
                $skipped[]=['asset'=>$asset,'symbol'=>$symbol,'reason'=>'irt_market_or_bid_unavailable'];
                continue;
            }
            $hardLimit=max(0.00000001,$bestBid*0.985);

            try{
                $prepared=$client->prepareOrder([
                    'type'=>'sell',
                    'srcCurrency'=>strtolower($asset),
                    'dstCurrency'=>'rls',
                    'amount'=>$this->num($target),
                    'price'=>$this->num($hardLimit),
                    'execution'=>'market',
                ]);
            }catch(\Throwable $e){
                $skipped[]=['asset'=>$asset,'symbol'=>$symbol,'reason'=>'order_rules_unavailable','error'=>mb_substr($e->getMessage(),0,220)];
                continue;
            }

            $rules=is_array($prepared['rules']??null)?$prepared['rules']:[];
            $ruleReason=(string)($prepared['reason']??'exchange_order_rules_rejected');
            if(!($prepared['valid']??false)||($rules['below_minimum']??false)){
                $skipped[]=[
                    'asset'=>$asset,'symbol'=>$symbol,
                    'reason'=>($rules['below_minimum']??false)?'below_exchange_minimum':$ruleReason,
                    'estimated_order_value'=>$rules['estimated_order_value']??null,
                    'min_order_quote'=>$rules['min_order_quote']??null,
                ];
                continue;
            }

            $payload=is_array($prepared['payload']??null)?$prepared['payload']:[];
            $normalizedAmount=is_numeric($payload['amount']??null)?(float)$payload['amount']:0.0;
            if($normalizedAmount<=self::EPS){
                $skipped[]=['asset'=>$asset,'symbol'=>$symbol,'reason'=>'amount_below_exchange_step'];
                continue;
            }

            $estimatedToman=NobitexDisplayMoney::quoteValue($normalizedAmount*$bestBid,'IRT');
            if($estimatedToman<(float)$status['min_toman']){
                $skipped[]=['asset'=>$asset,'symbol'=>$symbol,'reason'=>'below_configured_managed_dust_floor','estimated_toman'=>$estimatedToman];
                continue;
            }
            if($estimatedToman>(float)$status['max_toman']){
                $skipped[]=['asset'=>$asset,'symbol'=>$symbol,'reason'=>'managed_dust_safety_cap','estimated_toman'=>$estimatedToman,'max_toman'=>$status['max_toman']];
                continue;
            }

            $identifier='dust-to-irt-'.substr(bin2hex(random_bytes(8)),0,16);
            $reservedIds=$this->reserveGroup($pdo,$rows,$identifier);
            if($reservedIds===[]){
                $skipped[]=['asset'=>$asset,'symbol'=>$symbol,'reason'=>'dust_state_changed_before_conversion'];
                continue;
            }

            try{
                $order=$service->create([
                    'symbol'=>$symbol,
                    'side'=>'sell',
                    'mode'=>'market',
                    'amount'=>$normalizedAmount,
                    'price'=>$hardLimit,
                    'identifier'=>$identifier,
                ],'nobitex_dust_converter');

                $remote=is_array($order['order']??null)?$order['order']:[];
                $localId=trim((string)($order['local_id']??''));
                $exchangeId=trim((string)($remote['id']??''));
                $this->attachOrderToReservedGroup($pdo,$identifier,$localId,$exchangeId);

                $finalization=null;
                if(NobitexOrderFill::isDone($remote)){
                    $matched=NobitexOrderFill::matchedAmount($remote,$normalizedAmount);
                    $finalization=$this->finalizeGroup($pdo,$localId,$matched,$exchangeId);
                }

                $converted[]=[
                    'asset'=>$asset,'symbol'=>$symbol,'amount'=>$normalizedAmount,'estimated_toman'=>$estimatedToman,
                    'best_bid_rls'=>$bestBid,'order_local_id'=>$localId!==''?$localId:null,
                    'exchange_order_id'=>$exchangeId!==''?$exchangeId:null,'position_ids'=>$reservedIds,
                    'finalization'=>$finalization,
                ];
            }catch(NobitexCandidateRejectedException $e){
                $this->releaseReservation($pdo,$identifier);
                $skipped[]=['asset'=>$asset,'symbol'=>$symbol,'reason'=>$e->reasonCode(),'assessment'=>$e->assessment()];
            }catch(\Throwable $e){
                $this->releaseReservation($pdo,$identifier);
                $failed[]=['asset'=>$asset,'symbol'=>$symbol,'error'=>mb_substr($e->getMessage(),0,300)];
                ErrorReporter::captureThrowable($e,'error','managed_dust_converter',['exchange'=>'nobitex','symbol'=>$symbol]);
            }
        }

        $this->write($pdo,self::LAST_RUN_KEY,gmdate('Y-m-d H:i:s'));
        $result=[
            'status'=>'completed','model'=>self::MODEL,'converted'=>$converted,'skipped'=>$skipped,'failed'=>$failed,
            'pending_reconciliation'=>$pending,'managed_dust_groups'=>count($groups),'time_iran'=>IranClock::nowPayload(),
        ];
        $this->audit($pdo,'nobitex.managed_dust_conversion_run',$result);
        return$result;
    }

    private function reconcilePendingConversions(PDO $pdo,NobitexOrderService $service,object $client): array
    {
        $rows=$pdo->query("SELECT id,asset,amount,exit_identifier,exit_order_local_id,exit_exchange_order_id,updated_at
            FROM nobitex_autotrade_positions WHERE status='dust_converting' ORDER BY updated_at ASC LIMIT 30")->fetchAll();
        if($rows===[])return['status'=>'idle','checked'=>0,'completed'=>0,'partial'=>0,'released'=>0,'deferred'=>0];

        $byLocal=[];
        foreach($rows as$row){
            $local=trim((string)($row['exit_order_local_id']??''));
            $identifier=trim((string)($row['exit_identifier']??''));
            if($local===''){
                $updated=trim((string)($row['updated_at']??''));
                $ts=$updated!==''?strtotime($updated.' UTC'):false;
                if($identifier!==''&&$ts!==false&&time()-$ts>=300){
                    $this->releaseReservation($pdo,$identifier);
                }
                continue;
            }
            $byLocal[$local][]=$row;
        }

        $out=['status'=>'processed','checked'=>count($rows),'completed'=>0,'partial'=>0,'released'=>0,'deferred'=>0];
        foreach($byLocal as$localId=>$group){
            $meta=$this->localOrder($pdo,$localId);
            if($meta===null){$out['deferred']++;continue;}
            $exchangeId=trim((string)($meta['exchange_order_id']??''));
            $identifier=trim((string)($meta['identifier']??''));
            $requested=max(0.0,(float)($meta['amount']??0.0));
            try{
                $response=$exchangeId!==''?$client->orderStatus($exchangeId):$client->orderStatus(null,$identifier);
                $remote=$service->normalizedOrder($response);
            }catch(\Throwable){$out['deferred']++;continue;}
            if($remote===[]){$out['deferred']++;continue;}

            $state=NobitexOrderFill::status($remote);
            $matched=NobitexOrderFill::matchedAmount($remote,NobitexOrderFill::isDone($remote)?$requested:0.0);
            if(NobitexOrderFill::isDone($remote)){
                $result=$this->finalizeGroup($pdo,$localId,$matched,$exchangeId);
                $out['completed']+=(int)($result['closed']??0);
                $out['partial']+=(int)($result['partial']??0);
                continue;
            }
            if(in_array($state,['canceled','cancelled','rejected','failed'],true)){
                if($matched>self::EPS){
                    $result=$this->finalizeGroup($pdo,$localId,$matched,$exchangeId);
                    $out['completed']+=(int)($result['closed']??0);
                    $out['partial']+=(int)($result['partial']??0);
                }else{
                    $this->releaseReservation($pdo,$identifier);
                    $out['released']+=count($group);
                }
                continue;
            }
            $out['deferred']++;
        }
        return$out;
    }

    private function managedDustGroups(PDO $pdo): array
    {
        $rows=$pdo->query("SELECT id,asset,amount FROM nobitex_autotrade_positions
            WHERE status='dust' AND (exit_identifier IS NULL OR exit_identifier='') ORDER BY id ASC LIMIT 100")->fetchAll();
        $groups=[];
        foreach($rows as$row){
            $asset=NobitexPositionReconciler::canonicalAsset((string)($row['asset']??''));
            $amount=max(0.0,(float)($row['amount']??0.0));
            if($asset===''||$amount<=self::EPS)continue;
            if(!isset($groups[$asset]))$groups[$asset]=['amount'=>0.0,'rows'=>[]];
            $groups[$asset]['amount']+=$amount;
            $groups[$asset]['rows'][]=$row;
        }
        return$groups;
    }

    private function reserveGroup(PDO $pdo,array $rows,string $identifier): array
    {
        return Database::transaction(function(PDO $tx)use($rows,$identifier):array{
            $ids=[];
            $stmt=$tx->prepare("UPDATE nobitex_autotrade_positions SET status='dust_converting',exit_identifier=:identifier,
                exit_order_local_id=NULL,exit_exchange_order_id=NULL,updated_at=UTC_TIMESTAMP()
                WHERE id=:id AND status='dust' AND (exit_identifier IS NULL OR exit_identifier='')");
            foreach($rows as$row){
                $id=(int)($row['id']??0);if($id<=0)continue;
                $stmt->execute([':identifier'=>$identifier,':id'=>$id]);
                if($stmt->rowCount()===1)$ids[]=$id;
            }
            return$ids;
        });
    }

    private function attachOrderToReservedGroup(PDO $pdo,string $identifier,string $localId,string $exchangeId): void
    {
        $stmt=$pdo->prepare("UPDATE nobitex_autotrade_positions SET exit_order_local_id=:local,exit_exchange_order_id=:exchange,updated_at=UTC_TIMESTAMP()
            WHERE status='dust_converting' AND exit_identifier=:identifier");
        $stmt->execute([':local'=>$localId!==''?$localId:null,':exchange'=>$exchangeId!==''?$exchangeId:null,':identifier'=>$identifier]);
    }

    private function releaseReservation(PDO $pdo,string $identifier): void
    {
        if($identifier==='')return;
        $stmt=$pdo->prepare("UPDATE nobitex_autotrade_positions SET status='dust',exit_identifier=NULL,exit_order_local_id=NULL,
            exit_exchange_order_id=NULL,updated_at=UTC_TIMESTAMP() WHERE status='dust_converting' AND exit_identifier=:identifier");
        $stmt->execute([':identifier'=>$identifier]);
    }

    private function finalizeGroup(PDO $pdo,string $localId,float $soldAmount,string $exchangeId=''): array
    {
        $soldAmount=max(0.0,$soldAmount);
        return Database::transaction(function(PDO $tx)use($localId,$soldAmount,$exchangeId):array{
            $stmt=$tx->prepare("SELECT id,asset,amount FROM nobitex_autotrade_positions
                WHERE status='dust_converting' AND exit_order_local_id=:local ORDER BY id ASC FOR UPDATE");
            $stmt->execute([':local'=>$localId]);
            $rows=$stmt->fetchAll();
            $remainingSale=$soldAmount;$closed=0;$partial=0;$released=0;
            foreach($rows as$row){
                $id=(int)$row['id'];$amount=max(0.0,(float)$row['amount']);
                $applied=min($amount,$remainingSale);$remainingSale=max(0.0,$remainingSale-$applied);
                $left=max(0.0,$amount-$applied);
                if($applied>self::EPS&&$left<=max(self::EPS,$amount*0.000001)){
                    $u=$tx->prepare("UPDATE nobitex_autotrade_positions SET status='closed',amount=0,exit_price=NULL,
                        exit_identifier='dust_to_irt_completed',exit_exchange_order_id=:exchange,closed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                        WHERE id=:id AND status='dust_converting'");
                    $u->execute([':exchange'=>$exchangeId!==''?$exchangeId:null,':id'=>$id]);
                    if($u->rowCount()===1)$closed++;
                }elseif($applied>self::EPS){
                    $u=$tx->prepare("UPDATE nobitex_autotrade_positions SET status='dust',amount=:amount,exit_identifier=NULL,
                        exit_order_local_id=NULL,exit_exchange_order_id=NULL,closed_at=NULL,updated_at=UTC_TIMESTAMP()
                        WHERE id=:id AND status='dust_converting'");
                    $u->execute([':amount'=>$left,':id'=>$id]);
                    if($u->rowCount()===1)$partial++;
                }else{
                    $u=$tx->prepare("UPDATE nobitex_autotrade_positions SET status='dust',exit_identifier=NULL,
                        exit_order_local_id=NULL,exit_exchange_order_id=NULL,updated_at=UTC_TIMESTAMP()
                        WHERE id=:id AND status='dust_converting'");
                    $u->execute([':id'=>$id]);
                    if($u->rowCount()===1)$released++;
                }
            }
            $event=['order_local_id'=>$localId,'exchange_order_id'=>$exchangeId!==''?$exchangeId:null,'sold_amount'=>$soldAmount,
                'closed_rows'=>$closed,'partial_rows'=>$partial,'released_rows'=>$released,'pnl_recorded'=>false,'destination'=>'IRT_TOMAN'];
            $this->event($tx,'info','nobitex.managed_dust_converted_to_toman',$event);
            return['closed'=>$closed,'partial'=>$partial,'released'=>$released];
        });
    }

    private function localOrder(PDO $pdo,string $localId): ?array
    {
        $stmt=$pdo->prepare("SELECT local_id,identifier,exchange_order_id,amount,status FROM orders WHERE exchange_name='nobitex' AND local_id=:local LIMIT 1");
        $stmt->execute([':local'=>$localId]);$row=$stmt->fetch();return$row?:null;
    }

    private function assetBusy(PDO $pdo,string $asset,string $symbol): bool
    {
        $rows=$pdo->query("SELECT asset FROM nobitex_autotrade_positions WHERE status IN ('pending_open','open','pending_close') LIMIT 100")->fetchAll();
        foreach($rows as$row){
            if(NobitexPositionReconciler::canonicalAsset((string)($row['asset']??''))===$asset)return true;
        }
        $s=$pdo->prepare("SELECT EXISTS(SELECT 1 FROM orders WHERE exchange_name='nobitex' AND market_code=:symbol
            AND status IN ('submitting','submitted','pending') AND side IN ('buy','sell'))");
        $s->execute([':symbol'=>$symbol]);return(bool)$s->fetchColumn();
    }

    private function walletAvailable(array $response,string $asset): float
    {
        $asset=NobitexPositionReconciler::canonicalAsset($asset);
        $rows=$this->walletRows($response);$sum=0.0;
        foreach($rows as$key=>$row){
            if(!is_array($row))continue;
            $raw=(string)($row['currency']??$row['asset']??$row['currencyCode']??(is_string($key)?$key:''));
            if(NobitexPositionReconciler::canonicalAsset($raw)!==$asset)continue;
            foreach(['activeBalance','available','free','balance']as$field){
                if(array_key_exists($field,$row)&&is_numeric($row[$field])){$sum+=max(0.0,(float)$row[$field]);break;}
            }
        }
        return$sum;
    }

    private function walletRows(array $response): array
    {
        if(isset($response['wallets'])&&is_array($response['wallets']))return$response['wallets'];
        if(isset($response['data'])&&is_array($response['data'])){
            if(array_is_list($response['data']))return$response['data'];
            if(isset($response['data']['wallets'])&&is_array($response['data']['wallets']))return$response['data']['wallets'];
        }
        return[];
    }

    private function book(array $books,string $symbol): array
    {
        foreach([$symbol,strtolower($symbol)]as$key)if(isset($books[$key])&&is_array($books[$key]))return$books[$key];
        if(isset($books['orderbooks'])&&is_array($books['orderbooks']))foreach([$symbol,strtolower($symbol)]as$key)if(isset($books['orderbooks'][$key])&&is_array($books['orderbooks'][$key]))return$books['orderbooks'][$key];
        return[];
    }

    private function bestBid(array $book): float
    {
        $bids=$book['bids']??[];if(!is_array($bids)||$bids===[])return 0.0;
        $first=$bids[0]??null;
        if(is_array($first)){
            $p=$first[0]??$first['price']??null;
            return is_numeric($p)?max(0.0,(float)$p):0.0;
        }
        return 0.0;
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(sprintf('%.12F',$value),'0'),'.');
    }

    private function setting(PDO $pdo,string $key):?string{$s=$pdo->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');$s->execute([':k'=>$key]);$v=$s->fetchColumn();return$v===false?null:(string)$v;}
    private function boolSetting(PDO $pdo,string $key,bool $default):bool{$v=$this->setting($pdo,$key);if($v===null)return$default;return in_array(strtolower(trim($v)),['1','true','yes','on'],true);}
    private function numberSetting(PDO $pdo,string $key,float $default,float $min,float $max):float{$v=$this->setting($pdo,$key);$n=is_numeric($v)?(float)$v:$default;return max($min,min($max,$n));}
    private function write(PDO $pdo,string $key,string $value):void{$s=$pdo->prepare("INSERT INTO settings (key_name,value_text,updated_at) VALUES (:k,:v,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");$s->execute([':k'=>$key,':v'=>$value]);}
    private function audit(PDO $pdo,string $event,array $context):void{$s=$pdo->prepare('INSERT INTO audit_logs(event_name,context_json,created_at) VALUES(:e,:c,UTC_TIMESTAMP())');$s->execute([':e'=>$event,':c'=>json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
    private function event(PDO $pdo,string $level,string $event,array $context):void{$s=$pdo->prepare('INSERT INTO nobitex_autotrade_events(level,event_name,context_json,created_at) VALUES(:l,:e,:c,UTC_TIMESTAMP())');$s->execute([':l'=>$level,':e'=>$event,':c'=>json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
}
