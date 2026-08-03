<?php
declare(strict_types=1);

require_once __DIR__ . '/../idempotency.php';
require_once __DIR__ . '/audit_service.php';
require_once __DIR__ . '/notification_service.php';
require_once __DIR__ . '/../repositories/order_repository.php';
require_once __DIR__ . '/../repositories/delivery_repository.php';
require_once __DIR__ . '/dispatch_service.php';

function delivery_result(bool $ok, int $status, string $message, array $data = []): array
{
    $result = ['ok' => $ok, 'status' => $status, 'message' => $message];
    if ($data !== []) $result['data'] = $data;
    return $result;
}

function delivery_server_now(mysqli $conn): string
{
    return (string) ($conn->query('SELECT NOW() AS now')->fetch_assoc()['now'] ?? date('Y-m-d H:i:s'));
}

function delivery_idempotent_result(mysqli $conn, int $actorId, string $key, string $action, array $payload): ?array
{
    if ($key === '') return null;
    return savora_idempotency_find($conn, $actorId, $key, $action, savora_idempotency_hash($action, $payload));
}

function delivery_store_result(mysqli $conn, int $actorId, string $key, string $action, array $payload, array $result): void
{
    if ($key !== '') savora_idempotency_store($conn, $actorId, $key, $action, savora_idempotency_hash($action, $payload), $result);
}

function driver_update_location(mysqli $conn, int $driverUserId, float $latitude, float $longitude, ?float $accuracyMeters, string $recordedAt, int $expectedVersion = 0, string $idempotencyKey = '', ?int $deliveryId = null): array
{
    if ($driverUserId <= 0 || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) throw new InvalidArgumentException('Driver and valid coordinates are required.');
    if ($accuracyMeters !== null && ($accuracyMeters < 0 || $accuracyMeters > 100000)) throw new InvalidArgumentException('Location accuracy is invalid.');
    $payload = ['driverUserId' => $driverUserId, 'latitude' => $latitude, 'longitude' => $longitude, 'accuracyMeters' => $accuracyMeters, 'recordedAt' => $recordedAt, 'expectedVersion' => $expectedVersion, 'deliveryId' => $deliveryId];
    $conn->begin_transaction();
    try {
        $action = 'driver_update_location'; $stored = delivery_idempotent_result($conn, $driverUserId, $idempotencyKey, $action, $payload);
        if ($stored !== null) { $conn->commit(); return $stored; }
        $clock = $conn->prepare('SELECT (? < DATE_SUB(NOW(), INTERVAL 5 MINUTE)) AS stale, (? > NOW()) AS future'); $clock->bind_param('ss', $recordedAt, $recordedAt); $clock->execute(); $clockRow = $clock->get_result()->fetch_assoc() ?: []; $clock->close();
        if ((int) ($clockRow['stale'] ?? 1) === 1 || (int) ($clockRow['future'] ?? 0) === 1) $result = delivery_result(false, 422, 'Location timestamp is stale or invalid.');
        else {
            $profile = delivery_repository_driver_profile($conn, $driverUserId, true);
            if ($profile === [] || (string) $profile['user_status'] !== 'active' || (string) $profile['eligibility_status'] !== 'eligible' || !in_array((string) $profile['availability_status'], ['online', 'busy'], true)) $result = delivery_result(false, 403, 'Driver is not authorized to send location.');
            else {
                if ($deliveryId !== null) {
                    $delivery = delivery_repository_delivery($conn, $deliveryId, true);
                    if ($delivery === [] || (int) $delivery['driver_user_id'] !== $driverUserId || $delivery['superseded_at'] !== null || !in_array((string) $delivery['status'], ['assigned','arrived','picked_up'], true)) $result = delivery_result(false, 403, 'Driver is not assigned to this delivery.');
                }
                if (!isset($result)) {
                    $existing = delivery_repository_location($conn, $driverUserId, true);
                    $version = $expectedVersion > 0 ? $expectedVersion : (int) ($existing['version'] ?? 0);
                    if (!delivery_repository_set_location($conn, $driverUserId, $latitude, $longitude, $accuracyMeters, $recordedAt, $version)) $result = delivery_result(false, 409, 'Location changed. Refresh before retrying.');
                    else $result = delivery_result(true, 200, 'Driver location updated.', ['driverUserId' => $driverUserId, 'latitude' => $latitude, 'longitude' => $longitude, 'recordedAt' => $recordedAt, 'serverReceivedAt' => delivery_server_now($conn), 'version' => $version + 1]);
                }
            }
        }
        delivery_store_result($conn, $driverUserId, $idempotencyKey, $action, $payload, $result); $conn->commit(); return $result;
    } catch (Throwable $exception) { $conn->rollback(); if ($exception instanceof SavoraIdempotencyConflict) throw $exception; throw $exception; }
}

