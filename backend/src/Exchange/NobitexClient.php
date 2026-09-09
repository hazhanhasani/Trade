<?php

declare(strict_types=1);

namespace Trade\Exchange;

final class NobitexClient
{
    private string $baseUrl;
    private string $publicKey;
    private string $privateKey;
    private int $timeout;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? 'https://apiv2.nobitex.ir'), '/');
        $this->publicKey = trim((string) ($config['public_key'] ?? ''));
        $this->privateKey = trim((string) ($config['private_key'] ?? ''));
        $this->timeout = max(3, min(30, (int) ($config['timeout'] ?? 12)));
    }

    public function credentialsConfigured(): bool
    {
        return $this->publicKey !== '' && $this->privateKey !== '';
    }

    public function test(): array
    {
        return $this->wallets();
    }

    public function allOrderBooks(): array
    {
        return $this->request('GET', '/v3/orderbook/all', [], false);
    }

    public function orderBook(string $symbol): array
    {
        $symbol = $this->safeSymbol($symbol);
        return $this->request('GET', '/v3/orderbook/' . rawurlencode($symbol), [], false);
    }

    public function stats(array $query = []): array
    {
        return $this->request('GET', '/market/stats', $query, false);
    }

    public function ohlc(string $symbol, string $resolution = '15', int $countback = 120): array
    {
        $symbol = $this->safeSymbol($symbol);
        $allowed = ['1','5','15','30','60','180','240','360','720','D','2D','3D'];
        if (!in_array($resolution, $allowed, true)) {
            $resolution = '15';
        }
        $countback = max(20, min(500, $countback));
        return $this->request('GET', '/market/udf/history', [
            'symbol' => $symbol,
            'resolution' => $resolution,
            'to' => time(),
            'countback' => $countback,
        ], false, false);
    }

    public function wallets(array $query = []): array
    {
        $payload = $query === [] ? ['type' => 'spot'] : $query;
        return $this->request('POST', '/users/wallets/list', $payload, true);
    }

    public function orders(array $query = []): array
    {
        return $this->request('GET', '/market/orders/list', $query, true);
    }

    public function orderStatus(?string $id = null, ?string $clientOrderId = null): array
    {
        $payload = [];
        if ($id !== null && $id !== '') {
            if (!ctype_digit($id)) {
                throw new \InvalidArgumentException('Invalid Nobitex order id.');
            }
            $payload['id'] = (int) $id;
        } elseif ($clientOrderId !== null && $clientOrderId !== '') {
            $payload['clientOrderId'] = $this->safeClientOrderId($clientOrderId);
        } else {
            throw new \InvalidArgumentException('Nobitex order id or clientOrderId is required.');
        }
        return $this->request('POST', '/market/orders/status', $payload, true);
    }

    public function createOrder(array $payload): array
    {
        return $this->request('POST', '/market/orders/add', $payload, true);
    }

    public function cancelOrder(?string $id = null, ?string $clientOrderId = null): array
    {
        $payload = ['status' => 'canceled'];
        if ($id !== null && $id !== '') {
            if (!ctype_digit($id)) {
                throw new \InvalidArgumentException('Invalid Nobitex order id.');
            }
            $payload['order'] = (int) $id;
        } elseif ($clientOrderId !== null && $clientOrderId !== '') {
            $payload['clientOrderId'] = $this->safeClientOrderId($clientOrderId);
        } else {
            throw new \InvalidArgumentException('Nobitex order id or clientOrderId is required.');
        }
        return $this->request('POST', '/market/orders/update-status', $payload, true);
    }

    private function request(string $method, string $path, array $data = [], bool $authenticated = false, bool $requireStatusOk = true): array
    {
        $method = strtoupper($method);
        $path = '/' . ltrim($path, '/');
        $body = '';
        $fullPath = $path;
        if ($method === 'GET' && $data !== []) {
            $query = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
            $fullPath .= '?' . $query;
        } elseif ($method !== 'GET') {
            $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $headers = ['Accept: application/json', 'User-Agent: Trade/1.1'];
        if ($method !== 'GET') $headers[] = 'Content-Type: application/json';
        if ($authenticated) {
            foreach ($this->authHeaders($method, $fullPath, $body) as $header) $headers[] = $header;
        }

        $ch = curl_init($this->baseUrl . $fullPath);
        if ($ch === false) throw new \RuntimeException('Unable to initialize cURL for Nobitex.');
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ];
        if ($method !== 'GET') $options[CURLOPT_POSTFIELDS] = $body;
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) throw new \RuntimeException('Nobitex network error (' . $errno . '): ' . $error);

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) $decoded = ['raw' => mb_substr((string) $raw, 0, 2000)];
        if ($status < 200 || $status >= 300) throw new NobitexHttpException($status, $decoded);
        if ($requireStatusOk) {
            $apiStatus = strtolower(trim((string) ($decoded['status'] ?? $decoded['s'] ?? 'ok')));
            if (in_array($apiStatus, ['failed', 'error'], true)) throw new NobitexHttpException($status, $decoded);
        }
        return $decoded;
    }

    private function authHeaders(string $method, string $fullPath, string $body): array
    {
        if (!$this->credentialsConfigured()) throw new \RuntimeException('Nobitex API Key is not configured.');
        if (!function_exists('sodium_crypto_sign_detached')) throw new \RuntimeException('PHP Sodium extension is required for Nobitex Ed25519 API signing.');
        $timestamp = (string) time();
        $payload = $timestamp . $method . $fullPath . $body;
        $signature = sodium_crypto_sign_detached($payload, $this->signingSecretKey($this->privateKey));
        $signatureB64 = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        return [
            'Nobitex-Key: ' . $this->publicKey,
            'Nobitex-Signature: ' . $signatureB64,
            'Nobitex-Timestamp: ' . $timestamp,
        ];
    }

    private function signingSecretKey(string $encoded): string
    {
        $bytes = $this->base64UrlDecode($encoded);
        if (strlen($bytes) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($bytes));
        }
        if (strlen($bytes) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) return $bytes;
        throw new \RuntimeException('Nobitex private key must decode to a 32-byte Ed25519 seed or 64-byte secret key.');
    }

    private function base64UrlDecode(string $value): string
    {
        $value = strtr(trim($value), '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding !== 0) $value .= str_repeat('=', 4 - $padding);
        $decoded = base64_decode($value, true);
        if ($decoded === false) throw new \RuntimeException('Invalid Nobitex private key encoding.');
        return $decoded;
    }

    private function safeSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        if (!preg_match('/^[A-Z0-9]{4,30}$/', $symbol)) throw new \InvalidArgumentException('Invalid Nobitex market symbol.');
        return $symbol;
    }

    private function safeClientOrderId(string $id): string
    {
        $id = trim($id);
        if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $id)) throw new \InvalidArgumentException('Invalid Nobitex clientOrderId.');
        return $id;
    }
}

final class NobitexHttpException extends \RuntimeException
{
    public function __construct(public readonly int $statusCode, public readonly array $response)
    {
        $message = 'Nobitex HTTP ' . $statusCode;
        foreach (['message', 'detail', 'error', 'code', 'errmsg'] as $key) {
            $value = $response[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $message .= ' — ' . mb_substr(trim((string) $value), 0, 220);
                break;
            }
        }
        parent::__construct($message);
    }
}
