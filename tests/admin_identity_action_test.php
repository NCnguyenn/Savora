<?php
declare(strict_types=1);
putenv('SAVORA_DB_NAME=' . (getenv('SAVORA_DB_NAME') ?: 'savora_test'));
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/admin_security.php';
require_once __DIR__ . '/../lib/admin_actions.php';

$admin = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
$target = $conn->query("SELECT id, status, version FROM users WHERE username = 'driver-nearby-3' LIMIT 1")->fetch_assoc();
if (!$admin || !$target) throw new RuntimeException('Identity test seed is missing.');
$actorId = (int) $admin['id'];
$targetId = (int) $target['id'];

if ($target['status'] !== 'active') {
    $conn->query("UPDATE users SET status = 'active', version = version + 1 WHERE id = {$targetId}");
    $target = $conn->query("SELECT id, status, version FROM users WHERE id = {$targetId}")->fetch_assoc();
}
$suspendKey = 'identity-suspend-' . bin2hex(random_bytes(5));
$suspendPayload = ['user_id' => $targetId, 'version' => (int) $target['version'], 'reason' => 'Automated controlled-intervention test'];
$suspend = admin_execute_action($conn, 'suspend_account', $suspendPayload, $actorId, $suspendKey);
$retry = admin_execute_action($conn, 'suspend_account', $suspendPayload, $actorId, $suspendKey);
if (!($suspend['ok'] ?? false) || $suspend !== $retry || $suspend['data']['status'] !== 'suspended') throw new RuntimeException('Suspension must be successful and idempotent.');

$reactivate = admin_execute_action($conn, 'reactivate_account', ['user_id' => $targetId, 'version' => (int) $suspend['data']['version'], 'reason' => 'Restore the demo identity after verification'], $actorId, 'identity-restore-' . bin2hex(random_bytes(5)));
if (!($reactivate['ok'] ?? false) || $reactivate['data']['status'] !== 'active') throw new RuntimeException('Reactivation must restore the account.');

$history = (int) $conn->query("SELECT COUNT(*) AS total FROM account_status_history WHERE user_id = {$targetId}")->fetch_assoc()['total'];
if ($history < 2) throw new RuntimeException('Status changes must append history.');
echo "PASS: account interventions are versioned, audited, idempotent and reversible\n";
