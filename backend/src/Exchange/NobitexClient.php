<?php

declare(strict_types=1);

namespace Trade\Exchange;

final class NobitexClient
{
    private string $baseUrl;
    private string $publicBaseUrl;
    private string $legacyPublicBaseUrl;
    private string $publicKey;
    private string $privateKey;
    private int $timeout;

    /** @var array<string,mixed>|null */
    private static ?array $optionsCache = null;
    private static int $optionsCacheAt = 0;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? 'https://apiv2.nobitex.ir'), '/');
        // Public market endpoints are available on apiv2 as well. Using the same
        // host avoids shared-hosting DNS failures seen for api.nobitex.ir.
        $this->publicBaseUrl = rtrim((string) ($config['public_base_url'] ?? 'https://apiv2.nobitex.ir'), '/');
        $this->legacyPublicBaseUrl = rtrim((string) ($config['legacy_public_base_url'] ?? 'https://api.nobitex.ir'), '/');
        $this->publicKey = trim((string) ($config['public_key'] ?? ''));
        $this->privateKey = trim((string) ($config['private_key'] ?? ''));
        $this->timeout = max(3, min(30, (int) ($config['timeout'] ?? 12)));
    }

    public function credentialsConfigured(): bool { return $this->publicKey !== '' && $this->privateKey !== ''; }
    public function test(): array { return $this->wallets(); }
    public function allOrderBooks(): array { return $this->request('GET', '/v3/orderbook/all', [], false); }
    public function orderBook(string $symbol): array { return $this->request('GET', '/v3/orderbook/' . rawurlencode($this->safeSymbol($symbol)), [], false); }
    public function stats(array $query = []): array { return $this->request('GET', '/market/stats', $query, false); }

    public function options(bool $force = false): array
    {
        if (!$force && self::$optionsCache !== null && time() - self::$optionsCacheAt <= 30) {
            return self::$optionsCache;
        }
        $response = $this->request('GET', '/v2/options', [], false);
        self::$optionsCache = $response;
        self::$optionsCacheAt = time();
        return $response;
    }

    /**
     * Normalizes a spot order against the live Nobitex amount/price steps.
     * This method is public so the order service can persist exactly the same
     * amount/price that will be sent to the exchange.
     *
     * @return array{payload:array<string,mixed>,rules:array<string,mixed>,valid:bool,reason:?string}
     */
    public function prepareOrder(array $payload): array
    {
        return NobitexOrderRules::prepare($payload, $this->options());
    }

    public function ohlc(string $symbol, string $resolution = '15', int $countback = 120): array
    {
        $symbol = $this->safeSymbol($symbol);
        $resolution = $this->safeResolution($resolution);
        $countback = max(20, min(500, $countback));
        return $this->request('GET', '/market/udf/history', ['symbol'=>$symbol,'resolution'=>$resolution,'to'=>time(),'countback'=>$countback], false, false);
    }

    /**
     * Fetches a caller-budgeted set of public OHLC histories concurrently.
     *
     * Deliberately does not retry failed batch items: Nobitex documents a hard
     * 60 OHLC requests/minute limit and automatic retries could silently consume
     * twice the reserved budget. The scanner persists histories and schedules a
     * missed market again on a later tick instead.
     */
    public function ohlcMany(array $symbols, string $resolution = '1', int $countback = 480, int $concurrency = 8): array
    {
        $resolution = $this->safeResolution($resolution);
        $countback = max(20, min(500, $countback));
        $concurrency = max(2, min(12, $concurrency));

        $safe = [];
        foreach ($symbols as $symbol) {
            if (!is_string($symbol) || trim($symbol) === '') continue;
            try {
                $normalized = $this->safeSymbol($symbol);
                $safe[$normalized] = $normalized;
            } catch (\Throwable) {}
        }
        if ($safe === []) return [];

        $result = [];
        $to = time();

        foreach (array_chunk(array_values($safe), $concurrency) as $chunk) {
            $multi = curl_multi_init();
            if ($multi === false) continue;

            $handles = [];
            foreach ($chunk as $symbol) {
                $query = http_build_query([
                    'symbol'=>$symbol,
                    'resolution'=>$resolution,
                    'to'=>$to,
                    'countback'=>$countback,
                ], '', '&', PHP_QUERY_RFC3986);

                $ch = curl_init($this->publicBaseUrl . '/market/udf/history?' . $query);
                if ($ch === false) continue;

                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER=>true,
                    CURLOPT_CONNECTTIMEOUT=>min(5, $this->timeout),
                    CURLOPT_TIMEOUT=>$this->timeout,
                    CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: TraderBot/Trade'],
                    CURLOPT_FOLLOWLOCATION=>false,
                    CURLOPT_MAXREDIRS=>0,
                    CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                    CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
                    CURLOPT_PROXY=>'',
                    CURLOPT_NOPROXY=>'*',
                ]);
                curl_multi_add_handle($multi, $ch);
                $handles[$symbol] = $ch;
            }

            do {
                $status = curl_multi_exec($multi, $running);
                if ($running > 0) curl_multi_select($multi, 0.75);
            } while ($running > 0 && $status === CURLM_OK);

            foreach ($handles as $symbol => $ch) {
                $raw = curl_multi_getcontent($ch);
                $errno = curl_errno($ch);
                $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $decoded = is_string($raw) ? json_decode($raw, true) : null;

                if ($errno === 0 && $http >= 200 && $http < 300 && is_array($decoded)) {
                    $result[$symbol] = $decoded;
                }

                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
            }
            curl_multi_close($multi);
        }

        return $result;
    }

    public function wallets(array $query = []): array
    {
        $payload = $query === [] ? ['type'=>'spot'] : $query;
        return $this->authenticatedRead(fn() => $this->request('POST', '/users/wallets/list', $payload, true));
    }

    public function orders(array $query = []): array
    {
        return $this->authenticatedRead(fn() => $this->request('GET', '/market/orders/list', $query, true));
    }

    /** Authenticated spot fills/trades. Nobitex currently documents 180-day history. */
    public function trades(array $query = []): array
    {
        return $this->authenticatedRead(fn() => $this->request('GET', '/market/trades/list', $query, true));
    }

    public function orderStatus(?string $id = null, ?string $clientOrderId = null): array
    {
        $payload=[];
        if($id!==null&&$id!==''){if(!ctype_digit($id))throw new \InvalidArgumentException('Invalid Nobitex order id.');$payload['id']=(int)$id;}
        elseif($clientOrderId!==null&&$clientOrderId!==''){$payload['clientOrderId']=$this->safeClientOrderId($clientOrderId);}
        else throw new \InvalidArgumentException('Nobitex order id or clientOrderId is required.');
        return $this->authenticatedRead(fn() => $this->request('POST','/market/orders/status',$payload,true));
    }

    public function createOrder(array $payload): array
    {
        $prepared = $this->prepareOrder($payload);
        if (!($prepared['valid'] ?? false)) {
            throw new \InvalidArgumentException('Nobitex order rules rejected payload: ' . (string)($prepared['reason'] ?? 'invalid_order'));
        }
        return $this->request('POST','/market/orders/add',(array)$prepared['payload'],true);
    }

    public function cancelOrder(?string $id = null, ?string $clientOrderId = null): array
    {
        $payload=['status'=>'canceled'];
        if($id!==null&&$id!==''){if(!ctype_digit($id))throw new \InvalidArgumentException('Invalid Nobitex order id.');$payload['order']=(int)$id;}
        elseif($clientOrderId!==null&&$clientOrderId!==''){$payload['clientOrderId']=$this->safeClientOrderId($clientOrderId);}
        else throw new \InvalidArgumentException('Nobitex order id or clientOrderId is required.');
        return $this->request('POST','/market/orders/update-status',$payload,true);
    }

    /**
     * One retry is allowed only for authenticated read operations. These calls
     * are idempotent, so a DNS/connect/timeout retry cannot duplicate a trade.
     * Order creation/cancellation deliberately bypass this helper because their
     * remote outcome can be ambiguous after a network failure.
     */
    private function authenticatedRead(callable $operation): array
    {
        try {
            return $operation();
        } catch (\RuntimeException $e) {
            if (!self::isTransientNetworkError($e->getMessage())) throw $e;
            usleep(300000);
            return $operation();
        }
    }

    public static function isTransientNetworkError(string $message): bool
    {
        return preg_match('/Nobitex network error \((?:6|7|28)\):/i', $message) === 1;
    }

    private function request(string $method,string $path,array $data=[],bool $authenticated=false,bool $requireStatusOk=true):array
    {
        $method=strtoupper($method);$path='/'.ltrim($path,'/');$body='';$fullPath=$path;
        if($method==='GET'&&$data!==[]){$fullPath.='?'.http_build_query($data,'','&',PHP_QUERY_RFC3986);}elseif($method!=='GET'){$body=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
        $headers=['Accept: application/json','User-Agent: TraderBot/Trade'];if($method!=='GET')$headers[]='Content-Type: application/json';if($authenticated)foreach($this->authHeaders($method,$fullPath,$body)as$header)$headers[]=$header;

        $bases = $authenticated
            ? [$this->baseUrl]
            : array_values(array_unique(array_filter([$this->publicBaseUrl, $this->baseUrl, $this->legacyPublicBaseUrl])));
        $networkErrors=[];

        foreach($bases as $base){
            $ch=curl_init($base.$fullPath);if($ch===false)throw new \RuntimeException('Unable to initialize cURL for Nobitex.');
            $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>min(5,$this->timeout),CURLOPT_TIMEOUT=>$this->timeout,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*'];if($method!=='GET')$options[CURLOPT_POSTFIELDS]=$body;
            curl_setopt_array($ch,$options);$raw=curl_exec($ch);$error=curl_error($ch);$errno=curl_errno($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
            if($raw===false){$networkErrors[]=(parse_url($base,PHP_URL_HOST)?:$base).': '.$errno.' '.$error;if(!$authenticated)continue;throw new \RuntimeException('Nobitex network error ('.$errno.'): '.$error);}
            $decoded=json_decode((string)$raw,true);if(!is_array($decoded))$decoded=['raw'=>mb_substr((string)$raw,0,2000)];
            if($status<200||$status>=300)throw new NobitexHttpException($status,$decoded);
            if($requireStatusOk){$apiStatus=strtolower(trim((string)($decoded['status']??$decoded['s']??'ok')));if(in_array($apiStatus,['failed','error'],true))throw new NobitexHttpException($status,$decoded);}
            return$decoded;
        }

        throw new \RuntimeException('Nobitex public network error after fallback: '.implode(' | ',$networkErrors));
    }

    private function authHeaders(string $method,string $fullPath,string $body):array
    {
        if(!$this->credentialsConfigured())throw new \RuntimeException('Nobitex API Key is not configured.');
        if(!function_exists('sodium_crypto_sign_detached'))throw new \RuntimeException('PHP Sodium extension is required for Nobitex Ed25519 API signing.');
        $timestamp=(string)time();$payload=$timestamp.$method.$fullPath.$body;$signature=sodium_crypto_sign_detached($payload,$this->signingSecretKey($this->privateKey));$signatureB64=strtr(base64_encode($signature),'+/','-_');
        return['Nobitex-Key: '.$this->publicKey,'Nobitex-Signature: '.$signatureB64,'Nobitex-Timestamp: '.$timestamp];
    }

    private function signingSecretKey(string $encoded):string
    {
        $bytes=$this->base64UrlDecode($encoded);if(strlen($bytes)===SODIUM_CRYPTO_SIGN_SEEDBYTES)return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($bytes));if(strlen($bytes)===SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)return$bytes;throw new \RuntimeException('Nobitex private key must decode to a 32-byte Ed25519 seed or 64-byte secret key.');
    }

    private function base64UrlDecode(string $value):string
    {
        $value=strtr(trim($value),'-_','+/');$padding=strlen($value)%4;if($padding!==0)$value.=str_repeat('=',4-$padding);$decoded=base64_decode($value,true);if($decoded===false)throw new \RuntimeException('Invalid Nobitex private key encoding.');return$decoded;
    }

    private function safeResolution(string $resolution): string
    {
        $allowed = ['1','5','15','30','60','180','240','360','720','D','2D','3D'];
        return in_array($resolution, $allowed, true) ? $resolution : '15';
    }

    private function safeSymbol(string $symbol):string{$symbol=strtoupper(trim($symbol));if(!preg_match('/^[A-Z0-9]{4,30}$/',$symbol))throw new \InvalidArgumentException('Invalid Nobitex market symbol.');return$symbol;}
    private function safeClientOrderId(string $id):string{$id=trim($id);if(!preg_match('/^[A-Za-z0-9._-]{1,32}$/',$id))throw new \InvalidArgumentException('Invalid Nobitex clientOrderId.');return$id;}
}

