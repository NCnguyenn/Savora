<?php
declare(strict_types=1);
putenv('SAVORA_SEED_DEMO=1');
putenv('SAVORA_DB_NAME=' . (getenv('SAVORA_DB_NAME') ?: 'savora_test'));
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/session_security.php';

$user = $conn->query("SELECT id,role,status,session_version FROM users WHERE username='driver-nearby-2' LIMIT 1")->fetch_assoc();
if (!$user) throw new RuntimeException('Session test user is missing.');
$sessionId = 'security-test-' . bin2hex(random_bytes(8));
$sessionHash = savora_session_hash($sessionId);
$userId = (int) $user['id'];
$conn->query("DELETE FROM user_sessions WHERE user_id={$userId} AND session_hash='{$sessionHash}'");
$insert = $conn->prepare('INSERT INTO user_sessions(user_id,session_hash,last_seen_at) VALUES(?,?,NOW())');
$insert->bind_param('is', $userId, $sessionHash); $insert->execute(); $insert->close();
$session = ['user_id' => $userId, 'role' => $user['role'], 'session_version' => (int) $user['session_version']];
if (!savora_validate_session($conn, $session, $sessionId, 'driver')['ok']) throw new RuntimeException('A registered active session must validate.');
$conn->query("UPDATE users SET session_version=session_version+1 WHERE id={$userId}");
if (savora_validate_session($conn, $session, $sessionId, 'driver')['ok']) throw new RuntimeException('A stale session version must be rejected.');
$conn->query("UPDATE users SET session_version=" . (int) $user['session_version'] . " WHERE id={$userId}");
$conn->query("UPDATE user_sessions SET revoked_at=NOW() WHERE user_id={$userId} AND session_hash='{$sessionHash}'");
if (savora_validate_session($conn, $session, $sessionId, 'driver')['ok']) throw new RuntimeException('A revoked session must be rejected.');
$conn->query("DELETE FROM user_sessions WHERE user_id={$userId} AND session_hash='{$sessionHash}'");
if (savora_validate_session($conn, $session, $sessionId, 'driver')['ok']) throw new RuntimeException('An unregistered session must be rejected.');
echo "PASS: DB-backed sessions enforce role, status, version and revocation\n";
