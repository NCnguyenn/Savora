<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/admin_security.php';
require_once __DIR__ . '/lib/admin_actions.php';

header('Content-Type: application/json; charset=utf-8');
admin_require_role();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST is required.', 'referenceId' => admin_reference_id()]);
    exit;
}

$csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!admin_verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Your secure session expired. Refresh and try again.', 'referenceId' => admin_reference_id()]);
    exit;
}

$idempotencyKey = mb_substr(trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')), 0, 100);
if ($idempotencyKey === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'An idempotency key is required.', 'referenceId' => admin_reference_id()]);
    exit;
}

$decoded = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($decoded)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON request.', 'referenceId' => admin_reference_id()]);
    exit;
}

$action = mb_substr(trim((string) ($decoded['action'] ?? '')), 0, 100);
$payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];
$result = admin_execute_action($conn, $action, $payload, (int) $_SESSION['user_id'], $idempotencyKey);
http_response_code(($result['ok'] ?? false) ? 200 : 422);
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
