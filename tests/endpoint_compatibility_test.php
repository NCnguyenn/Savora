<?php
declare(strict_types=1);

require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/session_security.php';

function endpoint_expect(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function endpoint_request(string $script, string $body, string $sessionId, string $sessionPath, string $csrf, string $idempotencyKey): array
{
    $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi.exe';
    $root = dirname(__DIR__);
    $scriptPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $script);
    $environment = array_merge(getenv(), [
        'REDIRECT_STATUS' => '1',
        'GATEWAY_INTERFACE' => 'CGI/1.1',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'REQUEST_METHOD' => 'POST',
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => (string) strlen($body),
        'SCRIPT_FILENAME' => $scriptPath,
        'SCRIPT_NAME' => '/' . str_replace('\\', '/', $script),
        'DOCUMENT_ROOT' => $root,
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_COOKIE' => 'PHPSESSID=' . $sessionId,
        'HTTP_X_CSRF_TOKEN' => $csrf,
        'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
        'SAVORA_SESSION_PATH' => $sessionPath,
    ]);
    $process = proc_open([$cgi], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $root, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start endpoint CGI process.');
    }
    fwrite($pipes[0], $body);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || $stderr !== '') {
        throw new RuntimeException("Endpoint CGI process failed: {$stderr}");
    }
    $parts = preg_split('/\r?\n\r?\n/', $stdout, 2);
    if (!is_array($parts) || count($parts) !== 2) {
        throw new RuntimeException('Endpoint CGI response was malformed.');
    }
    preg_match('/^Status:\s+(\d+)/mi', $parts[0], $statusMatch);
    return [
        'status' => isset($statusMatch[1]) ? (int) $statusMatch[1] : 200,
        'raw' => $parts[1],
        'body' => json_decode($parts[1], true, 512, JSON_THROW_ON_ERROR),
    ];
}

$conn = savora_test_database();
$admin = $conn->query("SELECT id,role,session_version FROM users WHERE role='admin' AND status='active' LIMIT 1")->fetch_assoc();
if (!$admin) {
    throw new RuntimeException('Active Admin fixture is missing.');
}
$userId = (int) $admin['id'];
$sessionId = 'task4-endpoint-' . bin2hex(random_bytes(7));
$sessionHash = savora_session_hash($sessionId);
$csrf = 'task4-endpoint-csrf';
$sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'savora-task4-endpoint-sessions';
if (!is_dir($sessionPath) && !mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) {
    throw new RuntimeException('Unable to create endpoint session directory.');
}
$sessionInsert = $conn->prepare('INSERT INTO user_sessions(user_id,session_hash,last_seen_at) VALUES(?,?,NOW())');
$sessionInsert->bind_param('is', $userId, $sessionHash);
$sessionInsert->execute();
$sessionInsert->close();

session_save_path($sessionPath);
session_id($sessionId);
session_start();
$_SESSION = ['user_id' => $userId, 'role' => (string) $admin['role'], 'session_version' => (int) $admin['session_version'], 'admin_csrf' => $csrf];
session_write_close();

$replayKeyPrefix = 'task4-endpoint-envelope-' . bin2hex(random_bytes(5));
$validTrueReplayKey = $replayKeyPrefix . '-valid-true';
$validFalseReplayKey = $replayKeyPrefix . '-valid-false';
$validTrueReplay = ['ok' => true, 'message' => 'Replay response.', 'data' => ['replayed' => true]];
$validFalseReplay = ['ok' => false, 'message' => 'Replay failure.'];
$replayAction = 'replay_test';
$replayHash = hash('sha256', $replayAction . "\n{}");
$store = $conn->prepare('INSERT INTO idempotency_keys(actor_user_id,idempotency_key,action,request_hash,response_json) VALUES(?,?,?,?,?)');
$replayFixtures = [
    $validTrueReplayKey => json_encode($validTrueReplay, JSON_THROW_ON_ERROR),
    $validFalseReplayKey => json_encode($validFalseReplay, JSON_THROW_ON_ERROR),
    $replayKeyPrefix . '-invalid-invalid-json' => '',
    $replayKeyPrefix . '-invalid-list' => '[]',
    $replayKeyPrefix . '-invalid-empty-object' => '{}',
    $replayKeyPrefix . '-invalid-missing-ok' => '{"message":"Missing ok."}',
    $replayKeyPrefix . '-invalid-non-boolean-ok' => '{"ok":1}',
];
foreach ($replayFixtures as $replayKey => $replayJson) {
    $store->bind_param('issss', $userId, $replayKey, $replayAction, $replayHash, $replayJson);
    $store->execute();
}
$store->close();

