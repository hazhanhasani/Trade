<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;
use Trade\Support\IranClock;

final class NobitexPerformanceAnalytics
{
    public function snapshot(?PDO $pdo = null, int $days = 30): array
    {
        NobitexSchema::ensure();
        $pdo ??= Database::connection();
        $days = max(7, min(180, $days));

        $summary = [];
        $series = [];
        foreach (['IRT','USDT'] as $quote) {
            $summary[$quote] = $this->quoteSummary($pdo, $quote, $days);
            $series[$quote] = $this->dailySeries($pdo, $quote, $days);
        }

        $assets = $this->assetPerformance($pdo, $days);
        $edgeCalibration = $this->edgeCalibration($pdo, $days);
        $strategies = [];
        try { $strategies = (new NobitexPortfolioIntelligence())->snapshot($pdo)['strategy_performance'] ?? []; }
        catch (\Throwable) {}

        return [
            'model'=>'fee_aware_performance_analytics_v3_cross_currency_safe',
            'window_days'=>$days,
            'day_timezone'=>'Asia/Tehran',
            'calendar'=>'Solar Hijri',
            'display_units'=>['IRT'=>'TOMAN','USDT'=>'USDT'],
            'summary_by_quote'=>$summary,
            'daily_series_by_quote'=>$series,
            'best_asset'=>$assets[0] ?? null,
            'worst_asset'=>$assets !== [] ? $assets[count($assets)-1] : null,
            'asset_ranking_metric'=>'average_return_percent',
            'asset_performance'=>array_slice($assets,0,20),
            'edge_calibration'=>$edgeCalibration,
            'strategy_performance'=>$strategies,
            'generated_at'=>gmdate(DATE_ATOM),
            'generated_at_iran'=>IranClock::nowPayload(),
        ];
    }

    private function quoteSummary(PDO $pdo, string $quote, int $days): array
    {
        $interval = max(1, $days);
        $stmt=$pdo->prepare(
            "SELECT COUNT(*) trades,
                    COALESCE(SUM(COALESCE(net_pnl,pnl)),0) net_pnl,
                    COALESCE(SUM(CASE WHEN COALESCE(net_pnl,pnl)>0 THEN COALESCE(net_pnl,pnl) ELSE 0 END),0) gross_profit,
                    ABS(COALESCE(SUM(CASE WHEN COALESCE(net_pnl,pnl)<0 THEN COALESCE(net_pnl,pnl) ELSE 0 END),0)) gross_loss,
                    COALESCE(AVG(pnl_percent),0) avg_return_percent,
                    SUM(CASE WHEN COALESCE(net_pnl,pnl)>0 THEN 1 ELSE 0 END) wins,
                    SUM(CASE WHEN COALESCE(net_pnl,pnl)<0 THEN 1 ELSE 0 END) losses
             FROM nobitex_autotrade_pnl
             WHERE quote_asset=:quote AND created_at >= (UTC_TIMESTAMP() - INTERVAL {$interval} DAY)"
        );
        $stmt->execute([':quote'=>$quote]);
        $r=$stmt->fetch() ?: [];
        $trades=(int)($r['trades']??0);$wins=(int)($r['wins']??0);$profit=(float)($r['gross_profit']??0);$loss=(float)($r['gross_loss']??0);

        [$todayStart,$todayEnd]=IranClock::todayUtcRange();
        $today=$pdo->prepare("SELECT COALESCE(SUM(COALESCE(net_pnl,pnl)),0) FROM nobitex_autotrade_pnl WHERE quote_asset=:quote AND created_at>=:start AND created_at<:end");
        $today->execute([':quote'=>$quote,':start'=>$todayStart,':end'=>$todayEnd]);
        $week=$pdo->prepare("SELECT COALESCE(SUM(COALESCE(net_pnl,pnl)),0) FROM nobitex_autotrade_pnl WHERE quote_asset=:quote AND created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)");$week->execute([':quote'=>$quote]);
        $month=$pdo->prepare("SELECT COALESCE(SUM(COALESCE(net_pnl,pnl)),0) FROM nobitex_autotrade_pnl WHERE quote_asset=:quote AND created_at >= (UTC_TIMESTAMP() - INTERVAL 30 DAY)");$month->execute([':quote'=>$quote]);

        $net=(float)($r['net_pnl']??0);$todayValue=(float)$today->fetchColumn();$weekValue=(float)$week->fetchColumn();$monthValue=(float)$month->fetchColumn();
        if($quote==='IRT'){
            $net=NobitexDisplayMoney::quoteValue($net,$quote);
            $todayValue=NobitexDisplayMoney::quoteValue($todayValue,$quote);
            $weekValue=NobitexDisplayMoney::quoteValue($weekValue,$quote);
            $monthValue=NobitexDisplayMoney::quoteValue($monthValue,$quote);
            $profit=NobitexDisplayMoney::quoteValue($profit,$quote);
            $loss=NobitexDisplayMoney::quoteValue($loss,$quote);
        }
        $curve=$this->dailySeries($pdo,$quote,$days);$maxDrawdown=$this->maxAbsoluteDrawdown(array_column($curve,'cumulative_net_pnl'));
        return [
            'quote_asset'=>$quote,'display_unit'=>NobitexDisplayMoney::quoteUnit($quote),'trades'=>$trades,'wins'=>$wins,'losses'=>(int)($r['losses']??0),
            'win_rate_percent'=>$trades>0?round(($wins/$trades)*100,2):0.0,
            'net_pnl'=>round($net,8),'today_net_pnl'=>round($todayValue,8),
            'week_net_pnl'=>round($weekValue,8),'month_net_pnl'=>round($monthValue,8),
            'average_return_percent'=>round((float)($r['avg_return_percent']??0),4),
            'profit_factor'=>$loss>0?round($profit/$loss,4):($profit>0?999.0:0.0),
            'max_drawdown_absolute'=>round($maxDrawdown,8),
        ];
    }

