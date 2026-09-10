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
    $s=statusFa((string)($d['status']??'-'));$r=(string)($d['reason']??$d['error']??'');$selected=is_array($d['selected']??null)?$d['selected']:null;$tail='';
    if($selected){$tail=' — '.($selected['symbol']??'');if(isset($selected['tradable_net_edge_percent']))$tail.=' | Edge '.fmt($selected['tradable_net_edge_percent'],3).'%';elseif(isset($selected['expected_net_edge_percent']))$tail.=' | Net Edge '.fmt($selected['expected_net_edge_percent'],3).'%';elseif(isset($selected['signal_score'])&&(int)$selected['signal_score']!==0)$tail.=' | Score '.(int)$selected['signal_score'];}
    return $s.($r!==''?' — '.reasonFa($r):'').$tail;
}
function detailsOf(array $row): array { if(is_array($row['details']??null))return $row['details'];$d=json_decode((string)($row['details_json']??''),true);return is_array($d)?$d:[]; }
function activeSymbolSet(array $positions): array { $set=[];foreach($positions as $p)$set[strtoupper((string)($p['symbol']??''))]=true;return $set; }
function signalStateFa(array $row,array $activeSymbols): array {
    if((int)($row['executed']??0)===1)return['سفارش ایجاد شد','good'];$symbol=strtoupper((string)($row['symbol']??''));$action=strtolower((string)($row['action']??'hold'));
    if(isset($activeSymbols[$symbol]))return[$action==='sell'?'سیگنال خروج':'پایش پوزیشن','info'];if($action==='hold')return['فقط تحلیل',''];if($action==='buy')return['فرصت؛ بدون سفارش','warn'];if($action==='sell')return['فروش؛ بدون پوزیشن',''];return['بدون سفارش',''];
}
function latestSignalsBySymbol(array $rows): array { $out=[];foreach($rows as $row){$symbol=strtoupper((string)($row['symbol']??''));if($symbol!==''&&!isset($out[$symbol]))$out[$symbol]=$row;}return $out; }
function positionAnalytics(array $position,?array $signal): array {
    $entry=(float)($position['entry_price']??0);$amount=(float)($position['amount']??0);$current=$signal?(float)($signal['price']??0):0.0;if($current<=0)$current=$entry;$grossPct=$entry>0?(($current-$entry)/$entry)*100.0:0.0;$grossPnl=($current-$entry)*$amount;$details=$signal?detailsOf($signal):[];$exitCost=max(0.0,(float)($details['estimated_exit_cost_percent']??0));$netPct=$grossPct-$exitCost;$netPnl=$entry>0?($entry*$amount*($netPct/100.0)):0.0;
    return ['current'=>$current,'gross_pct'=>$grossPct,'gross_pnl'=>$grossPnl,'exit_cost_pct'=>$exitCost,'net_pct'=>$netPct,'net_pnl'=>$netPnl,'signal_details'=>$details,'signal_action'=>strtolower((string)($signal['action']??'hold')),'signal_time'=>(string)($signal['created_at']??'')];
}

$c=new BotController();$message='';$error='';$runResult=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    $posted=(string)($_POST['csrf']??'');$session=(string)($_SESSION['csrf']??'');
    if($posted===''||$session===''||!hash_equals($session,$posted)){$_SESSION['csrf']=bin2hex(random_bytes(24));header('Location: /admin/bot/?csrf_refresh=1',true,303);exit;}
    try{$a=(string)($_POST['action']??'');$exchange=strtolower((string)($_POST['exchange']??'nobitex'));if($a==='save_settings'){$c->updateSettings($_POST);$message='تنظیمات ریسک و پورتفو ذخیره شد.';}elseif($a==='run_now'){$runResult=$c->runNow($exchange);$message='چرخه '.$exchange.' اجرا شد.';}}catch(Throwable $e){$error=mb_substr($e->getMessage(),0,700);}
}
if(isset($_GET['csrf_refresh']))$error='فرم قدیمی بود؛ صفحه تازه شد و هیچ تغییری انجام نشد.';

$status=$c->status();$data=$c->recentData(30);$settings=$status['settings'];$ex=$status['exchanges'];$csrf=h((string)$_SESSION['csrf']);$version=Updater::currentVersion();
require dirname(__DIR__).'/ _nav.php';
