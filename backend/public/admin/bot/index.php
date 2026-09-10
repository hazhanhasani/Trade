<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use Trade\Config;
use Trade\Trading\BotController;
use Trade\Trading\NobitexRotationMonitor;

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
    'buy_submitted','first_buy_submitted'=>'سفارش خرید ارسال شد',
    'sell_submitted'=>'سفارش فروش ارسال شد',
    'profit_actions_processed'=>'معاملات سودمحور پردازش شد',
    'holding_position'=>'در حال نگهداری پوزیشن',
    'waiting_order'=>'در انتظار تکمیل سفارش',
    'portfolio_full'=>'ظرفیت پورتفو تکمیل است',
    'no_trade'=>'فعلاً معامله‌ای انجام نمی‌شود',
    'blocked'=>'اجرای ربات مسدود است',
    'disabled'=>'ربات خاموش است',
    'failed'=>'خطا',
    'success'=>'موفق',
    default=>$s,
}; }
function reasonFa(string $s): string { return match($s) {
    'no_candidate_passed_signal_and_risk_filters'=>'هیچ بازار فعلی پس از هزینه‌ها و حاشیه اطمینان، Edge قابل معامله مثبت و ظرفیت ریسک لازم را نداشت',
    'no_eligible_markets'=>'بازار قابل‌اجرای کافی پیدا نشد',
    'expected_net_profit_not_positive'=>'سود خالص مورد انتظار پس از هزینه‌ها مثبت نیست',
    'positive_expected_net_profit_after_costs'=>'سود خالص مورد انتظار پس از هزینه‌ها مثبت است',
    'edge_below_adaptive_safety_buffer'=>'Net Edge خام برای پوشش خطای پیش‌بینی و نویز بازار کافی نیست',
    'positive_tradable_net_edge_after_costs_and_buffer'=>'Edge قابل معامله پس از هزینه‌ها و حاشیه اطمینان پویا مثبت است',
    'expected_forward_move_negative_after_exit_cost'=>'چشم‌انداز حرکت بعدی پس از هزینه خروج منفی شده است',
    'spread_not_executable'=>'اسپرد این بازار برای اجرای امن مناسب نیست',
    'market_quality_not_ready'=>'داده یا کیفیت اجرای بازار هنوز کافی نیست',
    'insufficient_internal_mtf_history'=>'تاریخچه چندبازه‌ای برای تصمیم‌گیری هنوز کافی نیست',
    'score_below_threshold'=>'امتیاز سیگنال Bitpin هنوز به حد ورود نرسیده',
    'buy_score_without_confirmation'=>'سیگنال Bitpin تأیید کامل روند را ندارد',
    'spread_too_wide'=>'فاصله خرید و فروش زیاد است',
    'volatility_too_high'=>'نوسان کوتاه‌مدت بیش از حد است',
    'portfolio_full'=>'ظرفیت پورتفو تکمیل است',
    'portfolio_exposure_limit_reached'=>'سقف سرمایه درگیر پورتفو تکمیل است',
    'pending_order_capacity_reached'=>'ظرفیت سفارش‌های در انتظار تکمیل است؛ Watchdog در حال پیگیری آن‌هاست',
    'minimum_order_rounding'=>'مبلغ سفارش پس از گرد کردن کمتر از حداقل صرافی است',
    'minimum_order_exceeds_budget'=>'حداقل سفارش صرافی از بودجه این پوزیشن بیشتر است',
    'symbol_cooldown_active'=>'دوره استراحت این بازار هنوز تمام نشده',
    'already_positioned'=>'برای این دارایی پوزیشن فعال وجود دارد',
    'bot_disabled'=>'ربات خاموش است',
    'live_execution_disabled'=>'ارسال واقعی خاموش است',
    'kill_switch'=>'توقف اضطراری فعال است',
    'credentials_missing'=>'API تنظیم نشده',
    'no_quote_balance'=>'موجودی IRT/USDT کافی نیست',
    'daily_loss_limit_reached'=>'حد زیان روزانه فعال شده',
    'insufficient_balance'=>'موجودی آزاد کافی نیست',
    'waiting_for_positive_market'=>'ربات منتظر Edge قابل معامله مثبت است',
    'superior_opportunity_after_rotation_costs'=>'فرصت جایگزین پس از هزینه‌های تعویض، برتری کافی دارد',
    'portfolio_has_free_slot'=>'پورتفو هنوز اسلات آزاد دارد و Rotation لازم نیست',
    'pending_order_present'=>'تا تعیین تکلیف Pending، Rotation متوقف است',
    'rotation_cooldown_active'=>'Cooldown تعویض هنوز تمام نشده است',
    'no_profitable_replacement_candidate'=>'فرصت خرید جایگزین سودمند پیدا نشده است',
    'no_rotation_eligible_position'=>'هیچ پوزیشن فعلی شرایط Rotation را ندارد',
    'replacement_advantage_insufficient'=>'اختلاف Edge برای جبران هزینه تعویض کافی نیست',
    'position_signal_data_stale'=>'داده پایش پوزیشن‌ها برای Preview تازه نیست',
    'rotation_disabled'=>'Portfolio Rotation غیرفعال است',
    default=>$s,
}; }
function decisionLine(?array $d): string {
    if (!$d) return 'هنوز تصمیم ثبت نشده است.';
    $s=statusFa((string)($d['status']??'-'));
    $r=(string)($d['reason']??$d['error']??'');
    $selected=is_array($d['selected']??null)?$d['selected']:null;
    $tail='';
    if ($selected) {
        $tail=' — '.($selected['symbol']??'');
        if (isset($selected['tradable_net_edge_percent'])) $tail.=' | Edge قابل معامله '.fmt($selected['tradable_net_edge_percent'],3).'%';
        elseif (isset($selected['expected_net_edge_percent'])) $tail.=' | Net Edge '.fmt($selected['expected_net_edge_percent'],3).'%';
        elseif (isset($selected['signal_score']) && (int)$selected['signal_score'] !== 0) $tail.=' | امتیاز '.(int)$selected['signal_score'];
    }
    return $s.($r!==''?' — '.reasonFa($r):'').$tail;
}
function detailsOf(array $row): array {
    if (is_array($row['details']??null)) return $row['details'];
    $d=json_decode((string)($row['details_json']??''), true);
    return is_array($d)?$d:[];
}
function activeSymbolSet(array $positions): array {
    $set=[];
    foreach ($positions as $p) $set[strtoupper((string)($p['symbol']??''))]=true;
    return $set;
}
function signalStateFa(array $row, array $activeSymbols): array {
    if ((int)($row['executed']??0)===1) return ['سفارش ایجاد شد','done'];
    $symbol=strtoupper((string)($row['symbol']??''));
    $action=strtolower((string)($row['action']??'hold'));
    if (isset($activeSymbols[$symbol])) {
        if ($action==='sell') return ['سیگنال خروج؛ سفارش هنوز ایجاد نشده','watch'];
        return ['پایش پوزیشن باز','watch'];
    }
    if ($action==='hold') return ['فقط تحلیل','mutedState'];
    if ($action==='buy') return ['فرصت ثبت شد؛ سفارش ایجاد نشد','queued'];
    if ($action==='sell') return ['سیگنال فروش؛ پوزیشن فعالی نیست','mutedState'];
    return ['بدون سفارش','mutedState'];
}
function latestSignalsBySymbol(array $rows): array {
    $out=[];
    foreach ($rows as $row) {
        $symbol=strtoupper((string)($row['symbol']??''));
        if ($symbol!=='' && !isset($out[$symbol])) $out[$symbol]=$row;
    }
    return $out;
}
function positionAnalytics(array $position, ?array $signal): array {
    $entry=(float)($position['entry_price']??0);
    $amount=(float)($position['amount']??0);
    $current=$signal ? (float)($signal['price']??0) : 0.0;
    if ($current<=0) $current=$entry;
    $grossPct=$entry>0 ? (($current-$entry)/$entry)*100.0 : 0.0;
    $grossPnl=($current-$entry)*$amount;
    $details=$signal ? detailsOf($signal) : [];
    $exitCost=max(0.0,(float)($details['estimated_exit_cost_percent']??0));
    $netPct=$grossPct-$exitCost;
    $netPnl=$entry>0 ? ($entry*$amount*($netPct/100.0)) : 0.0;
    return [
        'current'=>$current,
        'gross_pct'=>$grossPct,
        'gross_pnl'=>$grossPnl,
        'exit_cost_pct'=>$exitCost,
        'net_pct'=>$netPct,
        'net_pnl'=>$netPnl,
        'signal_details'=>$details,
        'signal_action'=>strtolower((string)($signal['action']??'hold')),
        'signal_time'=>(string)($signal['created_at']??''),
    ];
}

