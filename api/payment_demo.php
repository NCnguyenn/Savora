<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/environment.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/idempotency.php';
require_once __DIR__ . '/../lib/request_security.php';
require_once __DIR__ . '/../lib/services/payment_confirmation_service.php';

$actor = savora_request_actor($conn, ['customer']);
if (!savora_demo_mode()) savora_error(404, 'Demo payment is unavailable.');
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    savora_error(405, 'Method not allowed.');
}
try {
    savora_require_csrf(['X-CSRF-Token' => (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')]);
} catch (InvalidArgumentException) {
    savora_error(403, 'Secure session expired.');
}
try {
    $body = savora_read_json();
} catch (JsonException) {
    savora_error(400, 'Invalid JSON.');
}
try {
    $idempotencyKey = savora_require_idempotency_key([
        'Idempotency-Key' => (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''),
    ]);
} catch (InvalidArgumentException) {
    savora_error(422, 'Idempotency key required.');
}

$action = trim((string) ($body['action'] ?? ''));
if ($action !== 'simulate_success') savora_error(422, 'Unsupported demo payment action.');
$payload = is_array($body['payload'] ?? null) ? $body['payload'] : $body;
$customerUserId = (int) $actor['userId'];
$locked = false;
$response = null;
$httpError = null;
try {
    savora_idempotency_lock($conn, $customerUserId, $idempotencyKey);
    $locked = true;
    $response = payment_simulate_customer_success(
        $conn,
        $customerUserId,
        (string) ($payload['referenceCode'] ?? ''),
        $idempotencyKey
    );
} catch (SavoraIdempotencyConflict) {
    $httpError = [409, 'Idempotency key was already used for a different demo payment request.'];
} catch (Throwable) {
    $httpError = [500, 'Demo payment could not be completed.'];
} finally {
    if ($locked) savora_idempotency_unlock($conn, $customerUserId, $idempotencyKey);
}
if ($httpError !== null) savora_error($httpError[0], $httpError[1]);
$status = (int) ($response['status'] ?? 500);
unset($response['status']);
savora_json($response, $status);
