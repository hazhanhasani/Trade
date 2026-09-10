<?php

declare(strict_types=1);

namespace Trade\Security;

final class GitHubOidcVerifier
{
    private const ISSUER = 'https://token.actions.githubusercontent.com';
    private const JWKS_URL = 'https://token.actions.githubusercontent.com/.well-known/jwks';
    private const AUDIENCE = 'trade-android-signing';
    private const REPOSITORY = 'hazhanhasani/Trade';
    private const REPOSITORY_ID = '1363124612';
    private const OWNER = 'hazhanhasani';
    private const OWNER_ID = '311383112';

    private const ALLOWED_WORKFLOWS = [
        'hazhanhasani/Trade/.github/workflows/publish-latest.yml@refs/heads/main',
        'hazhanhasani/Trade/.github/workflows/android-build.yml@refs/heads/main',
        'hazhanhasani/Trade/.github/workflows/android-signing-bootstrap.yml@refs/heads/main',
    ];

    public function verify(string $jwt): array
    {
        $parts = explode('.', trim($jwt));
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid GitHub OIDC token format.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = json_decode($this->base64UrlDecode($encodedHeader), true);
        $claims = json_decode($this->base64UrlDecode($encodedPayload), true);
        if (!is_array($header) || !is_array($claims)) {
            throw new \RuntimeException('Invalid GitHub OIDC token JSON.');
        }
        if (($header['alg'] ?? null) !== 'RS256') {
            throw new \RuntimeException('Unexpected GitHub OIDC signing algorithm.');
        }
        $kid = trim((string) ($header['kid'] ?? ''));
        if ($kid === '') {
            throw new \RuntimeException('GitHub OIDC token has no key id.');
        }

        $jwk = $this->jwkByKid($kid);
        $publicKey = $this->rsaJwkToPem($jwk);
        $signature = $this->base64UrlDecode($encodedSignature);
        $verified = openssl_verify(
            $encodedHeader . '.' . $encodedPayload,
            $signature,
            $publicKey,
            OPENSSL_ALGO_SHA256
        );
        if ($verified !== 1) {
            throw new \RuntimeException('GitHub OIDC signature verification failed.');
        }

        $now = time();
        if ((string) ($claims['iss'] ?? '') !== self::ISSUER) {
            throw new \RuntimeException('Unexpected GitHub OIDC issuer.');
        }
        if (!$this->audienceMatches($claims['aud'] ?? null)) {
            throw new \RuntimeException('Unexpected GitHub OIDC audience.');
        }
        if ((int) ($claims['exp'] ?? 0) < $now - 5) {
            throw new \RuntimeException('GitHub OIDC token expired.');
        }
        if ((int) ($claims['nbf'] ?? 0) > $now + 60) {
            throw new \RuntimeException('GitHub OIDC token is not active yet.');
        }
        $issuedAt = (int) ($claims['iat'] ?? 0);
        if ($issuedAt <= 0 || $issuedAt > $now + 60 || $issuedAt < $now - 900) {
            throw new \RuntimeException('GitHub OIDC token issue time is outside the accepted window.');
        }

        if ((string) ($claims['repository'] ?? '') !== self::REPOSITORY) {
            throw new \RuntimeException('Untrusted GitHub repository.');
        }
        if ((string) ($claims['repository_id'] ?? '') !== self::REPOSITORY_ID) {
            throw new \RuntimeException('Untrusted GitHub repository id.');
        }
        if ((string) ($claims['repository_owner'] ?? '') !== self::OWNER || (string) ($claims['repository_owner_id'] ?? '') !== self::OWNER_ID) {
            throw new \RuntimeException('Untrusted GitHub repository owner.');
        }
        if ((string) ($claims['actor'] ?? '') !== self::OWNER || (string) ($claims['actor_id'] ?? '') !== self::OWNER_ID) {
            throw new \RuntimeException('Android signing access is restricted to the repository owner.');
        }
        if ((string) ($claims['ref'] ?? '') !== 'refs/heads/main') {
            throw new \RuntimeException('Android signing access is restricted to main.');
        }

        $workflowRef = (string) ($claims['workflow_ref'] ?? '');
        if (!in_array($workflowRef, self::ALLOWED_WORKFLOWS, true)) {
            throw new \RuntimeException('Untrusted GitHub workflow.');
        }
        $event = (string) ($claims['event_name'] ?? '');
        if (!in_array($event, ['push', 'workflow_dispatch'], true)) {
            throw new \RuntimeException('Untrusted GitHub workflow event.');
        }

        // GitHub repositories created after July 15, 2026 use immutable default
        // subjects that include owner/repository IDs. Keep legacy acceptance only
        // for compatibility; the independent repository/owner/ref claims above are
        // pinned as well, so neither form weakens the trust decision.
        $subject = (string) ($claims['sub'] ?? '');
        $legacyPrefix = 'repo:' . self::REPOSITORY . ':';
        $immutablePrefix = 'repo:' . self::OWNER . '@' . self::OWNER_ID . '/Trade@' . self::REPOSITORY_ID . ':';
        if (!str_starts_with($subject, $legacyPrefix) && !str_starts_with($subject, $immutablePrefix)) {
            throw new \RuntimeException('Unexpected GitHub OIDC subject.');
        }

        return $claims;
    }