$c=new BotController();
$message='';$error='';$runResult=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $posted=(string)($_POST['csrf']??'');$session=(string)($_SESSION['csrf']??'');
    if ($posted===''||$session===''||!hash_equals($session,$posted)) {
        $_SESSION['csrf']=bin2hex(random_bytes(24));
        header('Location: /admin/bot/?csrf_refresh=1',true,303);exit;
    }
    try {
        $a=(string)($_POST['action']??'');
        $exchange=strtolower((string)($_POST['exchange']??'bitpin'));
        if ($a==='exchange_bot') {
            $on=(string)($_POST['enabled']??'0')==='1';$c->setExchangeEnabled($exchange,$on);
            $message=($exchange==='nobitex'?'نوبیتکس':'بیت‌پین').' Bot '.($on?'فعال شد.':'غیرفعال شد.');
        } elseif ($a==='exchange_live') {
            $on=(string)($_POST['enabled']??'0')==='1';$c->setExchangeLive($exchange,$on);
            $message=($exchange==='nobitex'?'نوبیتکس':'بیت‌پین').' Live '.($on?'فعال شد.':'غیرفعال شد.');
        } elseif ($a==='kill_switch') {
            $on=(string)($_POST['enabled']??'1')==='1';$c->setKillSwitch($on);
            $message=$on?'توقف اضطراری سراسری فعال شد.':'توقف اضطراری برداشته شد.';
        } elseif ($a==='save_settings') {
            $c->updateSettings($_POST);$message='تنظیمات ریسک و پورتفو ذخیره شد.';
        } elseif ($a==='run_now') {
            $runResult=$c->runNow($exchange);$message='چرخه '.$exchange.' اجرا شد.';
        }
    } catch (Throwable $e) { $error=mb_substr($e->getMessage(),0,700); }
}
if (isset($_GET['csrf_refresh'])) $error='فرم قدیمی بود؛ صفحه تازه شد و هیچ تغییری انجام نشد.';

