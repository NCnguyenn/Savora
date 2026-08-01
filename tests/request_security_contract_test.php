<?php
declare(strict_types=1);

require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/request_security.php';

function security_expect(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function security_expect_invalid(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
}

function security_probe(array $environment): array
{
    $probe = __DIR__ . '/support/request_security_probe.php';
    $process = proc_open([PHP_BINARY, $probe], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, __DIR__, array_merge(getenv(), $environment));
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start request security probe.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    security_expect($exitCode === 0, "Request security probe failed with exit code {$exitCode}.");
    preg_match('/STATUS=(\d+)/', $stderr, $statusMatch);
    return [
        'status' => isset($statusMatch[1]) ? (int) $statusMatch[1] : 200,
        'body' => json_decode($stdout, true, 512, JSON_THROW_ON_ERROR),
    ];
}

$conn = savora_test_database();
$admin = $conn->query("SELECT id, role, status, session_version FROM users WHERE role='admin' AND status='active' LIMIT 1")->fetch_assoc();
if (!$admin) {
    throw new RuntimeException('Active Admin fixture is missing.');
}
$sessionId = 'task4-' . bin2hex(random_bytes(8));
$sessionHash = savora_session_hash($sessionId);
$userId = (int) $admin['id'];
$lastSeen = '2001-02-03 04:05:06';
$insert = $conn->prepare('INSERT INTO user_sessions(user_id,session_hash,last_seen_at) VALUES(?,?,?)');
$insert->bind_param('iss', $userId, $sessionHash, $lastSeen);
$insert->execute();
$insert->close();

$sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'savora-task4-sessions';
if (!is_dir($sessionPath) && !mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) {
    throw new RuntimeException('Unable to create Task 4 session directory.');
}
$baseEnvironment = [
    'SAVORA_PROBE_SESSION_PATH' => $sessionPath,
    'SAVORA_PROBE_SESSION_ID' => $sessionId,
    'SAVORA_PROBE_USER_ID' => (string) $userId,
    'SAVORA_PROBE_ROLE' => (string) $admin['role'],
    'SAVORA_PROBE_SESSION_VERSION' => (string) $admin['session_version'],
    'SAVORA_PROBE_CSRF' => 'csrf-contract-token',
];

try {
    $actor = security_probe($baseEnvironment + ['SAVORA_PROBE_MODE' => 'actor']);
    security_expect($actor === ['status' => 200, 'body' => ['userId' => $userId, 'role' => 'admin']], 'savora_request_actor() must return stable userId and role identity.');

    $wrongRole = security_probe($baseEnvironment + ['SAVORA_PROBE_MODE' => 'wrong_role']);
    security_expect($wrongRole === ['status' => 401, 'body' => ['ok' => false, 'message' => 'Authentication required.']], 'Disallowed roles must be rejected.');

    $legacyEnvironment = $baseEnvironment;
    $legacyEnvironment['SAVORA_PROBE_CSRF'] = '';
    $legacy = security_probe($legacyEnvironment + ['SAVORA_PROBE_MODE' => 'legacy']);
    security_expect($legacy === ['status' => 401, 'body' => ['ok' => false, 'message' => 'Please sign in again.']], 'Legacy sessions without CSRF must reauthenticate.');
    $lastSeenRow = $conn->prepare('SELECT last_seen_at FROM user_sessions WHERE user_id=? AND session_hash=?');
    $lastSeenRow->bind_param('is', $userId, $sessionHash);
    $lastSeenRow->execute();
    $storedLastSeen = (string) $lastSeenRow->get_result()->fetch_assoc()['last_seen_at'];
    $lastSeenRow->close();
    security_expect($storedLastSeen === $lastSeen, 'Actor validation and legacy rejection must not touch last_seen_at.');

    $csrfValid = security_probe($baseEnvironment + ['SAVORA_PROBE_MODE' => 'csrf_valid']);
    security_expect($csrfValid === ['status' => 200, 'body' => ['ok' => true]], 'Case-insensitive valid CSRF headers must pass.');
    $csrfInvalid = security_probe($baseEnvironment + ['SAVORA_PROBE_MODE' => 'csrf_invalid']);
    security_expect($csrfInvalid === ['status' => 200, 'body' => ['class' => InvalidArgumentException::class, 'message' => 'Invalid CSRF token.']], 'Invalid CSRF headers must throw for endpoint-specific error compatibility.');

    security_expect(savora_require_idempotency_key(['idempotency-key' => ' role-1234-abcd ']) === 'role-1234-abcd', 'Current role key format must be accepted and trimmed.');
    security_expect(savora_require_idempotency_key(['Idempotency-Key' => 'adm-1234.dead:beef']) === 'adm-1234.dead:beef', 'Current Admin key format and supported separators must be accepted.');
    security_expect_invalid(static fn () => savora_require_idempotency_key([]), 'Missing idempotency headers must be rejected.');
    security_expect_invalid(static fn () => savora_require_idempotency_key(['Idempotency-Key' => 'bad key']), 'Whitespace inside idempotency keys must be rejected.');
    security_expect_invalid(static fn () => savora_require_idempotency_key(['Idempotency-Key' => str_repeat('a', 101)]), 'Idempotency keys longer than the schema limit must be rejected.');
} finally {
    $delete = $conn->prepare('DELETE FROM user_sessions WHERE user_id=? AND session_hash=?');
    $delete->bind_param('is', $userId, $sessionHash);
    $delete->execute();
    $delete->close();
    $conn->close();
}

echo "request security contract ok\n";