    private function audienceMatches(mixed $aud): bool
    {
        if (is_string($aud)) return hash_equals(self::AUDIENCE, $aud);
        if (is_array($aud)) {
            foreach ($aud as $value) {
                if (is_string($value) && hash_equals(self::AUDIENCE, $value)) return true;
            }
        }
        return false;
    }

    private function jwkByKid(string $kid): array
    {
        $cachePath = TRADE_ROOT . '/storage/github-oidc-jwks.json';
        $cached = $this->readCachedJwks($cachePath, 21600);
        $key = $this->findKey($cached, $kid);
        if ($key) return $key;

        $fresh = $this->fetchJwks();
        @file_put_contents($cachePath, json_encode($fresh, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
        @chmod($cachePath, 0600);
        $key = $this->findKey($fresh, $kid);
        if (!$key) {
            throw new \RuntimeException('GitHub OIDC signing key was not found.');
        }
        return $key;
    }

    private function readCachedJwks(string $path, int $maxAge): array
    {
        if (!is_file($path) || (time() - (int) @filemtime($path)) > $maxAge) return [];
        $raw = @file_get_contents($path);
        if ($raw === false) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function fetchJwks(): array
    {
        $ch = curl_init(self::JWKS_URL);
        if ($ch === false) throw new \RuntimeException('Unable to initialize GitHub OIDC JWKS request.');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: Trade-Android-Signing/1.1'],
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false || $status !== 200) {
            throw new \RuntimeException('Unable to fetch GitHub OIDC JWKS: HTTP ' . $status . ($error !== '' ? ' - ' . $error : ''));
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
            throw new \RuntimeException('Invalid GitHub OIDC JWKS response.');
        }
        return $decoded;
    }

    private function findKey(array $jwks, string $kid): ?array
    {
        foreach (($jwks['keys'] ?? []) as $key) {
            if (!is_array($key)) continue;
            if ((string) ($key['kid'] ?? '') !== $kid) continue;
            if (($key['kty'] ?? '') !== 'RSA' || ($key['alg'] ?? '') !== 'RS256') continue;
            if (!isset($key['n'], $key['e'])) continue;
            return $key;
        }
        return null;
    }

    private function rsaJwkToPem(array $jwk): string
    {
        $modulus = $this->base64UrlDecode((string) $jwk['n']);
        $exponent = $this->base64UrlDecode((string) $jwk['e']);
        $rsaPublicKey = $this->asn1Sequence($this->asn1Integer($modulus) . $this->asn1Integer($exponent));
        $rsaEncryptionAlgorithm = hex2bin('300d06092a864886f70d0101010500');
        if ($rsaEncryptionAlgorithm === false) throw new \RuntimeException('Unable to build RSA algorithm identifier.');
        $subjectPublicKeyInfo = $this->asn1Sequence($rsaEncryptionAlgorithm . $this->asn1BitString($rsaPublicKey));
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function asn1Sequence(string $value): string { return "\x30" . $this->asn1Length(strlen($value)) . $value; }
    private function asn1Integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '') $value = "\x00";
        if ((ord($value[0]) & 0x80) !== 0) $value = "\x00" . $value;
        return "\x02" . $this->asn1Length(strlen($value)) . $value;
    }
    private function asn1BitString(string $value): string { $value = "\x00" . $value; return "\x03" . $this->asn1Length(strlen($value)) . $value; }
    private function asn1Length(int $length): string
    {
        if ($length < 0x80) return chr($length);
        $bytes = '';
        while ($length > 0) { $bytes = chr($length & 0xff) . $bytes; $length >>= 8; }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }
    private function base64UrlDecode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding !== 0) $value .= str_repeat('=', 4 - $padding);
        $decoded = base64_decode($value, true);
        if ($decoded === false) throw new \RuntimeException('Invalid base64url payload.');
        return $decoded;
    }
}
