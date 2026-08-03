<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/request_security.php';
require_once __DIR__ . '/../lib/services/review_service.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$actor = savora_request_actor($conn, ['customer', 'restaurant']);
$userId = (int) $actor['userId']; $role = (string) $actor['role'];
if ($method === 'GET') {
    $response = $role === 'customer' ? reviews_for_customer($conn, $userId) : reviews_for_restaurant($conn, $userId);
    $status = (int) ($response['status'] ?? 200); unset($response['status']);
    savora_json($response, $status);
}
if ($method !== 'POST') savora_error(405, 'Method not allowed.');

try { savora_require_csrf(['X-CSRF-Token' => (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')]); }
catch (InvalidArgumentException) { savora_error(403, 'Secure session expired.'); }
try { $idempotencyKey = savora_require_idempotency_key(['Idempotency-Key' => (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')]); }
catch (InvalidArgumentException) { savora_error(422, 'Idempotency key required.'); }
try { $body = savora_read_json(); }
catch (JsonException) { savora_error(400, 'Invalid JSON.'); }

$action = trim((string) ($body['action'] ?? ''));
$payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
$payload['version'] = (int) ($payload['version'] ?? $body['version'] ?? 0);
$requestHash = savora_idempotency_hash($action, $payload);
savora_idempotency_lock($conn, $userId, $idempotencyKey);
$response = null; $httpError = null;
try {
    $stored = savora_idempotency_find($conn, $userId, $idempotencyKey, $action, $requestHash);
    $response = $stored ?? review_execute_action($conn, $userId, $role, $action, $payload, (int) $payload['version'], $idempotencyKey);
} catch (SavoraIdempotencyConflict) { $httpError = [409, 'Idempotency key was already used for a different request.']; }
catch (JsonException) { $httpError = [500, 'Stored response is invalid.']; }
catch (Throwable) { $httpError = [500, 'Review request could not be completed.']; }
finally { savora_idempotency_unlock($conn, $userId, $idempotencyKey); }
if ($httpError !== null) savora_error($httpError[0], $httpError[1]);
$status = (int) ($response['status'] ?? 200); unset($response['status']);
savora_json($response, $status);
