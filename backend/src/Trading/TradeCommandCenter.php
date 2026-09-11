<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;
use Trade\Support\IranClock;

/**
 * Read-optimized control/observability facade shared by Admin and Android.
 * No method in snapshot() can place an order. Mutating methods are explicit.
 */
final class TradeCommandCenter
{
    private const HISTORY_LIMIT = 80;
    private const SETTINGS_KEYS = [
        'quote_asset','risk_profile','position_percent','max_position_percent',
        'stop_loss_percent','take_profit_percent','daily_loss_limit_percent',
        'min_signal_score','cooldown_minutes','nobitex_max_positions',
        'nobitex_scan_limit','nobitex_portfolio_exposure_percent',
        'nobitex_max_pending_orders','nobitex_pending_timeout_seconds',
    ];

    public function snapshot(?PDO $pdo = null): array
    {
        NobitexSchema::ensure();
        $pdo ??= Database::connection();
        $this->ensureStorage($pdo);

        $controller = new BotController();
        $status = $controller->status();
        $settings = $this->currentSettings($status);
        $this->captureSettingsIfChanged($pdo, $settings, 'observed', 'وضعیت ثبت‌شده');

        $analytics = $this->safe(fn() => (new NobitexPerformanceAnalytics())->snapshot($pdo, 30), []);
        $intelligence = $this->safe(fn() => (new NobitexPortfolioIntelligence())->snapshot($pdo), []);
        $timeline = $this->safe(fn() => (new NobitexTradeTimeline())->snapshot($pdo, 50), ['items'=>[]]);
        $global = $this->globalPortfolio($pdo);
        $positions = $this->positionDetails($pdo);
        $market = $this->marketRadar($pdo);
        $heatmap = $this->correlationHeatmap($pdo, $positions);
        $shadow = $this->shadowSummary($pdo);
        $alerts = $this->alerts($pdo, $status, $intelligence);
        $circuit = $this->entryCircuit($pdo);
        $emergency = $this->emergencyMode($pdo);

        $nobitex = is_array($status['exchanges']['nobitex'] ?? null) ? $status['exchanges']['nobitex'] : [];
        $capacity = is_array($nobitex['portfolio_capacity'] ?? null) ? $nobitex['portfolio_capacity'] : [];
        $effectiveMax = $this->intSetting($pdo, 'nobitex_effective_max_positions', (int)($capacity['max_positions'] ?? 0), 0, 20);
        $summaryIrt = is_array($analytics['summary_by_quote']['IRT'] ?? null) ? $analytics['summary_by_quote']['IRT'] : [];
        $drawdown = is_array($intelligence['drawdown'] ?? null) ? $intelligence['drawdown'] : [];
        $activeCount = (int)($capacity['active_positions'] ?? count($positions));
        $configuredMax = (int)($capacity['max_positions'] ?? ($settings['nobitex_max_positions'] ?? 0));
        $risk = $this->riskLabel((float)($drawdown['current_drawdown_percent'] ?? 0), $circuit['open'], $alerts);

        $latestDecision = $this->decisionExplainability($pdo);
        $activity = $this->naturalActivity((array)($timeline['items'] ?? []));
        $rules = $this->notificationRules($pdo);

        return [
            'model'=>'trade_command_center_v1',
            'headline'=>[
                'portfolio_value_irt'=>(float)($global['portfolio_value_irt'] ?? 0),
                'today_net_pnl_irt'=>(float)($summaryIrt['today_net_pnl'] ?? 0),
                'month_net_pnl_irt'=>(float)($summaryIrt['month_net_pnl'] ?? 0),
                'current_drawdown_percent'=>(float)($drawdown['current_drawdown_percent'] ?? 0),
                'active_positions'=>$activeCount,
                'configured_max_positions'=>$configuredMax,
                'effective_max_positions'=>$effectiveMax > 0 ? $effectiveMax : $configuredMax,
                'bot_state'=>$this->botState($nobitex, $emergency),
            ],
            'status_strip'=>[
                'bot'=>($nobitex['bot_enabled'] ?? false) ? 'live' : 'off',
                'api'=>($nobitex['credentials_configured'] ?? false) ? 'ready' : 'missing',
                'live_execution'=>($nobitex['live_execution_enabled'] ?? false) ? 'on' : 'off',
                'circuit'=>$circuit['open'] ? 'open' : 'closed',
                'circuit_reason'=>$circuit['reason'],
                'positions'=>$activeCount,
                'position_limit'=>$effectiveMax > 0 ? $effectiveMax : $configuredMax,
                'risk'=>$risk,
                'emergency_mode'=>$emergency,
                'cron_healthy'=>(bool)($status['cron_health']['healthy'] ?? false),
            ],
            'global_portfolio'=>$global,
            'positions'=>$positions,
            'alerts'=>$alerts,
            'decision_explainability'=>$latestDecision,
            'activity_timeline'=>$activity,
            'market_radar'=>$market['radar'],
            'opportunity_ranking'=>$market['ranking'],
            'risk_heatmap'=>$heatmap,
            'performance'=>[
                'by_strategy'=>$analytics['strategy_performance'] ?? [],
                'by_coin'=>$analytics['asset_performance'] ?? [],
                'summary_by_quote'=>$analytics['summary_by_quote'] ?? [],
                'best_asset'=>$analytics['best_asset'] ?? null,
                'worst_asset'=>$analytics['worst_asset'] ?? null,
                'edge_calibration'=>$analytics['edge_calibration'] ?? [],
            ],
            'equity_curve'=>$analytics['daily_series_by_quote']['IRT'] ?? [],
            'reports'=>$this->reports($analytics),
            'shadow'=>$shadow,
            'settings'=>[
                'current'=>$settings,
                'presets'=>$this->presets(),
                'risk_score'=>$this->settingsRiskScore($settings),
            ],
            'notification_rules'=>$rules,
            'emergency'=>[
                'mode'=>$emergency,
                'entry_circuit'=>$circuit,
                'supported_modes'=>['normal','pause_buys','graceful_close','full_stop'],
            ],
            'generated_at'=>gmdate(DATE_ATOM),
            'generated_at_iran'=>IranClock::nowPayload(),
        ];
    }

