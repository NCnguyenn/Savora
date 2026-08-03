<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/request_security.php';
require_once __DIR__ . '/../lib/services/pricing_service.php';
require_once __DIR__ . '/../lib/services/order_service.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') savora_error(405, 'Method not allowed.');
$actor = savora_request_actor($conn, ['customer']);
try { savora_require_csrf(['X-CSRF-Token' => (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')]); }
catch (InvalidArgumentException) { savora_error(403, 'Secure session expired.'); }
try { $body = savora_read_json(); }
catch (JsonException) { savora_error(400, 'Invalid JSON.'); }

$action = trim((string) ($body['action'] ?? ''));
$payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
if ($action === 'quote') {
    $cart = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $response = pricing_create_quote(
        $conn,
        (int) $actor['userId'],
        $cart,
        (string) ($payload['addressPublicId'] ?? ''),
        array_key_exists('promotionCode', $payload) ? (string) $payload['promotionCode'] : null
    );
} elseif ($action === 'place_order') {
    try { $idempotencyKey = savora_require_idempotency_key(['Idempotency-Key' => (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')]); }
    catch (InvalidArgumentException) { savora_error(422, 'Idempotency key required.'); }
    $userId = (int) $actor['userId'];
    savora_idempotency_lock($conn, $userId, $idempotencyKey);
    try {
        $response = order_place_from_quote($conn, $userId, (string) ($payload['quoteId'] ?? ''), (string) ($payload['paymentMethod'] ?? ''), $idempotencyKey, (string) ($payload['deliveryNote'] ?? ''));
    } catch (SavoraIdempotencyConflict) {
        savora_idempotency_unlock($conn, $userId, $idempotencyKey);
        savora_error(409, 'Idempotency key was already used for a different checkout request.');
    } catch (Throwable) {
        savora_idempotency_unlock($conn, $userId, $idempotencyKey);
        savora_error(500, 'Checkout request could not be completed.');
    }
    savora_idempotency_unlock($conn, $userId, $idempotencyKey);
} else {
    savora_error(422, 'Unsupported checkout action.');
}
$status = (int) ($response['status'] ?? 200);
unset($response['status']);
savora_json($response, $status);
