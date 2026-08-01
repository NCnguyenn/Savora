<?php
declare(strict_types=1);

function admin_idempotency_response(mysqli $conn, int $actorId, string $idempotencyKey): ?array
{
    $lookup = $conn->prepare('SELECT response_json FROM idempotency_keys WHERE actor_user_id = ? AND idempotency_key = ? LIMIT 1');
    $lookup->bind_param('is', $actorId, $idempotencyKey);
    $lookup->execute();
    $existing = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$existing) {
        return null;
    }
    $decoded = json_decode((string) $existing['response_json'], true);
    return is_array($decoded) ? $decoded : ['ok' => false, 'message' => 'Stored response is invalid.'];
}

function admin_store_idempotency(mysqli $conn, int $actorId, string $key, string $action, array $response): void
{
    $json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $stmt = $conn->prepare('INSERT INTO idempotency_keys (actor_user_id, idempotency_key, action, response_json) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $actorId, $key, $action, $json);
    $stmt->execute();
    $stmt->close();
}

function admin_append_audit(mysqli $conn, int $actorId, string $action, string $entityType, ?int $entityId, mixed $before, mixed $after, string $reason, string $referenceId): void
{
    $beforeJson = $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $afterJson = $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ipAddress = mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 64);
    $sessionId = session_status() === PHP_SESSION_ACTIVE ? session_id() : '';
    $stmt = $conn->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_summary, after_summary, reason, ip_address, session_id, result, reference_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'success', ?)");
    $stmt->bind_param('ississssss', $actorId, $action, $entityType, $entityId, $beforeJson, $afterJson, $reason, $ipAddress, $sessionId, $referenceId);
    $stmt->execute();
    $stmt->close();
}

function admin_setting_value(string $key, mixed $value, array $allowedSettingKeys): string
{
    $rule = $allowedSettingKeys[$key];
    if ($rule['type'] === 'boolean') {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
    }
    if (!is_numeric($value)) {
        throw new InvalidArgumentException('Enter a numeric value.');
    }
    $number = (int) $value;
    if ($number < $rule['min'] || $number > $rule['max']) {
        throw new InvalidArgumentException("Value must be between {$rule['min']} and {$rule['max']}.");
    }
    return (string) $number;
}

function admin_update_setting(mysqli $conn, array $payload, int $actorId, string $idempotencyKey): array
{
    $allowedSettingKeys = [
        'restaurant_acceptance_minutes' => ['type' => 'integer', 'min' => 1, 'max' => 30],
        'preparation_delay_minutes' => ['type' => 'integer', 'min' => 5, 'max' => 120],
        'dispatch_offer_seconds' => ['type' => 'integer', 'min' => 10, 'max' => 120],
        'dispatch_max_attempts' => ['type' => 'integer', 'min' => 1, 'max' => 12],
        'support_critical_minutes' => ['type' => 'integer', 'min' => 5, 'max' => 240],
        'support_standard_hours' => ['type' => 'integer', 'min' => 1, 'max' => 168],
        'maintenance_mode' => ['type' => 'boolean', 'min' => 0, 'max' => 1],
    ];
    $key = (string) ($payload['setting_key'] ?? '');
    if (!isset($allowedSettingKeys[$key])) {
        return ['ok' => false, 'message' => 'This setting cannot be changed.', 'errors' => ['setting_key' => 'Unsupported setting.'], 'referenceId' => admin_reference_id()];
    }
    try {
        $value = admin_setting_value($key, $payload['setting_value'] ?? null, $allowedSettingKeys);
    } catch (InvalidArgumentException $exception) {
        return ['ok' => false, 'message' => 'Check the setting value.', 'errors' => ['setting_value' => $exception->getMessage()], 'referenceId' => admin_reference_id()];
    }
    $expectedVersion = max(1, (int) ($payload['version'] ?? 0));
    $reason = trim((string) ($payload['reason'] ?? 'Platform configuration update'));
    $referenceId = admin_reference_id();

    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT setting_value, value_type, version FROM platform_settings WHERE setting_key = ? FOR UPDATE');
        $lock->bind_param('s', $key);
        $lock->execute();
        $before = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$before) {
            throw new RuntimeException('Setting not found.');
        }
        if ((int) $before['version'] !== $expectedVersion) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'This setting changed in another session. Refresh and try again.', 'errors' => ['version' => 'Stale setting version.'], 'referenceId' => $referenceId];
        }
        $update = $conn->prepare('UPDATE platform_settings SET setting_value = ?, updated_by = ?, version = version + 1 WHERE setting_key = ? AND version = ?');
        $update->bind_param('sisi', $value, $actorId, $key, $expectedVersion);
        $update->execute();
        $update->close();
        $after = ['setting_value' => $value, 'version' => $expectedVersion + 1];
        admin_append_audit($conn, $actorId, 'update_setting', 'platform_setting', null, $before, $after, mb_substr($reason, 0, 500), $referenceId);
        $response = ['ok' => true, 'message' => 'Platform setting updated.', 'data' => ['setting_key' => $key] + $after, 'referenceId' => $referenceId];
        admin_store_idempotency($conn, $actorId, $idempotencyKey, 'update_setting', $response);
        $conn->commit();
        return $response;
    } catch (Throwable $exception) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'The setting could not be updated.', 'errors' => ['setting_value' => $exception->getMessage()], 'referenceId' => $referenceId];
    }
}

