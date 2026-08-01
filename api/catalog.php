<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/request_security.php';
require_once __DIR__ . '/../lib/services/catalog_service.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    if ((string) ($_GET['scope'] ?? '') === 'restaurant') {
        $actor = savora_request_actor($conn, ['restaurant']);
        $snapshot = catalog_for_restaurant($conn, (int) $actor['userId']);
        $status = (int) ($snapshot['status'] ?? 200);
        unset($snapshot['status']);
        if (($snapshot['ok'] ?? false) !== true) {
            savora_json($snapshot, $status);
        }
        unset($snapshot['ok']);
        savora_json(['ok' => true, 'data' => $snapshot], $status);
    }
    $items = catalog_for_customer($conn, [
        'q' => (string) ($_GET['q'] ?? ''),
        'restaurant' => (string) ($_GET['restaurant'] ?? ''),
    ]);
    savora_json(['ok' => true, 'data' => $items]);
}

if ($method !== 'POST') {
    savora_error(405, 'Method not allowed.');
}

$actor = savora_request_actor($conn, ['restaurant']);
try {
    savora_require_csrf(['X-CSRF-Token' => (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')]);
} catch (InvalidArgumentException) {
    savora_error(403, 'Secure session expired.');
}
try {
    $idempotencyKey = savora_require_idempotency_key(['Idempotency-Key' => (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')]);
} catch (InvalidArgumentException) {
    savora_error(422, 'Idempotency key required.');
}
try {
    $body = savora_read_json();
} catch (JsonException) {
    savora_error(400, 'Invalid JSON.');
}

$action = trim((string) ($body['action'] ?? ''));
$payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
$expectedVersion = (int) ($payload['version'] ?? $body['version'] ?? 0);
$payload['version'] = $expectedVersion;
$requestHash = savora_idempotency_hash($action, $payload);

savora_idempotency_lock($conn, (int) $actor['userId'], $idempotencyKey);
$response = null;
$httpError = null;
try {
    $stored = savora_idempotency_find($conn, (int) $actor['userId'], $idempotencyKey, $action, $requestHash);
    $response = $stored ?? catalog_execute_action(
        $conn,
        (int) $actor['userId'],
        $action,
        $payload,
        $expectedVersion,
        $idempotencyKey
    );
} catch (SavoraIdempotencyConflict) {
    $httpError = [409, 'Idempotency key was already used for a different request.'];
} catch (JsonException) {
    $httpError = [500, 'Stored response is invalid.'];
} catch (Throwable) {
    $httpError = [500, 'Catalog request could not be completed.'];
} finally {
    savora_idempotency_unlock($conn, (int) $actor['userId'], $idempotencyKey);
}

if ($httpError !== null) {
    savora_error($httpError[0], $httpError[1]);
}

$status = (int) ($response['status'] ?? 200);
unset($response['status']);
savora_json($response, $status);
