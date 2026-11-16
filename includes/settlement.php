<?php
require_once __DIR__ . '/staff.php';
require_once __DIR__ . '/providers.php';

const SETTLEMENT_NEXT = [
    'requested'=>['confirmed','failed','cancelled'],
    'confirmed'=>['received','failed','cancelled'],
    'received'=>['settled','reversed'],
    'settled'=>['refund_initiated','reversed'],
    'refund_initiated'=>['refunded','reversed'],
];

function provider_amount(int $minor, string $currency): string
{
    if ($currency === 'UGX') return 'UGX ' . (string)$minor;
    return $currency . ' ' . intdiv($minor,100) . '.' . str_pad((string)($minor % 100),2,'0',STR_PAD_LEFT);
}

function provider_transition_reachable(string $from, string $target, array $edges): bool
{
    $remaining = $edges[$from] ?? [];
    while ($remaining) {
        $next = array_pop($remaining);
        if ($next === $target) return true;
        array_push($remaining,...($edges[$next] ?? []));
    }
    return false;
}

function apply_provider_events(PDO $pdo, array $transaction): void
{
    $provider = $transaction['provider'];
    $status = $transaction['status'];
    $edges = SETTLEMENT_NEXT;
    // MTN's authenticated SUCCESSFUL result is terminal for this product adapter.
    // Settlement and reversal would require a separately verified reporting channel.
    if (in_array($provider,['mtn_collection','mtn_disbursement'],true)) $edges['confirmed'] = [];
    $id = (int)$transaction['id'];
    $reference = $transaction['reference'];
    $pdo->prepare("UPDATE provider_events SET status='failed',outcome='Amount or currency mismatch'
        WHERE provider=? AND reference=? AND status='pending' AND (amount_minor<>? OR currency<>?)")
        ->execute([$provider,$reference,$transaction['amount_minor'],$transaction['currency']]);
    // One representative per milestone keeps worker memory bounded even with many duplicate deliveries.
    $head = $pdo->prepare("SELECT MIN(id) AS id,event_type FROM provider_events
        WHERE provider=? AND reference=? AND status='pending' GROUP BY event_type ORDER BY id");
    $markGroup = $pdo->prepare("UPDATE provider_events SET status=?,outcome=?
        WHERE provider=? AND reference=? AND event_type=? AND status='pending'");
    do {
        $advanced = false;
        $head->execute([$provider,$reference]);
        foreach ($head->fetchAll() as $event) {
            $type = $event['event_type'];
            if (in_array($type,$edges[$status] ?? [],true)) {
                $pdo->prepare('INSERT INTO settlement_ledger (transaction_id,event_id,event_type,amount_minor,currency)
                    VALUES (?,?,?,?,?)')->execute([$id,$event['id'],$type,$transaction['amount_minor'],$transaction['currency']]);
                $pdo->prepare('UPDATE provider_transactions SET status=? WHERE id=?')->execute([$type,$id]);
                $pdo->prepare("UPDATE provider_events SET status='applied',outcome=NULL WHERE id=?")->execute([$event['id']]);
                $markGroup->execute(['ignored','Milestone already applied',$provider,$reference,$type]);
                $pdo->prepare("INSERT INTO audit_log (action,entity,entity_id,meta)
                    VALUES ('provider.event','provider_transaction',?,?)")
                    ->execute([$id,json_encode(['from'=>$status,'to'=>$type,'event_id'=>(int)$event['id']])]);
                $status = $type;
                $advanced = true;
            } elseif (!provider_transition_reachable($status,$type,$edges)) {
                $recorded = workflow_row($pdo,'SELECT id FROM settlement_ledger WHERE transaction_id=? AND event_type=?',[$id,$type]);
                $markGroup->execute([$recorded?'ignored':'failed',$recorded?'Milestone already applied':'Incompatible with transaction state',
                    $provider,$reference,$type]);
            }
        }
    } while ($advanced);
}

function store_provider_event(PDO $pdo, string $provider, array $input): array
{
    $pdo->prepare('INSERT INTO provider_events (provider,event_key,reference,event_type,amount_minor,currency)
        VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id')
        ->execute([$provider,$input['id'],$input['reference'],$input['type'],$input['amount_minor'],$input['currency']]);
    $event = workflow_row($pdo,'SELECT * FROM provider_events WHERE provider=? AND event_key=? FOR UPDATE',[$provider,$input['id']]);
    if ($event['reference'] !== $input['reference'] || $event['event_type'] !== $input['type']
        || (int)$event['amount_minor'] !== $input['amount_minor'] || $event['currency'] !== $input['currency']) {
        throw new DomainException('Conflicting provider event ID.');
    }
    return $event;
}

// Input comes only from a verified provider adapter.
function process_provider_event(PDO $pdo, string $provider, array $input): string
{
    return workflow_transaction($pdo,function () use ($pdo,$provider,$input) {
        $txn = workflow_row($pdo,'SELECT * FROM provider_transactions WHERE provider=? AND reference=? FOR UPDATE',
            [$provider,$input['reference']]);
        $event = store_provider_event($pdo,$provider,$input);
        if ($event['status'] !== 'pending') return $event['status'];
        if (!$txn) {
            $pdo->prepare("UPDATE provider_events SET status='failed',outcome='Unknown reference' WHERE id=?")
                ->execute([$event['id']]);
            return 'failed';
        }
        apply_provider_events($pdo,$txn);
        return workflow_row($pdo,'SELECT status FROM provider_events WHERE id=? FOR UPDATE',[$event['id']])['status'];
    });
}

function reconcile_provider_status(PDO $pdo, PaymentProvider $provider, int $actor, int $id): ?string
{
    if (!staff_can($pdo,$actor,'finance.write')) throw new DomainException('Finance permission required.');
    $txn = workflow_row($pdo,'SELECT reference FROM provider_transactions WHERE id=? AND provider=?',[$id,$provider->code()]);
    if (!$txn) throw new DomainException('Provider transaction not found.');
    $event = $provider->checkStatus($txn['reference']);
    if ($event === null) return null;
    if ($event['reference'] !== $txn['reference']) throw new DomainException('Provider returned a different transaction.');
    return process_provider_event($pdo,$provider->code(),$event);
}

function webhook_rate_allowed(PDO $pdo, string $source, string $secret): bool
{
    $key = hash_hmac('sha256',$source,$secret);
    $now = time();
    return workflow_transaction($pdo,function () use ($pdo,$key,$now) {
        $pdo->prepare('INSERT INTO webhook_limits (source_hash,next_reset,hits) VALUES (?,?,1)
            ON DUPLICATE KEY UPDATE hits=IF(next_reset<=?,1,hits+1),next_reset=IF(next_reset<=?,VALUES(next_reset),next_reset)')
            ->execute([$key,$now+60,$now,$now]);
        $allowed = (int)workflow_row($pdo,'SELECT hits FROM webhook_limits WHERE source_hash=?',[$key])['hits'] <= 120;
        return $allowed;
    });
}

