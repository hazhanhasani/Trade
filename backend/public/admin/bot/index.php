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
    'buy_submitted','first_buy_submitted'=>'سفارش خرید ارسال شد','sell_submitted'=>'سفارش فروش ارسال شد','profit_actions_processed'=>'معاملات سودمحور پردازش شد','holding_position'=>'در حال نگهداری معامله باز','waiting_order'=>'در انتظار تکمیل سفارش','portfolio_full'=>'ظرفیت معاملات باز تکمیل است','no_trade'=>'فعلاً معامله‌ای انجام نمی‌شود','blocked'=>'اجرای ربات مسدود است','disabled'=>'ربات خاموش است','failed'=>'خطا','success'=>'موفق',default=>$s,
}; }
function reasonFa(string $s): string { return match($s) {
    'no_candidate_passed_signal_and_risk_filters'=>'هیچ بازار فعلی بعد از هزینه‌ها و کنترل ریسک، شرایط خرید نداشت',
    'no_eligible_markets'=>'بازار مناسب و قابل‌اجرایی پیدا نشد',
    'expected_net_profit_not_positive'=>'سود خالص پیش‌بینی‌شده بعد از هزینه‌ها مثبت نیست',
    'positive_expected_net_profit_after_costs'=>'سود خالص پیش‌بینی‌شده بعد از هزینه‌ها مثبت است',
    'edge_below_adaptive_safety_buffer'=>'سود پیش‌بینی‌شده برای پوشش خطای تحلیل و نوسان بازار کافی نیست',
    'positive_tradable_net_edge_after_costs_and_buffer'=>'سود پیش‌بینی‌شده قابل معامله، بعد از هزینه‌ها و حاشیه اطمینان مثبت است',
    'expected_forward_move_negative_after_exit_cost'=>'چشم‌انداز ادامه حرکت بعد از هزینه فروش منفی شده است',
    'spread_not_executable'=>'فاصله قیمت خرید و فروش برای اجرای امن مناسب نیست',
    'market_quality_not_ready'=>'کیفیت داده یا شرایط اجرای سفارش کافی نیست',
    'insufficient_internal_mtf_history'=>'داده تاریخی کافی در چند بازه زمانی وجود ندارد',
    'score_below_threshold'=>'امتیاز سیگنال بیت‌پین به حد ورود نرسیده است',
    'buy_score_without_confirmation'=>'سیگنال خرید بیت‌پین هنوز تأیید کامل ندارد',
    'spread_too_wide'=>'فاصله قیمت خرید و فروش زیاد است',
    'volatility_too_high'=>'نوسان کوتاه‌مدت بیش از حد است',
    'portfolio_full'=>'تعداد معاملات باز به سقف تعیین‌شده رسیده است',
    'portfolio_exposure_limit_reached'=>'حداکثر سرمایه مجاز در معاملات درگیر شده است',
    'pending_order_capacity_reached'=>'تعداد سفارش‌های در انتظار به سقف رسیده است',
    'minimum_order_rounding'=>'مبلغ سفارش بعد از گرد کردن از حداقل صرافی کمتر شده است',
    'minimum_order_exceeds_budget'=>'حداقل مبلغ سفارش صرافی از بودجه این معامله بیشتر است',
    'symbol_cooldown_active'=>'فاصله اجباری معامله این ارز هنوز تمام نشده است',
    'already_positioned'=>'برای این ارز از قبل معامله باز وجود دارد',
    'bot_disabled'=>'ربات خاموش است',
    'live_execution_disabled'=>'ارسال سفارش واقعی خاموش است',
    'kill_switch'=>'توقف اضطراری فعال است',
    'credentials_missing'=>'کلید API تنظیم نشده است',
    'no_quote_balance'=>'موجودی تومان/تتر کافی نیست',
    'daily_loss_limit_reached'=>'حداکثر زیان مجاز روزانه فعال شده است',
    'insufficient_balance'=>'موجودی آزاد کافی نیست',
    'waiting_for_positive_market'=>'ربات منتظر یک فرصت با سود خالص مثبت است',
    default=>$s,
}; }
function decisionLine(?array $d): string {
    if (!$d) return 'هنوز تصمیم ثبت نشده است.';
    $s=statusFa((string)($d['status']??'-')); $r=(string)($d['reason']??$d['error']??''); $selected=is_array($d['selected']??null)?$d['selected']:null; $tail='';
    if($selected){
        $tail=' — '.($selected['symbol']??'');
        if(isset($selected['tradable_net_edge_percent']))$tail.=' | سود قابل معامله '.fmt($selected['tradable_net_edge_percent'],3).'%';
        elseif(isset($selected['expected_net_edge_percent']))$tail.=' | سود خالص پیش‌بینی‌شده '.fmt($selected['expected_net_edge_percent'],3).'%';
        elseif(isset($selected['signal_score'])&&(int)$selected['signal_score']!==0)$tail.=' | امتیاز '.(int)$selected['signal_score'];
    }
    return $s.($r!==''?' — '.reasonFa($r):'').$tail;
}
function detailsOf(mixed $row): array { if(!is_array($row)) return []; if(is_array($row['details']??null)) return $row['details']; $d=json_decode((string)($row['details_json']??''),true); return is_array($d)?$d:[]; }
function activeSymbolSet(array $positions): array { $set=[]; foreach($positions as $p){if(!is_array($p))continue;$symbol=strtoupper((string)($p['symbol']??''));if($symbol!=='')$set[$symbol]=true;} return $set; }
function signalStateFa(mixed $row,array $activeSymbols): array {
    if(!is_array($row))return['داده نامعتبر','bad'];
    if((int)($row['executed']??0)===1)return['سفارش ایجاد شد','good']; $symbol=strtoupper((string)($row['symbol']??'')); $action=strtolower((string)($row['action']??'hold'));
    if(isset($activeSymbols[$symbol]))return[$action==='sell'?'سیگنال خروج':'پایش معامله','info']; if($action==='hold')return['فقط تحلیل','']; if($action==='buy')return['فرصت؛ بدون سفارش','warn']; if($action==='sell')return['فروش؛ بدون معامله باز','']; return['بدون سفارش',''];
}
function latestSignalsBySymbol(array $rows): array { $out=[]; foreach($rows as $row){if(!is_array($row))continue;$symbol=strtoupper((string)($row['symbol']??''));if($symbol!==''&&!isset($out[$symbol]))$out[$symbol]=$row;} return $out; }
function positionAnalytics(?array $position,?array $signal): array {
    $position??=[]; $entry=(float)($position['entry_price']??0); $amount=(float)($position['amount']??0); $current=$signal?(float)($signal['price']??0):0.0; if($current<=0)$current=$entry; $grossPct=$entry>0?(($current-$entry)/$entry)*100.0:0.0; $grossPnl=($current-$entry)*$amount; $details=$signal?detailsOf($signal):[]; $exitCost=max(0.0,(float)($details['estimated_exit_cost_percent']??0)); $netPct=$grossPct-$exitCost; $netPnl=$entry>0?($entry*$amount*($netPct/100.0)):0.0;
    return ['current'=>$current,'gross_pct'=>$grossPct,'gross_pnl'=>$grossPnl,'exit_cost_pct'=>$exitCost,'net_pct'=>$netPct,'net_pnl'=>$netPnl,'signal_details'=>$details,'signal_action'=>strtolower((string)($signal['action']??'hold')),'signal_time'=>(string)($signal['created_at']??'')];
}

