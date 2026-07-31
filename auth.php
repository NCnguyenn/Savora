<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

$loginUsername = trim((string) ($_POST['username'] ?? ''));
$loginPassword = (string) ($_POST['password'] ?? '');

if ($loginUsername === '' || $loginPassword === '') {
    $_SESSION['error'] = 'Please enter both username and password.';
    header('Location: index.php');
    exit();
}

$stmt = $conn->prepare('SELECT id, username, password, role, full_name, status, session_version FROM users WHERE username = ? LIMIT 1');
$stmt->bind_param('s', $loginUsername);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || !password_verify($loginPassword, (string) $user['password'])) {
    $_SESSION['error'] = 'Invalid username or password.';
    header('Location: index.php');
    exit();
}

if (($user['status'] ?? 'active') !== 'active') {
    $_SESSION['error'] = 'This account is not active. Please contact Savora support.';
    header('Location: index.php');
    exit();
}

session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['username'] = (string) $user['username'];
$_SESSION['role'] = (string) $user['role'];
$_SESSION['full_name'] = (string) $user['full_name'];
$_SESSION['session_version'] = (int) $user['session_version'];

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

header('Location: ' . ($destinations[$_SESSION['role']] ?? 'index.php'));
exit();
