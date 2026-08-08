<?php
declare(strict_types=1);

require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/migrations.php';
require_once __DIR__ . '/../lib/session_security.php';

function payment_status_test_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function payment_status_test_user(mysqli $conn, string $username): int
{
    $password = password_hash('payment-status-test', PASSWORD_DEFAULT);
    $name = 'Payment Status Customer';
    $statement = $conn->prepare("INSERT INTO users(username,password,role,full_name,status) VALUES(?,?,'customer',?,'active')");
    $statement->bind_param('sss', $username, $password, $name);
    $statement->execute();
    $id = (int) $statement->insert_id;
    $statement->close();
    return $id;
}

function payment_status_test_session(mysqli $conn, int $userId, string $sessionId, string $sessionPath): void
{
    if (!is_dir($sessionPath) && !mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) {
        throw new RuntimeException('Unable to create endpoint test session directory.');
    }
    $hash = savora_session_hash($sessionId);
    $statement = $conn->prepare('INSERT INTO user_sessions(user_id,session_hash,last_seen_at) VALUES(?,?,NOW())');
    $statement->bind_param('is', $userId, $hash);
    $statement->execute();
    $statement->close();

    session_save_path($sessionPath);
    session_id($sessionId);
    session_start();
    $_SESSION = [
        'user_id' => $userId,
        'role' => 'customer',
        'session_version' => 1,
        'admin_csrf' => 'payment-status-csrf-' . $userId,
    ];
    session_write_close();
}

function payment_status_test_request(string $method, string $query, string $sessionId = '', string $sessionPath = ''): array
{
    $cgi = 'D:\\Xampp\\php\\php-cgi.exe';
    if (!is_file($cgi)) throw new RuntimeException('PHP CGI executable is required for payment status endpoint tests.');
    $root = dirname(__DIR__);
    $environment = array_merge(getenv(), [
        'REDIRECT_STATUS' => '1',
        'GATEWAY_INTERFACE' => 'CGI/1.1',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'REQUEST_METHOD' => $method,
        'QUERY_STRING' => $query,
        'REQUEST_URI' => '/api/payment_status.php' . ($query === '' ? '' : '?' . $query),
        'SCRIPT_FILENAME' => $root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'payment_status.php',
        'SCRIPT_NAME' => '/api/payment_status.php',
        'DOCUMENT_ROOT' => $root,
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_COOKIE' => $sessionId === '' ? '' : 'PHPSESSID=' . $sessionId,
        'SAVORA_SESSION_PATH' => $sessionPath,
        'SAVORA_ENV' => 'test',
        'SAVORA_DB_NAME' => 'savora_test',
    ]);
    $process = proc_open([$cgi], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $root, $environment);
    if (!is_resource($process)) throw new RuntimeException('Unable to start payment status endpoint process.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || $stderr !== '') throw new RuntimeException('Payment status endpoint process failed: ' . $stderr);
    $parts = preg_split('/\r?\n\r?\n/', $stdout, 2);
    if (!is_array($parts) || count($parts) !== 2) throw new RuntimeException('Payment status endpoint response was malformed.');
    preg_match('/^Status:\s+(\d+)/mi', $parts[0], $statusMatch);
    return [
        'status' => isset($statusMatch[1]) ? (int) $statusMatch[1] : 200,
        'body' => json_decode($parts[1], true, 512, JSON_THROW_ON_ERROR),
    ];
}

function payment_status_test_remove_session_path(string $path): void
{
    if (!is_dir($path)) return;
    foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) unlink($file);
    }
    rmdir($path);
}

$conn = null;
$userIds = [];
$orderIds = [];
$restaurantId = 0;
$suffix = strtolower(bin2hex(random_bytes(5)));
$reference = 'SVR-' . strtoupper($suffix) . '-STATUS';
$cashReference = 'SVR-' . strtoupper($suffix) . '-CASH';
$ownerSessionId = 'sepay-owner-' . $suffix;
$otherSessionId = 'sepay-other-' . $suffix;
$ownerSessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'savora-sepay-owner-' . $suffix;
$otherSessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'savora-sepay-other-' . $suffix;