final class NobitexHttpException extends \RuntimeException
{
    public function __construct(public readonly int $statusCode,public readonly array $response)
    {
        $code=self::scalar($response['code']??null);
        $human='';
        foreach(['message','detail','error','errmsg']as$key){$value=self::scalar($response[$key]??null);if($value!==''){$human=$value;break;}}
        $message='Nobitex HTTP '.$statusCode;
        if($code!=='')$message.=' ['.mb_substr($code,0,100).']';
        if($human!=='')$message.=' — '.mb_substr($human,0,260);

        $details=self::diagnosticDetails($response);
        if($details!=='')$message.=' | '.mb_substr($details,0,420);
        parent::__construct($message);
    }

    public function apiCode(): string
    {
        return self::scalar($this->response['code']??null);
    }

    private static function diagnosticDetails(array $response): string
    {
        $parts=[];
        foreach(['reason','validation','validationErrors','errors','data']as$key){
            if(!array_key_exists($key,$response))continue;
            $value=$response[$key];
            if(is_scalar($value)){$text=trim((string)$value);if($text!=='')$parts[]=$key.'='.$text;continue;}
            if(is_array($value)){
                $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
                if(is_string($json)&&$json!=='[]'&&$json!=='{}')$parts[]=$key.'='.$json;
            }
        }
        return implode(' ',array_slice($parts,0,3));
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value)?trim((string)$value):'';
    }
}
