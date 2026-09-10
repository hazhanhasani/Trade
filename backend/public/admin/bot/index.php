<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use Trade\Config;
use Trade\Trading\BotController;
use Trade\Updater;

if (!Config::installed()) { header('Location: /install/'); exit; }
session_name('trade_admin');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/admin']);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }
if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24));

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt(mixed $v, int $d=2): string { return number_format((float)$v, $d, '.', ','); }
function statusFa(string $s): string { return match($s) {
    'buy_submitted','first_buy_submitted'=>'سفارش خرید ارسال شد','sell_submitted'=>'سفارش فروش ارسال شد','profit_actions_processed'=>'معاملات سودمحور پردازش شد','holding_position'=>'در حال نگهداری پوزیشن','waiting_order'=>'در انتظار تکمیل سفارش','portfolio_full'=>'ظرفیت پورتفو تکمیل است','no_trade'=>'فعلاً معامله‌ای انجام نمی‌شود','blocked'=>'اجرای ربات مسدود است','disabled'=>'ربات خاموش است','failed'=>'خطا','success'=>'موفق',default=>$s,
}; }
function reasonFa(string $s): string { return match($s) {
    'no_candidate_passed_signal_and_risk_filters'=>'هیچ بازار فعلی پس از هزینه‌ها، Buffer و فیلتر ریسک شرایط ورود نداشت','no_eligible_markets'=>'بازار قابل‌اجرای کافی پیدا نشد','expected_net_profit_not_positive'=>'سود خالص مورد انتظار پس از هزینه‌ها مثبت نیست','positive_expected_net_profit_after_costs'=>'سود خالص مورد انتظار پس از هزینه‌ها مثبت است','edge_below_adaptive_safety_buffer'=>'Edge برای پوشش خطای پیش‌بینی و نویز بازار کافی نیست','positive_tradable_net_edge_after_costs_and_buffer'=>'Edge قابل معامله پس از هزینه‌ها و Buffer مثبت است','expected_forward_move_negative_after_exit_cost'=>'چشم‌انداز حرکت بعدی پس از هزینه خروج منفی شده است','spread_not_executable'=>'اسپرد برای اجرای امن مناسب نیست','market_quality_not_ready'=>'کیفیت داده یا اجرا کافی نیست','insufficient_internal_mtf_history'=>'تاریخچه چندبازه‌ای کافی نیست','score_below_threshold'=>'امتیاز سیگنال Bitpin به حد ورود نرسیده','buy_score_without_confirmation'=>'سیگنال Bitpin تأیید کامل ندارد','spread_too_wide'=>'فاصله خرید و فروش زیاد است','volatility_too_high'=>'نوسان کوتاه‌مدت بیش از حد است','portfolio_full'=>'ظرفیت پورتفو تکمیل است','portfolio_exposure_limit_reached'=>'سقف سرمایه درگیر پورتفو تکمیل است','pending_order_capacity_reached'=>'ظرفیت سفارش‌های Pending تکمیل است','minimum_order_rounding'=>'مبلغ سفارش پس از گرد کردن کمتر از حداقل صرافی است','minimum_order_exceeds_budget'=>'حداقل سفارش صرافی از بودجه پوزیشن بیشتر است','symbol_cooldown_active'=>'Cooldown این بازار هنوز تمام نشده','already_positioned'=>'برای این دارایی پوزیشن فعال وجود دارد','bot_disabled'=>'ربات خاموش است','live_execution_disabled'=>'ارسال واقعی خاموش است','kill_switch'=>'توقف اضطراری فعال است','credentials_missing'=>'API تنظیم نشده','no_quote_balance'=>'موجودی IRT/USDT کافی نیست','daily_loss_limit_reached'=>'حد زیان روزانه فعال شده','insufficient_balance'=>'موجودی آزاد کافی نیست','waiting_for_positive_market'=>'ربات منتظر Edge قابل معامله مثبت است',default=>$s,
}; }
function decisionLine(?array $d): string {
    if (!$d) return 'هنوز تصمیم ثبت نشده است.';
    $s=statusFa((string)($d['status']??'-')); $r=(string)($d['reason']??$d['error']??''); $selected=is_array($d['selected']??null)?$d['selected']:null; $tail='';
    if($selected){$tail=' — '.($selected['symbol']??'');if(isset($selected['tradable_net_edge_percent']))$tail.=' | Edge '.fmt($selected['tradable_net_edge_percent'],3).'%';elseif(isset($selected['expected_net_edge_percent']))$tail.=' | Net Edge '.fmt($selected['expected_net_edge_percent'],3).'%';elseif(isset($selected['signal_score'])&&(int)$selected['signal_score']!==0)$tail.=' | Score '.(int)$selected['signal_score'];}
    return $s.($r!==''?' — '.reasonFa($r):'').$tail;
}
function detailsOf(array $row): array { if(is_array($row['details']??null)) return $row['details']; $d=json_decode((string)($row['details_json']??''),true); return is_array($d)?$d:[]; }
function activeSymbolSet(array $positions): array { $set=[]; foreach($positions as $p)$set[strtoupper((string)($p['symbol']??''))]=true; return $set; }
function signalStateFa(array $row,array $activeSymbols): array {
    if((int)($row['executed']??0)===1)return['سفارش ایجاد شد','good']; $symbol=strtoupper((string)($row['symbol']??'')); $action=strtolower((string)($row['action']??'hold'));
    if(isset($activeSymbols[$symbol]))return[$action==='sell'?'سیگنال خروج':'پایش پوزیشن','info']; if($action==='hold')return['فقط تحلیل','']; if($action==='buy')return['فرصت؛ بدون سفارش','warn']; if($action==='sell')return['فروش؛ بدون پوزیشن','']; return['بدون سفارش',''];
}
function latestSignalsBySymbol(array $rows): array { $out=[]; foreach($rows as $row){$symbol=strtoupper((string)($row['symbol']??''));if($symbol!==''&&!isset($out[$symbol]))$out[$symbol]=$row;} return $out; }
function positionAnalytics(array $position,?array $signal): array {
    $entry=(float)($position['entry_price']??0); $amount=(float)($position['amount']??0); $current=$signal?(float)($signal['price']??0):0.0; if($current<=0)$current=$entry; $grossPct=$entry>0?(($current-$entry)/$entry)*100.0:0.0; $grossPnl=($current-$entry)*$amount; $details=$signal?detailsOf($signal):[]; $exitCost=max(0.0,(float)($details['estimated_exit_cost_percent']??0)); $netPct=$grossPct-$exitCost; $netPnl=$entry>0?($entry*$amount*($netPct/100.0)):0.0;
    return ['current'=>$current,'gross_pct'=>$grossPct,'gross_pnl'=>$grossPnl,'exit_cost_pct'=>$exitCost,'net_pct'=>$netPct,'net_pnl'=>$netPnl,'signal_details'=>$details,'signal_action'=>strtolower((string)($signal['action']??'hold')),'signal_time'=>(string)($signal['created_at']??'')];
}

