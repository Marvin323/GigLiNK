<?php
require_once __DIR__ . '/workflow.php';

const STAFF_ROLES = [
    'super_admin'=>'Super Admin','dispute_officer'=>'Dispute Officer','finance_officer'=>'Finance Officer',
    'auditor'=>'Auditor','support_officer'=>'Support Officer',
];

function staff_role(PDO $pdo, int $user): ?string
{
    $row = workflow_row($pdo,"SELECT s.role FROM staff_roles s JOIN users u ON u.id=s.user_id
        WHERE s.user_id=? AND s.enabled=1 AND u.role='admin' AND u.active=1 AND u.deleted_at IS NULL",[$user]);
    return $row ? $row['role'] : null;
}

function staff_can(PDO $pdo, int $actor, string $permission): bool
{
    $role = staff_role($pdo,$actor);
    if ($role === 'super_admin') return true;
    return in_array($permission, match ($role) {
        'dispute_officer' => ['disputes'],
        'finance_officer' => ['finance.read','finance.write','sandbox.collection'],
        'auditor' => ['audit','finance.read'],
        'support_officer' => ['support'],
        default => [],
    },true);
}

function require_staff(PDO $pdo, ?string $permission = null): void
{
    require_login();
    if ($permission === null ? !staff_role($pdo,current_user_id()) : !staff_can($pdo,current_user_id(),$permission)) {
        http_response_code(403);
        exit('Staff permission required.');
    }
}

function change_staff(PDO $pdo, int $actor, int $target, ?string $role, bool $enabled): void
{
    if ($role !== null && !isset(STAFF_ROLES[$role])) throw new DomainException('Choose a supported staff role.');
    workflow_transaction($pdo, function () use ($pdo,$actor,$target,$role,$enabled) {
        workflow_row($pdo,'SELECT id FROM staff_governance WHERE id=1 FOR UPDATE',[]);
        if (!staff_can($pdo,$actor,'staff.manage')) throw new DomainException('Super Admin permission required.');
        $user = workflow_row($pdo,'SELECT id,role,active,deleted_at FROM users WHERE id=? FOR UPDATE',[$target]);
        $old = workflow_row($pdo,'SELECT role,enabled FROM staff_roles WHERE user_id=? FOR UPDATE',[$target]);
        if (!$user || $user['deleted_at'] || (!$old && ($role === null || !$user['active']))) {
            throw new DomainException('Select an active account for a new staff assignment.');
        }
        $newRole = $role ?? ($old['role'] ?? null);
        $active = $role !== null && $enabled;
        if ($old && $old['role'] === 'super_admin' && $old['enabled'] && ($newRole !== 'super_admin' || !$active)) {
            $other = workflow_row($pdo,"SELECT s.user_id FROM staff_roles s JOIN users u ON u.id=s.user_id
                WHERE s.user_id<>? AND s.role='super_admin' AND s.enabled=1 AND u.role='admin'
                    AND u.active=1 AND u.deleted_at IS NULL LIMIT 1",[$target]);
            if (!$other) throw new DomainException('At least one active Super Admin must remain.');
        }
        if ($old && $old['role'] === $newRole && (bool)$old['enabled'] === $active && (bool)$user['active'] === $active) return;
        $pdo->prepare("INSERT INTO staff_roles (user_id,role,enabled) VALUES (?,?,?) ON DUPLICATE KEY UPDATE
            role=VALUES(role),enabled=VALUES(enabled)")->execute([$target,$newRole,(int)$active]);
        $pdo->prepare("UPDATE users SET role='admin',active=? WHERE id=?")->execute([(int)$active,$target]);
        $pdo->prepare('UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$target]);
        $pdo->prepare('UPDATE api_tokens SET revoked=1 WHERE user_id=?')->execute([$target]);
        audit($pdo,'staff.permission','users',$target,['from'=>$old ?: null,
            'to'=>['role'=>$newRole,'enabled'=>$active]],$actor);
    });
}

function can_manage_dispute(PDO $pdo, int $actor, array $case): bool
{
    return staff_can($pdo,$actor,'staff.manage')
        || ((int)$case['assigned_to'] === $actor && staff_can($pdo,$actor,'disputes'));
}

