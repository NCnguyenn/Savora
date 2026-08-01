<?php
declare(strict_types=1);

function savora_configure_session_path(): void
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

function savora_start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        savora_configure_session_path();
        session_start();
    }
}

function savora_session_hash(?string $sessionId = null): string
{
    return hash('sha256', $sessionId ?? session_id());
}

function savora_register_user_session(mysqli $conn, int $userId): void
{
    $hash = savora_session_hash();
    $ip = mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    $agent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $stmt = $conn->prepare('INSERT INTO user_sessions (user_id, session_hash, ip_address, user_agent, last_seen_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), ip_address = VALUES(ip_address), user_agent = VALUES(user_agent), revoked_at = NULL, last_seen_at = NOW()');
    $stmt->bind_param('isss', $userId, $hash, $ip, $agent);
    $stmt->execute();
    $stmt->close();
}

function savora_touch_session(mysqli $conn, int $userId, string $sessionId): void
{
    $hash = savora_session_hash($sessionId);
    $touch = $conn->prepare('UPDATE user_sessions SET last_seen_at = NOW() WHERE user_id = ? AND session_hash = ? AND revoked_at IS NULL');
    $touch->bind_param('is', $userId, $hash);
    $touch->execute();
    $touch->close();
}

function savora_validate_session(mysqli $conn, array $session, string $sessionId, ?string $requiredRole = null): array
{
    $userId = (int) ($session['user_id'] ?? 0);
    $sessionRole = (string) ($session['role'] ?? '');
    $sessionVersion = (int) ($session['session_version'] ?? 0);
    if ($userId <= 0 || $sessionRole === '' || $sessionVersion <= 0 || $sessionId === '') {
        return ['ok' => false, 'reason' => 'authentication_required'];
    }

    $hash = savora_session_hash($sessionId);
    $stmt = $conn->prepare('SELECT u.role, u.status, u.session_version, s.id AS session_record_id, s.revoked_at FROM users u LEFT JOIN user_sessions s ON s.user_id = u.id AND s.session_hash = ? WHERE u.id = ? LIMIT 1');
    $stmt->bind_param('si', $hash, $userId);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$record || $record['session_record_id'] === null || $record['revoked_at'] !== null) {
        return ['ok' => false, 'reason' => 'session_revoked'];
    }
    if ((string) $record['status'] !== 'active') {
        return ['ok' => false, 'reason' => 'account_inactive'];
    }
    if ((string) $record['role'] !== $sessionRole || ($requiredRole !== null && $sessionRole !== $requiredRole)) {
        return ['ok' => false, 'reason' => 'role_mismatch'];
    }
    if ((int) $record['session_version'] !== $sessionVersion) {
        return ['ok' => false, 'reason' => 'session_version_changed'];
    }

    return ['ok' => true, 'reason' => 'active'];
}

function savora_revoke_current_session(mysqli $conn): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['user_id'])) {
        return;
    }
    $userId = (int) $_SESSION['user_id'];
    $hash = savora_session_hash();
    $stmt = $conn->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND session_hash = ? AND revoked_at IS NULL');
    $stmt->bind_param('is', $userId, $hash);
    $stmt->execute();
    $stmt->close();
}

function savora_end_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
