<?php
declare(strict_types=1);
putenv('SAVORA_SEED_DEMO=1');
putenv('SAVORA_DB_NAME=' . (getenv('SAVORA_DB_NAME') ?: 'savora_test'));
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/admin_security.php';
require_once __DIR__ . '/../lib/admin_actions.php';

function security_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$actorId = (int) $conn->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch_assoc()['id'];

$application = $conn->query("SELECT * FROM driver_applications WHERE reference_code='DA-2026-208' LIMIT 1")->fetch_assoc();
security_assert((bool) $application, 'Driver application fixture is missing.');
$appId = (int) $application['id'];
$conn->query("UPDATE driver_applications SET status='pending',password_hash='" . $conn->real_escape_string(password_hash('123456', PASSWORD_DEFAULT)) . "',version=version+1 WHERE id={$appId}");
$application = $conn->query("SELECT version,password_hash FROM driver_applications WHERE id={$appId}")->fetch_assoc();
$changes = admin_execute_action($conn, 'request_driver_changes', ['application_id' => $appId, 'version' => (int) $application['version'], 'reviewer_note' => 'Upload a clearer license image'], $actorId, 'security-changes-' . bin2hex(random_bytes(4)));
security_assert(($changes['ok'] ?? false) === true, 'Request Changes must succeed.');
$preserved = $conn->query("SELECT password_hash FROM driver_applications WHERE id={$appId}")->fetch_assoc();
security_assert(!empty($preserved['password_hash']), 'Request Changes must preserve application credentials.');
$conn->query("DELETE FROM driver_application_documents WHERE application_id={$appId}");
$arbitrary = $conn->prepare("INSERT INTO driver_application_documents(application_id,document_type,verification_status,expires_at) VALUES(?,?,'verified',DATE_ADD(NOW(),INTERVAL 1 YEAR))");
foreach (['portrait', 'utility_bill', 'reference_letter'] as $type) { $arbitrary->bind_param('is', $appId, $type); $arbitrary->execute(); }
$arbitrary->close();
$invalidApproval = admin_execute_action($conn, 'approve_driver', ['application_id' => $appId, 'version' => (int) $changes['data']['version']], $actorId, 'security-docs-' . bin2hex(random_bytes(4)));
security_assert(($invalidApproval['ok'] ?? true) === false, 'Arbitrary verified document labels must not satisfy approval.');

$target = $conn->query("SELECT id,version,password FROM users WHERE username='driver-nearby-2' LIMIT 1")->fetch_assoc();
$reset = admin_execute_action($conn, 'reset_password', ['user_id' => (int) $target['id'], 'version' => (int) $target['version'], 'reason' => 'Security regression test'], $actorId, 'security-reset-' . bin2hex(random_bytes(4)));
security_assert(($reset['ok'] ?? false) === true, 'Password recovery must succeed.');
security_assert(!empty($reset['data']['recovery_url']), 'Password recovery must return a usable one-time link.');
$afterPassword = $conn->query('SELECT password FROM users WHERE id=' . (int) $target['id'])->fetch_assoc()['password'];
security_assert(hash_equals((string) $target['password'], (string) $afterPassword), 'Issuing recovery must not destroy the existing credential.');
echo "PASS: change requests preserve credentials and password reset issues a recovery link\n";
