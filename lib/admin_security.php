<?php
declare(strict_types=1);
require_once __DIR__ . '/session_security.php';

function admin_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_csrf_token(): string
{
    $token = $_SESSION['admin_csrf'] ?? '';
    return is_string($token) ? $token : '';
}

function admin_issue_csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        savora_start_session();
    }
    if (!isset($_SESSION['admin_csrf']) || !is_string($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf'];
}

function admin_verify_csrf(string $token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        savora_start_session();
    }
    $stored = $_SESSION['admin_csrf'] ?? '';
    return is_string($stored) && $stored !== '' && hash_equals($stored, $token);
}

function admin_require_role(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        savora_start_session();
    }
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) {
        http_response_code(500);
        exit('Authentication service unavailable');
    }
    $validation = savora_validate_session($conn, $_SESSION, session_id(), 'admin');
    if (!$validation['ok']) {
        savora_end_session();
        header('Location: index.php');
        exit();
    }
    if (!savora_session_has_csrf_token($_SESSION)) {
        header('Location: index.php');
        exit();
    }
}

function admin_configure_session_path(): void
{
    savora_configure_session_path();
}

function admin_reference_id(): string
{
    return 'ADM-' . strtoupper(bin2hex(random_bytes(5)));
}