    private function dailySeries(PDO $pdo, string $quote, int $days): array
    {
        $interval=max(2,$days+2);
        $stmt=$pdo->prepare("SELECT COALESCE(net_pnl,pnl) net_pnl,created_at FROM nobitex_autotrade_pnl WHERE quote_asset=:quote AND created_at >= (UTC_TIMESTAMP() - INTERVAL {$interval} DAY) ORDER BY created_at ASC");
        $stmt->execute([':quote'=>$quote]);
        $byDay=[];
        foreach($stmt->fetchAll() as $r){
            $day=IranClock::tehranDateFromUtc((string)$r['created_at']);
            $p=(float)$r['net_pnl'];
            if($quote==='IRT')$p=NobitexDisplayMoney::quoteValue($p,$quote);
            if(!isset($byDay[$day]))$byDay[$day]=['net_pnl'=>0.0,'trades'=>0,'wins'=>0,'sample_utc'=>(string)$r['created_at']];
            $byDay[$day]['net_pnl']+=$p;$byDay[$day]['trades']++;if($p>0)$byDay[$day]['wins']++;
        }
        $out=[];$cum=0.0;$now=IranClock::now();
        for($i=$days-1;$i>=0;$i--){
            $dt=$now->modify("-{$i} days");$key=$dt->format('Y-m-d');$r=$byDay[$key]??[];$p=(float)($r['net_pnl']??0);$cum+=$p;$trades=(int)($r['trades']??0);$wins=(int)($r['wins']??0);
            $fakeUtc=$dt->setTime(12,0)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $out[]=['day'=>IranClock::jalaliDateFromUtc($fakeUtc),'gregorian_day_iran'=>$key,'net_pnl'=>round($p,8),'cumulative_net_pnl'=>round($cum,8),'trades'=>$trades,'win_rate_percent'=>$trades>0?round(($wins/$trades)*100,2):0.0,'display_unit'=>NobitexDisplayMoney::quoteUnit($quote)];
        }
        return $out;
    }

