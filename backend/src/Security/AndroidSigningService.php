<?php

declare(strict_types=1);

namespace Trade\Security;

use Trade\Config;

final class AndroidSigningService
{
    private const ALIAS = 'trade-release';
    private const FORMAT = 'PKCS12';

    public function status(): array
    {
        $meta = $this->readMetadata();
        $keyFile = $this->keyPath();
        if (!$meta || !is_file($keyFile) || filesize($keyFile) < 256) {
            return [
                'configured' => false,
                'alias' => self::ALIAS,
                'format' => self::FORMAT,
                'cert_sha256' => null,
                'created_at' => null,
            ];
        }

        return [
            'configured' => true,
            'alias' => (string) ($meta['alias'] ?? self::ALIAS),
            'format' => (string) ($meta['format'] ?? self::FORMAT),
            'cert_sha256' => $this->normalizeFingerprint((string) ($meta['cert_sha256'] ?? '')),
            'created_at' => (string) ($meta['created_at'] ?? ''),
        ];
    }

    public function ensure(): array
    {
        $this->ensureDirectory();
        $lock = fopen($this->dir() . '/generate.lock', 'c+');
        if ($lock === false) {
            throw new \RuntimeException('Unable to open Android signing lock.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock Android signing identity.');
            }

            $current = $this->status();
            if ($current['configured']) {
                return $current;
            }

            if (!extension_loaded('openssl')) {
                throw new \RuntimeException('OpenSSL extension is required to create the Android signing identity.');
            }

            $password = bin2hex(random_bytes(24));
            $privateKey = openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'private_key_bits' => 4096,
                'digest_alg' => 'sha256',
            ]);
            if ($privateKey === false) {
                throw new \RuntimeException('Unable to generate Android signing private key.');
            }

            $dn = [
                'commonName' => 'Trade Android Release',
                'organizationName' => 'Trade',
                'organizationalUnitName' => 'Production Signing',
            ];
            $csr = openssl_csr_new($dn, $privateKey, ['digest_alg' => 'sha256']);
            if ($csr === false) {
                throw new \RuntimeException('Unable to create Android signing certificate request.');
            }

            $certificate = openssl_csr_sign(
                $csr,
                null,
                $privateKey,
                36500,
                ['digest_alg' => 'sha256'],
                random_int(1, 2147483647)
            );
            if ($certificate === false) {
                throw new \RuntimeException('Unable to create Android signing certificate.');
            }

            $pkcs12 = '';
            if (!openssl_pkcs12_export(
                $certificate,
                $pkcs12,
                $privateKey,
                $password,
                ['friendly_name' => self::ALIAS]
            )) {
                throw new \RuntimeException('Unable to export Android signing PKCS#12.');
            }

            $fingerprint = openssl_x509_fingerprint($certificate, 'sha256', false);
            if (!is_string($fingerprint) || $fingerprint === '') {
                throw new \RuntimeException('Unable to calculate Android signing certificate fingerprint.');
            }
            $fingerprint = $this->normalizeFingerprint($fingerprint);

            $keyTmp = $this->keyPath() . '.tmp.' . bin2hex(random_bytes(4));
            if (file_put_contents($keyTmp, $pkcs12, LOCK_EX) === false) {
                throw new \RuntimeException('Unable to persist Android signing key.');
            }
            @chmod($keyTmp, 0600);
            if (!rename($keyTmp, $this->keyPath())) {
                @unlink($keyTmp);
                throw new \RuntimeException('Unable to finalize Android signing key.');
            }
            @chmod($this->keyPath(), 0600);

            $appKey = (string) Config::require('app.encryption_key');
            $metadata = [
                'schema' => 1,
                'alias' => self::ALIAS,
                'format' => self::FORMAT,
                'cert_sha256' => $fingerprint,
                'password_enc' => Crypto::encrypt($password, $appKey),
                'created_at' => gmdate(DATE_ATOM),
            ];
            $this->writeMetadata($metadata);

            return $this->status();
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    public function bundleForTrustedWorkflow(): array
    {
        $status = $this->ensure();
        $meta = $this->readMetadata();
        if (!$meta || !$status['configured']) {
            throw new \RuntimeException('Android signing identity is unavailable.');
        }
        $bytes = file_get_contents($this->keyPath());
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Unable to read Android signing identity.');
        }

        $appKey = (string) Config::require('app.encryption_key');
        $password = Crypto::decrypt((string) $meta['password_enc'], $appKey);

        return [
            'format' => self::FORMAT,
            'alias' => (string) $status['alias'],
            'password' => $password,
            'pkcs12_base64' => base64_encode($bytes),
            'cert_sha256' => (string) $status['cert_sha256'],
            'created_at' => (string) $status['created_at'],
        ];
    }

    public function createRecoveryArchive(): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZIP extension is required to create a signing recovery archive.');
        }
        $bundle = $this->bundleForTrustedWorkflow();
        $tmp = $this->dir() . '/recovery-' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create signing recovery archive.');
        }
        $zip->addFile($this->keyPath(), 'Trade-release.p12');
        $zip->addFromString('RECOVERY.txt', implode("\n", [
            'Trade permanent Android signing recovery bundle',
            'Keep this file private and backed up offline.',
            '',
            'Store type: ' . $bundle['format'],
            'Alias: ' . $bundle['alias'],
            'Password: ' . $bundle['password'],
            'Certificate SHA-256: ' . $bundle['cert_sha256'],
            'Created at UTC: ' . $bundle['created_at'],
            '',
            'Losing this key prevents future APK updates from being installed over existing production versions.',
        ]) . "\n");
        $zip->close();
        @chmod($tmp, 0600);
        return $tmp;
    }

    private function ensureDirectory(): void
    {
        $dir = $this->dir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create private Android signing directory.');
        }
        @chmod($dir, 0700);
    }

    private function readMetadata(): ?array
    {
        $path = $this->metadataPath();
        if (!is_file($path)) return null;
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeMetadata(array $metadata): void
    {
        $tmp = $this->metadataPath() . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Unable to persist Android signing metadata.');
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, $this->metadataPath())) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to finalize Android signing metadata.');
        }
        @chmod($this->metadataPath(), 0600);
    }

    private function dir(): string
    {
        return TRADE_ROOT . '/storage/android-signing';
    }

    private function keyPath(): string
    {
        return $this->dir() . '/Trade-release.p12';
    }

    private function metadataPath(): string
    {
        return $this->dir() . '/metadata.json';
    }

    private function normalizeFingerprint(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $value) ?? '');
    }
}
