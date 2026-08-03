<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/request_security.php';
require_once __DIR__ . '/../lib/idempotency.php';
require_once __DIR__ . '/../lib/profile_locations.php';
require_once __DIR__ . '/../lib/location_service.php';

$actor = savora_request_actor($conn, ['customer', 'driver', 'restaurant']);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$userId = (int) $actor['userId'];
$role = (string) $actor['role'];

if ($method === 'GET') savora_json(['ok' => true, 'data' => ['location' => savora_profile_location($conn, $role, $userId)]]);
if ($method !== 'POST') savora_error(405, 'Method not allowed.');
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) savora_error(413, 'Location request is too large.');
try { savora_require_csrf(['X-CSRF-Token' => (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')]); }
catch (InvalidArgumentException) { savora_error(403, 'Secure session expired.'); }
try { $idempotencyKey = savora_require_idempotency_key(['Idempotency-Key' => (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')]); }
catch (InvalidArgumentException) { savora_error(422, 'Idempotency key required.'); }
try { $body = savora_read_json(); }
catch (JsonException) { savora_error(400, 'Invalid JSON.'); }

$action = trim((string) ($body['action'] ?? ''));
$payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
if (!in_array($action, ['save_gps_location', 'save_manual_location'], true)) savora_error(422, 'Unsupported location action.');
$requestHash = savora_idempotency_hash($action, $payload);
$response = null;
$httpError = null;
$transaction = false;

savora_idempotency_lock($conn, $userId, $idempotencyKey);
try {
    $stored = savora_idempotency_find($conn, $userId, $idempotencyKey, $action, $requestHash);
    if ($stored !== null) {
        $response = $stored;
    } else {
        $resolved = null;
        $coordinates = null;
        if ($action === 'save_gps_location') {
            $coordinates = savora_validate_coordinates($payload['latitude'] ?? null, $payload['longitude'] ?? null);
            $resolved = savora_reverse_geocode($coordinates['latitude'], $coordinates['longitude']);
        }
        $conn->begin_transaction();
        $transaction = true;
        $location = $action === 'save_gps_location'
            ? savora_save_gps_location($conn, $role, $userId, $resolved ?? [], (float) $coordinates['latitude'], (float) $coordinates['longitude'])
            : savora_save_manual_location($conn, $role, $userId, $payload);
        $response = ['ok' => true, 'status' => 200, 'message' => 'Location saved.', 'data' => ['location' => $location]];
        savora_idempotency_store($conn, $userId, $idempotencyKey, $action, $requestHash, $response);
        $conn->commit();
        $transaction = false;
    }
} catch (SavoraIdempotencyConflict) {
    $httpError = [409, 'Idempotency key was already used for a different location request.'];
} catch (InvalidArgumentException $exception) {
    $httpError = [422, $exception->getMessage()];
} catch (RuntimeException $exception) {
    $temporary = str_contains($exception->getMessage(), 'Automatic address lookup') || str_contains($exception->getMessage(), 'No readable address');
    $httpError = [$temporary ? 503 : 409, $exception->getMessage()];
} catch (Throwable) {
    $httpError = [500, 'Location could not be saved.'];
} finally {
    if ($transaction) $conn->rollback();
    savora_idempotency_unlock($conn, $userId, $idempotencyKey);
}

if ($httpError !== null) savora_error($httpError[0], $httpError[1]);
$status = (int) ($response['status'] ?? 200);
unset($response['status']);
savora_json($response, $status);
