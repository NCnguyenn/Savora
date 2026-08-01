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

savora_require_csrf(['X-CSRF-Token' => (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')]);

$idempotencyKey = mb_substr(trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')), 0, 100);
if ($idempotencyKey === '') {
    savora_error(422, 'An idempotency key is required.', [], admin_reference_id());
}

$decoded = savora_read_json();

$action = mb_substr(trim((string) ($decoded['action'] ?? '')), 0, 100);
$payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];
$result = admin_execute_action($conn, $action, $payload, $actor['userId'], $idempotencyKey);
savora_json($result, ($result['ok'] ?? false) ? 200 : 422);
