<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

final class TradeNotificationCenter
{
    private static bool $ensured=false;

    public function recent(int $limit=50,bool $unreadOnly=false,?PDO $pdo=null):array
    {
        $pdo??=Database::connection();$this->ensure($pdo);$this->syncSources($pdo);
        $limit=max(1,min(100,$limit));$where=$unreadOnly?'WHERE read_at IS NULL':'';
        $rows=$pdo->query("SELECT id,event_key,category,priority,title,body,context_json,read_at,created_at FROM trade_notifications {$where} ORDER BY id DESC LIMIT {$limit}")->fetchAll();
        foreach($rows as &$row){$ctx=json_decode((string)($row['context_json']??''),true);$row['context']=is_array($ctx)?$ctx:[];unset($row['context_json']);$row['unread']=$row['read_at']===null;}
        unset($row);return $rows;
    }

    public function unreadCount(?PDO $pdo=null):int
    {
        $pdo??=Database::connection();$this->ensure($pdo);$this->syncSources($pdo);
        return (int)$pdo->query('SELECT COUNT(*) FROM trade_notifications WHERE read_at IS NULL')->fetchColumn();
    }

    public function markRead(?int $id=null,?PDO $pdo=null):void
    {
        $pdo??=Database::connection();$this->ensure($pdo);
        if($id===null){$pdo->exec('UPDATE trade_notifications SET read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE read_at IS NULL');return;}
        $stmt=$pdo->prepare('UPDATE trade_notifications SET read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE id=:id');$stmt->execute([':id'=>$id]);
    }

    public function emit(string $eventKey,string $category,string $priority,string $title,string $body,array $context=[],?PDO $pdo=null):void
    {
        $pdo??=Database::connection();$this->ensure($pdo);
        $eventKey=substr(trim($eventKey),0,190);if($eventKey==='')return;
        $priority=in_array($priority,['info','success','warning','critical'],true)?$priority:'info';
        $category=substr(trim($category),0,50);$title=mb_substr(trim($title),0,160);$body=mb_substr(trim($body),0,800);
        $stmt=$pdo->prepare("INSERT IGNORE INTO trade_notifications (event_key,category,priority,title,body,context_json,created_at) VALUES (:k,:c,:p,:t,:b,:x,UTC_TIMESTAMP())");
        $stmt->execute([':k'=>$eventKey,':c'=>$category,':p'=>$priority,':t'=>$title,':b'=>$body,':x'=>$context===[]?null:json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }

    private function syncSources(PDO $pdo):void
    {
        try{
            $events=$pdo->query("SELECT id,level,event_name,context_json,created_at FROM nobitex_autotrade_events ORDER BY id DESC LIMIT 160")->fetchAll();
            foreach(array_reverse($events) as $e){
                $name=(string)$e['event_name'];$ctx=json_decode((string)($e['context_json']??''),true);if(!is_array($ctx))$ctx=[];
                [$category,$priority,$title,$body]=$this->mapTradingEvent($name,(string)$e['level'],$ctx);
                if($title==='')continue;
                $this->emit('nobitex-event:'.$e['id'],$category,$priority,$title,$body,$ctx+['event_name'=>$name,'source_created_at'=>$e['created_at']],$pdo);
            }
        }catch(\Throwable){}
        try{
            $runs=$pdo->query("SELECT id,run_id,status,summary_json,started_at,finished_at FROM bot_runs WHERE status='failed' ORDER BY id DESC LIMIT 30")->fetchAll();
            foreach($runs as $r){$this->emit('bot-run-failed:'.$r['id'],'system','critical','خطای اجرای ربات','یکی از چرخه‌های Cron با وضعیت failed پایان یافته است.',['run_id'=>$r['run_id'],'started_at'=>$r['started_at'],'finished_at'=>$r['finished_at']],$pdo);}
        }catch(\Throwable){}
    }

    private function mapTradingEvent(string $name,string $level,array $ctx):array
    {
        $symbol=(string)($ctx['symbol']??'');
        if(str_contains($name,'buy_submitted'))return['trade','success','خرید خودکار ارسال شد',trim(($symbol!==''?$symbol.' • ':'').'سفارش BUY به نوبیتکس ارسال شد.')];
        if(str_contains($name,'sell_submitted'))return['trade','success','فروش خودکار ارسال شد',trim(($symbol!==''?$symbol.' • ':'').'سفارش SELL به نوبیتکس ارسال شد.')];
        if(str_contains($name,'position.closed')){$pnl=$ctx['pnl_percent']??null;return['performance',((float)$pnl>=0?'success':'warning'),'پوزیشن بسته شد',trim(($symbol!==''?$symbol.' • ':'').($pnl!==null?'PnL '.round((float)$pnl,2).'%':'نتیجه معامله ثبت شد.'))];}
        if(str_contains($name,'rotation')&&str_contains($name,'submitted'))return['rotation','info','Portfolio Rotation','پوزیشن ضعیف‌تر برای آزادسازی ظرفیت و جایگزینی فرصت بهتر وارد مسیر خروج شد.'];
        if(str_contains($name,'pending.timeout'))return['execution','warning','Pending Watchdog','سفارش Pending از محدوده زمانی مجاز عبور کرد و مدیریت اجرای سفارش فعال شد.'];
        if(str_contains($name,'scan_failed')||str_contains($name,'reconcile.error'))return['system','warning','خطای پایش بازار',$symbol!==''?$symbol.' در این چرخه کامل پایش نشد.':'یکی از مراحل پایش بازار ناموفق بود.'];
        if(str_contains($name,'blocked'))return['risk','warning','ورود توسط محافظ ریسک متوقف شد',(string)($ctx['reason']??'یکی از گاردهای ایمنی فعال شد.')];
        if(strtolower($level)==='error')return['system','critical','خطای موتور معاملات',$name];
        return['','','',''];
    }

    private function ensure(PDO $pdo):void
    {
        if(self::$ensured)return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS trade_notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_key VARCHAR(190) NOT NULL UNIQUE,
            category VARCHAR(50) NOT NULL,
            priority VARCHAR(20) NOT NULL,
            title VARCHAR(160) NOT NULL,
            body VARCHAR(800) NOT NULL,
            context_json LONGTEXT NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_trade_notifications_unread (read_at,created_at),
            INDEX idx_trade_notifications_category (category,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::$ensured=true;
    }
}
