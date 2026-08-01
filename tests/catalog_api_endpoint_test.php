<?php
declare(strict_types=1);

require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/session_security.php';
require_once __DIR__ . '/../lib/idempotency.php';

function catalog_endpoint_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function catalog_endpoint_schema_blocker(mysqli $conn): ?string
{
    $database = savora_test_selected_database($conn);
    $tables = $conn->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ('schema_migrations','restaurant_weekly_hours','restaurant_special_hours','menu_option_groups','menu_option_choices')"
    );
    $tables->bind_param('s', $database);
    $tables->execute();
    $present = array_column($tables->get_result()->fetch_all(MYSQLI_ASSOC), 'TABLE_NAME');
    $tables->close();
    if (count($present) !== 5) {
        return 'savora_test is missing the catalog migration tables.';
    }
    $migration = $conn->prepare('SELECT 1 FROM schema_migrations WHERE version=? LIMIT 1');
    $version = '004_catalog_contract';
    $migration->bind_param('s', $version);
    $migration->execute();
    $applied = $migration->get_result()->fetch_assoc();
    $migration->close();
    return $applied ? null : 'savora_test has not recorded migration 004_catalog_contract.';
}

function catalog_endpoint_request(
    string $method,
    string $query,
    string $body,
    string $sessionId = '',
    string $sessionPath = '',
    ?string $csrf = null,
    ?string $idempotencyKey = null
): array {
    $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi.exe';
    if (!is_file($cgi)) {
        throw new RuntimeException('PHP CGI executable is required for catalog endpoint tests.');
    }
    $root = dirname(__DIR__);
    $script = 'api/catalog.php';
    $environment = array_merge(getenv(), [
        'REDIRECT_STATUS' => '1',
        'GATEWAY_INTERFACE' => 'CGI/1.1',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'REQUEST_METHOD' => $method,
        'QUERY_STRING' => $query,
        'REQUEST_URI' => '/api/catalog.php' . ($query === '' ? '' : '?' . $query),
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => (string) strlen($body),
        'SCRIPT_FILENAME' => $root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'catalog.php',
        'SCRIPT_NAME' => '/api/catalog.php',
        'DOCUMENT_ROOT' => $root,
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_COOKIE' => $sessionId === '' ? '' : 'PHPSESSID=' . $sessionId,
        'SAVORA_SESSION_PATH' => $sessionPath,
    ]);
    if ($csrf !== null) {
        $environment['HTTP_X_CSRF_TOKEN'] = $csrf;
    } else {
        unset($environment['HTTP_X_CSRF_TOKEN']);
    }
    if ($idempotencyKey !== null) {
        $environment['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
    } else {
        unset($environment['HTTP_IDEMPOTENCY_KEY']);
    }

    $process = proc_open([$cgi], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $root, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start catalog endpoint CGI process.');
    }
    fwrite($pipes[0], $body);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || $stderr !== '') {
        throw new RuntimeException("Catalog endpoint CGI process failed: {$stderr}");
    }
    $parts = preg_split('/\r?\n\r?\n/', $stdout, 2);
    if (!is_array($parts) || count($parts) !== 2) {
        throw new RuntimeException('Catalog endpoint CGI response was malformed.');
    }
    preg_match('/^Status:\s+(\d+)/mi', $parts[0], $statusMatch);
    return [
        'status' => isset($statusMatch[1]) ? (int) $statusMatch[1] : 200,
        'body' => json_decode($parts[1], true, 512, JSON_THROW_ON_ERROR),
    ];
}

$conn = savora_test_database();
$blocker = catalog_endpoint_schema_blocker($conn);
if ($blocker !== null) {
    $conn->close();
    echo "BLOCKED: {$blocker}\n";
    return;
}

$suffix = bin2hex(random_bytes(6));
$userId = null;
$restaurantId = null;
$publicId = 'task7-api-' . $suffix;
$sessionId = 'task7-api-session-' . $suffix;
$sessionHash = savora_session_hash($sessionId);
$csrf = 'task7-api-csrf-' . $suffix;
$sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'savora-task7-api-' . $suffix;
$replayKey = 'task7-api-replay-' . $suffix;

