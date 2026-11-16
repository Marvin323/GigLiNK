<?php
require_once __DIR__ . '/providers.php';

final class SandboxProviderFailure extends RuntimeException {}

final class MtnSandbox implements QueuedPaymentProvider
{
    private ?string $token = null;
    private int $expires = 0;
    private const HOST = 'https://sandbox.momodeveloper.mtn.com';

    public function __construct(private string $operation, private array $config,
        private Closure $transport, private Closure $clock)
    {
        if (!in_array($operation,['collection','disbursement'],true)) throw new DomainException('Unsupported MTN product.');
        $prefix = '/' . $operation . '/';
        foreach (['user','api_key','subscription','binding_key'] as $key) {
            if (!is_string($config[$key] ?? null) || !preg_match('/\A[\x21-\x7e]{1,512}\z/',$config[$key])) {
                throw new DomainException('MTN sandbox configuration incomplete.');
            }
        }
        if (!preg_match('/\A[0-9]{8,15}\z/',$config['wallet'] ?? '') || strlen($config['binding_key'])<32
            || str_contains($config['user'],':')) throw new DomainException('MTN sandbox configuration incomplete.');
        if ($operation==='disbursement' && !preg_match('/\A[A-Za-z0-9_]{1,48}\z/',$config['active_field'] ?? '')) {
            throw new DomainException('Provide the approved beneficiary eligibility response field.');
        }
        foreach (['token_path'=>null,'status_path'=>'{reference}'] + ($operation==='disbursement' ? ['active_path'=>'{msisdn}'] : [])
            as $field=>$placeholder) {
            $path = $config[$field] ?? '';
            if (!str_starts_with($path,$prefix) || !preg_match('/\A\/[A-Za-z0-9_{}\/-]{1,160}\z/',$path)
                || str_contains($path,'//') || ($placeholder && substr_count($path,$placeholder)!==1)
                || strpbrk($placeholder ? str_replace($placeholder,'',$path) : $path,'{}') !== false) {
                throw new DomainException('Set the product paths from the approved MTN sandbox contract.');
            }
        }
    }

    public function code(): string { return 'mtn_' . $this->operation; }
    public function operation(): string { return $this->operation; }

    public function binding(): string
    {
        return hash_hmac('sha256',$this->operation . ':' . $this->config['wallet'],$this->config['binding_key']);
    }

    public function initiate(int $amount, string $currency, string $requestKey): string
    {
        throw new DomainException('MTN sandbox requires an isolated queue case.');
    }
    public function checkStatus(string $reference): ?array { return null; }

    public function verifyWebhook(string $raw, array $headers, int $now): array
    {
        throw new UnexpectedValueException('MTN callbacks are not enabled. Use authenticated status polling.');
    }

