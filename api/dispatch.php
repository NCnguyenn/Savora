<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/request_security.php';
require_once __DIR__ . '/../lib/idempotency.php';
require_once __DIR__ . '/../lib/services/dispatch_service.php';
require_once __DIR__ . '/../lib/services/delivery_service.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$actor = savora_request_actor($conn, ['driver', 'admin']);
$actorId = (int) $actor['userId'];
$role = (string) $actor['role'];

if ($method === 'GET') {
    if ($role === 'driver') {
        $profile = dispatch_repository_driver_profile($conn, $actorId);
        $location = dispatch_repository_driver_location($conn, $actorId);
        savora_json(['ok' => true, 'data' => [
            'offers' => dispatch_offers_for_driver($conn, $actorId),
            'availabilityStatus' => (string) ($profile['availability_status'] ?? 'offline'),
            'eligibilityStatus' => (string) ($profile['eligibility_status'] ?? 'pending'),
            'version' => (int) ($profile['version'] ?? 0),
            'location' => $location === [] ? null : [
                'latitude' => (float) $location['latitude'], 'longitude' => (float) $location['longitude'],
                'accuracyMeters' => $location['accuracy_meters'] === null ? null : (float) $location['accuracy_meters'],
                'recordedAt' => (string) $location['recorded_at'], 'version' => (int) $location['version'],
            ],
        ], 'csrfToken' => admin_csrf_token()]);
    }
    $dispatchId = (int) ($_GET['dispatchId'] ?? 0);
    if ($dispatchId <= 0) savora_error(422, 'Admin dispatchId is required.');
    $dispatch = dispatch_repository_dispatch($conn, $dispatchId);
    if ($dispatch === []) savora_error(404, 'Dispatch not found.');
    savora_json(['ok' => true, 'data' => ['dispatch' => $dispatch, 'offer' => dispatch_repository_active_offer_for_dispatch($conn, $dispatchId)], 'csrfToken' => admin_csrf_token()]);
}

