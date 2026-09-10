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
    public function options(): array { return $this->request('GET', '/v2/options', [], false); }

    public function ohlc(string $symbol, string $resolution = '15', int $countback = 120): array
    {
        $symbol = $this->safeSymbol($symbol);
        $resolution = $this->safeResolution($resolution);
        $countback = max(20, min(500, $countback));
        return $this->request('GET', '/market/udf/history', ['symbol'=>$symbol,'resolution'=>$resolution,'to'=>time(),'countback'=>$countback], false, false);
    }

    /**
     * Fetches public OHLC histories concurrently. This lets the trading engine
     * inspect the complete executable market universe instead of arbitrarily
     * choosing a top-N shortlist before profitability analysis.
     *
     * Failed batch items are retried through the normal public-host fallback.
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
        $failed = [];
        $to = time();

        foreach (array_chunk(array_values($safe), $concurrency) as $chunk) {
            $multi = curl_multi_init();
            if ($multi === false) {
                foreach ($chunk as $symbol) $failed[$symbol] = true;
                continue;
            }

            $handles = [];
            foreach ($chunk as $symbol) {
                $query = http_build_query([
                    'symbol'=>$symbol,
                    'resolution'=>$resolution,
                    'to'=>$to,
                    'countback'=>$countback,
                ], '', '&', PHP_QUERY_RFC3986);

                $ch = curl_init($this->publicBaseUrl . '/market/udf/history?' . $query);
                if ($ch === false) {
                    $failed[$symbol] = true;
                    continue;
                }

                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER=>true,
                    CURLOPT_CONNECTTIMEOUT=>min(5, $this->timeout),
                    CURLOPT_TIMEOUT=>$this->timeout,
                    CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: Trade/1.2.3'],
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
                } else {
                    $failed[$symbol] = true;
                }

                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
            }
            curl_multi_close($multi);
        }

        // Public fallback hosts are used only for batch misses; one bad/illiquid
        // market must not abort analysis of the rest of the universe.
        foreach (array_keys($failed) as $symbol) {
            if (isset($result[$symbol])) continue;
            try {
                $result[$symbol] = $this->ohlc($symbol, $resolution, $countback);
            } catch (\Throwable) {
                $result[$symbol] = [];
            }
        }

        return $result;
    }

    public function wallets(array $query = []): array
    {
        return $this->request('POST', '/users/wallets/list', $query === [] ? ['type'=>'spot'] : $query, true);
    }

    public function orders(array $query = []): array { return $this->request('GET', '/market/orders/list', $query, true); }

    public function orderStatus(?string $id = null, ?string $clientOrderId = null): array
    {
        $payload=[];
        if($id!==null&&$id!==''){if(!ctype_digit($id))throw new \InvalidArgumentException('Invalid Nobitex order id.');$payload['id']=(int)$id;}
        elseif($clientOrderId!==null&&$clientOrderId!==''){$payload['clientOrderId']=$this->safeClientOrderId($clientOrderId);}
        else throw new \InvalidArgumentException('Nobitex order id or clientOrderId is required.');
        return $this->request('POST','/market/orders/status',$payload,true);
    }

    public function createOrder(array $payload): array { return $this->request('POST','/market/orders/add',$payload,true); }

    public function cancelOrder(?string $id = null, ?string $clientOrderId = null): array
    {
        $payload=['status'=>'canceled'];
        if($id!==null&&$id!==''){if(!ctype_digit($id))throw new \InvalidArgumentException('Invalid Nobitex order id.');$payload['order']=(int)$id;}
        elseif($clientOrderId!==null&&$clientOrderId!==''){$payload['clientOrderId']=$this->safeClientOrderId($clientOrderId);}
        else throw new \InvalidArgumentException('Nobitex order id or clientOrderId is required.');
        return $this->request('POST','/market/orders/update-status',$payload,true);
    }

    private function request(string $method,string $path,array $data=[],bool $authenticated=false,bool $requireStatusOk=true):array
    {
        $method=strtoupper($method);$path='/'.ltrim($path,'/');$body='';$fullPath=$path;
        if($method==='GET'&&$data!==[]){$fullPath.='?'.http_build_query($data,'','&',PHP_QUERY_RFC3986);}elseif($method!=='GET'){$body=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
        $headers=['Accept: application/json','User-Agent: Trade/1.2.3'];if($method!=='GET')$headers[]='Content-Type: application/json';if($authenticated)foreach($this->authHeaders($method,$fullPath,$body)as$header)$headers[]=$header;

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
        $message='Nobitex HTTP '.$statusCode;foreach(['message','detail','error','code','errmsg']as$key){$value=$response[$key]??null;if(is_scalar($value)&&trim((string)$value)!==''){$message.=' — '.mb_substr(trim((string)$value),0,220);break;}}parent::__construct($message);
    }
}