function admin_update_notification_template(mysqli $conn, array $payload, int $actorId, string $idempotencyKey): array
{
    $key = mb_substr(trim((string) ($payload['template_key'] ?? '')), 0, 100);
    $subject = mb_substr(trim((string) ($payload['subject'] ?? '')), 0, 200);
    $message = mb_substr(trim((string) ($payload['message_template'] ?? '')), 0, 2000);
    $channel = (string) ($payload['channel'] ?? 'in_app');
    $enabled = filter_var($payload['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    $expectedVersion = max(1, (int) ($payload['version'] ?? 0));
    if ($key === '' || $subject === '' || $message === '' || !in_array($channel, ['in_app', 'email', 'sms'], true)) {
        return ['ok' => false, 'message' => 'Complete all template fields.', 'errors' => ['template' => 'Subject, message and a supported channel are required.'], 'referenceId' => admin_reference_id()];
    }
    $referenceId = admin_reference_id();
    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT template_key, subject, message_template, channel, enabled, version FROM notification_templates WHERE template_key = ? FOR UPDATE');
        $lock->bind_param('s', $key);
        $lock->execute();
        $before = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$before || (int) $before['version'] !== $expectedVersion) {
            throw new RuntimeException('Template is missing or has a stale version.');
        }
        $update = $conn->prepare('UPDATE notification_templates SET subject = ?, message_template = ?, channel = ?, enabled = ?, updated_by = ?, version = version + 1 WHERE template_key = ? AND version = ?');
        $update->bind_param('sssiisi', $subject, $message, $channel, $enabled, $actorId, $key, $expectedVersion);
        $update->execute();
        $update->close();
        $after = ['subject' => $subject, 'message_template' => $message, 'channel' => $channel, 'enabled' => $enabled, 'version' => $expectedVersion + 1];
        admin_append_audit($conn, $actorId, 'update_notification_template', 'notification_template', null, $before, $after, 'Notification policy updated', $referenceId);
        $response = ['ok' => true, 'message' => 'Notification template updated.', 'data' => ['template_key' => $key] + $after, 'referenceId' => $referenceId];
        admin_store_idempotency($conn, $actorId, $idempotencyKey, 'update_notification_template', $response);
        $conn->commit();
        return $response;
    } catch (Throwable $exception) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'The template could not be updated.', 'errors' => ['template' => $exception->getMessage()], 'referenceId' => $referenceId];
    }
}