if ($method !== 'POST') savora_error(405, 'Method not allowed.');
try { savora_require_csrf(['X-CSRF-Token' => (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')]); }
catch (InvalidArgumentException) { savora_error(403, 'Secure session expired.'); }
try { $body = savora_read_json(); }
catch (JsonException) { savora_error(400, 'Invalid JSON.'); }
$command = trim((string) ($body['command'] ?? $body['action'] ?? ''));
$payload = is_array($body['payload'] ?? null) ? $body['payload'] : $body;
try { $idempotencyKey = savora_require_idempotency_key(['Idempotency-Key' => (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')]); }
catch (InvalidArgumentException) { savora_error(422, 'Idempotency key required.'); }

try { savora_idempotency_lock($conn, $actorId, $idempotencyKey); }
catch (Throwable) { savora_error(503, 'Dispatch command is busy; retry with the same idempotency key.'); }

try {
    $result = match ($command) {
        'set_availability', 'driver_set_availability' => $role === 'driver'
            ? driver_set_availability($conn, $actorId, (string) ($payload['availabilityStatus'] ?? $payload['status'] ?? ''), isset($payload['latitude']) ? (float) $payload['latitude'] : null, isset($payload['longitude']) ? (float) $payload['longitude'] : null, isset($payload['accuracyMeters']) ? (float) $payload['accuracyMeters'] : null, isset($payload['recordedAt']) ? (string) $payload['recordedAt'] : null, $idempotencyKey)
            : dispatch_result(false, 403, 'Only a Driver can change availability.'),
        'demo_start_shift' => $role === 'driver'
            ? driver_start_demo_shift($conn, $actorId, $idempotencyKey)
            : dispatch_result(false, 403, 'Only a Driver can start a demo shift.'),
        'accept_offer', 'dispatch_accept_offer' => $role === 'driver'
            ? dispatch_accept_offer($conn, $actorId, (string) ($payload['offerReference'] ?? $payload['offer_reference'] ?? ''), $idempotencyKey)
            : dispatch_result(false, 403, 'Only a Driver can accept an offer.'),
        'decline_offer', 'dispatch_decline_offer' => $role === 'driver'
            ? dispatch_decline_offer($conn, $actorId, (string) ($payload['offerReference'] ?? $payload['offer_reference'] ?? ''), $idempotencyKey, (string) ($payload['reason'] ?? ''))
            : dispatch_result(false, 403, 'Only a Driver can decline an offer.'),
        'update_location', 'driver_update_location' => $role === 'driver'
            ? driver_update_location($conn, $actorId, (float) ($payload['latitude'] ?? 0), (float) ($payload['longitude'] ?? 0), isset($payload['accuracyMeters']) ? (float) $payload['accuracyMeters'] : null, (string) ($payload['recordedAt'] ?? ''), (int) ($payload['expectedVersion'] ?? $payload['version'] ?? 0), $idempotencyKey, isset($payload['deliveryId']) ? (int) $payload['deliveryId'] : null)
            : dispatch_result(false, 403, 'Only a Driver can update location.'),
        'record_arrival', 'delivery_record_arrival' => $role === 'driver'
            ? delivery_record_arrival($conn, $actorId, (int) ($payload['deliveryId'] ?? 0), (int) ($payload['expectedVersion'] ?? $payload['version'] ?? 0), $idempotencyKey)
            : dispatch_result(false, 403, 'Only a Driver can record arrival.'),
        'record_pickup', 'delivery_record_pickup' => $role === 'driver'
            ? delivery_record_pickup($conn, $actorId, (int) ($payload['deliveryId'] ?? 0), (int) ($payload['expectedVersion'] ?? $payload['version'] ?? 0), $idempotencyKey)
            : dispatch_result(false, 403, 'Only a Driver can record pickup.'),
        'demo_start_delivery' => $role === 'driver'
            ? demo_route_start($conn, $actorId, (int) ($payload['deliveryId'] ?? 0), (int) ($payload['expectedVersion'] ?? 0), $idempotencyKey)
            : dispatch_result(false, 403, 'Only a Driver can start demo delivery.'),
        'record_completion', 'delivery_record_completion' => $role === 'driver'
            ? delivery_record_completion($conn, $actorId, (int) ($payload['deliveryId'] ?? 0), (int) ($payload['expectedVersion'] ?? $payload['version'] ?? 0), $idempotencyKey, is_array($payload['evidenceIds'] ?? null) ? $payload['evidenceIds'] : [])
            : dispatch_result(false, 403, 'Only a Driver can record completion.'),
        'fail_delivery', 'delivery_fail' => $role === 'driver'
            ? delivery_fail($conn, $actorId, (int) ($payload['deliveryId'] ?? 0), (int) ($payload['expectedVersion'] ?? $payload['version'] ?? 0), $idempotencyKey, (string) ($payload['reason'] ?? ''))
            : dispatch_result(false, 403, 'Only a Driver can fail a delivery.'),
        'offer_next_driver', 'dispatch_offer_next_driver' => $role === 'admin'
            ? dispatch_offer_next_driver($conn, (int) ($payload['dispatchId'] ?? $payload['dispatch_id'] ?? 0), $actorId)
            : dispatch_result(false, 403, 'Only an Admin can start dispatching an offer.'),
        'expire_offers', 'dispatch_expire_offers' => $role === 'admin'
            ? dispatch_expire_offers($conn, isset($payload['dispatchId']) ? (int) $payload['dispatchId'] : null, $actorId)
            : dispatch_result(false, 403, 'Only an Admin can expire offers.'),
        default => dispatch_result(false, 422, 'Unsupported dispatch command.'),
    };
} catch (SavoraIdempotencyConflict) {
    savora_idempotency_unlock($conn, $actorId, $idempotencyKey);
    savora_error(409, 'Idempotency key was already used for a different dispatch request.');
} catch (InvalidArgumentException $exception) {
    savora_idempotency_unlock($conn, $actorId, $idempotencyKey);
    savora_error(422, $exception->getMessage());
} catch (Throwable) {
    savora_idempotency_unlock($conn, $actorId, $idempotencyKey);
    savora_error(500, 'Dispatch command could not be completed.');
}

savora_idempotency_unlock($conn, $actorId, $idempotencyKey);

$status = (int) ($result['status'] ?? 200);
unset($result['status']);
savora_json($result, $status);
