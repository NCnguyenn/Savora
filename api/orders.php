<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/request_security.php';
require_once __DIR__ . '/../lib/services/order_query_service.php';
require_once __DIR__ . '/../lib/services/order_transition_service.php';
require_once __DIR__ . '/../lib/services/customer_receipt_service.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$actor = savora_request_actor($conn, ['customer', 'restaurant', 'driver', 'admin']);
if ($method === 'POST') {
    try { savora_require_csrf(['X-CSRF-Token' => (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')]); }
    catch (InvalidArgumentException) { savora_error(403, 'Secure session expired.'); }
    try { $body = savora_read_json(); }
    catch (JsonException) { savora_error(400, 'Invalid JSON.'); }
    $action = trim((string) ($body['action'] ?? ''));
    if (!in_array($action, ['confirm_received', 'transition'], true)) savora_error(422, 'Unsupported order action.');
    if ($action === 'confirm_received' && (string) $actor['role'] !== 'customer') savora_error(403, 'Only the Customer can confirm receipt.');
    $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
    try { $idempotencyKey = savora_require_idempotency_key(['Idempotency-Key' => (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')]); }
    catch (InvalidArgumentException) { savora_error(422, 'Idempotency key required.'); }
    $userId = (int) $actor['userId']; $locked = false;
    try {
        savora_idempotency_lock($conn, $userId, $idempotencyKey); $locked = true;
        if ($action === 'confirm_received') {
            $response = customer_confirm_receipt(
                $conn,
                (int) $actor['userId'],
                (string) ($payload['referenceCode'] ?? ''),
                (int) ($payload['expectedVersion'] ?? 0),
                $idempotencyKey
            );
        } elseif ($action === 'transition') {
            $response = order_transition(
                $conn,
                $actor,
                (string) ($payload['referenceCode'] ?? $payload['reference_code'] ?? ''),
                (string) ($payload['nextStatus'] ?? $payload['status'] ?? ''),
                (int) ($payload['expectedVersion'] ?? $payload['version'] ?? 0),
                $idempotencyKey,
                (string) ($payload['reason'] ?? '')
            );
        } else {
            savora_error(422, 'Unsupported order action.');
        }
    } catch (SavoraIdempotencyConflict) {
        if ($locked) savora_idempotency_unlock($conn, $userId, $idempotencyKey);
        savora_error(409, 'Idempotency key was already used for a different request.');
    } catch (InvalidArgumentException $exception) {
        if ($locked) savora_idempotency_unlock($conn, $userId, $idempotencyKey);
        savora_error(422, $exception->getMessage());
    } catch (Throwable) {
        if ($locked) savora_idempotency_unlock($conn, $userId, $idempotencyKey);
        savora_error(500, 'Order command could not be completed.');
    }
    if ($locked) savora_idempotency_unlock($conn, $userId, $idempotencyKey);
    $status = (int) ($response['status'] ?? 200); unset($response['status']);
    savora_json($response, $status);
}
if ($method !== 'GET') savora_error(405, 'Method not allowed.');
$filters = [
    'status' => (string) ($_GET['status'] ?? ''),
    'from' => (string) ($_GET['from'] ?? ''),
    'to' => (string) ($_GET['to'] ?? ''),
    'page' => (int) ($_GET['page'] ?? 1),
    'pageSize' => (int) ($_GET['pageSize'] ?? 20),
];

try {
    $role = (string) $actor['role'];
    if ($role === 'customer') {
        $data = orders_for_customer($conn, (int) $actor['userId'], $filters);
    } elseif ($role === 'restaurant') {
        $data = orders_for_restaurant($conn, (int) $actor['userId'], $filters);
    } elseif ($role === 'driver') {
        $data = orders_for_driver($conn, (int) $actor['userId'], $filters);
    } else {
        $orderId = (int) ($_GET['orderId'] ?? 0);
        if ($orderId <= 0) savora_error(422, 'Admin orderId is required.');
        $order = order_for_admin($conn, $orderId);
        if ($order === []) savora_error(404, 'Order not found.');
        $data = ['orders' => [$order], 'pagination' => ['page' => 1, 'pageSize' => 1, 'total' => 1, 'pages' => 1]];
    }
} catch (InvalidArgumentException $exception) {
    savora_error(422, $exception->getMessage());
} catch (Throwable) {
    savora_error(500, 'Orders are temporarily unavailable.');
}

$data['csrfToken'] = admin_csrf_token();
savora_json(['ok' => true, 'data' => $data]);