function admin_account_action(mysqli $conn, string $action, array $payload, int $actorId, string $idempotencyKey): array
{
    $targetId = max(0, (int) ($payload['user_id'] ?? 0));
    $expectedVersion = max(1, (int) ($payload['version'] ?? 0));
    $reason = mb_substr(trim((string) ($payload['reason'] ?? '')), 0, 500);
    $statusActions = ['suspend_account' => 'suspended', 'reactivate_account' => 'active', 'block_account' => 'blocked'];
    if ($targetId === 0 || (($action !== 'reset_password') && $reason === '')) {
        return ['ok' => false, 'message' => 'A target account and audit reason are required.', 'errors' => ['reason' => 'Explain why this intervention is needed.'], 'referenceId' => admin_reference_id()];
    }
    $referenceId = admin_reference_id();
    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT id, username, role, full_name, email, status, session_version, version FROM users WHERE id = ? FOR UPDATE');
        $lock->bind_param('i', $targetId);
        $lock->execute();
        $before = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$before) {
            throw new RuntimeException('Account not found.');
        }
        if ($before['role'] === 'admin') {
            throw new RuntimeException('The only full-access Admin account is protected from account interventions.');
        }
        if ((int) $before['version'] !== $expectedVersion) {
            throw new RuntimeException('Account has a stale version. Refresh before retrying.');
        }

        $after = $before;
        if (isset($statusActions[$action])) {
            $nextStatus = $statusActions[$action];
            if ($before['status'] === $nextStatus) {
                throw new RuntimeException('Account is already in that status.');
            }
            $update = $conn->prepare('UPDATE users SET status = ?, session_version = session_version + 1, version = version + 1 WHERE id = ? AND version = ?');
            $update->bind_param('sii', $nextStatus, $targetId, $expectedVersion);
            $update->execute();
            $update->close();
            $history = $conn->prepare('INSERT INTO account_status_history (user_id, previous_status, next_status, actor_user_id, reason) VALUES (?, ?, ?, ?, ?)');
            $history->bind_param('issis', $targetId, $before['status'], $nextStatus, $actorId, $reason);
            $history->execute();
            $history->close();
            $after['status'] = $nextStatus;
            $after['version'] = $expectedVersion + 1;
            $after['session_version'] = (int) $before['session_version'] + 1;
        } elseif ($action === 'revoke_sessions') {
            $revoke = $conn->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL');
            $revoke->bind_param('i', $targetId);
            $revoke->execute();
            $revokedCount = $revoke->affected_rows;
            $revoke->close();
            $update = $conn->prepare('UPDATE users SET session_version = session_version + 1, version = version + 1 WHERE id = ? AND version = ?');
            $update->bind_param('ii', $targetId, $expectedVersion);
            $update->execute();
            $update->close();
            $after['revoked_sessions'] = $revokedCount;
            $after['version'] = $expectedVersion + 1;
        } elseif ($action === 'reset_password') {
            $temporarySecret = bin2hex(random_bytes(16));
            $passwordHash = password_hash($temporarySecret, PASSWORD_DEFAULT);
            $update = $conn->prepare('UPDATE users SET password = ?, session_version = session_version + 1, version = version + 1 WHERE id = ? AND version = ?');
            $update->bind_param('sii', $passwordHash, $targetId, $expectedVersion);
            $update->execute();
            $update->close();
            $after['credential_reset'] = true;
            $after['version'] = $expectedVersion + 1;
            $reason = $reason ?: 'Administrator initiated secure credential recovery';
        } else {
            throw new RuntimeException('Unsupported account action.');
        }

        $notification = $conn->prepare('INSERT INTO notifications (user_id, event_type, title, message, entity_type, entity_id) VALUES (?, ?, ?, ?, ?, ?)');
        $title = 'Account security update';
        $message = 'An account security action was completed. Contact support if you did not expect this change.';
        $entityType = 'user';
        $notification->bind_param('issssi', $targetId, $action, $title, $message, $entityType, $targetId);
        $notification->execute();
        $notification->close();
        admin_append_audit($conn, $actorId, $action, 'user', $targetId, $before, $after, $reason, $referenceId);
        $response = ['ok' => true, 'message' => 'Account security action completed.', 'data' => ['user_id' => $targetId, 'status' => $after['status'], 'version' => $after['version']], 'referenceId' => $referenceId];
        admin_store_idempotency($conn, $actorId, $idempotencyKey, $action, $response);
        $conn->commit();
        return $response;
    } catch (Throwable $exception) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'The account action could not be completed.', 'errors' => ['reason' => $exception->getMessage()], 'referenceId' => $referenceId];
    }
}

function admin_execute_action(mysqli $conn, string $action, array $payload, int $actorId, string $idempotencyKey): array
{
    $existing = admin_idempotency_response($conn, $actorId, $idempotencyKey);
    if ($existing !== null) {
        return $existing;
    }
    if ($action === 'update_setting') {
        return admin_update_setting($conn, $payload, $actorId, $idempotencyKey);
    }
    if ($action === 'update_notification_template') {
        return admin_update_notification_template($conn, $payload, $actorId, $idempotencyKey);
    }
    if (in_array($action, ['suspend_account', 'reactivate_account', 'block_account', 'revoke_sessions', 'reset_password'], true)) {
        return admin_account_action($conn, $action, $payload, $actorId, $idempotencyKey);
    }
    return [
        'ok' => false,
        'message' => 'Unsupported Admin action.',
        'errors' => ['action' => "The action {$action} is not available."],
        'referenceId' => admin_reference_id(),
    ];
}