try {
    $password = password_hash('catalog-api-test', PASSWORD_DEFAULT);
    $username = 'task7-api-' . $suffix;
    $ownerName = 'Catalog API Owner';
    $user = $conn->prepare("INSERT INTO users(username,password,role,full_name,status) VALUES(?,?,'restaurant',?,'active')");
    $user->bind_param('sss', $username, $password, $ownerName);
    $user->execute();
    $userId = $conn->insert_id;
    $user->close();

    $restaurantName = str_repeat('R', 120);
    $restaurant = $conn->prepare("INSERT INTO restaurants(owner_user_id,name,status,accepting_orders) VALUES(?,?,'active',1)");
    $restaurant->bind_param('is', $userId, $restaurantName);
    $restaurant->execute();
    $restaurantId = $conn->insert_id;
    $restaurant->close();

    $itemName = str_repeat('Q', 80);
    $price = 6.50;
    $item = $conn->prepare('INSERT INTO menu_items(public_id,restaurant_id,name,price,is_available,version) VALUES(?,?,?,?,1,1)');
    $item->bind_param('sisd', $publicId, $restaurantId, $itemName, $price);
    $item->execute();
    $item->close();

    if (!is_dir($sessionPath) && !mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) {
        throw new RuntimeException('Unable to create catalog endpoint session directory.');
    }
    $sessionInsert = $conn->prepare('INSERT INTO user_sessions(user_id,session_hash,last_seen_at) VALUES(?,?,NOW())');
    $sessionInsert->bind_param('is', $userId, $sessionHash);
    $sessionInsert->execute();
    $sessionInsert->close();

    session_save_path($sessionPath);
    session_id($sessionId);
    session_start();
    $_SESSION = ['user_id' => $userId, 'role' => 'restaurant', 'session_version' => 1, 'admin_csrf' => $csrf];
    session_write_close();

    $query = 'q=' . rawurlencode(str_repeat('Q', 100)) . '&restaurant=' . rawurlencode(str_repeat('R', 150));
    $filtered = catalog_endpoint_request('GET', $query, '', $sessionId, $sessionPath);
    catalog_endpoint_expect(
        $filtered['status'] === 200
        && ($filtered['body']['ok'] ?? false) === true
        && count($filtered['body']['data'] ?? []) === 1
        && $filtered['body']['data'][0]['publicId'] === $publicId,
        'Catalog GET must execute bounded q and restaurant filters.'
    );

    $method = catalog_endpoint_request('PUT', '', '', $sessionId, $sessionPath);
    catalog_endpoint_expect($method === ['status' => 405, 'body' => ['ok' => false, 'message' => 'Method not allowed.']], 'Catalog must reject unsupported methods.');

    $invalidCsrf = catalog_endpoint_request('POST', '', '{', $sessionId, $sessionPath, 'wrong-token', 'task7-invalid-csrf');
    catalog_endpoint_expect($invalidCsrf === ['status' => 403, 'body' => ['ok' => false, 'message' => 'Secure session expired.']], 'Catalog invalid CSRF must fail before JSON parsing.');

    $missingKey = catalog_endpoint_request('POST', '', '{', $sessionId, $sessionPath, $csrf, null);
    catalog_endpoint_expect($missingKey === ['status' => 422, 'body' => ['ok' => false, 'message' => 'Idempotency key required.']], 'Catalog must require an idempotency key before JSON parsing.');

    $malformed = catalog_endpoint_request('POST', '', '{', $sessionId, $sessionPath, $csrf, 'task7-malformed-json');
    catalog_endpoint_expect($malformed === ['status' => 400, 'body' => ['ok' => false, 'message' => 'Invalid JSON.']], 'Catalog must reject malformed JSON after security checks.');

    $payload = ['publicId' => $publicId, 'name' => $itemName, 'price' => $price, 'available' => true, 'version' => 1];
    $action = 'save_item';
    $requestHash = savora_idempotency_hash($action, $payload);
    $storedResponse = ['ok' => true, 'status' => 200, 'data' => ['replayed' => true]];
    $storedJson = json_encode($storedResponse, JSON_THROW_ON_ERROR);
    $store = $conn->prepare('INSERT INTO idempotency_keys(actor_user_id,idempotency_key,action,request_hash,response_json) VALUES(?,?,?,?,?)');
    $store->bind_param('issss', $userId, $replayKey, $action, $requestHash, $storedJson);
    $store->execute();
    $store->close();

    $replayBody = json_encode(['action' => $action, 'payload' => $payload], JSON_THROW_ON_ERROR);
    $replay = catalog_endpoint_request('POST', '', $replayBody, $sessionId, $sessionPath, $csrf, $replayKey);
    catalog_endpoint_expect($replay === ['status' => 200, 'body' => ['ok' => true, 'data' => ['replayed' => true]]], 'Catalog must replay an identical idempotent request.');

    $payload['name'] = 'Changed request';
    $mismatchBody = json_encode(['action' => $action, 'payload' => $payload], JSON_THROW_ON_ERROR);
    $mismatch = catalog_endpoint_request('POST', '', $mismatchBody, $sessionId, $sessionPath, $csrf, $replayKey);
    catalog_endpoint_expect(
        $mismatch === ['status' => 409, 'body' => ['ok' => false, 'message' => 'Idempotency key was already used for a different request.']],
        'Catalog key reuse with a different request must return 409.'
    );
} finally {
    if ($userId !== null) {
        $deleteKeys = $conn->prepare('DELETE FROM idempotency_keys WHERE actor_user_id=? AND idempotency_key=?');
        $deleteKeys->bind_param('is', $userId, $replayKey);
        $deleteKeys->execute();
        $deleteKeys->close();
        $deleteSession = $conn->prepare('DELETE FROM user_sessions WHERE user_id=? AND session_hash=?');
        $deleteSession->bind_param('is', $userId, $sessionHash);
        $deleteSession->execute();
        $deleteSession->close();
    }
    if ($restaurantId !== null) {
        $deleteItem = $conn->prepare('DELETE FROM menu_items WHERE public_id=?');
        $deleteItem->bind_param('s', $publicId);
        $deleteItem->execute();
        $deleteItem->close();
        $deleteRestaurant = $conn->prepare('DELETE FROM restaurants WHERE id=?');
        $deleteRestaurant->bind_param('i', $restaurantId);
        $deleteRestaurant->execute();
        $deleteRestaurant->close();
    }
    if ($userId !== null) {
        $deleteUser = $conn->prepare('DELETE FROM users WHERE id=?');
        $deleteUser->bind_param('i', $userId);
        $deleteUser->execute();
        $deleteUser->close();
    }
    $conn->close();
    $sessionFile = $sessionPath . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
    if (is_file($sessionFile)) {
        unlink($sessionFile);
    }
    if (is_dir($sessionPath)) {
        rmdir($sessionPath);
    }
}

echo "PASS: catalog CGI filters and security/idempotency boundaries are executable\n";