function delivery_transition(mysqli $conn, int $driverUserId, int $deliveryId, int $expectedVersion, string $idempotencyKey, string $fromStatus, string $nextStatus, ?string $orderStatus, array $evidence = [], string $reason = ''): array
{
    if ($driverUserId <= 0 || $deliveryId <= 0 || $expectedVersion < 1 || $idempotencyKey === '') throw new InvalidArgumentException('Driver, delivery, version and idempotency key are required.');
    $action = 'delivery_record_' . $nextStatus; $payload = ['deliveryId' => $deliveryId, 'expectedVersion' => $expectedVersion, 'evidence' => $evidence, 'reason' => $reason];
    $conn->begin_transaction();
    try {
        $stored = delivery_idempotent_result($conn, $driverUserId, $idempotencyKey, $action, $payload); if ($stored !== null) { $conn->commit(); return $stored; }
        $delivery = delivery_repository_delivery($conn, $deliveryId, true);
        if ($delivery === []) $result = delivery_result(false, 404, 'Delivery not found.');
        elseif ((int) $delivery['driver_user_id'] !== $driverUserId || $delivery['superseded_at'] !== null) $result = delivery_result(false, 403, 'Driver is not assigned to this delivery.');
        elseif ((string) $delivery['status'] !== $fromStatus) $result = delivery_result(false, 409, 'Delivery milestone is out of order.');
        elseif ((int) $delivery['version'] !== $expectedVersion) $result = delivery_result(false, 409, 'Delivery changed. Refresh before retrying.');
        elseif (in_array((string) $delivery['order_status'], ['cancelled','refunded','delivered'], true)) $result = delivery_result(false, 409, 'Order no longer accepts delivery updates.');
        elseif ($nextStatus === 'delivered' && (int) $delivery['proof_required'] === 1 && $evidence === []) $result = delivery_result(false, 422, 'Proof of delivery is required.');
        else {
            $validEvidence = [];
            foreach ($evidence as $item) {
                if (!is_array($item) || !preg_match('/\A(photo|signature|document)\z/', (string) ($item['type'] ?? '')) || !preg_match('/\A(?:image\/(?:jpeg|png)|application\/pdf)\z/', (string) ($item['mimeType'] ?? '')) || !preg_match('/\A[a-f0-9]{64}\z/i', (string) ($item['sha256'] ?? '')) || !preg_match('/\A(?![A-Za-z]:|\/|\\\\)[A-Za-z0-9._\/-]{1,500}\z/', (string) ($item['storedPath'] ?? '')) || (int) ($item['sizeBytes'] ?? 0) <= 0 || (int) ($item['sizeBytes'] ?? 0) > 20 * 1024 * 1024) { $result = delivery_result(false, 422, 'Proof metadata is invalid.'); break; }
                $validEvidence[] = $item;
            }
            if (!isset($result)) {
                if (!delivery_repository_update_status($conn, $deliveryId, $nextStatus, $expectedVersion, $fromStatus === 'assigned', $nextStatus === 'delivered')) $result = delivery_result(false, 409, 'Delivery changed. Refresh before retrying.');
                else {
                    delivery_repository_add_milestone($conn, $deliveryId, $nextStatus, $driverUserId, $reason);
                    foreach ($validEvidence as $item) delivery_repository_add_evidence($conn, $deliveryId, $driverUserId, $item);
                    $nextOrderVersion = (int) $delivery['order_version'];
                    if ($orderStatus !== null) {
                        $orderUpdate = $conn->prepare('UPDATE orders SET status=?,version=version+1 WHERE id=? AND version=?'); $orderId = (int) $delivery['order_id']; $orderUpdate->bind_param('sii', $orderStatus, $orderId, $nextOrderVersion); $orderUpdate->execute(); $orderUpdate->close();
                        order_repository_insert_history_event($conn, $orderId, $orderStatus, 'driver', $driverUserId, 'Delivery milestone recorded.'); $nextOrderVersion++;
                    }
                    if ($nextStatus === 'delivered' && (string) $delivery['payment_method'] === 'cash') { $paid = $conn->prepare("UPDATE payments SET status='paid',paid_at=NOW(),version=version+1 WHERE order_id=? AND status<>'paid'"); $paidOrder = (int) $delivery['order_id']; $paid->bind_param('i', $paidOrder); $paid->execute(); $paid->close(); }
                    if ($nextStatus === 'delivered') { $profile = $conn->prepare("UPDATE driver_profiles SET availability_status='online',version=version+1 WHERE user_id=?"); $profile->bind_param('i', $driverUserId); $profile->execute(); $profile->close(); }
                    notification_queue($conn, (int) $delivery['customer_user_id'], 'delivery_' . $nextStatus, 'Delivery updated', 'Your order ' . (string) $delivery['reference_code'] . ' is now ' . str_replace('_', ' ', $nextStatus) . '.', 'order', (int) $delivery['order_id']);
                    audit_append($conn, $driverUserId, 'delivery_' . $nextStatus, 'delivery', $deliveryId, ['status' => $fromStatus, 'version' => $expectedVersion], ['status' => $nextStatus, 'version' => $expectedVersion + 1], $reason, 'DLV-' . strtoupper(bin2hex(random_bytes(5))));
                    $result = delivery_result(true, 200, 'Delivery milestone recorded.', ['deliveryId' => $deliveryId, 'status' => $nextStatus, 'version' => $expectedVersion + 1, 'orderStatus' => $orderStatus ?? (string) $delivery['order_status'], 'serverRecordedAt' => delivery_server_now($conn), 'evidenceCount' => count($validEvidence)]);
                }
            }
        }
        delivery_store_result($conn, $driverUserId, $idempotencyKey, $action, $payload, $result); $conn->commit(); return $result;
    } catch (Throwable $exception) { $conn->rollback(); if ($exception instanceof SavoraIdempotencyConflict) throw $exception; throw $exception; }
}