    public function presets(): array
    {
        return [
            'safe'=>[
                'label'=>'محافظه‌کار','risk_profile'=>'safe','position_percent'=>2.0,'max_position_percent'=>6.0,
                'stop_loss_percent'=>3.0,'take_profit_percent'=>5.0,'daily_loss_limit_percent'=>2.0,
                'min_signal_score'=>82,'cooldown_minutes'=>60,'nobitex_max_positions'=>6,'nobitex_scan_limit'=>12,
                'nobitex_portfolio_exposure_percent'=>35.0,'nobitex_max_pending_orders'=>1,'nobitex_pending_timeout_seconds'=>60,
            ],
            'balanced'=>[
                'label'=>'متعادل','risk_profile'=>'balanced','position_percent'=>3.0,'max_position_percent'=>8.0,
                'stop_loss_percent'=>3.0,'take_profit_percent'=>6.0,'daily_loss_limit_percent'=>3.0,
                'min_signal_score'=>78,'cooldown_minutes'=>45,'nobitex_max_positions'=>8,'nobitex_scan_limit'=>16,
                'nobitex_portfolio_exposure_percent'=>50.0,'nobitex_max_pending_orders'=>2,'nobitex_pending_timeout_seconds'=>60,
            ],
            'aggressive'=>[
                'label'=>'تهاجمی','risk_profile'=>'aggressive','position_percent'=>5.0,'max_position_percent'=>12.0,
                'stop_loss_percent'=>4.0,'take_profit_percent'=>8.0,'daily_loss_limit_percent'=>5.0,
                'min_signal_score'=>72,'cooldown_minutes'=>30,'nobitex_max_positions'=>10,'nobitex_scan_limit'=>20,
                'nobitex_portfolio_exposure_percent'=>70.0,'nobitex_max_pending_orders'=>3,'nobitex_pending_timeout_seconds'=>60,
            ],
        ];
    }

    public function previewSettings(array $input, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $current = $this->currentSettings((new BotController())->status());
        $proposed = $this->sanitizeSettings($input, $current);
        $before = $this->settingsRiskScore($current);
        $after = $this->settingsRiskScore($proposed);
        $changes = [];
        foreach (self::SETTINGS_KEYS as $key) {
            if (!array_key_exists($key, $proposed) || !array_key_exists($key, $current)) continue;
            if ((string)$proposed[$key] === (string)$current[$key]) continue;
            $changes[] = ['key'=>$key,'before'=>$current[$key],'after'=>$proposed[$key]];
        }
        return [
            'model'=>'settings_risk_preview_v1',
            'current'=>$current,
            'proposed'=>$proposed,
            'changes'=>$changes,
            'risk_before'=>$before,
            'risk_after'=>$after,
            'risk_delta'=>round($after['score']-$before['score'],2),
            'impact'=>$after['score']>$before['score']+4?'higher_risk':($after['score']<$before['score']-4?'lower_risk':'similar_risk'),
            'note'=>'این ارزیابی یک شاخص کنترلی است و پیش‌بینی سود یا زیان آینده نیست.',
        ];
    }

