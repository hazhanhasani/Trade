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
            'tickers' => '/mkt/tickers/',
            'wallets' => '/wlt/wallets/',
            'orders' => '/odr/orders/',
            'orderbook' => '/mth/orderbook/{symbol}/',
            'matches' => '/mth/matches/{symbol}/',
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

        $this->accessToken = isset($response['access']) ? (string) $response['access'] : null;
        $this->refreshToken = isset($response['refresh']) ? (string) $response['refresh'] : null;

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

        try {
            $response = $this->request(
                'POST',
                $this->endpoints['refresh'],
                ['refresh' => $this->refreshToken],
                false
            );
        } catch (BitpinHttpException $e) {
            // Refresh tokens can expire or be revoked while the API key/secret
            // is still valid. In that situation a full authentication is the
            // correct recovery path instead of leaving the client stuck at 401.
            if (in_array($e->statusCode, [400, 401, 403], true)) {
                $this->accessToken = null;
                $this->refreshToken = null;
                return $this->authenticate();
            }
            throw $e;
        }

        $this->accessToken = isset($response['access']) ? (string) $response['access'] : null;
        if (isset($response['refresh']) && (string) $response['refresh'] !== '') {
            $this->refreshToken = (string) $response['refresh'];
        }

        if (!$this->accessToken) {
            // A malformed/partial refresh response should not permanently wedge
            // the client. Fall back to a clean API-key authentication.
            $this->accessToken = null;
            $this->refreshToken = null;
            return $this->authenticate();
        }

        return $response;
    }

    public function markets(array $query = []): array
    {
        return $this->request('GET', $this->endpoints['markets'], $query, false);
    }

    public function tickers(array $query = []): array
    {
        return $this->request('GET', $this->endpoints['tickers'], $query, false);
    }

    public function orderBook(string $symbol): array
    {
        $symbol = $this->safeSymbol($symbol);
        $endpoint = str_replace('{symbol}', rawurlencode($symbol), $this->endpoints['orderbook']);
        return $this->request('GET', $endpoint, [], false);
    }

    public function recentTrades(string $symbol): array
    {
        $symbol = $this->safeSymbol($symbol);
        $endpoint = str_replace('{symbol}', rawurlencode($symbol), $this->endpoints['matches']);
        return $this->request('GET', $endpoint, [], false);
    }

    public function wallets(array $query = []): array
    {
        return $this->authorizedRequest('GET', $this->endpoints['wallets'], $query);
    }

    public function orders(array $query = []): array
    {
        return $this->authorizedRequest('GET', $this->endpoints['orders'], $query);
    }

    public function createOrder(array $payload): array
    {
        return $this->authorizedRequest('POST', $this->endpoints['orders'], $payload);
    }

    public function cancelOrder(string $orderId): array
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $orderId)) {
            throw new \InvalidArgumentException('Invalid order id.');
        }
        return $this->authorizedRequest('DELETE', $this->endpoints['orders'] . rawurlencode($orderId) . '/');
    }

    public function tokens(): array
    {
        return ['access' => $this->accessToken, 'refresh' => $this->refreshToken];
    }

    private function safeSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $symbol)) {
            throw new \InvalidArgumentException('Invalid Bitpin market symbol.');
        }
        return $symbol;
    }

    private function authorizedRequest(string $method, string $endpoint, array $data = []): array
    {
        if (!$this->accessToken) {
            $this->authenticate();
        }

        try {
            return $this->request($method, $endpoint, $data, true);
        } catch (BitpinHttpException $e) {
            if ($e->statusCode !== 401) {
                throw $e;
            }

            $this->refreshAccessToken();
            return $this->request($method, $endpoint, $data, true);
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

        $headers = ['Accept: application/json', 'User-Agent: Trade/1.0-live'];
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
        $detail = self::safeDetail($response);
        $message = 'Bitpin HTTP ' . $statusCode;

        if ($detail !== '') {
            $message .= ' — ' . $detail;
        } elseif ($statusCode === 401) {
            $message .= ' — authentication rejected; token or API credentials/IP permission may be invalid.';
        } elseif ($statusCode === 403) {
            $message .= ' — access denied; check API permissions and allowed IP.';
        }

        parent::__construct($message);
    }

    private static function safeDetail(array $response): string
    {
        foreach (['detail', 'message', 'error', 'non_field_errors'] as $key) {
            if (!array_key_exists($key, $response)) {
                continue;
            }

            $value = $response[$key];
            if (is_array($value)) {
                $value = implode(' ', array_map(
                    static fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
                    $value
                ));
            }

            if (!is_scalar($value)) {
                continue;
            }

            $text = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
            if ($text !== '') {
                return mb_substr($text, 0, 180);
            }
        }

        return '';
    }
}
