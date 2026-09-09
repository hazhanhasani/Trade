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
    private ?string $sourceIp;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.bitpin.market/api/v1'), '/');
        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->secretKey = (string) ($config['secret_key'] ?? '');
        $this->accessToken = $config['access_token'] ?? null;
        $this->refreshToken = $config['refresh_token'] ?? null;
        $this->timeout = max(3, min(30, (int) ($config['timeout'] ?? 12)));
        $sourceIp = trim((string) ($config['source_ip'] ?? ''));
        $this->sourceIp = filter_var($sourceIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            ? $sourceIp
            : null;
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

    public function sourceIp(): ?string
    {
        return $this->sourceIp;
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

        $result = $this->curlRequest($method, $url, $data, $auth, $this->sourceIp);

        // Some shared hosts expose a public address in their panel but do not
        // actually assign that address to the PHP namespace. In that case libcurl
        // returns CURLE_INTERFACE_FAILED (45). Falling back keeps public market
        // data and diagnostics working instead of breaking every request.
        if ($result['body'] === false && $this->sourceIp !== null && (int) $result['errno'] === CURLE_INTERFACE_FAILED) {
            $result = $this->curlRequest($method, $url, $data, $auth, null);
        }

        if ($result['body'] === false) {
            $suffix = $this->sourceIp !== null ? ' (source IP ' . $this->sourceIp . ')' : '';
            throw new \RuntimeException('Bitpin network error' . $suffix . ': ' . $result['error']);
        }

        $body = (string) $result['body'];
        $status = (int) $result['status'];
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => substr($body, 0, 2000)];
        }

        if ($status < 200 || $status >= 300) {
            throw new BitpinHttpException($status, $decoded, $this->sourceIp);
        }

        return $decoded;
    }

    private function curlRequest(string $method, string $url, array $data, bool $auth, ?string $sourceIp): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['body' => false, 'error' => 'Unable to initialize cURL.', 'errno' => CURLE_FAILED_INIT, 'status' => 0];
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
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ];

        if ($sourceIp !== null) {
            $options[CURLOPT_INTERFACE] = $sourceIp;
        }

        if ($method !== 'GET' && $data !== []) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['body' => $body, 'error' => $error, 'errno' => $errno, 'status' => $status];
    }
}

final class BitpinHttpException extends \RuntimeException
{
    public function __construct(public readonly int $statusCode, public readonly array $response, public readonly ?string $sourceIp = null)
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

        if ($sourceIp !== null) {
            $message .= ' [bound source: ' . $sourceIp . ']';
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
