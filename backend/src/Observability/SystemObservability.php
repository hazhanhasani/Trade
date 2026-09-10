<?php

declare(strict_types=1);

namespace Trade\Observability;

use Trade\Database;
use Trade\Support\IranClock;
use Trade\Updater;

final class SystemObservability
{
    public function snapshot(): array
    {
        $root = defined('TRADE_ROOT') ? TRADE_ROOT : dirname(__DIR__,2);
        $storage = $root . '/storage';
        $diskTotal = @disk_total_space($root);
        $diskFree = @disk_free_space($root);
        $diskUsed = is_numeric($diskTotal) && is_numeric($diskFree) ? max(0.0, (float)$diskTotal - (float)$diskFree) : null;
        $diskPct = $diskUsed !== null && (float)$diskTotal > 0 ? ($diskUsed / (float)$diskTotal) * 100.0 : null;
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;

        $db = ['ok'=>false,'latency_ms'=>null,'driver'=>null];
        try {
            $start = hrtime(true);
            $pdo = Database::connection();
            $pdo->query('SELECT 1')->fetchColumn();
            $db = [
                'ok'=>true,
                'latency_ms'=>round((hrtime(true)-$start)/1_000_000,2),
                'driver'=>(string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME),
            ];
        } catch (\Throwable $e) {
            $db['error']=mb_substr($e->getMessage(),0,300);
        }

        $heartbeat = $this->heartbeat($storage . '/cron-heartbeat.json');
        $uptime = $this->uptime();
        $counts = ErrorReporter::counts(60);
        $recent = ErrorReporter::recent(40);
        $balePending = 0;
        try { $balePending = (new BaleSystemAlert())->pendingCount(); } catch (\Throwable) {}

        $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
        if (!$https && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) $https = strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';

        return [
            'model'=>'host_observability_v1',
            'backend_version'=>Updater::currentVersion(),
            'time_iran'=>IranClock::nowPayload(),
            'php'=>[
                'version'=>PHP_VERSION,
                'sapi'=>PHP_SAPI,
                'memory_limit'=>(string)ini_get('memory_limit'),
                'memory_usage_bytes'=>memory_get_usage(true),
                'memory_peak_bytes'=>memory_get_peak_usage(true),
                'opcache_enabled'=>function_exists('opcache_get_status') && (bool)@opcache_get_status(false),
                'sodium_available'=>function_exists('sodium_crypto_sign_detached'),
                'curl_available'=>extension_loaded('curl'),
            ],
            'host'=>[
                'hostname'=>(string)(gethostname() ?: php_uname('n')),
                'os'=>PHP_OS_FAMILY,
                'https'=>$https,
                'load_1m'=>is_array($load) ? round((float)($load[0] ?? 0),3) : null,
                'load_5m'=>is_array($load) ? round((float)($load[1] ?? 0),3) : null,
                'load_15m'=>is_array($load) ? round((float)($load[2] ?? 0),3) : null,
                'uptime_seconds'=>$uptime,
            ],
            'disk'=>[
                'total_bytes'=>is_numeric($diskTotal)?(int)$diskTotal:null,
                'free_bytes'=>is_numeric($diskFree)?(int)$diskFree:null,
                'used_bytes'=>$diskUsed!==null?(int)$diskUsed:null,
                'used_percent'=>$diskPct!==null?round($diskPct,2):null,
                'storage_writable'=>is_dir($storage) && is_writable($storage),
                'app_log_bytes'=>$this->size($storage.'/app.log'),
                'error_event_log_bytes'=>$this->size($storage.'/error-events.log'),
            ],
            'database'=>$db,
            'cron'=>$heartbeat,
            'errors_last_60m'=>$counts,
            'recent_errors'=>$recent,
            'bale_system_alerts'=>['pending'=>$balePending],
        ];
    }

    private function heartbeat(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) return ['status'=>'missing','healthy'=>false,'age_seconds'=>null];
        $raw=@file_get_contents($path);
        $data=is_string($raw)?json_decode($raw,true):null;
        if (!is_array($data)) return ['status'=>'invalid','healthy'=>false,'age_seconds'=>null];
        $finished=(string)($data['finished_at'] ?? $data['started_at'] ?? '');
        $ts=$finished!==''?strtotime($finished):false;
        $age=$ts===false?null:max(0,time()-$ts);
        $status=(string)($data['status']??'unknown');
        $healthy=$age!==null && $age<=180 && !in_array($status,['failed','fatal','failed_before_summary'],true);
        return [
            'status'=>$status,
            'healthy'=>$healthy,
            'age_seconds'=>$age,
            'backend_version'=>$data['backend_version']??null,
            'run_id'=>$data['run_id']??null,
            'started_at_utc'=>$data['started_at']??null,
            'finished_at_utc'=>$data['finished_at']??null,
            'finished_at_iran'=>$finished!==''?IranClock::fromUtc($finished):null,
            'last_error'=>$data['last_error']??$data['error']??null,
        ];
    }

    private function uptime(): ?int
    {
        $path='/proc/uptime';
        if (!is_readable($path)) return null;
        $raw=@file_get_contents($path);
        if (!is_string($raw) || !preg_match('/^([0-9.]+)/',$raw,$m)) return null;
        return max(0,(int)floor((float)$m[1]));
    }

    private function size(string $path): int
    {
        return is_file($path) ? (int)(@filesize($path) ?: 0) : 0;
    }
}
