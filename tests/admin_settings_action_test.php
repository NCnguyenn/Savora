<?php
declare(strict_types=1);

putenv('SAVORA_DB_NAME=' . (getenv('SAVORA_DB_NAME') ?: 'savora_test'));
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/admin_security.php';
require_once __DIR__ . '/../lib/admin_actions.php';

$adminRow = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
$setting = $conn->query("SELECT setting_value, version FROM platform_settings WHERE setting_key = 'dispatch_offer_seconds'")->fetch_assoc();
if (!$adminRow || !$setting) {
    throw new RuntimeException('Required Admin seed data is missing.');
}

$actorId = (int) $adminRow['id'];
$idempotencyKey = 'settings-test-' . bin2hex(random_bytes(6));
$payload = [
    'setting_key' => 'dispatch_offer_seconds',
    'setting_value' => $setting['setting_value'],
    'version' => (int) $setting['version'],
    'reason' => 'Automated settings integrity check',
];
$first = admin_execute_action($conn, 'update_setting', $payload, $actorId, $idempotencyKey);
$retry = admin_execute_action($conn, 'update_setting', $payload, $actorId, $idempotencyKey);
if (!($first['ok'] ?? false) || $first !== $retry) {
    throw new RuntimeException('Setting action must succeed once and return the same idempotent response.');
}

$reference = (string) $first['referenceId'];
$audit = $conn->prepare('SELECT COUNT(*) AS total FROM audit_logs WHERE reference_id = ? AND action = ?');
$action = 'update_setting';
$audit->bind_param('ss', $reference, $action);
$audit->execute();
$auditTotal = (int) $audit->get_result()->fetch_assoc()['total'];
$audit->close();
if ($auditTotal !== 1) {
    throw new RuntimeException('A successful settings action must append exactly one audit record.');
}

$stale = admin_execute_action($conn, 'update_setting', $payload, $actorId, 'settings-stale-' . bin2hex(random_bytes(5)));
if (($stale['ok'] ?? true) !== false || !isset($stale['errors']['version'])) {
    throw new RuntimeException('A stale settings version must be rejected.');
}

echo "PASS: settings actions are versioned, audited and idempotent\n";