    private function assetPerformance(PDO $pdo, int $days): array
    {
        $interval=max(1,$days);
        $rows=$pdo->query(
            "SELECT p.asset,p.quote_asset,COUNT(r.id) trades,
                    SUM(COALESCE(r.net_pnl,r.pnl)) net_pnl,AVG(r.pnl_percent) avg_return_percent,
                    SUM(CASE WHEN COALESCE(r.net_pnl,r.pnl)>0 THEN 1 ELSE 0 END) wins
             FROM nobitex_autotrade_pnl r JOIN nobitex_autotrade_positions p ON p.id=r.position_id
             WHERE r.created_at >= (UTC_TIMESTAMP() - INTERVAL {$interval} DAY)
             GROUP BY p.asset,p.quote_asset"
        )->fetchAll();
        foreach($rows as &$r){
            $trades=(int)$r['trades'];$quote=strtoupper((string)$r['quote_asset']);$net=(float)$r['net_pnl'];if($quote==='IRT')$net=NobitexDisplayMoney::quoteValue($net,$quote);
            $r['trades']=$trades;$r['net_pnl']=round($net,8);$r['display_unit']=NobitexDisplayMoney::quoteUnit($quote);$r['average_return_percent']=round((float)$r['avg_return_percent'],4);unset($r['avg_return_percent']);$r['win_rate_percent']=$trades>0?round(((int)$r['wins']/$trades)*100,2):0.0;unset($r['wins']);
            $r['ranking_metric']='average_return_percent';
        }
        unset($r);
        // IRT PnL is displayed in toman while USDT PnL remains USDT. Sorting by
        // raw/display money across those quotes is dimensionally invalid. Return
        // percentage is unitless and therefore safe for one cross-market ranking.
        usort($rows,static function(array $a,array $b):int{
            $byReturn=((float)$b['average_return_percent'])<=>((float)$a['average_return_percent']);
            if($byReturn!==0)return$byReturn;
            $byWin=((float)$b['win_rate_percent'])<=>((float)$a['win_rate_percent']);
            if($byWin!==0)return$byWin;
            return((int)$b['trades'])<=>((int)$a['trades']);
        });
        return $rows;
    }

    private function edgeCalibration(PDO $pdo, int $days): array
    {
        $interval=max(1,$days);
        try{
            $rows=$pdo->query(
                "SELECT i.entry_edge_percent,r.pnl_percent,COALESCE(r.net_pnl,r.pnl) net_pnl
                 FROM nobitex_intelligence_entries i
                 JOIN nobitex_autotrade_positions p ON p.entry_order_local_id=i.local_order_id
                 JOIN nobitex_autotrade_pnl r ON r.position_id=p.id
                 WHERE i.entry_edge_percent IS NOT NULL AND r.created_at >= (UTC_TIMESTAMP() - INTERVAL {$interval} DAY)
                 ORDER BY r.id DESC LIMIT 200"
            )->fetchAll();
        }catch(\Throwable){$rows=[];}
        if($rows===[])return['samples'=>0,'average_expected_edge_percent'=>null,'average_realized_return_percent'=>null,'average_error_percent'=>null,'directional_hit_rate_percent'=>null];
        $edge=0.0;$real=0.0;$hits=0;
        foreach($rows as $r){$e=(float)$r['entry_edge_percent'];$p=(float)$r['pnl_percent'];$edge+=$e;$real+=$p;if(($e>0&&$p>0)||($e<0&&$p<0))$hits++;}
        $n=count($rows);return['samples'=>$n,'average_expected_edge_percent'=>round($edge/$n,4),'average_realized_return_percent'=>round($real/$n,4),'average_error_percent'=>round(($real-$edge)/$n,4),'directional_hit_rate_percent'=>round(($hits/$n)*100,2)];
    }

    private function maxAbsoluteDrawdown(array $curve): float
    {
        $peak=0.0;$max=0.0;foreach($curve as $v){$x=(float)$v;$peak=max($peak,$x);$max=max($max,$peak-$x);}return$max;
    }
}
