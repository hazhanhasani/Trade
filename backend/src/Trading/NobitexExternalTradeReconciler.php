<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

/**
 * Reconciles manual/external Nobitex spot SELL fills with Trade-managed positions.
 *
 * Unlike wallet-only reconciliation this can distinguish Trade-owned order IDs
 * from external/manual orders and can preserve the exchange-reported exit price,
 * amount and sell fee. It is deliberately reduction-only and never creates or
 * increases a managed position.
 */
final class NobitexExternalTradeReconciler
{
    public const MODEL = 'nobitex_external_spot_trade_reconciliation_v2';
    private const EPS = 0.000000000001;

    public function __construct(private readonly NobitexOrderService $orders = new NobitexOrderService()) {}

    public function ensureSchema(?PDO $pdo = null): void
    {
        $pdo ??= Database::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS nobitex_external_trade_reconciliations (
            trade_id VARCHAR(100) PRIMARY KEY,
            order_id VARCHAR(100) NULL,
            trade_type VARCHAR(16) NOT NULL,
            asset VARCHAR(24) NOT NULL,
            quote_asset VARCHAR(24) NOT NULL,
            amount DECIMAL(36,18) NOT NULL DEFAULT 0,
            price DECIMAL(36,18) NOT NULL DEFAULT 0,
            fee_quote DECIMAL(36,18) NOT NULL DEFAULT 0,
            applied_amount DECIMAL(36,18) NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL,
            position_ids_json LONGTEXT NULL,
            raw_json LONGTEXT NULL,
            trade_time_utc DATETIME NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_external_trade_status (status,created_at),
            INDEX idx_external_trade_order (order_id),
            INDEX idx_external_trade_market (asset,quote_asset,trade_time_utc)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function reconcile(?PDO $pdo = null): array
    {
        NobitexSchema::ensure();
        $pdo ??= Database::connection();
        $this->ensureSchema($pdo);

        $open = $pdo->query("SELECT id,symbol,asset,quote_asset,amount,entry_price,entry_fee_quote,
                    entry_order_local_id,entry_exchange_order_id,status,opened_at
             FROM nobitex_autotrade_positions WHERE status='open' ORDER BY opened_at ASC,id ASC LIMIT 50")->fetchAll();
        if ($open === []) return ['status'=>'ok','model'=>self::MODEL,'trades_checked'=>0,'manual_sells_applied'=>0,'positions_changed'=>0,'events'=>[]];

        $client = $this->orders->client();
        try {
            $response = $client->trades(['pageSize'=>500]);
        } catch (\Throwable) {
            // Keep compatibility if an older Nobitex deployment rejects an
            // optional pagination name; the documented default page is enough
            // for the normal once-per-minute reconciler and the next tick retries.
            $response = $client->trades();
        }
        $trades = self::tradeRows($response);
        usort($trades, static fn(array $a,array $b): int => self::tradeSortKey($a) <=> self::tradeSortKey($b));

        $out = ['status'=>'ok','model'=>self::MODEL,'trades_checked'=>count($trades),'manual_sells_applied'=>0,'positions_changed'=>0,'bot_owned_ignored'=>0,'already_seen'=>0,'events'=>[]];

        foreach ($trades as $raw) {
            $trade = self::normalizeTrade($raw);
            if ($trade === null || $trade['type'] !== 'sell' || $trade['amount'] <= self::EPS || $trade['price'] <= 0) continue;
            if ($this->seen($pdo, $trade['id'])) { $out['already_seen']++; continue; }

            if ($trade['order_id'] !== '' && $this->botOwnsOrder($pdo, $trade['order_id'])) {
                $this->record($pdo,$trade,'bot_owned',0.0,[]);
                $out['bot_owned_ignored']++;
                continue;
            }

            $eligible = $this->eligiblePositions($pdo,$trade);
            if ($eligible === []) {
                $this->record($pdo,$trade,'no_matching_open_position',0.0,[]);
                continue;
            }

            $plan = self::allocationPlan($eligible,$trade['amount']);
            if ($plan === []) {
                $this->record($pdo,$trade,'no_applicable_amount',0.0,[]);
                continue;
            }

            $result = Database::transaction(function(PDO $tx) use($trade,$plan,$raw): array {
                // Claim the exchange trade ID first. A concurrent cron/admin
                // request then sees the unique key and cannot double-apply it.
                $stmt=$tx->prepare("INSERT INTO nobitex_external_trade_reconciliations
                    (trade_id,order_id,trade_type,asset,quote_asset,amount,price,fee_quote,applied_amount,status,position_ids_json,raw_json,trade_time_utc,created_at)
                    VALUES (:trade,:order_id,'sell',:asset,:quote,:amount,:price,:fee,0,'processing',NULL,:raw,:trade_time,UTC_TIMESTAMP())");
                $stmt->execute([
                    ':trade'=>$trade['id'],':order_id'=>$trade['order_id']!==''?$trade['order_id']:null,
                    ':asset'=>$trade['asset'],':quote'=>$trade['quote'],':amount'=>$trade['amount'],':price'=>$trade['price'],
                    ':fee'=>$trade['fee_quote'],':raw'=>json_encode($raw,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                    ':trade_time'=>$trade['time_utc'],
                ]);

                $events=[];$applied=0.0;$positionIds=[];
                foreach($plan as $part){
                    $positionId=(int)$part['id'];
                    $lock=$tx->prepare("SELECT * FROM nobitex_autotrade_positions WHERE id=:id AND status='open' FOR UPDATE");
                    $lock->execute([':id'=>$positionId]);$position=$lock->fetch();
                    if(!$position)continue;

                    $current=max(0.0,(float)$position['amount']);
                    $sold=min($current,max(0.0,(float)$part['amount']));
                    if($sold<=self::EPS)continue;
                    $entry=max(0.0,(float)$position['entry_price']);
                    if($entry<=0)continue;
                    $remaining=max(0.0,$current-$sold);
                    if($remaining <= max(self::EPS,$current*0.000001))$remaining=0.0;

                    $tradeFeeShare=$trade['amount']>0 ? max(0.0,$trade['fee_quote'])*($sold/$trade['amount']) : 0.0;
                    $remainingEntryFee=max(0.0,(float)($position['entry_fee_quote']??0));
                    $entryFeeShare=$current>0 ? $remainingEntryFee*($sold/$current) : 0.0;
                    $gross=($trade['price']-$entry)*$sold;
                    $fees=$entryFeeShare+$tradeFeeShare;
                    $net=$gross-$fees;
                    $basis=($entry*$sold)+$entryFeeShare;
                    $pct=$basis>0?($net/$basis)*100.0:0.0;
                    $created=$trade['time_utc'] ?: gmdate('Y-m-d H:i:s');

                    $pnl=$tx->prepare("INSERT INTO nobitex_autotrade_pnl
                        (position_id,pnl,pnl_percent,gross_pnl,entry_fee_quote,exit_fee_quote,total_fees_quote,net_pnl,fee_source,accounted_at,quote_asset,entry_price,exit_price,amount,created_at)
                        VALUES (:position,:pnl,:pct,:gross,:entry_fee,:exit_fee,:fees,:net,'external_actual',UTC_TIMESTAMP(),:quote,:entry,:exit,:amount,:created)");
                    $pnl->execute([
                        ':position'=>$positionId,':pnl'=>$net,':pct'=>$pct,':gross'=>$gross,':entry_fee'=>$entryFeeShare,
                        ':exit_fee'=>$tradeFeeShare,':fees'=>$fees,':net'=>$net,':quote'=>$trade['quote'],':entry'=>$entry,
                        ':exit'=>$trade['price'],':amount'=>$sold,':created'=>$created,
                    ]);

                    $entryFeeLeft=max(0.0,$remainingEntryFee-$entryFeeShare);
                    if($remaining<=self::EPS){
                        $identifier='manual-nbx-'.substr(sha1($trade['id']),0,20);
                        $u=$tx->prepare("UPDATE nobitex_autotrade_positions SET status='closed',exit_price=:exit,
                            exit_identifier=:identifier,exit_order_local_id=NULL,exit_exchange_order_id=:order_id,
                            entry_fee_quote=:entry_fee,closed_at=:closed,updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='open'");
                        $u->execute([':exit'=>$trade['price'],':identifier'=>$identifier,':order_id'=>$trade['order_id']!==''?$trade['order_id']:null,':entry_fee'=>$entryFeeLeft,':closed'=>$created,':id'=>$positionId]);
                        $kind='closed';
                    }else{
                        $u=$tx->prepare("UPDATE nobitex_autotrade_positions SET amount=:amount,entry_fee_quote=:entry_fee,updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='open'");
                        $u->execute([':amount'=>$remaining,':entry_fee'=>$entryFeeLeft,':id'=>$positionId]);
                        $kind='resized';
                    }
                    if($u->rowCount()!==1)continue;

                    self::refreshPositionTotals($tx,$positionId);
                    $applied+=$sold;$positionIds[]=$positionId;
                    $event=['type'=>$kind,'position_id'=>$positionId,'symbol'=>(string)$position['symbol'],'asset'=>$trade['asset'],'quote_asset'=>$trade['quote'],
                        'trade_id'=>$trade['id'],'external_order_id'=>$trade['order_id'],'sold_amount'=>$sold,'remaining_amount'=>$remaining,
                        'entry_price'=>$entry,'exit_price'=>$trade['price'],'exit_fee_quote'=>$tradeFeeShare,'net_pnl'=>$net,'pnl_percent'=>$pct,'time_utc'=>$created];
                    self::event($tx,'warning','nobitex.position.external_trade_applied',$event);$events[]=$event;
                }

                $status=$applied>self::EPS?'applied':'no_applicable_amount';
                $tx->prepare("UPDATE nobitex_external_trade_reconciliations SET applied_amount=:applied,status=:status,position_ids_json=:positions WHERE trade_id=:trade")
                    ->execute([':applied'=>$applied,':status'=>$status,':positions'=>$positionIds===[]?null:json_encode($positionIds,JSON_THROW_ON_ERROR),':trade'=>$trade['id']]);
                return['applied'=>$applied,'position_ids'=>$positionIds,'events'=>$events];
            });

            if($result['applied']>self::EPS){
                $out['manual_sells_applied']++;$out['positions_changed']+=count($result['position_ids']);
                foreach($result['events'] as $e)$out['events'][]=$e;
            }
        }
        return$out;
    }

    public static function tradeRows(array $response): array
    {
        foreach ([$response['trades']??null,$response['data']['trades']??null,$response['data']??null,$response] as $candidate) {
            if (is_array($candidate) && array_is_list($candidate)) return array_values(array_filter($candidate,'is_array'));
        }
        return[];
    }

    public static function normalizeTrade(array $row): ?array
    {
        $id=trim((string)($row['id']??$row['tradeId']??$row['trade_id']??''));
        if($id==='')return null;
        $type=strtolower(trim((string)($row['type']??$row['side']??'')));
        $src=NobitexPositionReconciler::canonicalAsset((string)($row['srcCurrency']??$row['src_currency']??''));
        $dst=strtoupper(trim((string)($row['dstCurrency']??$row['dst_currency']??'')));
        $quote=match($dst){'RLS','IRR','IRT'=>'IRT','USDT'=>'USDT',default=>$dst};
        if($src===''||!in_array($quote,['IRT','USDT'],true))return null;
        $amount=self::num($row['amount']??0);$price=self::num($row['price']??0);$fee=max(0.0,self::num($row['fee']??0));
        return[
            'id'=>$id,'order_id'=>trim((string)($row['orderId']??$row['order_id']??'')),'type'=>$type,
            'asset'=>$src,'quote'=>$quote,'amount'=>max(0.0,$amount),'price'=>max(0.0,$price),'fee_quote'=>$fee,
            'time_utc'=>self::utcTime($row['timestamp']??$row['createdAt']??$row['created_at']??null),
        ];
    }

    public static function allocationPlan(array $positions,float $tradeAmount): array
    {
        $remaining=max(0.0,$tradeAmount);$out=[];
        foreach($positions as $p){
            if($remaining<=self::EPS)break;$amount=max(0.0,(float)($p['amount']??0));if($amount<=self::EPS)continue;
            $part=min($amount,$remaining);$out[]=['id'=>(int)($p['id']??0),'amount'=>$part];$remaining=max(0.0,$remaining-$part);
        }
        return$out;
    }

    private function eligiblePositions(PDO $pdo,array $trade): array
    {
        $rows=$pdo->prepare("SELECT id,symbol,asset,quote_asset,amount,entry_price,entry_fee_quote,opened_at FROM nobitex_autotrade_positions WHERE status='open' AND quote_asset=:quote ORDER BY opened_at ASC,id ASC");
        $rows->execute([':quote'=>$trade['quote']]);$out=[];$tradeTs=$trade['time_utc']?strtotime($trade['time_utc'].' UTC'):false;
        foreach($rows->fetchAll() as $p){
            if(NobitexPositionReconciler::canonicalAsset((string)$p['asset'])!==$trade['asset'])continue;
            if($tradeTs!==false&&($p['opened_at']??'')!==''){
                $opened=strtotime((string)$p['opened_at'].' UTC');if($opened!==false&&$opened>$tradeTs+5)continue;
            }
            $out[]=$p;
        }
        return$out;
    }

    private function botOwnsOrder(PDO $pdo,string $orderId): bool
    {
        $s=$pdo->prepare("SELECT EXISTS(SELECT 1 FROM orders WHERE exchange_name='nobitex' AND exchange_order_id=:id)");$s->execute([':id'=>$orderId]);return(bool)$s->fetchColumn();
    }

    private function seen(PDO $pdo,string $tradeId): bool
    {
        $s=$pdo->prepare('SELECT EXISTS(SELECT 1 FROM nobitex_external_trade_reconciliations WHERE trade_id=:id)');$s->execute([':id'=>$tradeId]);return(bool)$s->fetchColumn();
    }

    private function record(PDO $pdo,array $trade,string $status,float $applied,array $positionIds): void
    {
        $s=$pdo->prepare("INSERT IGNORE INTO nobitex_external_trade_reconciliations
            (trade_id,order_id,trade_type,asset,quote_asset,amount,price,fee_quote,applied_amount,status,position_ids_json,trade_time_utc,created_at)
            VALUES(:trade,:order_id,:type,:asset,:quote,:amount,:price,:fee,:applied,:status,:positions,:trade_time,UTC_TIMESTAMP())");
        $s->execute([':trade'=>$trade['id'],':order_id'=>$trade['order_id']!==''?$trade['order_id']:null,':type'=>$trade['type'],':asset'=>$trade['asset'],':quote'=>$trade['quote'],
            ':amount'=>$trade['amount'],':price'=>$trade['price'],':fee'=>$trade['fee_quote'],':applied'=>$applied,':status'=>$status,
            ':positions'=>$positionIds===[]?null:json_encode($positionIds,JSON_THROW_ON_ERROR),':trade_time'=>$trade['time_utc']]);
    }

    private static function refreshPositionTotals(PDO $pdo,int $positionId): void
    {
        $s=$pdo->prepare("SELECT COALESCE(SUM(gross_pnl),0) gross,COALESCE(SUM(total_fees_quote),0) fees,COALESCE(SUM(net_pnl),0) net FROM nobitex_autotrade_pnl WHERE position_id=:id");
        $s->execute([':id'=>$positionId]);$sum=$s->fetch()?:['gross'=>0,'fees'=>0,'net'=>0];
        $pdo->prepare("UPDATE nobitex_autotrade_positions SET gross_realized_pnl=:gross,total_fees_quote=:fees,net_realized_pnl=:net,realized_pnl=:realized,updated_at=UTC_TIMESTAMP() WHERE id=:id")
            ->execute([':gross'=>$sum['gross'],':fees'=>$sum['fees'],':net'=>$sum['net'],':realized'=>$sum['net'],':id'=>$positionId]);
    }

    private static function event(PDO $pdo,string $level,string $name,array $context): void
    {
        $s=$pdo->prepare('INSERT INTO nobitex_autotrade_events(level,event_name,context_json,created_at) VALUES(:level,:name,:context,UTC_TIMESTAMP())');
        $s->execute([':level'=>$level,':name'=>$name,':context'=>json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }

    private static function tradeSortKey(array $row): int
    {
        $v=$row['timestamp']??$row['createdAt']??$row['created_at']??0;if(is_numeric($v)){ $n=(float)$v;return(int)($n>100000000000?$n/1000:$n); }
        $t=strtotime((string)$v);return$t===false?0:$t;
    }

    private static function utcTime(mixed $value): ?string
    {
        if($value===null||$value==='')return null;
        if(is_numeric($value)){$n=(float)$value;if($n>100000000000)$n/=1000;return gmdate('Y-m-d H:i:s',(int)$n);}
        $ts=strtotime((string)$value);return$ts===false?null:gmdate('Y-m-d H:i:s',$ts);
    }

    private static function num(mixed $v): float{return is_numeric($v)&&is_finite((float)$v)?(float)$v:0.0;}
}