$c=new BotController(); $message=''; $error=''; $runResult=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??''); $session=(string)($_SESSION['csrf']??'');
    if($posted===''||$session===''||!hash_equals($session,$posted)){$_SESSION['csrf']=bin2hex(random_bytes(24));header('Location: /admin/bot/?csrf_refresh=1',true,303);exit;}
    try{
        $a=(string)($_POST['action']??''); $exchange=strtolower((string)($_POST['exchange']??'nobitex'));
        if($a==='save_settings'){$c->updateSettings($_POST);$message='تنظیمات ریسک و پورتفو ذخیره شد.';}
        elseif($a==='run_now'){$runResult=$c->runNow($exchange);$message='چرخه '.$exchange.' اجرا شد.';}
    }catch(Throwable $e){$error=mb_substr($e->getMessage(),0,700);}
}
if(isset($_GET['csrf_refresh']))$error='فرم قدیمی بود؛ صفحه تازه شد و هیچ تغییری انجام نشد.';

$status=$c->status(); $data=$c->recentData(30); $settings=$status['settings']; $ex=$status['exchanges']; $csrf=h((string)$_SESSION['csrf']); $version=Updater::currentVersion();
require dirname(__DIR__) . '/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>معاملات — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>.positions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.position{border:1px solid var(--line);border-radius:15px;padding:12px;background:#fff}.position-head{display:flex;justify-content:space-between;gap:8px}.position-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:6px;margin-top:9px}.mini{background:var(--surface2);padding:8px;border-radius:10px}.mini span{display:block;color:var(--muted);font-size:9px}.mini b{display:block;margin-top:4px;font-size:11px}.decision{padding:12px;border-radius:14px;background:var(--purpleSoft);margin-top:11px;font-size:11px;line-height:1.8}.code{direction:ltr;text-align:left;white-space:pre-wrap;word-break:break-all;background:#171a24;color:#eef2ff;padding:12px;border-radius:12px;font-family:monospace;font-size:10px;max-height:300px;overflow:auto}@media(max-width:700px){.positions{grid-template-columns:1fr}}</style></head><body><div class="admin-shell">
<?php tradeAdminNav('trading',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">TRADING / AUTOMATION</div><h1>معاملات</h1><p>پوزیشن‌ها، تصمیم‌های ربات، سیگنال‌ها، اجرای چرخه و تنظیمات ریسک فقط در این بخش مدیریت می‌شوند.</p></div><span class="badge <?=($status['kill_switch']??false)?'bad':'good'?>">GLOBAL STOP <?=($status['kill_switch']??false)?'ON':'OFF'?></span></div>
<div class="subnav"><a class="active" href="/admin/bot/">معاملات</a><a href="/admin/bot/intelligence.php">Intelligence</a><a href="/admin/bot/rotation.php">Rotation</a><a href="/admin/tradingview.php">TradingView</a></div>
<?php if($message!==''):?><div class="notice good"><b><?=h($message)?></b></div><?php endif?><?php if($error!==''):?><div class="notice bad"><b><?=h($error)?></b></div><?php endif?>
<?php if($runResult!==null):?><section class="panel soft"><div class="panel-head"><div><h2>نتیجه اجرای دستی</h2><p><?=h(decisionLine($runResult))?></p></div><span class="badge info"><?=h(statusFa((string)($runResult['status']??'-')))?></span></div><details><summary>جزئیات فنی</summary><div class="code" style="margin-top:8px"><?=h(json_encode($runResult,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></div></details></section><?php endif?>

<div class="panel-grid">
<?php foreach(['nobitex'=>'Nobitex','bitpin'=>'Bitpin'] as $key=>$name): $e=$ex[$key]; $p=is_array($e['performance']??null)?$e['performance']:[]; $q=(string)($p['quote_asset']??($key==='nobitex'?'IRT':'—')); $positions=$e['active_positions']??[]; $signal=$e['latest_signal']??null; $signalRows=$data[$key]['signals']??[]; $latestMap=latestSignalsBySymbol($signalRows); ?>
<section class="panel"><div class="panel-head"><div><h2><?=$name?></h2><p><?=$key==='nobitex'?'Profit-First • Full Universe • Adaptive Sizing':'Secondary trading engine'?></p></div><div class="status-line"><span class="badge <?=$e['credentials_configured']?'good':'bad'?>">API <?=$e['credentials_configured']?'READY':'OFF'?></span><span class="badge <?=$e['bot_enabled']?'good':'bad'?>">BOT <?=$e['bot_enabled']?'ON':'OFF'?></span><span class="badge <?=$e['live_execution_enabled']?'good':'bad'?>">LIVE <?=$e['live_execution_enabled']?'ON':'OFF'?></span></div></div>
<div class="metric-grid"><div class="metric"><span>Active Positions</span><b><?=h($e['active_position_count']??0)?></b></div><div class="metric"><span>Today PnL <?=$q?></span><b><?=fmt($p['today_realized_pnl']??0,2)?></b></div><div class="metric"><span>Win Rate</span><b><?=fmt($p['win_rate_percent']??0,1)?>%</b></div></div>
<?php if($key==='nobitex'): $cap=is_array($e['portfolio_capacity']??null)?$e['portfolio_capacity']:[]; ?><div class="metric-grid" style="margin-top:8px"><div class="metric"><span>Position Capacity</span><b><?=h(($cap['active_positions']??0).'/'.($cap['max_positions']??0))?></b></div><div class="metric"><span>Effective Entry</span><b><?=fmt($cap['effective_position_percent']??0,2)?>%</b></div><div class="metric"><span>Pending</span><b><?=h(($cap['pending_orders']??0).'/'.($cap['max_pending_orders']??0))?></b></div></div><?php endif?>
<div class="decision"><b>آخرین تصمیم</b><br><?=h(decisionLine($e['last_decision']??null))?></div>
<?php if($positions):?><div class="positions" style="margin-top:10px"><?php foreach($positions as $pos):$sym=strtoupper((string)$pos['symbol']);$an=positionAnalytics($pos,$latestMap[$sym]??null);?><div class="position"><div class="position-head"><b><?=h($pos['symbol'])?></b><span class="badge good"><?=h($pos['status'])?></span></div><div class="position-grid"><div class="mini"><span>ورود</span><b><?=fmt($pos['entry_price'],8)?></b></div><div class="mini"><span>قیمت پایش</span><b><?=fmt($an['current'],8)?></b></div><div class="mini"><span>PnL Net</span><b class="<?=$an['net_pct']>=0?'ok':'bad-text'?>"><?=fmt($an['net_pnl'],2)?> <?=h($pos['quote_asset'])?> • <?=fmt($an['net_pct'],2)?>%</b></div><div class="mini"><span>TP / SL</span><b><?=fmt($pos['take_profit'],6)?> / <?=fmt($pos['stop_loss'],6)?></b></div></div></div><?php endforeach?></div><?php else:?><div class="empty">پوزیشن فعالی وجود ندارد.</div><?php endif?>
<div class="actions" style="margin-top:12px"><form method="post" onsubmit="return confirm('چرخه LIVE برای <?=$name?> اجرا شود؟ ممکن است سفارش واقعی ایجاد شود.');"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="run_now"><input type="hidden" name="exchange" value="<?=$key?>"><button class="btn warning">اجرای یک چرخه</button></form></div>
</section>
<?php endforeach?>
</div>

<section class="panel soft"><div class="panel-head"><div><h2>تنظیمات ریسک و پورتفو</h2><p>تنظیمات اتصال API و Bot/Live در «صرافی‌ها» و Kill Switch در «سیستم» قرار دارند.</p></div><span class="badge info">RISK SETTINGS</span></div><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="save_settings"><div class="field-grid">
<div class="field"><label>اولویت Quote</label><select name="quote_asset"><option value="IRT" <?=$settings['quote_asset']==='IRT'?'selected':''?>>IRT</option><option value="USDT" <?=$settings['quote_asset']==='USDT'?'selected':''?>>USDT</option></select></div>
<div class="field"><label>Risk Profile</label><select name="risk_profile"><option value="safe" <?=$settings['risk_profile']==='safe'?'selected':''?>>Safe</option><option value="balanced" <?=$settings['risk_profile']==='balanced'?'selected':''?>>Balanced</option><option value="aggressive" <?=$settings['risk_profile']==='aggressive'?'selected':''?>>Aggressive</option></select></div>
<div class="field"><label>Minimum Signal Score — Bitpin</label><input type="number" min="35" max="90" name="min_signal_score" value="<?=h($settings['min_signal_score'])?>"></div>
<div class="field"><label>درصد سرمایه درخواستی هر ورود</label><input type="number" step="0.1" min="0.5" max="20" name="position_percent" value="<?=h($settings['position_percent'])?>"></div>
<div class="field"><label>درصد مؤثر ورود Nobitex</label><input type="text" value="<?=h(fmt($settings['nobitex_effective_position_percent']??0,2))?>% — خودکار" disabled></div>
<div class="field"><label>حداکثر هر پوزیشن %</label><input type="number" step="0.1" min="1" max="25" name="max_position_percent" value="<?=h($settings['max_position_percent'])?>"></div>
<div class="field"><label>سقف Exposure نوبیتکس %</label><input type="number" step="1" min="10" max="90" name="nobitex_portfolio_exposure_percent" value="<?=h($settings['nobitex_portfolio_exposure_percent'])?>"></div>
<div class="field"><label>حداکثر پوزیشن همزمان</label><input type="number" min="1" max="20" name="nobitex_max_positions" value="<?=h($settings['nobitex_max_positions'])?>"></div>
<div class="field"><label>حداکثر Pending</label><input type="number" min="1" max="5" name="nobitex_max_pending_orders" value="<?=h($settings['nobitex_max_pending_orders'])?>"></div>
<div class="field"><label>Pending Timeout (sec)</label><input type="number" min="30" max="300" step="5" name="nobitex_pending_timeout_seconds" value="<?=h($settings['nobitex_pending_timeout_seconds'])?>"></div>
<div class="field"><label>Cooldown دقیقه</label><input type="number" min="1" max="1440" name="cooldown_minutes" value="<?=h($settings['cooldown_minutes'])?>"></div>
<div class="field"><label>Stop Loss %</label><input type="number" step="0.1" min="0.5" max="15" name="stop_loss_percent" value="<?=h($settings['stop_loss_percent'])?>"></div>
<div class="field"><label>Take Profit %</label><input type="number" step="0.1" min="0.5" max="50" name="take_profit_percent" value="<?=h($settings['take_profit_percent'])?>"></div>
<div class="field"><label>حد زیان روزانه %</label><input type="number" step="0.1" min="1" max="15" name="daily_loss_limit_percent" value="<?=h($settings['daily_loss_limit_percent'])?>"></div>
</div><div class="actions" style="margin-top:12px"><button class="btn">ذخیره تنظیمات ریسک</button></div></form></section>

<div class="panel-grid">
<?php foreach(['nobitex'=>'Nobitex','bitpin'=>'Bitpin'] as $key=>$name):$positions=$ex[$key]['active_positions']??[];$activeSet=activeSymbolSet($positions);?><section class="panel"><div class="panel-head"><div><h2>سیگنال‌های <?=$name?></h2><p>۳۰ مورد اخیر</p></div></div><div class="table-wrap"><table><thead><tr><th>UTC</th><th>Market</th><th>Action</th><th><?=$key==='nobitex'?'Edge':'Score'?></th><th>Confidence</th><th>وضعیت</th></tr></thead><tbody><?php if(($data[$key]['signals']??[])===[]):?><tr><td colspan="6" class="empty">سیگنالی ثبت نشده است.</td></tr><?php endif?><?php foreach($data[$key]['signals']??[] as $r):$details=detailsOf($r);$action=strtolower((string)$r['action']);[$stateLabel,$stateClass]=signalStateFa($r,$activeSet);?><tr><td><?=h($r['created_at'])?></td><td><b><?=h($r['symbol'])?></b></td><td><?=h(strtoupper($action))?></td><td><?php if($key==='nobitex'):?><?=isset($details['tradable_net_edge_percent'])?fmt($details['tradable_net_edge_percent'],3).'%':(isset($details['expected_net_edge_percent'])?fmt($details['expected_net_edge_percent'],3).'%':'—')?><?php else:?><?=h($r['score'])?><?php endif?></td><td><?=isset($details['confidence'])?h($details['confidence']).'%':'—'?></td><td><span class="badge <?=$stateClass?>"><?=h($stateLabel)?></span></td></tr><?php endforeach?></tbody></table></div></section><?php endforeach?>
</div>
<?php tradeAdminFooter($version); ?>
</div></body></html>