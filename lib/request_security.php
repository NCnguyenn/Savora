<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/admin_security.php';

function savora_request_actor(mysqli $conn, array $roles): array
{
    savora_start_session();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $role = (string) ($_SESSION['role'] ?? '');
    if ($userId <= 0 || !in_array($role, $roles, true)) {
        savora_error(401, 'Authentication required.');
    }

    $validation = savora_validate_session($conn, $_SESSION, session_id(), $role);
    if (!$validation['ok']) {
        savora_end_session();
        savora_error(401, 'Your session is no longer active.');
    }
    if (!savora_session_has_csrf_token($_SESSION)) {
        savora_error(401, 'Please sign in again.');
    }

    return ['userId' => $userId, 'role' => $role];
}

function savora_require_csrf(array $headers): void
{
    $token = '';
    foreach ($headers as $name => $value) {
        if (is_string($name) && strcasecmp($name, 'X-CSRF-Token') === 0) {
            $token = is_string($value) ? $value : '';
            break;
        }
    }
    if (!admin_verify_csrf($token)) {
        savora_error(403, 'Secure session expired.');
    }
}