$status=$c->status();
$data=$c->recentData(30);
$settings=$status['settings'];
$ex=$status['exchanges'];
$csrf=h((string)$_SESSION['csrf']);
$cron=$status['cron_health'];
try{$rotation=(new NobitexRotationMonitor())->snapshot(8);}catch(Throwable $e){$rotation=['state'=>'error','reason'=>$e->getMessage()];}
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>مرکز معاملات Trade</title>
<style>
:root{--bg:#f6f7fb;--card:#fff;--ink:#171a24;--muted:#73798b;--line:#e7e9f0;--primary:#6941ff;--primary2:#8b5cf6;--green:#0d966f;--red:#d14343;--amber:#b46a00;--blue:#2563eb;--soft:#f0edff;--greenSoft:#eaf8f3;--redSoft:#fff0f0;--amberSoft:#fff7e8;--blueSoft:#eef5ff}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Tahoma,Arial,sans-serif}.wrap{max-width:1240px;margin:auto;padding:22px}.top{display:flex;justify-content:space-between;gap:16px;align-items:center}.brand{display:flex;align-items:center;gap:12px}.logo{width:48px;height:48px;border-radius:16px;display:grid;place-items:center;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;font-weight:900;font-size:23px;box-shadow:0 10px 30px #6941ff33}.top h1{margin:0;font-size:24px}.muted{color:var(--muted);font-size:12px}.actions,.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.btn{border:0;border-radius:12px;padding:10px 14px;color:#fff;background:var(--primary);font-weight:700;cursor:pointer;text-decoration:none}.btn.gray{background:#646c7d}.btn.safe{background:var(--green)}.btn.danger{background:var(--red)}.btn.warn{background:var(--amber)}.health{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:18px}.healthItem,.metric{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px}.healthItem span,.metric span{display:block;color:var(--muted);font-size:12px}.healthItem b,.metric b{display:block;margin-top:6px;font-size:17px}.ok,.pnlPos{color:var(--green)}.bad,.pnlNeg{color:var(--red)}.warn{color:var(--amber)}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.card{background:var(--card);border:1px solid var(--line);border-radius:22px;padding:18px;margin-top:14px;box-shadow:0 10px 35px #17203309}.exchange{position:relative;overflow:hidden}.exchange:before{content:"";position:absolute;right:0;top:0;bottom:0;width:5px;background:linear-gradient(var(--primary),var(--primary2))}.badges{display:flex;gap:6px;flex-wrap:wrap}.badge{padding:6px 9px;border-radius:999px;background:#f3f4f8;font-size:11px}.badge.on,.state.done{background:var(--greenSoft);color:var(--green)}.badge.off{background:var(--redSoft);color:var(--red)}.state.watch{background:var(--blueSoft);color:var(--blue)}.state.queued{background:var(--amberSoft);color:var(--amber)}.state.mutedState{background:#f3f4f8;color:#697080}.metrics,.capacityGrid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:14px}.capacityGrid .metric{background:#f8f7ff}.decision{margin-top:12px;background:var(--soft);padding:12px 14px;border-radius:14px;line-height:1.8}.positionsGrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin-top:10px}.position{padding:12px;border:1px solid var(--line);border-radius:14px;background:#fff}.positionHead{display:flex;justify-content:space-between;gap:10px;align-items:center}.positionMetrics{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;margin-top:10px}.mini{background:#f8f9fc;border-radius:10px;padding:8px}.mini span{display:block;color:var(--muted);font-size:10px}.mini b{display:block;margin-top:4px;font-size:12px}.msg,.err,.notice{padding:13px 15px;border-radius:14px;margin-top:12px}.msg{background:var(--greenSoft);color:#116246}.err{background:var(--redSoft);color:#a12626}.notice{background:#fff7e5;color:#765100}.info{background:var(--blueSoft);color:#244c93;padding:12px 14px;border-radius:14px;margin-top:12px}.fields{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.field label{display:block;font-size:12px;color:var(--muted);margin-bottom:5px}input,select{width:100%;padding:11px;border:1px solid #cfd4df;border-radius:11px;background:#fff}.scroll{overflow:auto}table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:10px;border-bottom:1px solid #edf0f5;text-align:right;white-space:nowrap}th{color:var(--muted);font-weight:700}.signalBuy{color:var(--green);font-weight:800}.signalSell{color:var(--red);font-weight:800}.signalHold{color:var(--amber);font-weight:800}.details{margin-top:10px}.details summary{cursor:pointer;color:var(--primary);font-weight:700}.code{direction:ltr;text-align:left;white-space:pre-wrap;word-break:break-all;background:#171a24;color:#eef2ff;padding:12px;border-radius:12px;font-family:monospace;font-size:11px;max-height:320px;overflow:auto}.sectionTitle{display:flex;justify-content:space-between;gap:12px;align-items:center}.sectionTitle h2{margin:0}.signalMeta{line-height:1.8;margin-top:8px}.portfolioPnl{font-size:12px;margin-top:8px}.rotationGrid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-top:12px}.rotationGrid .metric{background:linear-gradient(135deg,#fff,#faf8ff)}.rotationReason{margin-top:10px;padding:11px 13px;border-radius:13px;background:var(--soft);line-height:1.8}@media(max-width:850px){.health,.metrics,.capacityGrid,.fields{grid-template-columns:repeat(2,1fr)}.rotationGrid{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:1fr}}@media(max-width:620px){.wrap{padding:12px}.top{align-items:flex-start;flex-direction:column}.actions,.actions .btn{width:100%}.actions .btn{text-align:center}.health,.metrics,.capacityGrid,.fields,.positionsGrid{grid-template-columns:1fr 1fr}.row form{flex:1}.row form .btn{width:100%}.top h1{font-size:20px}.positionMetrics{grid-template-columns:1fr 1fr}}@media(max-width:420px){.positionsGrid,.rotationGrid{grid-template-columns:1fr}}
</style></head><body><div class="wrap">
<div class="top"><div class="brand"><div class="logo">T</div><div><h1>مرکز معاملات خودکار Trade</h1><div class="muted">پورتفوی چندارزی • اولویت IRT / USDT • مانیتورینگ پوزیشن و تصمیم‌های واقعی</div></div></div><div class="actions"><a class="btn" href="/admin/bot/rotation.php">Rotation Live</a><a class="btn gray" href="/admin/">پنل اصلی</a><a class="btn gray" href="/admin/exchanges.php">صرافی‌ها</a></div></div>
<div class="health"><div class="healthItem"><span>وضعیت Cron</span><b class="<?=$cron['healthy']?'ok':'bad'?>"><?=$cron['healthy']?'فعال و سالم':'نیازمند بررسی'?></b></div><div class="healthItem"><span>آخرین اجرای Cron</span><b><?=isset($cron['age_seconds'])?h((string)$cron['age_seconds']).' ثانیه قبل':'—'?></b></div><div class="healthItem"><span>توقف اضطراری</span><b class="<?=$status['kill_switch']?'bad':'ok'?>"><?=$status['kill_switch']?'فعال':'خاموش'?></b></div><div class="healthItem"><span>سرمایه پایه</span><b>IRT → USDT</b></div></div>
<div class="notice">نوبیتکس Score ندارد. ابتدا Net Edge پس از کارمزد، Spread و Slippage محاسبه می‌شود؛ سپس حاشیه اطمینان پویا بر اساس هزینه اجرا و نوسان کم می‌شود. فقط وقتی «Edge قابل معامله» مثبت بماند خرید انجام می‌شود.</div>
<div class="info"><b>مدیریت ظرفیت هوشمند:</b> اندازه واقعی ورود نوبیتکس اکنون به‌صورت خودکار از سقف سرمایه درگیر و حداکثر تعداد پوزیشن محاسبه می‌شود. مثال: ۶۰٪ Exposure با ۲۰ پوزیشن یعنی حداکثر هدف حدود ۳٪ برای هر ورود جدید. سفارش‌های Pending نیز تا سقف مستقل ادامه پیدا می‌کنند و Watchdog سفارش قدیمی را پس از Timeout بررسی و لغو می‌کند.</div>
<?php $rp=is_array($rotation['portfolio']??null)?$rotation['portfolio']:[];$rw=is_array($rotation['weakest_position']??null)?$rotation['weakest_position']:[];$rb=is_array($rotation['best_replacement']??null)?$rotation['best_replacement']:[];$rplan=is_array($rotation['preview_plan']??null)?$rotation['preview_plan']:[];$rc=is_array($rotation['cooldown']??null)?$rotation['cooldown']:[]; ?>
<div class="card"><div class="sectionTitle"><div><h2>Rotation Live Preview</h2><div class="muted">تصمیم نمایشی از آخرین سیگنال‌های ذخیره‌شده؛ اجرای واقعی دوباره بازار را اسکن می‌کند.</div></div><a class="btn gray" href="/admin/bot/rotation.php">جزئیات و تاریخچه</a></div><div class="rotationGrid"><div class="metric"><span>وضعیت</span><b><?=h(strtoupper((string)($rotation['state']??'unknown')))?></b></div><div class="metric"><span>ظرفیت پورتفو</span><b><?=h(($rp['active_positions']??0).'/'.($rp['max_positions']??0))?></b></div><div class="metric"><span>ضعیف‌ترین پوزیشن</span><b><?=h($rw['symbol']??'—')?> <?=isset($rw['forward_edge_percent'])?'• '.h(fmt($rw['forward_edge_percent'],3)).'%':''?></b></div><div class="metric"><span>بهترین جایگزین</span><b><?=h($rb['symbol']??'—')?> <?=isset($rb['tradable_net_edge_percent'])?'• '.h(fmt($rb['tradable_net_edge_percent'],3)).'%':''?></b></div><div class="metric"><span>Edge Diff / Required</span><b><?=isset($rplan['advantage_percent'])?h(fmt($rplan['advantage_percent'],3)):'—')?>% / <?=isset($rplan['required_advantage_percent'])?h(fmt($rplan['required_advantage_percent'],3)):'—')?>%</b></div></div><div class="rotationReason"><b><?=h(reasonFa((string)($rotation['reason']??'rotation_preview_unavailable')))?></b><?php if(($rc['active']??false)===true):?> <span class="muted">• Cooldown: <?=h($rc['remaining_seconds']??0)?> ثانیه باقی مانده</span><?php endif?></div></div>
<?php if($message):?><div class="msg"><?=h($message)?></div><?php endif?><?php if($error):?><div class="err"><?=h($error)?></div><?php endif?>
<?php if($runResult!==null):?><div class="card"><div class="sectionTitle"><h2>نتیجه اجرای دستی</h2><b><?=h(statusFa((string)($runResult['status']??'-')))?></b></div><p><?=h(decisionLine($runResult))?></p><details class="details"><summary>جزئیات فنی</summary><div class="code"><?=h(json_encode($runResult,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></div></details></div><?php endif?>
<div class="grid">
<?php foreach(['nobitex'=>'Nobitex','bitpin'=>'Bitpin'] as $key=>$name):
    $e=$ex[$key];$p=$e['performance'];$q=$p['quote_asset'];$positions=$e['active_positions']??[];$signal=$e['latest_signal']??null;
    $signalRows=$data[$key]['signals']??[];$latestMap=latestSignalsBySymbol($signalRows);$activeSet=activeSymbolSet($positions);
    $floating=0.0;$floatingPctWeighted=0.0;$floatingBase=0.0;
    foreach($positions as $pp){$sym=strtoupper((string)$pp['symbol']);$an=positionAnalytics($pp,$latestMap[$sym]??null);$base=(float)$pp['entry_price']*(float)$pp['amount'];$floating+=$an['net_pnl'];$floatingPctWeighted+=($an['net_pct']*$base);$floatingBase+=$base;}
    $floatingPct=$floatingBase>0?$floatingPctWeighted/$floatingBase:0.0;
?>
<div class="card exchange"><div class="sectionTitle"><div><h2><?=$name?></h2><div class="muted"><?=$key==='nobitex'?'Profit-First • Full Universe • Adaptive Position Sizing • Pending Watchdog • Rotation':'کنترل مستقل صرافی'?></div></div><div class="badges"><span class="badge <?=$e['credentials_configured']?'on':'off'?>">API <?=$e['credentials_configured']?'READY':'OFF'?></span><span class="badge <?=$e['bot_enabled']?'on':'off'?>">Bot <?=$e['bot_enabled']?'ON':'OFF'?></span><span class="badge <?=$e['live_execution_enabled']?'on':'off'?>">Live <?=$e['live_execution_enabled']?'ON':'OFF'?></span></div></div>
<div class="metrics"><div class="metric"><span>پوزیشن فعال</span><b><?=h($e['active_position_count'])?></b></div><div class="metric"><span>Win Rate <?=$q?></span><b><?=h(fmt($p['win_rate_percent'],1))?>%</b></div><div class="metric"><span>PnL امروز <?=$q?></span><b><?=h(fmt($p['today_realized_pnl'],2))?></b></div><div class="metric"><span>PnL کل <?=$q?></span><b><?=h(fmt($p['total_realized_pnl'],2))?></b></div></div>
<?php if($key==='nobitex'):$cap=$e['portfolio_capacity']??[];?><div class="capacityGrid"><div class="metric"><span>ظرفیت پوزیشن</span><b><?=h(($cap['active_positions']??0).'/'.($cap['max_positions']??0))?></b></div><div class="metric"><span>هدف هر ورود جدید</span><b><?=h(fmt($cap['effective_position_percent']??0,2))?>%</b></div><div class="metric"><span>سقف Exposure</span><b><?=h(fmt($cap['portfolio_exposure_limit_percent']??0,1))?>%</b></div><div class="metric"><span>Pending</span><b><?=h(($cap['pending_orders']??0).'/'.($cap['max_pending_orders']??0))?></b></div></div><div class="muted" style="margin-top:7px">Timeout سفارش در انتظار: <?=h($cap['pending_timeout_seconds']??60)?> ثانیه • اسلات باقی‌مانده: <?=h($cap['remaining_position_slots']??0)?></div><?php endif?>
<?php if($positions):?><div class="portfolioPnl <?=$floatingPct>=0?'pnlPos':'pnlNeg'?>"><b>PnL تقریبی پوزیشن‌های باز: <?=h(fmt($floating,2))?> <?=$q?> (<?=h(fmt($floatingPct,2))?>%)</b></div><?php endif?>
<div class="decision"><b>آخرین تصمیم ربات</b><br><?=h(decisionLine($e['last_decision']??null))?><?php if($signal):$sd=detailsOf($signal);?><div class="signalMeta muted">آخرین سیگنال: <?=h($signal['symbol'])?> • <?=h(strtoupper((string)$signal['action']))?><?php if($key==='nobitex'&&isset($sd['tradable_net_edge_percent'])):?> • Edge قابل معامله <?=h(fmt($sd['tradable_net_edge_percent'],3))?>% • Net Edge خام <?=h(fmt($sd['expected_net_edge_percent']??0,3))?>% • Buffer <?=h(fmt($sd['required_edge_buffer_percent']??0,3))?>%<?php elseif($key==='nobitex'&&isset($sd['expected_net_edge_percent'])):?> • Net Edge <?=h(fmt($sd['expected_net_edge_percent'],3))?>%<?php elseif($key!=='nobitex'):?> • Score <?=h($signal['score'])?><?php endif?><?php if(isset($sd['confidence'])):?> • Confidence <?=h($sd['confidence'])?>%<?php endif?></div><?php endif?></div>
<?php if($positions):?><div class="positionsGrid"><?php foreach($positions as $pos):$sym=strtoupper((string)$pos['symbol']);$an=positionAnalytics($pos,$latestMap[$sym]??null);$pd=$an['signal_details'];?>
<div class="position"><div class="positionHead"><b><?=h($pos['symbol'])?></b><span class="badge on"><?=h($pos['status'])?></span></div>
<div class="positionMetrics"><div class="mini"><span>قیمت ورود</span><b><?=h(fmt($pos['entry_price'],8))?></b></div><div class="mini"><span>قیمت آخرین پایش</span><b><?=h(fmt($an['current'],8))?></b></div><div class="mini"><span>PnL بازار</span><b class="<?=$an['gross_pct']>=0?'pnlPos':'pnlNeg'?>"><?=h(fmt($an['gross_pnl'],2))?> <?=h($pos['quote_asset'])?> • <?=h(fmt($an['gross_pct'],2))?>%</b></div><div class="mini"><span>PnL پس از هزینه خروج تخمینی</span><b class="<?=$an['net_pct']>=0?'pnlPos':'pnlNeg'?>"><?=h(fmt($an['net_pnl'],2))?> <?=h($pos['quote_asset'])?> • <?=h(fmt($an['net_pct'],2))?>%</b></div><div class="mini"><span>Stop Loss</span><b><?=h(fmt($pos['stop_loss'],8))?></b></div><div class="mini"><span>Take Profit</span><b><?=h(fmt($pos['take_profit'],8))?></b></div></div>
<div class="signalMeta muted">سیگنال پایش: <?=h(strtoupper($an['signal_action']))?><?php if(isset($pd['tradable_net_edge_percent'])):?> • Edge قابل معامله <?=h(fmt($pd['tradable_net_edge_percent'],3))?>%<?php elseif(isset($pd['expected_net_edge_percent'])):?> • Net Edge <?=h(fmt($pd['expected_net_edge_percent'],3))?>%<?php endif?><?php if(isset($pd['confidence'])):?> • Confidence <?=h($pd['confidence'])?>%<?php endif?><?php if($an['signal_time']!==''):?> • <?=h($an['signal_time'])?> UTC<?php endif?></div></div>
<?php endforeach?></div><?php else:?><p class="muted">پوزیشن خودکار فعالی وجود ندارد.</p><?php endif?>
<div class="row" style="margin-top:14px"><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="exchange_bot"><input type="hidden" name="exchange" value="<?=$key?>"><input type="hidden" name="enabled" value="<?=$e['bot_enabled']?'0':'1'?>"><button class="btn <?=$e['bot_enabled']?'gray':'safe'?>"><?=$e['bot_enabled']?'خاموش کردن Bot':'روشن کردن Bot'?></button></form><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="exchange_live"><input type="hidden" name="exchange" value="<?=$key?>"><input type="hidden" name="enabled" value="<?=$e['live_execution_enabled']?'0':'1'?>"><button class="btn <?=$e['live_execution_enabled']?'gray':'safe'?>"><?=$e['live_execution_enabled']?'خاموش کردن Live':'روشن کردن Live'?></button></form><form method="post" onsubmit="return confirm('چرخه LIVE برای <?=$name?> اجرا شود؟ ممکن است سفارش واقعی ایجاد شود.');"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="run_now"><input type="hidden" name="exchange" value="<?=$key?>"><button class="btn warn">اجرای یک چرخه</button></form></div>
</div><?php endforeach?>
</div>
<div class="card"><div class="sectionTitle"><h2>مدیریت ریسک و اسکن</h2><span class="muted">سود آینده قابل تضمین نیست؛ معیار نوبیتکس Edge قابل معامله پس از هزینه و Buffer پویاست.</span></div><form method="post" style="margin-top:14px"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="save_settings"><div class="fields">
<div class="field"><label>اولویت Quote</label><select name="quote_asset"><option value="IRT" <?=$settings['quote_asset']==='IRT'?'selected':''?>>IRT</option><option value="USDT" <?=$settings['quote_asset']==='USDT'?'selected':''?>>USDT</option></select></div>
<div class="field"><label>Risk Profile</label><select name="risk_profile"><option value="safe" <?=$settings['risk_profile']==='safe'?'selected':''?>>Safe</option><option value="balanced" <?=$settings['risk_profile']==='balanced'?'selected':''?>>Balanced</option><option value="aggressive" <?=$settings['risk_profile']==='aggressive'?'selected':''?>>Aggressive</option></select></div>
<div class="field"><label>Minimum Signal Score — فقط Bitpin</label><input type="number" min="35" max="90" name="min_signal_score" value="<?=h($settings['min_signal_score'])?>"></div>
<div class="field"><label>درصد سرمایه درخواستی هر ورود</label><input type="number" step="0.1" min="0.5" max="20" name="position_percent" value="<?=h($settings['position_percent'])?>"></div>
<div class="field"><label>درصد مؤثر هر ورود نوبیتکس</label><input type="text" value="<?=h(fmt($settings['nobitex_effective_position_percent']??0,2))?>% — خودکار" disabled></div>
<div class="field"><label>حداکثر هر پوزیشن %</label><input type="number" step="0.1" min="1" max="25" name="max_position_percent" value="<?=h($settings['max_position_percent'])?>"></div>
<div class="field"><label>حداکثر کل پورتفو نوبیتکس %</label><input type="number" step="1" min="10" max="90" name="nobitex_portfolio_exposure_percent" value="<?=h($settings['nobitex_portfolio_exposure_percent'])?>"></div>
<div class="field"><label>حداکثر پوزیشن همزمان نوبیتکس</label><input type="number" min="1" max="20" name="nobitex_max_positions" value="<?=h($settings['nobitex_max_positions'])?>"></div>
<div class="field"><label>حداکثر سفارش Pending همزمان</label><input type="number" min="1" max="5" name="nobitex_max_pending_orders" value="<?=h($settings['nobitex_max_pending_orders'])?>"></div>
<div class="field"><label>Timeout سفارش Pending (ثانیه)</label><input type="number" min="30" max="300" step="5" name="nobitex_pending_timeout_seconds" value="<?=h($settings['nobitex_pending_timeout_seconds'])?>"></div>
<div class="field"><label>تحلیل نوبیتکس</label><input type="text" value="تمام بازارهای قابل‌اجرای IRT/USDT" disabled></div>
<div class="field"><label>Cooldown دقیقه</label><input type="number" min="1" max="1440" name="cooldown_minutes" value="<?=h($settings['cooldown_minutes'])?>"></div>
<div class="field"><label>Stop Loss %</label><input type="number" step="0.1" min="0.5" max="15" name="stop_loss_percent" value="<?=h($settings['stop_loss_percent'])?>"></div>
<div class="field"><label>Take Profit %</label><input type="number" step="0.1" min="0.5" max="50" name="take_profit_percent" value="<?=h($settings['take_profit_percent'])?>"></div>
<div class="field"><label>حد زیان روزانه %</label><input type="number" step="0.1" min="1" max="15" name="daily_loss_limit_percent" value="<?=h($settings['daily_loss_limit_percent'])?>"></div>
</div><button class="btn" style="margin-top:13px">ذخیره تنظیمات</button></form></div>
<div class="card"><div class="sectionTitle"><h2>توقف اضطراری</h2><b class="<?=$status['kill_switch']?'bad':'ok'?>"><?=$status['kill_switch']?'فعال':'خاموش'?></b></div><p class="muted">Kill Switch ارسال هر سفارش جدید را در هر دو صرافی متوقف می‌کند.</p><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="kill_switch"><input type="hidden" name="enabled" value="<?=$status['kill_switch']?'0':'1'?>"><button class="btn <?=$status['kill_switch']?'safe':'danger'?>"><?=$status['kill_switch']?'برداشتن توقف اضطراری':'فعال‌کردن توقف اضطراری'?></button></form></div>
<div class="grid"><?php foreach(['nobitex'=>'Nobitex','bitpin'=>'Bitpin'] as$key=>$name):$positions=$ex[$key]['active_positions']??[];$activeSet=activeSymbolSet($positions);?><div class="card"><div class="sectionTitle"><h2>سیگنال‌های <?=$name?></h2><span class="muted">۳۰ مورد اخیر</span></div><div class="scroll"><table><tr><th>UTC</th><th>Market</th><th>Action</th><th><?=$key==='nobitex'?'Edge قابل معامله':'Score'?></th><th>Confidence</th><th>وضعیت</th></tr><?php foreach($data[$key]['signals'] as$r):$details=detailsOf($r);$action=strtolower((string)$r['action']);[$stateLabel,$stateClass]=signalStateFa($r,$activeSet);?><tr><td><?=h($r['created_at'])?></td><td><b><?=h($r['symbol'])?></b></td><td class="<?=$action==='buy'?'signalBuy':($action==='sell'?'signalSell':'signalHold')?>"><?=h(strtoupper($action))?></td><td><?php if($key==='nobitex'):?><?=isset($details['tradable_net_edge_percent'])?h(fmt($details['tradable_net_edge_percent'],3)).'%':(isset($details['expected_net_edge_percent'])?h(fmt($details['expected_net_edge_percent'],3)).'%':'—')?><?php else:?><?=h($r['score'])?><?php endif?></td><td><?=isset($details['confidence'])?h($details['confidence']).'%':'—'?></td><td><span class="badge state <?=$stateClass?>"><?=h($stateLabel)?></span></td></tr><?php endforeach?></table></div></div><?php endforeach?></div>
<div class="card"><div class="sectionTitle"><h2>آخرین اجرای Cron</h2><span class="badge <?=$cron['healthy']?'on':'off'?>"><?=h($cron['status'])?></span></div><p class="muted">برای استفاده روزمره، تصمیم خوانا و وضعیت پوزیشن‌ها در کارت‌های بالا نمایش داده می‌شود. JSON فقط برای عیب‌یابی است.</p><details class="details"><summary>نمایش اطلاعات فنی Cron</summary><div class="code"><?=h(json_encode($status['last_run'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></div></details></div>
</div></body></html>
