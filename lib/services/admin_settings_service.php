<?php
declare(strict_types=1);

require_once __DIR__ . '/../idempotency.php';
require_once __DIR__ . '/audit_service.php';
require_once __DIR__ . '/../admin_security.php';

function admin_expected_version(array $payload): int
{
    $version = (int) ($payload['version'] ?? 0);
    if ($version < 1) {
        throw new RuntimeException('A record version is required. Refresh before retrying.');
    }
    return $version;
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
    $allowed = [
        'restaurant_acceptance_minutes' => ['type' => 'integer', 'min' => 1, 'max' => 30],
        'preparation_delay_minutes' => ['type' => 'integer', 'min' => 5, 'max' => 120],
        'dispatch_offer_seconds' => ['type' => 'integer', 'min' => 10, 'max' => 120],
        'dispatch_max_attempts' => ['type' => 'integer', 'min' => 1, 'max' => 12],
        'support_critical_minutes' => ['type' => 'integer', 'min' => 5, 'max' => 240],
        'support_standard_hours' => ['type' => 'integer', 'min' => 1, 'max' => 168],
        'maintenance_mode' => ['type' => 'boolean', 'min' => 0, 'max' => 1],
    ];
    $key = (string) ($payload['setting_key'] ?? '');
    $referenceId = admin_reference_id();
    if (!isset($allowed[$key])) {
        return ['ok' => false, 'message' => 'This setting cannot be changed.', 'errors' => ['setting_key' => 'Unsupported setting.'], 'referenceId' => $referenceId];
    }
    try {
        $value = admin_setting_value($key, $payload['setting_value'] ?? null, $allowed);
    } catch (InvalidArgumentException $exception) {
        return ['ok' => false, 'message' => 'Check the setting value.', 'errors' => ['setting_value' => $exception->getMessage()], 'referenceId' => $referenceId];
    }
    $expectedVersion = max(1, (int) ($payload['version'] ?? 0));
    $reason = mb_substr(trim((string) ($payload['reason'] ?? 'Platform configuration update')), 0, 500);
    $hash = savora_idempotency_hash('update_setting', $payload);
    $conn->begin_transaction();
    try {
        $stored = savora_idempotency_find($conn, $actorId, $idempotencyKey, 'update_setting', $hash);
        if ($stored !== null) { $conn->commit(); return $stored; }
        $lock = $conn->prepare('SELECT setting_value, value_type, version FROM platform_settings WHERE setting_key=? FOR UPDATE');
        $lock->bind_param('s', $key); $lock->execute(); $before = $lock->get_result()->fetch_assoc(); $lock->close();
        if (!$before) throw new RuntimeException('Setting not found.');
        if ((int) $before['version'] !== $expectedVersion) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'This setting changed in another session. Refresh and try again.', 'errors' => ['version' => 'Stale setting version.'], 'referenceId' => $referenceId];
        }
        $update = $conn->prepare('UPDATE platform_settings SET setting_value=?, updated_by=?, version=version+1 WHERE setting_key=? AND version=?');
        $update->bind_param('sisi', $value, $actorId, $key, $expectedVersion); $update->execute();
        if ($update->affected_rows !== 1) throw new RuntimeException('Setting changed during update.');
        $update->close();
        $after = ['setting_value' => $value, 'version' => $expectedVersion + 1];
        audit_append($conn, $actorId, 'update_setting', 'platform_setting', null, $before, $after, $reason, $referenceId);
        $response = ['ok' => true, 'message' => 'Platform setting updated.', 'data' => ['setting_key' => $key] + $after, 'referenceId' => $referenceId];
        savora_idempotency_store($conn, $actorId, $idempotencyKey, 'update_setting', $hash, $response);
        $conn->commit(); return $response;
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
    $referenceId = admin_reference_id();
    if ($key === '' || $subject === '' || $message === '' || !in_array($channel, ['in_app', 'email', 'sms'], true)) {
        return ['ok' => false, 'message' => 'Complete all template fields.', 'errors' => ['template' => 'Subject, message and a supported channel are required.'], 'referenceId' => $referenceId];
    }
    $hash = savora_idempotency_hash('update_notification_template', $payload);
    $conn->begin_transaction();
    try {
        $stored = savora_idempotency_find($conn, $actorId, $idempotencyKey, 'update_notification_template', $hash);
        if ($stored !== null) { $conn->commit(); return $stored; }
        $lock = $conn->prepare('SELECT template_key, subject, message_template, channel, enabled, version FROM notification_templates WHERE template_key=? FOR UPDATE');
        $lock->bind_param('s', $key); $lock->execute(); $before = $lock->get_result()->fetch_assoc(); $lock->close();
        if (!$before || (int) $before['version'] !== $expectedVersion) throw new RuntimeException('Template is missing or has a stale version.');
        $update = $conn->prepare('UPDATE notification_templates SET subject=?, message_template=?, channel=?, enabled=?, updated_by=?, version=version+1 WHERE template_key=? AND version=?');
        $update->bind_param('sssiisi', $subject, $message, $channel, $enabled, $actorId, $key, $expectedVersion); $update->execute();
        if ($update->affected_rows !== 1) throw new RuntimeException('Template changed during update.');
        $update->close();
        $after = ['subject' => $subject, 'message_template' => $message, 'channel' => $channel, 'enabled' => $enabled, 'version' => $expectedVersion + 1];
        audit_append($conn, $actorId, 'update_notification_template', 'notification_template', null, $before, $after, 'Notification policy updated', $referenceId);
        $response = ['ok' => true, 'message' => 'Notification template updated.', 'data' => ['template_key' => $key] + $after, 'referenceId' => $referenceId];
        savora_idempotency_store($conn, $actorId, $idempotencyKey, 'update_notification_template', $hash, $response);
        $conn->commit(); return $response;
    } catch (Throwable $exception) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'The template could not be updated.', 'errors' => ['template' => $exception->getMessage()], 'referenceId' => $referenceId];
    }
}
