<?php

interface PaymentProvider
{
    public function code(): string;
    public function initiate(int $amount, string $currency, string $requestKey): string;
    public function checkStatus(string $reference): ?array;
    public function verifyWebhook(string $raw, array $headers, int $now): array;
}

interface QueuedPaymentProvider extends PaymentProvider
{
    // This call may have moved funds even if the response was lost.
    public function dispatchPayment(array $attempt): array;

    // Implementations must authenticate the response and verify the stable request reference.
    public function inspectPayment(array $attempt, bool $settlement): array;
}

final class MockPaymentProvider implements QueuedPaymentProvider
{
    public function __construct(private string $secret)
    {
        if (strlen($secret) < 32) throw new DomainException('Mock provider is unavailable.');
    }

    public function code(): string { return 'mock'; }

    public function initiate(int $amount, string $currency, string $requestKey): string
    {
        return 'mock_' . bin2hex(random_bytes(16));
    }

    public function checkStatus(string $reference): ?array
    {
        // The local mock has no outbound service. Its verified events drive status.
        return null;
    }

    public function dispatchPayment(array $attempt): array
    {
        return ['state'=>'accepted','reference'=>'mock_' . $attempt['dispatch_key']];
    }

    public function inspectPayment(array $attempt, bool $settlement): array
    {
        // The local adapter has no remote lookup service. Signed events drive payment evidence.
        return ['state'=>'unknown'];
    }

    public function verifyWebhook(string $raw, array $headers, int $now): array
    {
        $time = $headers['timestamp'] ?? '';
        $signature = $headers['signature'] ?? '';
        if (!preg_match('/\A[0-9]{1,10}\z/',$time) || abs($now-(int)$time)>300
            || !preg_match('/\A[a-f0-9]{64}\z/',$signature)
            || !hash_equals(hash_hmac('sha256',$time . '.' . $raw,$this->secret),$signature)) {
            throw new UnexpectedValueException('Invalid webhook authentication.');
        }
        $event = json_decode($raw,true,8);
        if (!is_array($event) || !is_string($event['id'] ?? null) || !is_string($event['reference'] ?? null)
            || !preg_match('/\A[A-Za-z0-9_-]{1,80}\z/',$event['id'])
            || !preg_match('/\Amock_[a-f0-9]{32}\z/',$event['reference'])
            || !is_int($event['amount_minor'] ?? null) || $event['amount_minor'] < 1 || $event['amount_minor'] > 1000000000000
            || !in_array($event['currency'] ?? null,['UGX','USD'],true)
            || !in_array($event['type'] ?? null,[
                'confirmed','received','settled','refund_initiated','refunded','reversed','failed','cancelled'
            ],true)) {
            throw new DomainException('Malformed provider event.');
        }
        return array_intersect_key($event,array_flip(['id','reference','type','amount_minor','currency']));
    }
}

function mock_provider(): MockPaymentProvider
{
    if (GIGLINK_ENV !== 'dev' || getenv('GIGLINK_ENABLE_MOCK_PAYMENTS') !== '1') {
        throw new DomainException('Mock payments are disabled.');
    }
    return new MockPaymentProvider(getenv('GIGLINK_MOCK_WEBHOOK_SECRET') ?: '');
}


function queued_payment_provider(string $provider): QueuedPaymentProvider
{
    if ($provider === 'mock') return mock_provider();
    if (in_array($provider,['mtn_collection','mtn_disbursement'],true)) {
        require_once __DIR__ . '/mtn_sandbox.php';
        static $sandbox = [];
        return $sandbox[$provider] ??= configured_mtn_sandbox(substr($provider,4));
    }
    // Airtel and all production adapters remain unavailable.
    throw new DomainException('Provider adapter unavailable.');
}
