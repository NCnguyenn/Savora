<?php
declare(strict_types=1);

require_once __DIR__ . '/../idempotency.php';
require_once __DIR__ . '/audit_service.php';
require_once __DIR__ . '/notification_service.php';
require_once __DIR__ . '/../admin_security.php';

function admin_account_action(mysqli $conn, string $action, array $payload, int $actorId, string $idempotencyKey): array
{
    $targetId = max(0, (int) ($payload['user_id'] ?? 0));
    $expectedVersion = max(1, (int) ($payload['version'] ?? 0));
    $reason = mb_substr(trim((string) ($payload['reason'] ?? '')), 0, 500);
    $statusActions = ['suspend_account' => 'suspended', 'reactivate_account' => 'active', 'block_account' => 'blocked'];
    $referenceId = admin_reference_id();
    if ($targetId === 0 || (($action !== 'reset_password') && $reason === '')) {
        return ['ok' => false, 'message' => 'A target account and audit reason are required.', 'errors' => ['reason' => 'Explain why this intervention is needed.'], 'referenceId' => $referenceId];
    }
    $hash = savora_idempotency_hash($action, $payload);
    $conn->begin_transaction();
    try {
        $stored = savora_idempotency_find($conn, $actorId, $idempotencyKey, $action, $hash);
        if ($stored !== null) { $conn->commit(); return $stored; }
        $lock = $conn->prepare('SELECT id, username, role, full_name, email, status, session_version, version FROM users WHERE id=? FOR UPDATE');
        $lock->bind_param('i', $targetId); $lock->execute(); $before = $lock->get_result()->fetch_assoc(); $lock->close();
        if (!$before) throw new RuntimeException('Account not found.');
        if ($before['role'] === 'admin') throw new RuntimeException('The only full-access Admin account is protected from account interventions.');
        if ((int) $before['version'] !== $expectedVersion) throw new RuntimeException('Account has a stale version. Refresh before retrying.');
        $after = $before;
        if (isset($statusActions[$action])) {
            $next = $statusActions[$action];
            if ($before['status'] === $next) throw new RuntimeException('Account is already in that status.');
            $update = $conn->prepare('UPDATE users SET status=?, session_version=session_version+1, version=version+1 WHERE id=? AND version=?');
            $update->bind_param('sii', $next, $targetId, $expectedVersion); $update->execute();
            if ($update->affected_rows !== 1) throw new RuntimeException('Account changed during update.');
            $update->close();
            $history = $conn->prepare('INSERT INTO account_status_history(user_id,previous_status,next_status,actor_user_id,reason) VALUES(?,?,?,?,?)');
            $history->bind_param('issis', $targetId, $before['status'], $next, $actorId, $reason); $history->execute(); $history->close();
            $after['status'] = $next; $after['version'] = $expectedVersion + 1; $after['session_version'] = (int) $before['session_version'] + 1;
        } elseif ($action === 'revoke_sessions') {
            $revoke = $conn->prepare('UPDATE user_sessions SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL');
            $revoke->bind_param('i', $targetId); $revoke->execute(); $after['revoked_sessions'] = $revoke->affected_rows; $revoke->close();
            $update = $conn->prepare('UPDATE users SET session_version=session_version+1, version=version+1 WHERE id=? AND version=?');
            $update->bind_param('ii', $targetId, $expectedVersion); $update->execute();
            if ($update->affected_rows !== 1) throw new RuntimeException('Account changed during session revocation.');
            $update->close(); $after['version'] = $expectedVersion + 1;
        } elseif ($action === 'reset_password') {
            $temporarySecret = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $temporarySecret);
            $close = $conn->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL');
            $close->bind_param('i', $targetId); $close->execute(); $close->close();
            $token = $conn->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at,created_by) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE),?)');
            $token->bind_param('isi', $targetId, $tokenHash, $actorId); $token->execute(); $token->close();
            $update = $conn->prepare('UPDATE users SET session_version=session_version+1, version=version+1 WHERE id=? AND version=?');
            $update->bind_param('ii', $targetId, $expectedVersion); $update->execute();
            if ($update->affected_rows !== 1) throw new RuntimeException('Account changed during password reset.');
            $update->close();
            $revoke = $conn->prepare('UPDATE user_sessions SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL');
            $revoke->bind_param('i', $targetId); $revoke->execute(); $revoke->close();
            $after['credential_reset'] = true; $after['version'] = $expectedVersion + 1;
            $reason = $reason ?: 'Administrator initiated secure credential recovery';
            $recoveryUrl = 'reset_password.php?token=' . $temporarySecret;
            notification_queue($conn, $targetId, $action, 'Account security update', 'Use this secure password recovery link within 30 minutes: ' . $recoveryUrl, 'user', $targetId);
            audit_append($conn, $actorId, $action, 'user', $targetId, $before, $after, $reason, $referenceId);
            $response = ['ok' => true, 'message' => 'Account security action completed.', 'data' => ['user_id' => $targetId, 'status' => $after['status'], 'version' => $after['version']], 'referenceId' => $referenceId];
            savora_idempotency_store($conn, $actorId, $idempotencyKey, $action, $hash, $response);
            $conn->commit(); return $response;
        } else {
            throw new RuntimeException('Unsupported account action.');
        }
        notification_queue($conn, $targetId, $action, 'Account security update', 'An account security action was completed. Contact support if you did not expect this change.', 'user', $targetId);
        audit_append($conn, $actorId, $action, 'user', $targetId, $before, $after, $reason, $referenceId);
        $response = ['ok' => true, 'message' => 'Account security action completed.', 'data' => ['user_id' => $targetId, 'status' => $after['status'], 'version' => $after['version']], 'referenceId' => $referenceId];
        savora_idempotency_store($conn, $actorId, $idempotencyKey, $action, $hash, $response);
        $conn->commit(); return $response;
    } catch (Throwable $exception) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'The account action could not be completed.', 'errors' => ['reason' => $exception->getMessage()], 'referenceId' => $referenceId];
    }
}
