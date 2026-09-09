<?php

declare(strict_types=1);

namespace Trade\Exchange;

final class BitpinClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $secretKey;
    private ?string $accessToken;
    private ?string $refreshToken;
    private int $timeout;
    private array $endpoints;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.bitpin.market/api/v1'), '/');
        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->secretKey = (string) ($config['secret_key'] ?? '');
        $this->accessToken = $config['access_token'] ?? null;
        $this->refreshToken = $config['refresh_token'] ?? null;
        $this->timeout = max(3, min(30, (int) ($config['timeout'] ?? 12)));
        $this->endpoints = array_merge([
            'authenticate' => '/usr/authenticate/',
            'refresh' => '/usr/refresh_token/',
            'markets' => '/mkt/markets/',
            'wallets' => '/wlt/wallets/',
            'orders' => '/odr/orders/',
        ], $config['endpoints'] ?? []);
    }

    public function authenticate(): array
    {
        if ($this->apiKey === '' || $this->secretKey === '') {
            throw new \RuntimeException('Bitpin API credentials are not configured.');
        }

        $response = $this->request('POST', $this->endpoints['authenticate'], [
            'api_key' => $this->apiKey,
            'secret_key' => $this->secretKey,
        ], false);

        $this->accessToken = $response['access'] ?? null;
        $this->refreshToken = $response['refresh'] ?? null;
        if (!$this->accessToken || !$this->refreshToken) {
            throw new \RuntimeException('Bitpin authentication response did not include access/refresh tokens.');
        }
        return $response;
    }

    public function refreshAccessToken(): array
    {
        if (!$this->refreshToken) {
            return $this->authenticate();
        }
        $response = $this->request('POST', $this->endpoints['refresh'], ['refresh' => $this->refreshToken], false);
        $this->accessToken = $response['access'] ?? null;
        if (!$this->accessToken) {
            throw new \RuntimeException('Bitpin token refresh did not return an access token.');
        }
        return $response;
    }

    public function markets(array $query = []): array
    {
        return $this->request('GET', $this->endpoints['markets'], $query, false);
    }

    public function wallets(array $query = []): array
    {
        return $this->authorizedGet($this->endpoints['wallets'], $query);
    }

    public function orders(array $query = []): array
    {
        return $this->authorizedGet($this->endpoints['orders'], $query);
    }

    public function tokens(): array
    {
        return ['access' => $this->accessToken, 'refresh' => $this->refreshToken];
    }

    private function authorizedGet(string $endpoint, array $query = []): array
    {
        if (!$this->accessToken) {
            $this->authenticate();
        }
        try {
            return $this->request('GET', $endpoint, $query, true);
        } catch (BitpinHttpException $e) {
            if ($e->statusCode !== 401) {
                throw $e;
            }
            $this->refreshAccessToken();
            return $this->request('GET', $endpoint, $query, true);
        }
    }

    private function request(string $method, string $endpoint, array $data = [], bool $auth = false): array
    {
        $method = strtoupper($method);
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        if ($method === 'GET' && $data !== []) {
            $url .= '?' . http_build_query($data);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize cURL.');
        }

        $headers = ['Accept: application/json', 'User-Agent: Trade/0.2-readonly'];
        if ($auth && $this->accessToken) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ];

        if ($method !== 'GET' && $data !== []) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('Bitpin network error: ' . $error);
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => substr($body, 0, 2000)];
        }
        if ($status < 200 || $status >= 300) {
            throw new BitpinHttpException($status, $decoded);
        }
        return $decoded;
    }
}

final class BitpinHttpException extends \RuntimeException
{
    public function __construct(public readonly int $statusCode, public readonly array $response)
    {
        parent::__construct('Bitpin HTTP ' . $statusCode);
    }
}