$c=new BotController(); $message=''; $error=''; $runResult=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??''); $session=(string)($_SESSION['csrf']??'');
    if($posted===''||$session===''||!hash_equals($session,$posted)){$_SESSION['csrf']=bin2hex(random_bytes(24));header('Location: /admin/bot/?csrf_refresh=1',true,303);exit;}
    try{
        $a=(string)($_POST['action']??''); $exchange=strtolower((string)($_POST['exchange']??'nobitex'));
        if($a==='save_settings'){header('Location: /admin/bot/settings.php?legacy_form=1',true,303);exit;}
        elseif($a==='run_now'){$runResult=$c->runNow($exchange);$message='یک چرخه '.$exchange.' اجرا شد.';}
    }catch(Throwable $e){$error=mb_substr($e->getMessage(),0,700);}
}
if(isset($_GET['csrf_refresh']))$error='فرم قدیمی بود؛ صفحه تازه شد و هیچ تغییری انجام نشد.';

$status=$c->status(); $data=$c->recentData(30); $settings=$status['settings']; $ex=$status['exchanges']; $csrf=h((string)$_SESSION['csrf']); $version=Updater::currentVersion();
require dirname(__DIR__) . '/_nav.php';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>معاملات — Trade</title><link rel="stylesheet" href="/admin/assets/cockpit.css"><style>
.positions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.position{border:1px solid var(--line);border-radius:15px;padding:12px;background:var(--surface);overflow:hidden}.position-head{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap}.position-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;margin-top:9px}.mini{background:var(--surface2);padding:8px;border-radius:10px;overflow:hidden}.mini span{display:block;color:var(--muted);font-size:9px}.mini b{display:block;margin-top:4px;font-size:11px;overflow-wrap:anywhere}.decision{padding:12px;border-radius:14px;background:var(--purpleSoft);margin-top:11px;font-size:11px;line-height:1.9;overflow-wrap:anywhere}.code{direction:ltr;text-align:left;white-space:pre-wrap;word-break:break-all;background:#171a24;color:#eef2ff;padding:12px;border-radius:12px;font-family:monospace;font-size:10px;max-height:300px;overflow:auto}.settings-intro{margin-top:0}.settings-intro strong{display:block;margin-bottom:5px}.term{font-weight:900;color:var(--primary)}details.help-box{margin-top:12px;border:1px solid var(--line);border-radius:14px;background:var(--surface);padding:10px 12px}details.help-box summary{cursor:pointer;font-weight:800;font-size:11px}details.help-box p{color:var(--muted);font-size:10px;line-height:1.9;margin:9px 0 0}@media(max-width:700px){.positions{grid-template-columns:1fr}}
</style></head><body><div class="admin-shell">
<?php tradeAdminNav('trading',$version); ?>
<div class="page-head"><div><div class="page-eyebrow">مدیریت معاملات خودکار</div><h1>معاملات</h1><p>وضعیت زنده ربات، معاملات باز، تصمیم‌های اخیر و خلاصه تنظیمات فعال. ویرایش تنظیمات فقط از صفحه «تنظیمات معاملات» انجام می‌شود.</p></div><span class="badge <?=($status['kill_switch']??false)?'bad':'good'?>">توقف اضطراری <?=($status['kill_switch']??false)?'روشن':'خاموش'?></span></div>
<div class="subnav"><a class="active" href="/admin/bot/">معاملات</a><a href="/admin/bot/intelligence.php">ریسک هوشمند</a><a href="/admin/bot/rotation.php">تعویض فرصت‌ها</a><a href="/admin/tradingview.php">سیگنال TradingView</a></div>

<section class="panel settings-intro"><div class="panel-head"><div><h2>راهنمای خیلی کوتاه</h2><p>اگر با اصطلاحات ترید آشنا نیستی، همین سه مورد برای کنترل روزمره کافی است.</p></div><span class="badge info">راهنمای ساده</span></div><div class="simple-guide"><div><b>ربات</b><span>تحلیل بازار و تصمیم‌گیری خودکار را روشن یا خاموش می‌کند.</span></div><div><b>ارسال واقعی</b><span>اجازه می‌دهد تصمیم ربات واقعاً به سفارش خرید یا فروش در صرافی تبدیل شود.</span></div><div><b>ریسک</b><span>مشخص می‌کند چه مقدار از سرمایه در هر معامله و در کل معاملات درگیر شود.</span></div></div></section>

<?php if($message!==''):?><div class="notice good"><b><?=h($message)?></b></div><?php endif?><?php if($error!==''):?><div class="notice bad"><b><?=h($error)?></b></div><?php endif?>
<?php if($runResult!==null):?><section class="panel soft"><div class="panel-head"><div><h2>نتیجه اجرای دستی</h2><p><?=h(decisionLine($runResult))?></p></div><span class="badge info"><?=h(statusFa((string)($runResult['status']??'-')))?></span></div><details class="help-box"><summary>نمایش جزئیات فنی</summary><div class="code" style="margin-top:8px"><?=h(json_encode($runResult,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></div></details></section><?php endif?>

<div class="panel-grid">
<?php foreach(['nobitex'=>'Nobitex','bitpin'=>'Bitpin'] as $key=>$name): $e=is_array($ex[$key]??null)?$ex[$key]:[]; $p=is_array($e['performance']??null)?$e['performance']:[]; $q=(string)($p['quote_asset']??($key==='nobitex'?'IRT':'—')); $positions=is_array($e['active_positions']??null)?array_values(array_filter($e['active_positions'],'is_array')):[]; $signal=$e['latest_signal']??null; $signalRows=is_array($data[$key]['signals']??null)?array_values(array_filter($data[$key]['signals'],'is_array')):[]; $latestMap=latestSignalsBySymbol($signalRows); ?>
<section class="panel"><div class="panel-head"><div><h2><?=$name?></h2><p><?=$key==='nobitex'?'صرافی اصلی • تحلیل همه بازارها • اندازه خرید تطبیقی':'صرافی ثانویه'?></p></div><div class="status-line"><span class="badge <?=$e['credentials_configured']??false?'good':'bad'?>">API <?=($e['credentials_configured']??false)?'متصل':'قطع'?></span><span class="badge <?=$e['bot_enabled']??false?'good':'bad'?>">ربات <?=($e['bot_enabled']??false)?'روشن':'خاموش'?></span><span class="badge <?=$e['live_execution_enabled']??false?'good':'bad'?>">ارسال واقعی <?=($e['live_execution_enabled']??false)?'روشن':'خاموش'?></span></div></div>
<div class="metric-grid"><div class="metric"><span>معاملات باز</span><b><?=h($e['active_position_count']??0)?></b></div><div class="metric"><span>سود/زیان امروز <?=$q?></span><b><?=fmt($p['today_realized_pnl']??0,2)?></b></div><div class="metric"><span>درصد معاملات موفق</span><b><?=fmt($p['win_rate_percent']??0,1)?>%</b></div></div>
<?php if($key==='nobitex'): $cap=is_array($e['portfolio_capacity']??null)?$e['portfolio_capacity']:[]; ?><div class="metric-grid" style="margin-top:8px"><div class="metric"><span>تعداد معامله باز / سقف</span><b><?=h(($cap['active_positions']??0).'/'.($cap['max_positions']??0))?></b></div><div class="metric"><span>درصد واقعی خرید بعدی</span><b><?=fmt($cap['effective_position_percent']??0,2)?>%</b></div><div class="metric"><span>سفارش در انتظار / سقف</span><b><?=h(($cap['pending_orders']??0).'/'.($cap['max_pending_orders']??0))?></b></div></div><?php endif?>
<div class="decision"><b>آخرین تصمیم ربات</b><br><?=h(decisionLine(is_array($e['last_decision']??null)?$e['last_decision']:null))?></div>
<?php if($positions):?><div class="positions" style="margin-top:10px"><?php foreach($positions as $pos):if(!is_array($pos))continue;$sym=strtoupper((string)($pos['symbol']??''));$an=positionAnalytics($pos,is_array($latestMap[$sym]??null)?$latestMap[$sym]:null);?><div class="position"><div class="position-head"><b><?=h($pos['symbol']??'—')?></b><span class="badge good"><?=h($pos['status']??'—')?></span></div><div class="position-grid"><div class="mini"><span>قیمت ورود</span><b><?=fmt($pos['entry_price']??0,8)?></b></div><div class="mini"><span>قیمت فعلی پایش</span><b><?=fmt($an['current'],8)?></b></div><div class="mini"><span>سود/زیان خالص</span><b class="<?=$an['net_pct']>=0?'ok':'bad-text'?>"><?=fmt($an['net_pnl'],2)?> <?=h($pos['quote_asset']??'')?> • <?=fmt($an['net_pct'],2)?>%</b></div><div class="mini"><span>حد سود / حد ضرر</span><b><?=fmt($pos['take_profit']??0,6)?> / <?=fmt($pos['stop_loss']??0,6)?></b></div></div></div><?php endforeach?></div><?php else:?><div class="empty">معامله بازی وجود ندارد.</div><?php endif?>
<div class="actions" style="margin-top:12px"><form method="post" onsubmit="return confirm('یک چرخه واقعی برای <?=$name?> اجرا شود؟ ممکن است سفارش واقعی ایجاد شود.');"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="run_now"><input type="hidden" name="exchange" value="<?=$key?>"><button class="btn warning">اجرای یک چرخه همین حالا</button></form></div>
</section>
<?php endforeach?>
</div>

<section class="panel soft"><div class="panel-head"><div><h2>تنظیمات فعال ربات</h2><p>این بخش فقط وضعیت فعلی را از همان منبعی نشان می‌دهد که موتور معامله می‌خواند. برای جلوگیری از دو منبع تنظیمات، ویرایش فقط در «تنظیمات معاملات» انجام می‌شود.</p></div><span class="badge good">همگام با موتور</span></div>
<div class="metric-grid">
<div class="metric"><span>سرمایه هدف هر خرید</span><b><?=fmt($settings['position_percent']??0,1)?>%</b></div>
<div class="metric"><span>سقف کل سرمایه درگیر</span><b><?=fmt($settings['nobitex_portfolio_exposure_percent']??0,0)?>%</b></div>
<div class="metric"><span>معاملات باز مجاز</span><b><?=h($settings['nobitex_max_positions']??0)?></b></div>
<div class="metric"><span>حد ضرر / هدف سود</span><b><?=fmt($settings['stop_loss_percent']??0,1)?>% / <?=fmt($settings['take_profit_percent']??0,1)?>%</b></div>
<div class="metric"><span>زیان روزانه مجاز</span><b><?=fmt($settings['daily_loss_limit_percent']??0,1)?>%</b></div>
<div class="metric"><span>استراحت همان ارز</span><b><?=h($settings['cooldown_minutes']??0)?> دقیقه</b></div>
</div>
<div class="notice info" style="margin-top:12px"><b>منبع واحد:</b> مقادیر بالا و ربات هر دو از تنظیمات ذخیره‌شده مرکزی خوانده می‌شوند. فرم ویرایش تکراری این صفحه حذف شده است.</div>
<div class="actions"><a class="btn" href="/admin/bot/settings.php">ویرایش تنظیمات معاملات</a></div>
</section>

<div class="panel-grid">
<?php foreach(['nobitex'=>'Nobitex','bitpin'=>'Bitpin'] as $key=>$name):$positions=is_array($ex[$key]['active_positions']??null)?array_values(array_filter($ex[$key]['active_positions'],'is_array')):[];$activeSet=activeSymbolSet($positions);$recentSignals=is_array($data[$key]['signals']??null)?array_values(array_filter($data[$key]['signals'],'is_array')):[];?><section class="panel"><div class="panel-head"><div><h2>تحلیل‌های اخیر <?=$name?></h2><p>۳۰ مورد اخیر؛ این جدول نشان می‌دهد ربات چه دیده و آیا سفارشی ساخته یا نه.</p></div></div><div class="table-wrap"><table><thead><tr><th>زمان</th><th>بازار</th><th>تصمیم</th><th><?=$key==='nobitex'?'سود قابل معامله':'امتیاز'?></th><th>اطمینان تحلیل</th><th>نتیجه</th></tr></thead><tbody><?php if($recentSignals===[]):?><tr><td colspan="6" class="empty">تحلیلی ثبت نشده است.</td></tr><?php endif?><?php foreach($recentSignals as $r):if(!is_array($r))continue;$details=detailsOf($r);$action=strtolower((string)($r['action']??'hold'));[$stateLabel,$stateClass]=signalStateFa($r,$activeSet);?><tr><td><?=h($r['time_iran']['jalali_datetime']??$r['created_at']??'—')?></td><td><b><?=h($r['symbol']??'—')?></b></td><td><?=h(match($action){'buy'=>'خرید','sell'=>'فروش','hold'=>'صبر',default=>strtoupper($action)})?></td><td><?php if($key==='nobitex'):?><?=isset($details['tradable_net_edge_percent'])?fmt($details['tradable_net_edge_percent'],3).'%':(isset($details['expected_net_edge_percent'])?fmt($details['expected_net_edge_percent'],3).'%':'—')?><?php else:?><?=h($r['score']??0)?><?php endif?></td><td><?=isset($details['confidence'])?h($details['confidence']).'%':'—'?></td><td><span class="badge <?=$stateClass?>"><?=h($stateLabel)?></span></td></tr><?php endforeach?></tbody></table></div></section><?php endforeach?>
</div>
<?php tradeAdminFooter($version); ?>
</div></body></html>