try {
    $conn = savora_test_database();
    savora_apply_migrations($conn);
    $ownerId = payment_status_test_user($conn, 'pay-status-owner-' . $suffix);
    $otherId = payment_status_test_user($conn, 'pay-status-other-' . $suffix);
    $restaurantPassword = password_hash('payment-status-test', PASSWORD_DEFAULT);
    $restaurantUsername = 'pay-status-restaurant-' . $suffix;
    $restaurantName = 'Payment Status Restaurant Owner';
    $restaurantOwner = $conn->prepare("INSERT INTO users(username,password,role,full_name,status) VALUES(?,?,'restaurant',?,'active')");
    $restaurantOwner->bind_param('sss', $restaurantUsername, $restaurantPassword, $restaurantName);
    $restaurantOwner->execute();
    $restaurantOwnerId = (int) $restaurantOwner->insert_id;
    $restaurantOwner->close();
    $userIds = [$ownerId, $otherId, $restaurantOwnerId];

    $restaurant = $conn->prepare("INSERT INTO restaurants(owner_user_id,name,status) VALUES(?,'Payment Status Restaurant','active')");
    $restaurant->bind_param('i', $restaurantOwnerId);
    $restaurant->execute();
    $restaurantId = (int) $restaurant->insert_id;
    $restaurant->close();

    foreach ([[$reference, 'seapay', 125000.0], [$cashReference, 'cash', 50000.0]] as [$fixtureReference, $method, $amount]) {
        $order = $conn->prepare("INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,total) VALUES(?,?,?,'pending',?,?,?)");
        $order->bind_param('siisdd', $fixtureReference, $ownerId, $restaurantId, $method, $amount, $amount);
        $order->execute();
        $orderId = (int) $order->insert_id;
        $orderIds[] = $orderId;
        $order->close();
        $payment = $conn->prepare("INSERT INTO payments(order_id,method,amount,status) VALUES(?,?,?,'pending')");
        $payment->bind_param('isd', $orderId, $method, $amount);
        $payment->execute();
        $payment->close();
    }

    payment_status_test_session($conn, $ownerId, $ownerSessionId, $ownerSessionPath);
    payment_status_test_session($conn, $otherId, $otherSessionId, $otherSessionPath);

    $owned = payment_status_test_request('GET', 'order=' . rawurlencode($reference), $ownerSessionId, $ownerSessionPath);
    payment_status_test_expect($owned['status'] === 200, 'Owner must read payment status.');
    payment_status_test_expect(($owned['body']['data']['amountVnd'] ?? null) === 125000, 'Endpoint must return integer VND.');
    payment_status_test_expect(($owned['body']['data']['orderStatus'] ?? '') === 'pending', 'Endpoint must preserve pending order state.');

    $other = payment_status_test_request('GET', 'order=' . rawurlencode($reference), $otherSessionId, $otherSessionPath);
    payment_status_test_expect($other['status'] === 404, 'Another Customer must not discover the payment.');

    $cash = payment_status_test_request('GET', 'order=' . rawurlencode($cashReference), $ownerSessionId, $ownerSessionPath);
    payment_status_test_expect($cash['status'] === 404, 'Non-SePay orders must not be exposed.');

    $invalid = payment_status_test_request('GET', 'order=not-an-order', $ownerSessionId, $ownerSessionPath);
    payment_status_test_expect($invalid['status'] === 422, 'Invalid reference must be rejected.');

    $unauthenticated = payment_status_test_request('GET', 'order=' . rawurlencode($reference));
    payment_status_test_expect($unauthenticated['status'] === 401, 'Unauthenticated request must be rejected.');

    $method = payment_status_test_request('POST', 'order=' . rawurlencode($reference), $ownerSessionId, $ownerSessionPath);
    payment_status_test_expect($method['status'] === 405, 'Only GET is allowed.');
} finally {
    if ($conn instanceof mysqli) {
        if ($userIds !== []) $conn->query('DELETE FROM user_sessions WHERE user_id IN (' . implode(',', array_map('intval', $userIds)) . ')');
        if ($orderIds !== []) {
            $orderList = implode(',', array_map('intval', $orderIds));
            $conn->query('DELETE FROM payments WHERE order_id IN (' . $orderList . ')');
            $conn->query('DELETE FROM orders WHERE id IN (' . $orderList . ')');
        }
        if ($restaurantId > 0) $conn->query('DELETE FROM restaurants WHERE id=' . $restaurantId);
        if ($userIds !== []) $conn->query('DELETE FROM users WHERE id IN (' . implode(',', array_map('intval', $userIds)) . ')');
        $conn->close();
    }
    payment_status_test_remove_session_path($ownerSessionPath);
    payment_status_test_remove_session_path($otherSessionPath);
}

echo "PASS: payment status endpoint is Customer-owned, SePay-only, read-only, and VND-safe\n";
