<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

/**
 * Enforces the effective live-position ceiling without opening new risk.
 *
 * One SELL is submitted per call. NobitexAutoTraderEngine may call this again
 * after the previous order has reconciled, allowing several reductions in one
 * tick while never stacking ambiguous pending exits.
 */
final class NobitexCapacityManager
{
    public function __construct(
        private readonly NobitexOrderService $orders = new NobitexOrderService(),
        private readonly NobitexUniverseScanner $scanner = new NobitexUniverseScanner(),
    ) {}

    public function enforce(?PDO $pdo = null): array
    {
        NobitexSchema::ensure();
        $pdo ??= Database::connection();

        if (!NobitexSchema::botEnabled('nobitex')) return $this->result('no_reduction','bot_disabled');
        if (!$this->orders->credentialsConfigured() || !$this->orders->liveEnabled()) return $this->result('no_reduction','live_execution_unavailable');

        $configured = $this->intSetting($pdo,'nobitex_max_positions',5,1,20);
        $effectiveRaw = $this->intSetting($pdo,'nobitex_effective_max_positions',$configured,1,20);
        $effective = min($configured,$effectiveRaw);
        $positions = $pdo->query(
            "SELECT * FROM nobitex_autotrade_positions
             WHERE status IN ('pending_open','open','pending_close')
             ORDER BY id ASC LIMIT 30"
        )->fetchAll();
        $activeCount = count($positions);

        if ($activeCount <= $effective) {
            return $this->result('no_reduction','within_effective_limit',[
                'active_positions'=>$activeCount,'configured_max'=>$configured,'effective_max'=>$effective,
            ]);
        }

        foreach ($positions as $position) {
            if ((string)($position['status'] ?? '') !== 'open') {
                return $this->result('waiting_reconcile','pending_order_present',[
                    'active_positions'=>$activeCount,'configured_max'=>$configured,'effective_max'=>$effective,
                    'excess_positions'=>max(0,$activeCount-$effective),
                ]);
            }
        }

        $client = $this->orders->client();
        $wallets = $client->wallets();
        $enriched = [];
        foreach ($positions as $position) {
            try {
                $market = $this->scanner->snapshotSymbol($client,(string)$position['symbol'],60);
            } catch (\Throwable $e) {
                $this->event($pdo,'warning','nobitex.capacity.position_scan_failed',[
                    'position_id'=>$position['id'] ?? null,'symbol'=>$position['symbol'] ?? null,'error'=>mb_substr($e->getMessage(),0,200),
                ]);
                continue;
            }

            $signal = is_array($market['signal'] ?? null) ? $market['signal'] : [];
            $entry = max(0.0,(float)($position['entry_price'] ?? 0.0));
            $mark = max(0.0,(float)($market['price'] ?? 0.0));
            $exitCost = max(0.0,(float)($signal['estimated_exit_cost_percent'] ?? 0.0));
            $grossPct = $entry > 0.0 && $mark > 0.0 ? (($mark-$entry)/$entry)*100.0 : 0.0;
            $netPct = is_numeric($position['unrealized_net_pnl_percent'] ?? null)
                ? (float)$position['unrealized_net_pnl_percent']
                : $grossPct-$exitCost;
            $details = is_array($signal['indicators'] ?? null) ? $signal['indicators'] : [];
            $volatility = max(0.0,(float)($details['volatility_percent'] ?? 0.0));
            $spread = max(0.0,(float)($market['spread_percent'] ?? 0.0));
            $depth = max(0.0,(float)($market['depth_quote'] ?? 0.0));
            $edge = self::signalEdge($signal);

            $position['_market'] = $market;
            $position['forward_edge_percent'] = $edge;
            $position['unrealized_net_pnl_percent'] = $netPct;
            $position['estimated_exit_cost_percent'] = $exitCost;
            $position['spread_percent'] = $spread;
            $position['depth_quote'] = $depth;
            $position['volatility_percent'] = $volatility;
            $position['victim_score'] = self::victimScore($position);
            $enriched[] = $position;
        }

        if ($enriched === []) return $this->result('no_reduction','no_scannable_position');
        usort($enriched,static fn(array $a,array $b):int => ((float)$a['victim_score']) <=> ((float)$b['victim_score']));
        $victim = $enriched[0];
        $market = $victim['_market'];
        $symbol = strtoupper((string)$victim['symbol']);

        $available = $this->walletAvailable($wallets,$this->assetAliases((string)($victim['asset'] ?? '')));
        $amount = $this->floorAmount(min(max(0.0,(float)$victim['amount']),$available),(int)($market['base_precision'] ?? 8));
        if ($amount <= 0.0) return $this->result('no_reduction','victim_balance_unavailable',['position_id'=>(int)$victim['id']]);

        $reference = (float)(($market['best_bid'] ?? 0) > 0 ? $market['best_bid'] : ($market['price'] ?? 0));
        if ($reference <= 0.0) return $this->result('no_reduction','victim_price_unavailable');
        $buffer = self::dynamicExitBufferPercent($market,$victim);
        $priceBound = max(0.00000001,$reference*(1.0-($buffer/100.0)));

        $attempt = $this->existingCapacityAttempt($pdo,(int)$victim['id'],$effective);
        if (($attempt['active'] ?? false) === true) {
            return $this->result('waiting_reconcile','existing_capacity_exit_inflight',[
                'position_id'=>(int)$victim['id'],'identifier'=>$attempt['identifier'] ?? null,
            ]);
        }
        $identifier = $this->capacityIdentifier($pdo,(int)$victim['id'],$effective);

        $created = $this->orders->create([
            'symbol'=>$symbol,'amount1'=>$amount,'price'=>$priceBound,'mode'=>'market','type'=>'sell','identifier'=>$identifier,
        ],'autotrade_nobitex_position_limit');
        $remote = is_array($created['order'] ?? null) ? $created['order'] : [];
        $exchangeId = trim((string)($remote['id'] ?? ''));

        $stmt = $pdo->prepare(
            "UPDATE nobitex_autotrade_positions
             SET status='pending_close',exit_identifier=:identifier,exit_order_local_id=:local,
                 exit_exchange_order_id=:exchange_id,updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND status='open'"
        );
        $stmt->execute([
            ':identifier'=>$identifier,':local'=>$created['local_id'] ?? null,
            ':exchange_id'=>$exchangeId !== '' ? $exchangeId : null,':id'=>(int)$victim['id'],
        ]);

        if ($stmt->rowCount() !== 1) {
            $this->event($pdo,'error','nobitex.capacity.local_state_conflict',[
                'position_id'=>(int)$victim['id'],'identifier'=>$identifier,'order_local_id'=>$created['local_id'] ?? null,
            ]);
            return $this->result('sell_submitted','state_reconcile_required',['order'=>$created]);
        }

        $this->event($pdo,'info','nobitex.capacity.sell_submitted',[
            'position_id'=>(int)$victim['id'],'symbol'=>$symbol,'amount'=>$amount,
            'active_positions_before'=>$activeCount,'configured_max'=>$configured,'effective_max'=>$effective,
            'remaining_excess_after_submit'=>max(0,$activeCount-$effective-1),
            'victim_score'=>round((float)$victim['victim_score'],5),
            'forward_edge_percent'=>round((float)$victim['forward_edge_percent'],4),
            'net_pnl_percent'=>round((float)$victim['unrealized_net_pnl_percent'],4),
            'spread_percent'=>round((float)$victim['spread_percent'],4),
            'depth_quote'=>(float)$victim['depth_quote'],'volatility_percent'=>round((float)$victim['volatility_percent'],4),
            'dynamic_exit_buffer_percent'=>$buffer,'price_bound'=>$priceBound,'identifier'=>$identifier,
        ]);

        return $this->result('sell_submitted','position_limit_reduction',[
            'position'=>['position_id'=>(int)$victim['id'],'symbol'=>$symbol,'amount'=>$amount,'local_id'=>$created['local_id'] ?? null,'exchange_order_id'=>$exchangeId ?: null],
            'active_positions_before'=>$activeCount,'configured_max'=>$configured,'effective_max'=>$effective,
            'remaining_excess_after_submit'=>max(0,$activeCount-$effective-1),
            'execution'=>['reference_price'=>$reference,'price_bound'=>$priceBound,'dynamic_exit_buffer_percent'=>$buffer],
            'selection'=>['victim_score'=>round((float)$victim['victim_score'],5),'forward_edge_percent'=>round((float)$victim['forward_edge_percent'],4),'net_pnl_percent'=>round((float)$victim['unrealized_net_pnl_percent'],4),'spread_percent'=>round((float)$victim['spread_percent'],4),'depth_quote'=>(float)$victim['depth_quote']],
        ]);
    }