    public function applyPreset(string $name, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $name = strtolower(trim($name));
        $presets = $this->presets();
        if (!isset($presets[$name])) throw new \InvalidArgumentException('Unknown settings preset.');
        $before = $this->currentSettings((new BotController())->status());
        $this->captureSettingsIfChanged($pdo, $before, 'preset:'.$name, 'قبل از اعمال preset', true);
        $payload = $presets[$name]; unset($payload['label']);
        (new BotController())->updateSettings($payload);
        $after = $this->currentSettings((new BotController())->status());
        $this->captureSettingsIfChanged($pdo, $after, 'preset:'.$name, 'بعد از اعمال preset', true);
        return ['preset'=>$name,'settings'=>$after,'preview'=>$this->previewSettings($after,$pdo)];
    }

    public function history(?PDO $pdo = null, int $limit = 30): array
    {
        $pdo ??= Database::connection(); $this->ensureStorage($pdo);
        $limit=max(1,min(self::HISTORY_LIMIT,$limit));
        $rows=$pdo->query("SELECT id,source,label,settings_json,created_at FROM trade_settings_history ORDER BY id DESC LIMIT {$limit}")->fetchAll();
        foreach($rows as &$row){$row['settings']=json_decode((string)$row['settings_json'],true)?:[];unset($row['settings_json']);}
        unset($row); return $rows;
    }

    public function rollback(int $historyId, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection(); $this->ensureStorage($pdo);
        $stmt=$pdo->prepare('SELECT settings_json FROM trade_settings_history WHERE id=:id LIMIT 1');$stmt->execute([':id'=>$historyId]);
        $raw=$stmt->fetchColumn();if($raw===false)throw new \InvalidArgumentException('Settings history item not found.');
        $target=json_decode((string)$raw,true);if(!is_array($target))throw new \RuntimeException('Settings history payload is invalid.');
        $before=$this->currentSettings((new BotController())->status());
        $this->captureSettingsIfChanged($pdo,$before,'rollback:'.$historyId,'قبل از بازگردانی',true);
        (new BotController())->updateSettings($this->sanitizeSettings($target,$before));
        $after=$this->currentSettings((new BotController())->status());
        $this->captureSettingsIfChanged($pdo,$after,'rollback:'.$historyId,'بعد از بازگردانی',true);
        return ['rolled_back_to'=>$historyId,'settings'=>$after];
    }