try {
    $adminMalformed = endpoint_request('admin_action.php', '{', $sessionId, $sessionPath, $csrf, 'adm-malformed-1');
    endpoint_expect($adminMalformed['status'] === 400, 'Admin malformed JSON must return 400.');
    endpoint_expect(array_keys($adminMalformed['body']) === ['ok', 'message', 'referenceId'], 'Admin malformed JSON fields must retain response ordering.');
    endpoint_expect($adminMalformed['body']['ok'] === false && $adminMalformed['body']['message'] === 'Invalid JSON request.' && preg_match('/^ADM-[A-F0-9]{10}$/', $adminMalformed['body']['referenceId']) === 1, 'Admin malformed JSON body/reference must remain exact.');

    $platformMalformed = endpoint_request('api/platform_state.php', '{', $sessionId, $sessionPath, $csrf, 'role-malformed-1');
    endpoint_expect($platformMalformed === ['status' => 400, 'raw' => '{"ok":false,"message":"Invalid JSON."}', 'body' => ['ok' => false, 'message' => 'Invalid JSON.']], 'Platform malformed JSON response must remain exact.');

    $adminCsrf = endpoint_request('admin_action.php', '{', $sessionId, $sessionPath, 'wrong-token', 'adm-csrf-1');
    endpoint_expect($adminCsrf['status'] === 403, 'Admin invalid CSRF must return 403 before parsing malformed JSON.');
    endpoint_expect(array_keys($adminCsrf['body']) === ['ok', 'message', 'referenceId'], 'Admin CSRF fields must retain response ordering.');
    endpoint_expect($adminCsrf['body']['ok'] === false && $adminCsrf['body']['message'] === 'Your secure session expired. Refresh and try again.' && preg_match('/^ADM-[A-F0-9]{10}$/', $adminCsrf['body']['referenceId']) === 1, 'Admin CSRF body/reference must remain exact.');

    $platformCsrf = endpoint_request('api/platform_state.php', '{', $sessionId, $sessionPath, 'wrong-token', 'role-csrf-1');
    endpoint_expect($platformCsrf === ['status' => 403, 'raw' => '{"ok":false,"message":"Secure session expired."}', 'body' => ['ok' => false, 'message' => 'Secure session expired.']], 'Platform CSRF must precede malformed JSON and remain exact.');

    $validTrueReplayResponse = endpoint_request('api/platform_state.php', '{"command":"replay_test","payload":{}}', $sessionId, $sessionPath, $csrf, $validTrueReplayKey);
    endpoint_expect($validTrueReplayResponse['status'] === 200 && $validTrueReplayResponse['body'] === $validTrueReplay, 'A valid ok=true platform response must replay exactly.');
    $validFalseReplayResponse = endpoint_request('api/platform_state.php', '{"command":"replay_test","payload":{}}', $sessionId, $sessionPath, $csrf, $validFalseReplayKey);
    endpoint_expect($validFalseReplayResponse['status'] === 200 && $validFalseReplayResponse['body'] === $validFalseReplay, 'A valid ok=false platform response must replay exactly.');

    foreach (['invalid-json', 'list', 'empty-object', 'missing-ok', 'non-boolean-ok'] as $label) {
        $invalidReplay = endpoint_request('api/platform_state.php', '{"command":"replay_test","payload":{}}', $sessionId, $sessionPath, $csrf, $replayKeyPrefix . '-invalid-' . $label);
        endpoint_expect($invalidReplay === ['status' => 500, 'raw' => '{"ok":false,"message":"Stored response is invalid."}', 'body' => ['ok' => false, 'message' => 'Stored response is invalid.']], "Invalid {$label} response must not replay successfully.");
    }
} finally {
    $cleanupPattern = $replayKeyPrefix . '%';
    $deleteKeys = $conn->prepare('DELETE FROM idempotency_keys WHERE actor_user_id=? AND idempotency_key LIKE ?');
    $deleteKeys->bind_param('is', $userId, $cleanupPattern);
    $deleteKeys->execute();
    $deleteKeys->close();
    $deleteSession = $conn->prepare('DELETE FROM user_sessions WHERE user_id=? AND session_hash=?');
    $deleteSession->bind_param('is', $userId, $sessionHash);
    $deleteSession->execute();
    $deleteSession->close();
    $conn->close();
    $sessionFile = $sessionPath . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
    if (is_file($sessionFile)) {
        unlink($sessionFile);
    }
}

echo "endpoint compatibility contract ok\n";