function delivery_record_arrival(mysqli $conn, int $driverUserId, int $deliveryId, int $expectedVersion, string $idempotencyKey): array
{ return delivery_transition($conn, $driverUserId, $deliveryId, $expectedVersion, $idempotencyKey, 'assigned', 'arrived', null); }

function delivery_record_pickup(mysqli $conn, int $driverUserId, int $deliveryId, int $expectedVersion, string $idempotencyKey): array
{ return delivery_transition($conn, $driverUserId, $deliveryId, $expectedVersion, $idempotencyKey, 'arrived', 'picked_up', 'picked_up'); }

function delivery_record_completion(mysqli $conn, int $driverUserId, int $deliveryId, int $expectedVersion, string $idempotencyKey, array $evidence = []): array
{ return delivery_transition($conn, $driverUserId, $deliveryId, $expectedVersion, $idempotencyKey, 'picked_up', 'delivered', 'delivered', $evidence); }

function delivery_fail(mysqli $conn, int $driverUserId, int $deliveryId, int $expectedVersion, string $idempotencyKey, string $reason): array
{ return delivery_transition($conn, $driverUserId, $deliveryId, $expectedVersion, $idempotencyKey, 'assigned', 'failed', 'ready_for_pickup', [], mb_substr(trim($reason), 0, 500)); }
