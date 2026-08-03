<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/session_security.php';
require_once __DIR__ . '/lib/admin_security.php';
require_once __DIR__ . '/lib/customer_access.php';
require_once __DIR__ . '/lib/services/rate_limit_service.php';
savora_start_session();
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit();
}

$loginUsername = trim((string) ($_POST['username'] ?? ''));
$loginPassword = (string) ($_POST['password'] ?? '');
$returnTo = customer_safe_return_to($_POST['return_to'] ?? '');
$_SESSION['login_username'] = mb_substr($loginUsername, 0, 50);

$actor = ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . mb_strtolower(mb_substr($loginUsername, 0, 120));
if (!rate_limit_consume($conn, $actor, 'login', 8, 300)) {
    $_SESSION['error'] = 'Too many sign-in attempts. Please try again later.';
    header('Location: ' . customer_login_url($returnTo));
    exit();
}

if ($loginUsername === '' || $loginPassword === '') {
    $_SESSION['error'] = 'Please enter both username and password.';
    header('Location: ' . customer_login_url($returnTo));
    exit();
}

$stmt = $conn->prepare('SELECT id, username, password, role, full_name, status, session_version FROM users WHERE username = ? LIMIT 1');
$stmt->bind_param('s', $loginUsername);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || !password_verify($loginPassword, (string) $user['password'])) {
    $_SESSION['error'] = 'Invalid username or password.';
    header('Location: ' . customer_login_url($returnTo));
    exit();
}

if (($user['status'] ?? 'active') !== 'active') {
    $_SESSION['error'] = 'This account is not active. Please contact Savora support.';
    header('Location: ' . customer_login_url($returnTo));
    exit();
}

session_regenerate_id(true);
unset($_SESSION['login_username'], $_SESSION['error']);
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['username'] = (string) $user['username'];
$_SESSION['role'] = (string) $user['role'];
$_SESSION['full_name'] = (string) $user['full_name'];
$_SESSION['session_version'] = (int) $user['session_version'];
admin_issue_csrf_token();
savora_register_user_session($conn, (int) $user['id']);

$update = $conn->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
$update->bind_param('i', $_SESSION['user_id']);
$update->execute();
$update->close();

$destinations = [
    'customer' => 'customer_dashboard.php',
    'restaurant' => 'restaurant_dashboard.php',
    'driver' => 'driver_dashboard.php',
    'admin' => 'admin_dashboard.php',
];

$destination = $_SESSION['role'] === 'customer' && $returnTo !== ''
    ? $returnTo
    : ($destinations[$_SESSION['role']] ?? 'customer_dashboard.php');
header('Location: ' . $destination);
exit();