    public static function victimScore(array $position): float
    {
        $edge = (float)($position['forward_edge_percent'] ?? 0.0);
        $pnl = (float)($position['unrealized_net_pnl_percent'] ?? 0.0);
        $spread = max(0.0,(float)($position['spread_percent'] ?? 0.0));
        $exitCost = max(0.0,(float)($position['estimated_exit_cost_percent'] ?? 0.0));
        $volatility = max(0.0,(float)($position['volatility_percent'] ?? 0.0));
        $depth = max(0.0,(float)($position['depth_quote'] ?? 0.0));
        $liquidityBonus = $depth > 0.0 ? min(1.0,log10(1.0+$depth)/8.0) : 0.0;
        $openedAt = trim((string)($position['opened_at'] ?? ''));
        $ageHours = 0.0;
        if ($openedAt !== '') {
            $ts = strtotime($openedAt.' UTC');
            if ($ts !== false) $ageHours = min(72.0,max(0.0,(time()-$ts)/3600.0));
        }
        // Lower score is a better reduction candidate. Weak forward edge matters
        // most; cheap/liquid exits are preferred when expected value is similar.
        return ($edge*1.60)+($pnl*0.35)+($liquidityBonus*0.25)-($spread*0.90)-($exitCost*0.70)-($volatility*0.12)-min(0.30,$ageHours/240.0);
    }

