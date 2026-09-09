<?php

declare(strict_types=1);

namespace Trade;

use RuntimeException;
use Throwable;
use ZipArchive;

final class Updater
{
    private const DEFAULT_MANIFEST_URL = 'https://github.com/hazhanhasani/Trade/releases/download/trade-latest/latest.json';
    private const DEFAULT_CHECK_INTERVAL = 300;

    public static function currentVersion(): string
    {
        $file = TRADE_ROOT . '/version.php';
        if (!is_file($file)) {
            return '0.0.0';
        }
        $version = require $file;
        return is_string($version) && $version !== '' ? $version : '0.0.0';
    }

    public static function effectiveCheckInterval(): int
    {
        $configured = (int) Config::get('updates.check_interval_seconds', self::DEFAULT_CHECK_INTERVAL);

        // Older installers wrote 3600 as their default. Treat that exact legacy
        // value as the new five-minute production default without touching the
        // user's protected storage/config.php during an update.
        if ($configured <= 0 || $configured === 3600) {
            return self::DEFAULT_CHECK_INTERVAL;
        }

        return max(300, min(86400, $configured));
    }

    public static function manifest(): array
    {
        $url = (string) Config::get('updates.manifest_url', self::DEFAULT_MANIFEST_URL);
        $raw = self::downloadString($url, 15);
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['backend']) || !is_array($data['backend'])) {
            throw new RuntimeException('Update manifest is invalid.');
        }
        return $data;
    }

    public static function check(): array
    {
        $manifest = self::manifest();
        $remote = (string) ($manifest['backend']['version'] ?? '0.0.0');
        $current = self::currentVersion();
        return [
            'current_version' => $current,
            'latest_version' => $remote,
            'update_available' => version_compare($remote, $current, '>'),
            'manifest' => $manifest,
        ];
    }

    public static function appUpdateInfo(): array
    {
        $manifest = self::manifest();
        $android = is_array($manifest['android'] ?? null) ? $manifest['android'] : [];
        return [
            'backend_version' => self::currentVersion(),
            'android' => [
                'available' => (bool) ($android['available'] ?? false),
                'version_code' => (int) ($android['version_code'] ?? 0),
                'version_name' => (string) ($android['version_name'] ?? ''),
                'url' => (string) ($android['url'] ?? ''),
                'sha256' => strtolower((string) ($android['sha256'] ?? '')),
            ],
            'published_at' => (string) ($manifest['published_at'] ?? ''),
        ];
    }

    public static function autoUpdateIfDue(): array
    {
        if (!(bool) Config::get('updates.auto_backend', true)) {
            return [
                'status' => 'disabled',
                'current_version' => self::currentVersion(),
                'checked_at' => gmdate(DATE_ATOM),
            ];
        }

        $interval = self::effectiveCheckInterval();
        $state = self::state();
        $lastChecked = isset($state['checked_at']) ? strtotime((string) $state['checked_at']) : false;
        if ($lastChecked !== false && time() - $lastChecked < $interval) {
            return $state + [
                'status' => $state['status'] ?? 'not_due',
                'next_check_in_seconds' => max(0, $interval - (time() - $lastChecked)),
                'check_interval_seconds' => $interval,
            ];
        }

        return self::checkAndInstall(false);
    }

    public static function updateNow(): array
    {
        return self::checkAndInstall(true);
    }

    private static function checkAndInstall(bool $manual): array
    {
        try {
            $check = self::check();
            if (!$check['update_available']) {
                $state = [
                    'status' => 'up_to_date',
                    'current_version' => $check['current_version'],
                    'latest_version' => $check['latest_version'],
                    'check_interval_seconds' => self::effectiveCheckInterval(),
                    'manual' => $manual,
                    'checked_at' => gmdate(DATE_ATOM),
                ];
                self::writeState($state);
                return $state;
            }

            return self::install($check['manifest'], $manual);
        } catch (Throwable $e) {
            $existing = self::state();
            if (($existing['status'] ?? '') === 'update_failed') {
                return $existing;
            }

            $state = [
                'status' => 'check_failed',
                'current_version' => self::currentVersion(),
                'check_interval_seconds' => self::effectiveCheckInterval(),
                'manual' => $manual,
                'message' => $e->getMessage(),
                'checked_at' => gmdate(DATE_ATOM),
            ];
            self::writeState($state);
            return $state;
        }
    }

    public static function install(array $manifest, bool $manual = false): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZIP extension is required for automatic updates.');
        }

        $backend = is_array($manifest['backend'] ?? null) ? $manifest['backend'] : [];
        $remoteVersion = (string) ($backend['version'] ?? '');
        $url = (string) ($backend['url'] ?? '');
        $expectedSha = strtolower((string) ($backend['sha256'] ?? ''));
        $previousVersion = self::currentVersion();

        if ($remoteVersion === '' || $url === '' || !preg_match('/^[a-f0-9]{64}$/', $expectedSha)) {
            throw new RuntimeException('Backend update metadata is incomplete.');
        }
        if (!version_compare($remoteVersion, $previousVersion, '>')) {
            $state = [
                'status' => 'up_to_date',
                'current_version' => $previousVersion,
                'latest_version' => $remoteVersion,
                'check_interval_seconds' => self::effectiveCheckInterval(),
                'manual' => $manual,
                'checked_at' => gmdate(DATE_ATOM),
            ];
            self::writeState($state);
            return $state;
        }

        $storage = TRADE_ROOT . '/storage';
        $updateDir = $storage . '/updates';
        $backupDir = $storage . '/backups';
        foreach ([$updateDir, $backupDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create update directory: ' . $dir);
            }
        }

        $lockPath = $updateDir . '/update.lock';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another update is already running.');
        }

        $token = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        $zipPath = $updateDir . '/package-' . $token . '.zip';
        $stage = $updateDir . '/stage-' . $token;
        $backup = $backupDir . '/backup-' . $previousVersion . '-' . $token . '.zip';
        $originalFiles = [];
        $stagedFiles = [];

        try {
            self::downloadFile($url, $zipPath, 60);
            $actualSha = strtolower(hash_file('sha256', $zipPath) ?: '');
            if (!hash_equals($expectedSha, $actualSha)) {
                throw new RuntimeException('Backend package checksum mismatch.');
            }

            self::extractZip($zipPath, $stage);
            foreach (['bootstrap.php', 'version.php', 'public/index.php', 'src/Config.php'] as $required) {
                if (!is_file($stage . '/' . $required)) {
                    throw new RuntimeException('Update package is missing ' . $required);
                }
            }
            $stageVersion = require $stage . '/version.php';
            if (!is_string($stageVersion) || $stageVersion !== $remoteVersion) {
                throw new RuntimeException('Update package version does not match manifest.');
            }

            $originalFiles = self::listCodeFiles(TRADE_ROOT);
            self::createBackup($backup, $originalFiles);
            $stagedFiles = self::listCodeFiles($stage);

            if (file_put_contents($storage . '/maintenance.lock', 'Updating to ' . $remoteVersion . "\n", LOCK_EX) === false) {
                throw new RuntimeException('Cannot create maintenance lock.');
            }

            self::overlay($stage, TRADE_ROOT);

            $installedVersion = require TRADE_ROOT . '/version.php';
            if (!is_string($installedVersion) || $installedVersion !== $remoteVersion) {
                throw new RuntimeException('Installed backend version validation failed.');
            }
            Database::connection()->query('SELECT 1');

            @unlink($storage . '/maintenance.lock');
            $state = [
                'status' => 'updated',
                'current_version' => $remoteVersion,
                'previous_version' => $previousVersion,
                'latest_version' => $remoteVersion,
                'check_interval_seconds' => self::effectiveCheckInterval(),
                'manual' => $manual,
                'backup' => basename($backup),
                'checked_at' => gmdate(DATE_ATOM),
                'updated_at' => gmdate(DATE_ATOM),
            ];
            self::writeState($state);
            return $state;
        } catch (Throwable $e) {
            try {
                if (is_file($backup)) {
                    self::rollback($backup, $originalFiles, $stagedFiles);
                }
            } catch (Throwable $rollbackError) {
                $e = new RuntimeException($e->getMessage() . ' | Rollback failed: ' . $rollbackError->getMessage(), 0, $e);
            }
            @unlink($storage . '/maintenance.lock');
            $state = [
                'status' => 'update_failed',
                'current_version' => self::currentVersion(),
                'target_version' => $remoteVersion,
                'check_interval_seconds' => self::effectiveCheckInterval(),
                'manual' => $manual,
                'message' => $e->getMessage(),
                'checked_at' => gmdate(DATE_ATOM),
            ];
            self::writeState($state);
            throw $e;
        } finally {
            self::deleteTree($stage);
            @unlink($zipPath);
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public static function state(): array
    {
        $file = TRADE_ROOT . '/storage/update-state.json';
        if (!is_file($file)) {
            return [
                'status' => 'never_checked',
                'current_version' => self::currentVersion(),
                'check_interval_seconds' => self::effectiveCheckInterval(),
            ];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : [
            'status' => 'unknown',
            'current_version' => self::currentVersion(),
            'check_interval_seconds' => self::effectiveCheckInterval(),
        ];
    }

    public static function diagnostics(): array
    {
        $storage = TRADE_ROOT . '/storage';
        return [
            'current_version' => self::currentVersion(),
            'auto_enabled' => (bool) Config::get('updates.auto_backend', true),
            'check_interval_seconds' => self::effectiveCheckInterval(),
            'curl_available' => extension_loaded('curl'),
            'zip_available' => class_exists(ZipArchive::class),
            'storage_writable' => is_dir($storage) && is_writable($storage),
            'state' => self::state(),
        ];
    }

    private static function writeState(array $state): void
    {
        $file = TRADE_ROOT . '/storage/update-state.json';
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($file, $json, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write update state.');
        }
        @chmod($file, 0600);
    }

    private static function downloadString(string $url, int $timeout): string
    {
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Cannot initialize cURL.');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Trade-Updater/' . self::currentVersion(),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Cache-Control: no-cache'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            throw new RuntimeException('Update manifest request failed: HTTP ' . $status . ($error !== '' ? ' - ' . $error : ''));
        }
        return $body;
    }

    private static function downloadFile(string $url, string $target, int $timeout): void
    {
        $fp = fopen($target, 'wb');
        if ($fp === false) throw new RuntimeException('Cannot create update package file.');
        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fp);
            throw new RuntimeException('Cannot initialize cURL.');
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Trade-Updater/' . self::currentVersion(),
            CURLOPT_HTTPHEADER => ['Cache-Control: no-cache'],
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($ok !== true || $status < 200 || $status >= 300) {
            @unlink($target);
            throw new RuntimeException('Update package download failed: HTTP ' . $status . ($error !== '' ? ' - ' . $error : ''));
        }
    }

    private static function extractZip(string $zipPath, string $destination): void
    {
        if (!is_dir($destination) && !mkdir($destination, 0700, true) && !is_dir($destination)) {
            throw new RuntimeException('Cannot create staging directory.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Cannot open update archive.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $normalized = str_replace('\\', '/', $name);
            if ($normalized === '' || str_starts_with($normalized, '/') || str_contains($normalized, '../')) {
                $zip->close();
                throw new RuntimeException('Unsafe path in update archive.');
            }
        }
        if (!$zip->extractTo($destination)) {
            $zip->close();
            throw new RuntimeException('Cannot extract update archive.');
        }
        $zip->close();
    }

    private static function createBackup(string $path, array $files): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create update backup.');
        }
        foreach ($files as $relative) {
            $source = TRADE_ROOT . '/' . $relative;
            if (is_file($source) && !$zip->addFile($source, $relative)) {
                $zip->close();
                throw new RuntimeException('Cannot add file to backup: ' . $relative);
            }
        }
        $zip->addFromString('__trade_original_files.json', json_encode(array_values($files), JSON_THROW_ON_ERROR));
        $zip->close();
        @chmod($path, 0600);
    }

    private static function rollback(string $backup, array $originalFiles, array $stagedFiles): void
    {
        $originalMap = array_fill_keys($originalFiles, true);
        foreach ($stagedFiles as $relative) {
            if (!isset($originalMap[$relative])) {
                $candidate = TRADE_ROOT . '/' . $relative;
                if (is_file($candidate)) @unlink($candidate);
            }
        }
        $zip = new ZipArchive();
        if ($zip->open($backup) !== true) throw new RuntimeException('Cannot open rollback backup.');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '__trade_original_files.json') continue;
            $target = TRADE_ROOT . '/' . $name;
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                $zip->close();
                throw new RuntimeException('Cannot restore directory: ' . $dir);
            }
            $stream = $zip->getStream($name);
            if ($stream === false) {
                $zip->close();
                throw new RuntimeException('Cannot read backup file: ' . $name);
            }
            $out = fopen($target, 'wb');
            if ($out === false) {
                fclose($stream);
                $zip->close();
                throw new RuntimeException('Cannot restore file: ' . $name);
            }
            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
        }
        $zip->close();
    }

    private static function overlay(string $sourceRoot, string $targetRoot): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($sourceRoot) + 1));
            if ($relative === 'storage' || str_starts_with($relative, 'storage/')) continue;
            $target = $targetRoot . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new RuntimeException('Cannot create update directory: ' . $relative);
                }
                continue;
            }
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create update directory: ' . $dir);
            }
            if (!copy($item->getPathname(), $target)) {
                throw new RuntimeException('Cannot update file: ' . $relative);
            }
        }
    }

    private static function listCodeFiles(string $root): array
    {
        if (!is_dir($root)) return [];
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) continue;
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            if ($relative === 'storage' || str_starts_with($relative, 'storage/')) continue;
            $files[] = $relative;
        }
        sort($files);
        return $files;
    }

    private static function deleteTree(string $path): void
    {
        if (!is_dir($path)) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
