<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/session_security.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/admin_security.php';

header('Content-Type: application/json; charset=utf-8');
savora_start_session();

function session_heartbeat_json(array $value, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    session_heartbeat_json(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['customer', 'restaurant', 'driver', 'admin'], true) || !isset($_SESSION['user_id'])) {
    session_heartbeat_json(['ok' => false, 'message' => 'Authentication required.'], 401);
}

$validation = savora_validate_session($conn, $_SESSION, session_id(), $role);
if (!$validation['ok']) {
    savora_end_session();
    session_heartbeat_json(['ok' => false, 'message' => 'Your session is no longer active.'], 401);
}

if (!admin_verify_csrf((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    session_heartbeat_json(['ok' => false, 'message' => 'Secure session expired.'], 403);
}

savora_touch_session($conn, (int) $_SESSION['user_id'], session_id());
session_heartbeat_json(['ok' => true]);