    private function accessToken(): string
    {
        $now = ($this->clock)();
        if ($this->token !== null && $now < $this->expires) return $this->token;
        $auth = base64_encode($this->config['user'] . ':' . $this->config['api_key']);
        [$status,$raw] = ($this->transport)('POST',$this->config['token_path'],[
            'Authorization: Basic ' . $auth,'Ocp-Apim-Subscription-Key: ' . $this->config['subscription'],
            'X-Target-Environment: sandbox',
        ],null);
        if ($status === 401 || $status === 403) throw new SandboxProviderFailure('authentication_failed');
        if ($status === 429 || $status >= 500) throw new SandboxProviderFailure('upstream_unavailable');
        if ($status !== 200 && $status !== 201) throw new SandboxProviderFailure('invalid_provider_response');
        $body = json_decode($raw,true,8);
        $token = $body['access_token'] ?? null;
        $ttl = filter_var($body['expires_in'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>86400]]);
        if (!is_string($token) || !preg_match('/\A[A-Za-z0-9._~+\/=-]{1,4096}\z/',$token) || $ttl === false
            || !is_string($body['token_type'] ?? null) || strcasecmp($body['token_type'],'Bearer')!==0) {
            throw new SandboxProviderFailure('invalid_provider_response');
        }
        $this->expires = $now + max(0,$ttl-15);
        return $this->token = $token;
    }

    private function send(string $method,string $path,?array $body = null,?string $reference = null): array
    {
        // Transport never follows redirects. Refresh an expired token once for a safe GET.
        for ($i=0;$i<2;++$i) {
            $headers = ['Authorization: Bearer ' . $this->accessToken(),
                'Ocp-Apim-Subscription-Key: ' . $this->config['subscription'],'X-Target-Environment: sandbox'];
            if ($reference) $headers[] = 'X-Reference-Id: ' . $reference;
            if ($body !== null) $headers[] = 'Content-Type: application/json';
            [$code,$raw] = ($this->transport)($method,$path,$headers,$body === null ? null : json_encode($body,JSON_THROW_ON_ERROR));
            if ($code===401) {
                $this->token = null;
                if ($method==='GET' && $i===0) continue;
            }
            if (in_array($code,[401,403],true)) throw new SandboxProviderFailure('authentication_failed');
            if ($code===429 || $code>=500) throw new SandboxProviderFailure('upstream_unavailable');
            return [$code,$raw];
        }
        throw new SandboxProviderFailure('authentication_failed');
    }

    private function validateCase(array $case): void
    {
        if ($case['operation']!==$this->operation || $case['currency']!=='EUR'
            || !hash_equals($this->binding(),$case['wallet_binding'])
            || (int)$case['amount_minor']<1 || (int)$case['amount_minor']>10000) {
            throw new SandboxProviderFailure('sandbox_case_mismatch');
        }
    }

    public static function reference(string $key): string
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/',$key)) throw new SandboxProviderFailure('sandbox_case_mismatch');
        $key[12]='4';
        $key[16]=dechex((hexdec($key[16]) & 3) | 8);
        return substr($key,0,8) . '-' . substr($key,8,4) . '-' . substr($key,12,4) . '-'
            . substr($key,16,4) . '-' . substr($key,20,12);
    }

    private function partyField(): string { return $this->operation==='collection' ? 'payer' : 'payee'; }

    public function dispatchPayment(array $attempt): array
    {
        $this->validateCase($attempt);
        if ($this->operation==='disbursement') {
            $path = str_replace('{msisdn}',$this->config['wallet'],$this->config['active_path']);
            [$code,$raw] = $this->send('GET',$path);
            // The active-account check establishes eligibility only, not identity or ownership.
            if ($code !== 200 || (json_decode($raw,true,8)[$this->config['active_field']] ?? null) !== true) {
                throw new SandboxProviderFailure('beneficiary_unverified');
            }
        }
        $minor = (int)$attempt['amount_minor'];
        $reference = self::reference($attempt['dispatch_key']);
        $path = $this->operation==='collection' ? '/collection/v1_0/requesttopay' : '/disbursement/v1_0/transfer';
        [$code] = $this->send('POST',$path,[
            'amount'=>intdiv($minor,100) . '.' . str_pad((string)($minor % 100),2,'0',STR_PAD_LEFT),
            'currency'=>'EUR','externalId'=>$attempt['dispatch_key'],'transferType'=>'CUSTOM_PAYMENT',
            $this->partyField()=>['partyIdType'=>'MSISDN','partyId'=>$this->config['wallet']],
            'payerMessage'=>'GigLink sandbox','payeeNote'=>'GigLink sandbox',
        ],$reference);
        if ($code===202) return ['state'=>'accepted','reference'=>$reference];
        // A non-202 response may be ambiguous, especially a duplicate provider reference.
        throw new SandboxProviderFailure('dispatch_unknown');
    }

    public function inspectPayment(array $attempt, bool $settlement): array
    {
        $this->validateCase($attempt);
        if ($settlement) return ['state'=>'unsupported'];
        $reference = self::reference($attempt['dispatch_key']);
        if ($attempt['reference'] !== null && $attempt['reference'] !== $reference) {
            throw new SandboxProviderFailure('provider_conflict');
        }
        [$code,$raw] = $this->send('GET',str_replace('{reference}',$reference,$this->config['status_path']));
        if ($code===404) return ['state'=>'unknown'];
        if ($code!==200) throw new SandboxProviderFailure('invalid_provider_response');
        $body = json_decode($raw,true,8);
        $amount = $body['amount'] ?? null;
        $party = $body[$this->partyField()] ?? [];
        $status = $body['status'] ?? null;
        if (!is_string($amount) || !preg_match('/\A(\d{1,6})(?:\.(\d{1,2}))?\z/',$amount,$parts)
            || ((int)$parts[1]*100+(int)str_pad($parts[2] ?? '',2,'0')) !== (int)$attempt['amount_minor']
            || ($body['currency'] ?? null)!=='EUR' || ($body['externalId'] ?? null)!==$attempt['dispatch_key']
            || ($party['partyIdType'] ?? null)!=='MSISDN' || ($party['partyId'] ?? null)!==$this->config['wallet']
            || !in_array($status,['PENDING','SUCCESSFUL','FAILED'],true)) {
            throw new SandboxProviderFailure('invalid_provider_response');
        }
        if ($status==='PENDING') return ['state'=>'pending','reference'=>$reference];
        $type = $status==='SUCCESSFUL' ? 'confirmed' : 'failed';
        return ['state'=>'accepted','reference'=>$reference,'events'=>[[
            'id'=>hash('sha256',$reference . ':' . $type),'reference'=>$reference,'type'=>$type,
            'amount_minor'=>(int)$attempt['amount_minor'],'currency'=>'EUR',
        ]]];
    }

    public static function https(string $method,string $path,array $headers,?string $body): array
    {
        if (!function_exists('curl_init')) throw new SandboxProviderFailure('transport_unavailable');
        $handle = curl_init(self::HOST . $path);
        $buffer = '';
        $options = [CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,
            CURLOPT_WRITEFUNCTION=>static function ($handle,string $chunk) use (&$buffer): int {
                if (strlen($buffer)+strlen($chunk)>65536) return 0;
                $buffer .= $chunk;
                return strlen($chunk);
            }];
        if ($body!==null) $options[CURLOPT_POSTFIELDS]=$body;
        curl_setopt_array($handle,$options);
        $ok=curl_exec($handle);
        $code=curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($ok===false || !$code) throw new SandboxProviderFailure('transport_unavailable');
        return [(int)$code,$buffer];
    }
}

function configured_mtn_sandbox(string $operation): MtnSandbox
{
    if (!in_array($operation,['collection','disbursement'],true)) throw new DomainException('Unsupported MTN product.');
    $prefix = 'GIGLINK_MTN_SANDBOX_' . strtoupper($operation) . '_';
    if (GIGLINK_ENV!=='dev' || getenv('GIGLINK_ENABLE_MTN_SANDBOX')!=='1' || getenv($prefix . 'ENABLE')!=='1') {
        throw new DomainException('MTN sandbox product disabled.');
    }
    $cfg = [];
    foreach (['user','api_key','subscription','wallet','binding_key','token_path','status_path','active_path','active_field'] as $key) {
        $cfg[$key] = getenv($prefix . strtoupper($key)) ?: '';
    }
    return new MtnSandbox($operation,$cfg,MtnSandbox::https(...),time(...));
}

