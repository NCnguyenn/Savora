<?php
declare(strict_types=1);

function admin_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        admin_configure_session_path();
        session_start();
    }
    if (!isset($_SESSION['admin_csrf']) || !is_string($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf'];
}

function admin_verify_csrf(string $token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        admin_configure_session_path();
        session_start();
    }
    $stored = $_SESSION['admin_csrf'] ?? '';
    return is_string($stored) && $stored !== '' && hash_equals($stored, $token);
}

function admin_require_role(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        admin_configure_session_path();
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        header('Location: index.php');
        exit();
    }
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Forbidden');
    }
}

function admin_configure_session_path(): void
{
    $sessionPath = getenv('SAVORA_SESSION_PATH');
    $localSessionPath = dirname(__DIR__) . '/.sessions';
    if ((!is_string($sessionPath) || $sessionPath === '') && is_dir($localSessionPath)) {
        $sessionPath = $localSessionPath;
    }
    if (is_string($sessionPath) && $sessionPath !== '' && is_dir($sessionPath)) {
        session_save_path($sessionPath);
    }
}

function admin_reference_id(): string
{
    return 'ADM-' . strtoupper(bin2hex(random_bytes(5)));
}
