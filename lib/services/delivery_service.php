<?php
declare(strict_types=1);

require_once __DIR__ . '/../idempotency.php';
require_once __DIR__ . '/audit_service.php';
require_once __DIR__ . '/notification_service.php';
require_once __DIR__ . '/../repositories/order_repository.php';
require_once __DIR__ . '/../repositories/delivery_repository.php';
require_once __DIR__ . '/dispatch_service.php';
require_once __DIR__ . '/media_service.php';

const SAVORA_DELIVERY_EVIDENCE_MAX_BYTES = 20 * 1024 * 1024;
const SAVORA_DELIVERY_EVIDENCE_MIMES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];

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

function delivery_store_evidence_upload(mysqli $conn, int $driverUserId, int $deliveryId, string $type, array $file, string $idempotencyKey = ''): array
{
    if ($driverUserId <= 0 || $deliveryId <= 0 || !in_array($type, ['photo', 'signature', 'document'], true)) {
        throw new InvalidArgumentException('Delivery evidence scope is invalid.');
    }
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Delivery evidence upload failed.');
    $source = (string) ($file['tmp_name'] ?? '');
    if ($source === '' || !is_file($source) || (PHP_SAPI !== 'cli' && !is_uploaded_file($source))) throw new InvalidArgumentException('Delivery evidence upload is invalid.');
    $size = (int) filesize($source);
    if ($size <= 0 || $size > SAVORA_DELIVERY_EVIDENCE_MAX_BYTES) throw new InvalidArgumentException('Delivery evidence must be 20 MB or smaller.');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $source) : '';
    if ($finfo) finfo_close($finfo);
    if (!isset(SAVORA_DELIVERY_EVIDENCE_MIMES[$mime])) throw new InvalidArgumentException('Delivery evidence must be a JPEG, PNG, WebP, or PDF file.');
    $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $expectedExtension = SAVORA_DELIVERY_EVIDENCE_MIMES[$mime];
    $extensionMatches = $mime === 'image/jpeg' ? in_array($extension, ['jpg', 'jpeg'], true) : $extension === $expectedExtension;
    if (!$extensionMatches) throw new InvalidArgumentException('Delivery evidence extension does not match its content.');
    $sha256 = hash_file('sha256', $source);
    if (!is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/', $sha256)) throw new RuntimeException('Delivery evidence could not be hashed.');
    $action = 'upload_delivery_evidence';
    $payload = ['deliveryId' => $deliveryId, 'type' => $type, 'sha256' => $sha256, 'sizeBytes' => $size, 'mimeType' => $mime];

    $relative = 'delivery-evidence/' . date('Y/m') . '/' . bin2hex(random_bytes(18)) . '.' . $expectedExtension;
    $absolute = media_safe_absolute_path($relative);
    $directory = dirname($absolute);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Delivery evidence directory could not be created.');

    $conn->begin_transaction();
    try {
        if ($idempotencyKey !== '') {
            $stored = savora_idempotency_find($conn, $driverUserId, $idempotencyKey, $action, savora_idempotency_hash($action, $payload));
            if ($stored !== null) { $conn->commit(); return is_array($stored['data'] ?? null) ? $stored['data'] : []; }
        }
        $delivery = delivery_repository_delivery($conn, $deliveryId, true);
        if ($delivery === [] || (int) $delivery['driver_user_id'] !== $driverUserId || $delivery['superseded_at'] !== null) throw new RuntimeException('Driver is not assigned to this delivery.');
        if ((string) $delivery['status'] !== 'picked_up' || (string) $delivery['order_status'] !== 'picked_up') throw new RuntimeException('Proof can only be uploaded for a picked-up delivery.');
        $moved = PHP_SAPI === 'cli' ? rename($source, $absolute) : move_uploaded_file($source, $absolute);
        if (!$moved) throw new RuntimeException('Delivery evidence could not be stored.');
        @chmod($absolute, 0600);
        $evidenceId = delivery_repository_add_evidence($conn, $deliveryId, $driverUserId, [
            'type' => $type,
            'storedPath' => $relative,
            'mimeType' => $mime,
            'sizeBytes' => $size,
            'sha256' => $sha256,
            'capturedAt' => delivery_server_now($conn),
        ]);
        $result = ['evidenceId' => $evidenceId, 'type' => $type, 'mimeType' => $mime, 'sizeBytes' => $size, 'sha256' => $sha256];
        if ($idempotencyKey !== '') savora_idempotency_store($conn, $driverUserId, $idempotencyKey, $action, savora_idempotency_hash($action, $payload), ['ok' => true, 'status' => 201, 'message' => 'Delivery evidence uploaded.', 'data' => $result]);
        $conn->commit();
        return $result;
    } catch (Throwable $exception) {
        $conn->rollback();
        if (is_file($absolute)) @unlink($absolute);
        throw $exception;
    }
}

function delivery_verified_evidence(mysqli $conn, int $deliveryId, int $driverUserId, array $evidenceIds): array
{
    $ids = array_values(array_unique(array_filter(array_map(static fn (mixed $id): int => is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : 0, $evidenceIds), static fn (int $id): bool => $id > 0)));
    if (count($ids) !== count($evidenceIds)) throw new InvalidArgumentException('Proof identifiers are invalid.');
    $rows = delivery_repository_evidence_for_completion($conn, $deliveryId, $driverUserId, $ids);
    if (count($rows) !== count($ids)) throw new InvalidArgumentException('Proof does not belong to this delivery.');
    foreach ($rows as $row) {
        $absolute = media_safe_absolute_path((string) $row['stored_path']);
        $size = is_file($absolute) ? (int) filesize($absolute) : 0;
        $sha256 = $size > 0 ? hash_file('sha256', $absolute) : false;
        if ($size !== (int) $row['size_bytes'] || !is_string($sha256) || !hash_equals((string) $row['sha256'], $sha256)) {
            throw new RuntimeException('Stored proof could not be verified.');
        }
    }
    return $rows;
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
            try { if ($nextStatus === 'delivered' && $evidence !== []) $validEvidence = delivery_verified_evidence($conn, $deliveryId, $driverUserId, $evidence); }
            catch (InvalidArgumentException $exception) { $result = delivery_result(false, 422, $exception->getMessage()); }
            catch (RuntimeException $exception) { $result = delivery_result(false, 409, $exception->getMessage()); }
            if (!isset($result)) {
                if (!delivery_repository_update_status($conn, $deliveryId, $nextStatus, $expectedVersion, $fromStatus === 'assigned', $nextStatus === 'delivered')) $result = delivery_result(false, 409, 'Delivery changed. Refresh before retrying.');
                else {
                    delivery_repository_add_milestone($conn, $deliveryId, $nextStatus, $driverUserId, $reason);
                    $nextOrderVersion = (int) $delivery['order_version'];
                    if ($orderStatus !== null) {
                        $orderUpdate = $conn->prepare('UPDATE orders SET status=?,version=version+1 WHERE id=? AND version=?'); $orderId = (int) $delivery['order_id']; $orderUpdate->bind_param('sii', $orderStatus, $orderId, $nextOrderVersion); $orderUpdate->execute(); $orderUpdate->close();
                        order_repository_insert_history_event($conn, $orderId, $orderStatus, 'driver', $driverUserId, 'Delivery milestone recorded.'); $nextOrderVersion++;
                    }
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
