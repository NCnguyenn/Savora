<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/admin_security.php';
require_once __DIR__ . '/lib/admin_actions.php';
require_once __DIR__ . '/lib/request_security.php';

$actor = savora_request_actor($conn, ['admin']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    savora_error(405, 'POST is required.', [], admin_reference_id());
}

try {
    savora_require_csrf(['X-CSRF-Token' => (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')]);
} catch (InvalidArgumentException) {
    savora_error(403, 'Your secure session expired. Refresh and try again.', [], admin_reference_id());
}

try {
    $idempotencyKey = savora_require_idempotency_key(['Idempotency-Key' => (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')]);
} catch (InvalidArgumentException) {
    savora_error(422, 'An idempotency key is required.', [], admin_reference_id());
}

try {
    $decoded = savora_read_json();
} catch (JsonException) {
    savora_error(400, 'Invalid JSON request.', [], admin_reference_id());
}

$action = mb_substr(trim((string) ($decoded['action'] ?? '')), 0, 100);
$payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];
$result = admin_execute_action($conn, $action, $payload, $actor['userId'], $idempotencyKey);
savora_json($result, ($result['ok'] ?? false) ? 200 : 422);