    public static function dynamicExitBufferPercent(array $market,array $position=[]): float
    {
        $spread=max(0.0,(float)($market['spread_percent'] ?? $position['spread_percent'] ?? 0.0));
        $signal=is_array($market['signal'] ?? null)?$market['signal']:[];
        $indicators=is_array($signal['indicators'] ?? null)?$signal['indicators']:[];
        $vol=max(0.0,(float)($indicators['volatility_percent'] ?? $position['volatility_percent'] ?? 0.0));
        $depth=max(0.0,(float)($market['depth_quote'] ?? $position['depth_quote'] ?? 0.0));
        $shallowPenalty=$depth<=0.0?0.35:($depth<1000000.0?0.20:($depth<5000000.0?0.10:0.0));
        $buffer=0.08+($spread*1.35)+($vol*0.10)+$shallowPenalty;
        return round(max(0.12,min(1.20,$buffer)),4);
    }

    private static function signalEdge(array $signal): float
    {
        foreach (['tradable_net_edge_percent','effective_tradable_net_edge_percent','expected_net_edge_percent'] as $key) {
            if (is_numeric($signal[$key] ?? null)) return (float)$signal[$key];
        }
        return 0.0;
    }

    private function existingCapacityAttempt(PDO $pdo,int $positionId,int $effective):array
    {
        $prefix='cap'.$positionId.'m'.$effective.'a';
        $stmt=$pdo->prepare("SELECT identifier,status,local_id,exchange_order_id FROM orders WHERE exchange_name='nobitex' AND side='sell' AND source='autotrade_nobitex_position_limit' AND identifier LIKE :prefix ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([':prefix'=>$prefix.'%']);
        $row=$stmt->fetch();
        if(!$row)return['active'=>false];
        $state=strtolower((string)($row['status']??''));
        return ['active'=>in_array($state,['submitting','submitted','pending','filled'],true),'identifier'=>$row['identifier']??null,'status'=>$state];
    }

    private function capacityIdentifier(PDO $pdo,int $positionId,int $effective):string
    {
        $prefix='cap'.$positionId.'m'.$effective.'a';
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM orders WHERE exchange_name='nobitex' AND source='autotrade_nobitex_position_limit' AND identifier LIKE :prefix");
        $stmt->execute([':prefix'=>$prefix.'%']);
        $attempt=max(1,(int)$stmt->fetchColumn()+1);
        return substr($prefix.$attempt,0,32);
    }

    private function walletAvailable(array $response,array $assets):float
    {
        $assets=array_map(static fn(string $v):string=>strtoupper($v),$assets);
        $rows=$response['wallets']??$response['data']??$response;
        if(is_array($rows)&&!array_is_list($rows)&&is_array($rows['wallets']??null))$rows=$rows['wallets'];
        if(!is_array($rows))return 0.0;
        foreach($rows as $row){
            if(!is_array($row))continue;$asset=strtoupper((string)($row['currency']??$row['asset']??$row['currencyCode']??''));
            if(!in_array($asset,$assets,true))continue;
            foreach(['activeBalance','available','free','balance'] as $key)if(array_key_exists($key,$row)&&is_numeric($row[$key]))return max(0.0,(float)$row[$key]);
        }
        return 0.0;
    }

    private function assetAliases(string $asset):array
    {
        $asset=strtoupper(trim($asset));
        return match($asset){'TON','GRAM','TONCOIN'=>['TON','GRAM','TONCOIN'],default=>[$asset]};
    }

    private function floorAmount(float $amount,int $precision):float
    {
        $precision=max(0,min(18,$precision));$factor=10**$precision;return floor($amount*$factor)/$factor;
    }

    private function setting(PDO $pdo,string $key):?string
    {
        $stmt=$pdo->prepare('SELECT value_text FROM settings WHERE key_name=:key LIMIT 1');$stmt->execute([':key'=>$key]);$value=$stmt->fetchColumn();return$value===false?null:(string)$value;
    }

    private function intSetting(PDO $pdo,string $key,int $default,int $min,int $max):int
    {
        $value=$this->setting($pdo,$key);return is_numeric($value)?max($min,min($max,(int)$value)):$default;
    }

    private function event(PDO $pdo,string $level,string $event,array $context=[]):void
    {
        $pdo->prepare('INSERT INTO nobitex_autotrade_events (level,event_name,context_json,created_at) VALUES (:level,:event,:context,UTC_TIMESTAMP())')->execute([
            ':level'=>$level,':event'=>$event,':context'=>$context===[]?null:json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function result(string $status,string $reason,array $extra=[]):array
    {
        return ['status'=>$status,'exchange'=>'nobitex','reason'=>$reason,'time_utc'=>gmdate(DATE_ATOM)]+$extra;
    }
}