    public function setEmergencyMode(string $mode, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $mode=strtolower(trim($mode));
        if(!in_array($mode,['normal','pause_buys','graceful_close','full_stop'],true))throw new \InvalidArgumentException('Unsupported emergency mode.');
        $current=$this->emergencyMode($pdo);
        $this->setSetting($pdo,'trade_emergency_mode',$mode);
        $this->setSetting($pdo,'trade_emergency_changed_at',gmdate('Y-m-d H:i:s'));
        $controller=new BotController();
        if($mode==='full_stop'){
            $controller->setKillSwitch(true);
        }elseif($mode==='normal'){
            $controller->setKillSwitch(false);
            if(str_starts_with((string)($this->setting($pdo,'nobitex_entry_circuit_reason')??''),'emergency_')){
                $this->setSetting($pdo,'nobitex_entry_circuit_until','');
                $this->setSetting($pdo,'nobitex_entry_circuit_reason','');
            }
        }else{
            $this->setSetting($pdo,'nobitex_entry_circuit_until','2099-12-31 23:59:59');
            $this->setSetting($pdo,'nobitex_entry_circuit_reason','emergency_'.$mode);
        }
        $pdo->prepare('INSERT INTO nobitex_autotrade_events (level,event_name,context_json,created_at) VALUES (\'warning\',\'trade.emergency.mode_changed\',:c,UTC_TIMESTAMP())')->execute([
            ':c'=>json_encode(['from'=>$current,'to'=>$mode],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        try{(new TradeNotificationCenter())->emit('emergency:'.time(),'risk',$mode==='normal'?'info':'critical','حالت اضطراری تغییر کرد','وضعیت جدید: '.$mode,['from'=>$current,'to'=>$mode],$pdo);}catch(\Throwable){}
        return ['mode'=>$mode,'previous'=>$current,'graceful_action'=>$mode==='graceful_close'?(new NobitexEmergencyController())->enforce($pdo):null];
    }

    public function emergencyMode(?PDO $pdo = null): string
    {
        $pdo ??= Database::connection();
        $mode=strtolower(trim((string)($this->setting($pdo,'trade_emergency_mode')??'normal')));
        return in_array($mode,['normal','pause_buys','graceful_close','full_stop'],true)?$mode:'normal';
    }

    public function notificationRules(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $defaults=['enabled'=>true,'min_priority'=>'warning','categories'=>['risk','execution','system','trade','performance','rotation'],'drawdown_percent'=>2.0,'pnl_loss_percent'=>2.0,'circuit_open'=>true,'api_failures'=>true,'pending_watchdog'=>true];
        $raw=$this->setting($pdo,'trade_notification_rules');$decoded=$raw!==null?json_decode($raw,true):null;
        if(!is_array($decoded))return$defaults;
        return array_replace($defaults,$decoded);
    }

    public function configureNotificationRules(array $input, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();$current=$this->notificationRules($pdo);
        $allowedPriorities=['info','success','warning','critical'];
        $rules=$current;
        if(array_key_exists('enabled',$input))$rules['enabled']=(bool)$input['enabled'];
        if(isset($input['min_priority'])&&in_array((string)$input['min_priority'],$allowedPriorities,true))$rules['min_priority']=(string)$input['min_priority'];
        if(isset($input['categories'])&&is_array($input['categories']))$rules['categories']=array_values(array_slice(array_unique(array_map('strval',$input['categories'])),0,20));
        foreach(['drawdown_percent','pnl_loss_percent']as$key)if(isset($input[$key])&&is_numeric($input[$key]))$rules[$key]=max(0.1,min(50.0,(float)$input[$key]));
        foreach(['circuit_open','api_failures','pending_watchdog']as$key)if(array_key_exists($key,$input))$rules[$key]=(bool)$input[$key];
        $this->setSetting($pdo,'trade_notification_rules',json_encode($rules,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return$rules;
    }

    public function setShadowMode(bool $enabled, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();$this->setSetting($pdo,'trade_shadow_mode_enabled',$enabled?'1':'0');
        return['enabled'=>$enabled,'summary'=>$this->shadowSummary($pdo)];
    }

    public function tradeReplay(int $positionId, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();NobitexSchema::ensure();
        $stmt=$pdo->prepare('SELECT * FROM nobitex_autotrade_positions WHERE id=:id LIMIT 1');$stmt->execute([':id'=>$positionId]);$position=$stmt->fetch();
        if(!$position)throw new \InvalidArgumentException('Position not found.');
        $pnlStmt=$pdo->prepare('SELECT * FROM nobitex_autotrade_pnl WHERE position_id=:id ORDER BY id ASC');$pnlStmt->execute([':id'=>$positionId]);$pnl=$pnlStmt->fetchAll();
        $from=(string)($position['created_at']??'1970-01-01 00:00:00');$to=(string)($position['closed_at']??gmdate('Y-m-d H:i:s'));
        $sig=$pdo->prepare('SELECT id,action,score,price,details_json,executed,created_at FROM nobitex_autotrade_signals WHERE symbol=:s AND created_at BETWEEN :f AND :t ORDER BY id ASC LIMIT 120');
        $sig->execute([':s'=>$position['symbol'],':f'=>$from,':t'=>$to]);$signals=$sig->fetchAll();
        foreach($signals as &$s){$d=json_decode((string)($s['details_json']??''),true);$s['details']=is_array($d)?$d:[];unset($s['details_json']);}unset($s);
        $steps=[['type'=>'entry','time'=>$position['opened_at']??$position['created_at'],'text'=>'پوزیشن '.$position['symbol'].' با قیمت ورود ثبت شد.']];
        foreach($signals as $s)$steps[]=['type'=>'signal','time'=>$s['created_at'],'text'=>strtoupper((string)$s['action']).' • score '.(int)$s['score'],'signal'=>$s];
        if(!empty($position['closed_at']))$steps[]=['type'=>'exit','time'=>$position['closed_at'],'text'=>'پوزیشن بسته شد.','pnl'=>$pnl];
        usort($steps,static fn(array$a,array$b):int=>strcmp((string)$a['time'],(string)$b['time']));
        return['model'=>'trade_replay_v1','position'=>$position,'pnl'=>$pnl,'steps'=>$steps];
    }

    public function strategyLab(array $input, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        $current=$this->currentSettings((new BotController())->status());$proposed=$this->sanitizeSettings($input,$current);
        $rows=$pdo->query('SELECT pnl_percent FROM nobitex_autotrade_pnl ORDER BY id DESC LIMIT 200')->fetchAll();
        $returns=[];foreach(array_reverse($rows)as$row)if(is_numeric($row['pnl_percent']??null))$returns[]=(float)$row['pnl_percent'];
        $scale=max(0.05,(float)$proposed['position_percent'])/max(0.05,(float)$current['position_percent']);
        $stop=max(0.1,(float)$proposed['stop_loss_percent']);$take=max(0.1,(float)$proposed['take_profit_percent']);
        $sim=[];foreach($returns as$r){$scaled=$r*$scale;$sim[]=max(-$stop,min($take,$scaled));}
        $wins=count(array_filter($sim,static fn(float$v):bool=>$v>0));$equity=100.0;$peak=100.0;$maxDd=0.0;
        foreach($sim as$r){$equity*=max(0.01,1+$r/100);$peak=max($peak,$equity);$maxDd=max($maxDd,$peak>0?(($peak-$equity)/$peak)*100:0);}
        return[
            'model'=>'historical_scenario_lab_v1','live_orders'=>false,'samples'=>count($sim),'settings'=>$proposed,
            'win_rate_percent'=>$sim!==[]?round($wins/count($sim)*100,2):0.0,
            'average_simulated_return_percent'=>$sim!==[]?round(array_sum($sim)/count($sim),4):0.0,
            'compounded_equity_index'=>round($equity,4),'max_drawdown_percent'=>round($maxDd,4),
            'note'=>'این بخش سناریوی چه-می‌شد-اگر روی معاملات تاریخی است؛ بک‌تست کامل دفتر سفارش و تضمین عملکرد آینده نیست.',
        ];
    }

    public function captureCurrentSettings(string $source='manual', string $label='snapshot', ?PDO $pdo=null): array
    {
        $pdo??=Database::connection();$settings=$this->currentSettings((new BotController())->status());
        $this->captureSettingsIfChanged($pdo,$settings,$source,$label,true);return$settings;
    }

    private function positionDetails(PDO $pdo): array
    {
        $rows=$pdo->query("SELECT * FROM nobitex_autotrade_positions WHERE status IN ('pending_open','open','pending_close') ORDER BY id DESC LIMIT 30")->fetchAll();
        $risk=new RiskManager();
        foreach($rows as &$row){
            $entry=max(0.0,(float)$row['entry_price']);$mark=max(0.0,(float)($row['mark_price']??0));if($mark<=0)$mark=$entry;
            $amount=max(0.0,(float)$row['amount']);$gross=($mark-$entry)*$amount;
            $net=is_numeric($row['unrealized_net_pnl']??null)?(float)$row['unrealized_net_pnl']:$gross-(float)($row['entry_fee_quote']??0)-(float)($row['estimated_exit_fee_quote']??0);
            $netPct=is_numeric($row['unrealized_net_pnl_percent']??null)?(float)$row['unrealized_net_pnl_percent']:($entry*$amount>0?$net/($entry*$amount)*100:0);
            $opened=!empty($row['opened_at'])?strtotime((string)$row['opened_at'].' UTC'):false;
            $row['mark_price']=$mark;$row['unrealized_net_pnl']=$net;$row['unrealized_net_pnl_percent']=round($netPct,4);
            $row['holding_seconds']=$opened!==false?max(0,time()-$opened):0;
            $row['probable_exit_reason']=$risk->exitReason($mark,$row,'hold');
            $row['total_estimated_fees_quote']=(float)($row['entry_fee_quote']??0)+(float)($row['estimated_exit_fee_quote']??0);
        }unset($row);return$rows;
    }

    private function decisionExplainability(PDO $pdo): array
    {
        $signal=$pdo->query('SELECT id,symbol,action,score,price,details_json,executed,created_at FROM nobitex_autotrade_signals ORDER BY id DESC LIMIT 1')->fetch()?:[];
        $details=json_decode((string)($signal['details_json']??''),true);if(!is_array($details))$details=[];
        $event=$pdo->query("SELECT event_name,context_json,created_at FROM nobitex_autotrade_events ORDER BY id DESC LIMIT 1")->fetch()?:[];
        $ctx=json_decode((string)($event['context_json']??''),true);if(!is_array($ctx))$ctx=[];
        $reason=(string)($details['reason']??$ctx['reason']??$event['event_name']??'no_recent_decision');
        return[
            'symbol'=>$signal['symbol']??($ctx['symbol']??null),'action'=>$signal['action']??null,'score'=>isset($signal['score'])?(int)$signal['score']:null,
            'reason'=>$reason,'reason_fa'=>$this->reasonFa($reason),'strategy'=>$details['strategy_key']??$details['selected_strategy']['key']??null,
            'market_regime'=>$details['market_regime']['regime']??null,'expected_edge_percent'=>$details['expected_net_edge_percent']??null,
            'tradable_edge_percent'=>$details['tradable_net_edge_percent']??null,'spread_percent'=>$details['spread_percent']??null,
            'estimated_fee_percent'=>$details['estimated_total_fee_percent']??$details['round_trip_fee_percent']??null,
            'event'=>$event['event_name']??null,'created_at'=>$signal['created_at']??$event['created_at']??null,
        ];
    }

    private function marketRadar(PDO $pdo): array
    {
        $rows=$pdo->query("SELECT s.* FROM nobitex_autotrade_signals s JOIN (SELECT symbol,MAX(id) id FROM nobitex_autotrade_signals WHERE created_at>=(UTC_TIMESTAMP()-INTERVAL 6 HOUR) GROUP BY symbol) x ON x.id=s.id ORDER BY s.id DESC LIMIT 80")->fetchAll();
        $items=[];foreach($rows as$row){$d=json_decode((string)($row['details_json']??''),true);if(!is_array($d))$d=[];$edge=(float)($d['tradable_net_edge_percent']??$d['expected_net_edge_percent']??0);$spread=max(0.0,(float)($d['spread_percent']??0));$score=(int)($row['score']??0);$action=(string)($row['action']??'hold');
            $category=$action==='buy'&&$score>=82&&$edge>0.7&&$spread<0.8?'excellent':($action==='buy'&&$score>=72&&$edge>0?'good':($edge>-0.25&&$score>=55?'watch':'avoid'));
            $rank=($edge*25)+$score-($spread*8)+($action==='buy'?15:0);
            $items[]=['symbol'=>$row['symbol'],'action'=>$action,'score'=>$score,'edge_percent'=>round($edge,4),'spread_percent'=>round($spread,4),'category'=>$category,'rank_score'=>round($rank,3),'strategy'=>$d['strategy_key']??$d['selected_strategy']['key']??null,'regime'=>$d['market_regime']['regime']??null,'reason'=>$d['reason']??null,'created_at'=>$row['created_at']];
        }
        usort($items,static fn(array$a,array$b):int=>($b['rank_score']<=>$a['rank_score']));
        return['ranking'=>array_slice($items,0,10),'radar'=>$items];
    }

    private function correlationHeatmap(PDO $pdo,array $positions): array
    {
        $symbols=array_values(array_unique(array_filter(array_map(static fn(array$p):string=>(string)($p['symbol']??''),$positions))));
        $symbols=array_slice($symbols,0,10);$series=[];
        foreach($symbols as$s){$st=$pdo->prepare('SELECT price FROM nobitex_autotrade_signals WHERE symbol=:s ORDER BY id DESC LIMIT 60');$st->execute([':s'=>$s]);$prices=array_reverse(array_map('floatval',array_column($st->fetchAll(),'price')));$ret=[];for($i=1;$i<count($prices);$i++)if($prices[$i-1]>0)$ret[]=(($prices[$i]-$prices[$i-1])/$prices[$i-1])*100;$series[$s]=$ret;}
        $cells=[];foreach($symbols as$a)foreach($symbols as$b){$corr=$a===$b?1.0:NobitexPortfolioIntelligence::pearson($series[$a]??[],$series[$b]??[]);$cells[]=['x'=>$a,'y'=>$b,'correlation'=>$corr!==null?round($corr,4):null,'risk'=>$corr===null?'unknown':(abs($corr)>=0.86?'high':(abs($corr)>=0.65?'medium':'low'))];}
        return['symbols'=>$symbols,'cells'=>$cells,'threshold'=>0.86];
    }

    private function alerts(PDO $pdo,array $status,array $intelligence): array
    {
        $alerts=[];$n=$status['exchanges']['nobitex']??[];
        if(!($n['credentials_configured']??false))$alerts[]=$this->alert('critical','api','Nobitex API تنظیم نشده','اتصال صرافی برای پایش و معامله آماده نیست.');
        if(!($status['cron_health']['healthy']??false))$alerts[]=$this->alert('critical','system','Cron سالم نیست','آخرین اجرای موتور قدیمی یا ناموفق است.');
        $c=$this->entryCircuit($pdo);if($c['open'])$alerts[]=$this->alert('warning','risk','Circuit باز است',(string)($c['reason']??'ورود جدید موقتاً متوقف شده است.'));
        $fails=$this->intSetting($pdo,'nobitex_runtime_api_failures',0,0,1000);if($fails>0)$alerts[]=$this->alert($fails>=3?'critical':'warning','api','خطای متوالی API',$fails.' خطای متوالی ثبت شده است.');
        $pending=(int)($n['portfolio_capacity']['pending_orders']??0);if($pending>0)$alerts[]=$this->alert('warning','execution','سفارش Pending وجود دارد',$pending.' سفارش در انتظار reconcile/fill است.');
        $dd=(float)($intelligence['drawdown']['current_drawdown_percent']??0);if($dd>=2)$alerts[]=$this->alert($dd>=6?'critical':'warning','risk','Drawdown بالا','افت جاری '.round($dd,2).'% است.');
        $recent=(new TradeNotificationCenter())->recent(30,true,$pdo);foreach($recent as$r)if(in_array((string)$r['priority'],['warning','critical'],true))$alerts[]=['priority'=>$r['priority'],'category'=>$r['category'],'title'=>$r['title'],'body'=>$r['body'],'created_at'=>$r['created_at']];
        return array_slice($alerts,0,20);
    }

    private function shadowSummary(PDO $pdo): array
    {
        $enabled=$this->boolSetting($pdo,'trade_shadow_mode_enabled',true);
        try{$r=$pdo->query("SELECT COUNT(*) samples,AVG(return_15m) avg_15m,AVG(return_60m) avg_60m,AVG(return_240m) avg_240m,SUM(CASE WHEN return_60m>0 THEN 1 ELSE 0 END) positive_60m FROM nobitex_shadow_signal_outcomes")->fetch()?:[];$samples=(int)($r['samples']??0);return['enabled'=>$enabled,'samples'=>$samples,'average_return_15m'=>$r['avg_15m']!==null?round((float)$r['avg_15m'],4):null,'average_return_60m'=>$r['avg_60m']!==null?round((float)$r['avg_60m'],4):null,'average_return_240m'=>$r['avg_240m']!==null?round((float)$r['avg_240m'],4):null,'positive_60m_rate_percent'=>$samples>0?round(((int)($r['positive_60m']??0)/$samples)*100,2):null];}catch(\Throwable){return['enabled'=>$enabled,'samples'=>0];}
    }

    private function reports(array $analytics): array
    {
        $irt=$analytics['summary_by_quote']['IRT']??[];return['daily'=>['net_pnl'=>$irt['today_net_pnl']??0,'unit'=>'TOMAN'],'weekly'=>['net_pnl'=>$irt['week_net_pnl']??0,'unit'=>'TOMAN'],'monthly'=>['net_pnl'=>$irt['month_net_pnl']??0,'unit'=>'TOMAN'],'win_rate_percent'=>$irt['win_rate_percent']??0,'profit_factor'=>$irt['profit_factor']??0,'max_drawdown_absolute'=>$irt['max_drawdown_absolute']??0,'best_asset'=>$analytics['best_asset']??null,'worst_asset'=>$analytics['worst_asset']??null];
    }

    private function naturalActivity(array $items): array
    {
        $out=[];foreach(array_slice($items,0,30)as$i){$type=(string)($i['type']??'');$symbol=(string)($i['symbol']??'');$text=match($type){'buy'=>'خرید '.$symbol.' تأیید شد.','sell'=>'فروش '.$symbol.' تأیید شد'.(isset($i['pnl_percent'])?'؛ بازده '.round((float)$i['pnl_percent'],2).'%':'').'.','external_sell'=>'تغییر موجودی '.$symbol.' خارج از ربات شناسایی و همگام شد.',default=>'رویداد معاملاتی '.$symbol};$out[]=$i+['text_fa'=>$text];}return$out;
    }

    private function globalPortfolio(PDO $pdo): array
    {
        try{$svc=new NobitexOrderService();if(!$svc->credentialsConfigured())return['status'=>'unavailable','reason'=>'credentials_missing'];$client=$svc->client();$wallets=$client->wallets();$positions=$pdo->query("SELECT symbol,asset,quote_asset,amount,entry_price,mark_price,status FROM nobitex_autotrade_positions WHERE status IN ('pending_open','open','pending_close') ORDER BY id ASC LIMIT 30")->fetchAll();return['status'=>'ok']+(new NobitexPortfolioValuation())->snapshot($client,$wallets,$positions);}catch(\Throwable$e){return['status'=>'deferred','reason'=>'valuation_failed','message'=>mb_substr($e->getMessage(),0,240)];}
    }

    private function currentSettings(array $status): array
    {
        $s=is_array($status['settings']??null)?$status['settings']:[];$out=[];foreach(self::SETTINGS_KEYS as$k)if(array_key_exists($k,$s))$out[$k]=$s[$k];return$out;
    }

    private function sanitizeSettings(array $input,array $current):array
    {
        $out=$current;foreach(self::SETTINGS_KEYS as$k)if(array_key_exists($k,$input))$out[$k]=$input[$k];
        $out['quote_asset']=in_array(strtoupper((string)($out['quote_asset']??'IRT')),['IRT','USDT'],true)?strtoupper((string)$out['quote_asset']):'IRT';
        $out['risk_profile']=in_array(strtolower((string)($out['risk_profile']??'balanced')),['safe','balanced','aggressive'],true)?strtolower((string)$out['risk_profile']):'balanced';
        return$out;
    }

    private function settingsRiskScore(array $s):array
    {
        $exposure=(float)($s['nobitex_portfolio_exposure_percent']??60);$position=(float)($s['position_percent']??5);$max=(float)($s['max_position_percent']??10);$daily=(float)($s['daily_loss_limit_percent']??5);$stop=(float)($s['stop_loss_percent']??3);$positions=(int)($s['nobitex_max_positions']??5);$score=min(100,max(0,($exposure*.45)+($position*1.7)+($max*.65)+($daily*2)+($stop*.7)+($positions*.65)));$level=$score<35?'low':($score<58?'medium':($score<78?'high':'very_high'));return['score'=>round($score,2),'level'=>$level];
    }

    private function captureSettingsIfChanged(PDO $pdo,array $settings,string $source,string $label,bool $force=false):void
    {
        $this->ensureStorage($pdo);$json=json_encode($settings,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$last=$pdo->query('SELECT settings_json FROM trade_settings_history ORDER BY id DESC LIMIT 1')->fetchColumn();if(!$force&&$last!==false&&hash_equals(hash('sha256',(string)$last),hash('sha256',$json)))return;$stmt=$pdo->prepare('INSERT INTO trade_settings_history (source,label,settings_json,created_at) VALUES (:s,:l,:j,UTC_TIMESTAMP())');$stmt->execute([':s'=>substr($source,0,80),':l'=>mb_substr($label,0,160),':j'=>$json]);
    }

    private function ensureStorage(PDO $pdo):void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS trade_settings_history (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source VARCHAR(80) NOT NULL,label VARCHAR(160) NOT NULL,settings_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL,INDEX idx_trade_settings_history_created(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function entryCircuit(PDO $pdo):array
    {
        $until=trim((string)($this->setting($pdo,'nobitex_entry_circuit_until')??''));$ts=$until!==''?strtotime($until.' UTC'):false;$open=$ts!==false&&$ts>time();return['open'=>$open,'until'=>$open?gmdate(DATE_ATOM,$ts):null,'reason'=>$open?$this->setting($pdo,'nobitex_entry_circuit_reason'):null];
    }

    private function botState(array$n,string$emergency):string{if($emergency==='full_stop')return'full_stop';if($emergency==='graceful_close')return'closing';if($emergency==='pause_buys')return'buys_paused';return($n['bot_enabled']??false)&&($n['live_execution_enabled']??false)?'live':'idle';}
    private function riskLabel(float$dd,bool$circuit,array$alerts):string{if($circuit||$dd>=6)return'critical';if($dd>=3||count(array_filter($alerts,static fn(array$a):bool=>($a['priority']??'')==='critical'))>0)return'high';if($dd>=1.5)return'elevated';return'normal';}
    private function alert(string$p,string$c,string$t,string$b):array{return['priority'=>$p,'category'=>$c,'title'=>$t,'body'=>$b,'created_at'=>gmdate('Y-m-d H:i:s')];}
    private function reasonFa(string$r):string{return match($r){'no_buy_signal'=>'سیگنال خرید معتبر تشکیل نشده است.','risk_blocked'=>'محافظ ریسک ورود را رد کرده است.','portfolio_exposure_limit_reached'=>'سقف سرمایه درگیر پر شده است.','daily_loss_limit_reached'=>'حد زیان روزانه فعال شده است.','symbol_cooldown_active'=>'زمان استراحت این ارز هنوز تمام نشده است.','execution_signal_expired'=>'سیگنال تا زمان ارسال سفارش اعتبار خود را از دست داده است.','runtime_entry_circuit_open'=>'Circuit ایمنی ورود جدید را متوقف کرده است.',default=>$r};}
    private function safe(callable$fn,mixed$fallback):mixed{try{return$fn();}catch(\Throwable){return$fallback;}}
    private function setting(PDO$pdo,string$key):?string{$s=$pdo->prepare('SELECT value_text FROM settings WHERE key_name=:k LIMIT 1');$s->execute([':k'=>$key]);$v=$s->fetchColumn();return$v===false?null:(string)$v;}
    private function setSetting(PDO$pdo,string$key,string$value):void{$s=$pdo->prepare("INSERT INTO settings(key_name,value_text,updated_at)VALUES(:k,:v,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()");$s->execute([':k'=>$key,':v'=>$value]);}
    private function intSetting(PDO$pdo,string$key,int$default,int$min,int$max):int{$v=$this->setting($pdo,$key);return is_numeric($v)?max($min,min($max,(int)$v)):$default;}
    private function boolSetting(PDO$pdo,string$key,bool$default=false):bool{$v=$this->setting($pdo,$key);if($v===null)return$default;return in_array(strtolower(trim($v)),['1','true','yes','on'],true);}
}
