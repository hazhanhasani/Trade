<?php

declare(strict_types=1);

namespace Trade\Observability;

use Trade\Trading\TradeNotificationCenter;

final class HostHealthSentinel
{
    public const MODEL = 'host_health_sentinel_v1';

    public function run(): array
    {
        $snapshot = (new SystemObservability())->snapshot();
        $alerts = [];

        $disk = $snapshot['disk']['used_percent'] ?? null;
        if (is_numeric($disk) && (float)$disk >= 90.0) {
            $severity = (float)$disk >= 97.0 ? 'critical' : 'warning';
            $alerts[] = $this->alert(
                'host.disk.high',
                $severity,
                'فضای دیسک هاست رو به اتمام است',
                'مصرف دیسک به ' . number_format((float)$disk, 2) . '٪ رسیده است.',
                ['component'=>'host_disk','status'=>(string)$disk]
            );
        }

        if (!(bool)($snapshot['disk']['storage_writable'] ?? false)) {
            $alerts[] = $this->alert(
                'host.storage.not_writable',
                'critical',
                'Storage قابل نوشتن نیست',
                'پوشه storage برای ثبت لاگ، وضعیت و فایل‌های موقت قابل نوشتن نیست.',
                ['component'=>'host_storage','status'=>'not_writable']
            );
        }

        $dbOk = (bool)($snapshot['database']['ok'] ?? false);
        $dbLatency = $snapshot['database']['latency_ms'] ?? null;
        if (!$dbOk) {
            $alerts[] = $this->alert('host.database.down','critical','اتصال دیتابیس قطع است','بررسی سلامت دیتابیس ناموفق بود.',['component'=>'database','status'=>'down']);
        } elseif (is_numeric($dbLatency) && (float)$dbLatency >= 1200.0) {
            $alerts[] = $this->alert(
                'host.database.slow',
                (float)$dbLatency >= 3000.0 ? 'error' : 'warning',
                'دیتابیس کند شده است',
                'تاخیر SELECT 1 برابر ' . number_format((float)$dbLatency, 2) . ' ms است.',
                ['component'=>'database','status'=>(string)$dbLatency]
            );
        }

        $memoryUsage = (int)($snapshot['php']['memory_usage_bytes'] ?? 0);
        $memoryLimit = $this->bytes((string)($snapshot['php']['memory_limit'] ?? ''));
        if ($memoryLimit > 0) {
            $pct = ($memoryUsage / $memoryLimit) * 100.0;
            if ($pct >= 85.0) {
                $alerts[] = $this->alert(
                    'host.php_memory.high',
                    $pct >= 95.0 ? 'critical' : 'warning',
                    'مصرف حافظه PHP بالا است',
                    'این پردازش حدود ' . number_format($pct, 1) . '٪ از memory_limit را مصرف کرده است.',
                    ['component'=>'php_memory','status'=>number_format($pct,1)]
                );
            }
        }

        return [
            'model'=>self::MODEL,
            'alerts'=>array_values(array_filter($alerts)),
            'alert_count'=>count(array_filter($alerts)),
            'snapshot'=>[
                'disk_used_percent'=>$disk,
                'database_ok'=>$dbOk,
                'database_latency_ms'=>$dbLatency,
                'storage_writable'=>$snapshot['disk']['storage_writable'] ?? null,
            ],
        ];
    }

    private function alert(string $fingerprint, string $severity, string $title, string $message, array $context): array
    {
        try {
            (new BaleSystemAlert())->queue($fingerprint,$severity,$title,$message,$context,true);
        } catch (\Throwable) {}
        try {
            $priority = $severity === 'critical' ? 'critical' : ($severity === 'error' ? 'high' : 'normal');
            (new TradeNotificationCenter())->emit(
                'host:' . $fingerprint . ':' . intdiv(time(), 600),
                'system',
                $priority,
                $title,
                $message,
                $context
            );
        } catch (\Throwable) {}
        return ['fingerprint'=>$fingerprint,'severity'=>$severity,'title'=>$title,'message'=>$message];
    }

    private function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') return -1;
        if (!preg_match('/^([0-9.]+)\s*([KMG]?)$/i',$value,$m)) return 0;
        $n=(float)$m[1];
        $mult=match(strtoupper($m[2]??'')){'K'=>1024,'M'=>1024**2,'G'=>1024**3,default=>1};
        return (int)floor($n*$mult);
    }
}
