<?php
declare(strict_types=1);

require_once __DIR__ . '/../idempotency.php';
require_once __DIR__ . '/../environment.php';
require_once __DIR__ . '/audit_service.php';
require_once __DIR__ . '/notification_service.php';
require_once __DIR__ . '/../repositories/order_repository.php';
require_once __DIR__ . '/../repositories/dispatch_repository.php';

function dispatch_result(bool $ok, int $status, string $message, array $data = []): array
{
    $result = ['ok' => $ok, 'status' => $status, 'message' => $message];
    if ($data !== []) $result['data'] = $data;
    return $result;
}

function dispatch_offer_from_row(array $dispatch, array $offer): array
{
    return dispatch_repository_offer_contract($dispatch, $offer);
}

function dispatch_safe_delivery(array $offer): array
{
    return [
        'address' => (string) ($offer['delivery_address'] ?? ''),
        'note' => (string) ($offer['delivery_note'] ?? ''),
    ];
}

function dispatch_offer_next_driver_in_transaction(mysqli $conn, int $dispatchId, ?int $actorId = null): array
{
    $dispatch = dispatch_repository_dispatch($conn, $dispatchId, true);
    if ($dispatch === []) return dispatch_result(false, 404, 'Dispatch not found.');
    if (in_array((string) $dispatch['order_status'], ['cancelled', 'delivered', 'refunded'], true)) {
        return dispatch_result(false, 409, 'This order is no longer dispatchable.');
    }
    if (in_array((string) $dispatch['status'], ['assigned', 'completed', 'cancelled'], true)) {
        return dispatch_result(false, 409, 'This dispatch is already assigned or closed.');
    }

    $expire = $conn->prepare("UPDATE delivery_offers SET status='expired',expired_at=NOW(),responded_at=NOW(),response_code='expired',active_dispatch_key=NULL,active_driver_key=NULL
        WHERE status='sent' AND expires_at<=NOW()");
    $expire->execute(); $expire->close();

    $active = dispatch_repository_active_offer_for_dispatch($conn, $dispatchId, true);
    if ($active !== []) {
        $active['distance_km'] = null;
        return dispatch_result(true, 200, 'An active driver offer already exists.', ['offer' => dispatch_offer_from_row($dispatch, $active)]);
    }

    $candidate = dispatch_repository_candidate_driver($conn, $dispatch);
    if ($candidate === []) {
        $status = 'searching_driver';
        $update = $conn->prepare('UPDATE delivery_dispatches SET status=?,last_offered_at=NULL,version=version+1 WHERE id=?');
        $update->bind_param('si', $status, $dispatchId); $update->execute(); $update->close();
        return dispatch_result(true, 200, 'No eligible driver is currently available.', ['offer' => null, 'dispatchVersion' => (int) $dispatch['version'] + 1]);
    }

    $offerReference = 'OFF-' . strtoupper(bin2hex(random_bytes(7)));
    $clock = $conn->query("SELECT DATE_FORMAT(DATE_ADD(NOW(), INTERVAL 30 SECOND),'%Y-%m-%d %H:%i:%s') AS expires_at");
    $expiresAt = (string) ($clock->fetch_assoc()['expires_at'] ?? date('Y-m-d H:i:s', time() + 30));
    $activeDispatchKey = 'dispatch:' . $dispatchId;
    $activeDriverKey = 'driver:' . (int) $candidate['user_id'];
    $offer = $conn->prepare("INSERT INTO delivery_offers(public_id,dispatch_id,driver_user_id,status,expires_at,dispatch_version,active_dispatch_key,active_driver_key)
        VALUES(?,?,?,'sent',?,?,?,?)");
    $driverId = (int) $candidate['user_id']; $dispatchVersion = (int) $dispatch['version'] + 1;
    $offer->bind_param('siisiss', $offerReference, $dispatchId, $driverId, $expiresAt, $dispatchVersion, $activeDispatchKey, $activeDriverKey);
    $offer->execute(); $offerId = (int) $offer->insert_id; $offer->close();

    $status = 'offered';
    $update = $conn->prepare('UPDATE delivery_dispatches SET status=?,attempt_count=attempt_count+1,last_offered_at=NOW(),version=version+1 WHERE id=?');
    $update->bind_param('si', $status, $dispatchId); $update->execute(); $update->close();
    $created = dispatch_repository_one($conn, 'SELECT id,public_id,dispatch_id,driver_user_id,status,offered_at,expires_at,responded_at,dispatch_version,response_code,response_reason FROM delivery_offers WHERE id=?', 'i', [$offerId]);
    $created['distance_km'] = $candidate['distance_km'] ?? null;
    notification_queue($conn, $driverId, 'delivery_offer_created', 'New delivery offer', 'A new delivery offer is available for order ' . (string) $dispatch['reference_code'] . '.', 'delivery_dispatch', $dispatchId);
    audit_append($conn, $actorId ?? 0, 'dispatch_offer_created', 'delivery_dispatch', $dispatchId, null, ['driverUserId' => $driverId, 'offerReference' => $offerReference], 'Exclusive driver offer created.', 'DSP-' . strtoupper(bin2hex(random_bytes(5))));
    $dispatch['version'] = $dispatchVersion;
    return dispatch_result(true, 200, 'Driver offer created.', ['offer' => dispatch_offer_from_row($dispatch, $created)]);
}

function driver_set_availability(
    mysqli $conn,
    int $driverUserId,
    string $availabilityStatus,
    ?float $latitude = null,
    ?float $longitude = null,
    ?float $accuracyMeters = null,
    ?string $recordedAt = null,
    string $idempotencyKey = ''
): array {
    $availabilityStatus = strtolower(trim($availabilityStatus));
    if (!in_array($availabilityStatus, ['online', 'offline'], true)) throw new InvalidArgumentException('Availability must be online or offline.');
    if ($driverUserId <= 0) throw new InvalidArgumentException('Driver is required.');
    if ($availabilityStatus === 'online' && ($latitude === null || $longitude === null || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180)) {
        throw new InvalidArgumentException('Online availability requires valid coordinates.');
    }
    $payload = ['availabilityStatus' => $availabilityStatus, 'latitude' => $latitude, 'longitude' => $longitude, 'accuracyMeters' => $accuracyMeters, 'recordedAt' => $recordedAt];
    $hash = savora_idempotency_hash('driver_set_availability', $payload);
    $conn->begin_transaction();
    try {
        if ($idempotencyKey !== '') {
            $stored = savora_idempotency_find($conn, $driverUserId, $idempotencyKey, 'driver_set_availability', $hash);
            if ($stored !== null) { $conn->commit(); return $stored; }
        }
        $profile = dispatch_repository_driver_profile($conn, $driverUserId, true);
        if ($profile === [] || (string) $profile['user_status'] !== 'active') {
            $result = dispatch_result(false, 403, 'Driver is not active.');
        } elseif ((string) $profile['eligibility_status'] !== 'eligible') {
            $result = dispatch_result(false, 403, 'Driver is not eligible for dispatch.');
        } else {
            $profileUpdate = $conn->prepare('UPDATE driver_profiles SET availability_status=?,version=version+1 WHERE user_id=? AND version=?');
            $version = (int) $profile['version']; $profileUpdate->bind_param('sii', $availabilityStatus, $driverUserId, $version); $profileUpdate->execute(); $profileUpdate->close();
            if ($availabilityStatus === 'online') {
                if ($recordedAt === null || trim($recordedAt) === '') {
                    $recordedAt = (string) ($conn->query('SELECT NOW() AS now')->fetch_assoc()['now'] ?? date('Y-m-d H:i:s'));
                }
                $location = $conn->prepare('INSERT INTO driver_locations(driver_user_id,latitude,longitude,accuracy_meters,recorded_at,version) VALUES(?,?,?,?,?,1)
                    ON DUPLICATE KEY UPDATE latitude=VALUES(latitude),longitude=VALUES(longitude),accuracy_meters=VALUES(accuracy_meters),recorded_at=VALUES(recorded_at),version=version+1');
                $location->bind_param('iddds', $driverUserId, $latitude, $longitude, $accuracyMeters, $recordedAt); $location->execute(); $location->close();
            }
            $result = dispatch_result(true, 200, 'Driver availability updated.', ['driverUserId' => $driverUserId, 'availabilityStatus' => $availabilityStatus, 'version' => $version + 1]);
        }
        if ($idempotencyKey !== '') savora_idempotency_store($conn, $driverUserId, $idempotencyKey, 'driver_set_availability', $hash, $result);
        $conn->commit();
        return $result;
    } catch (Throwable $exception) {
        $conn->rollback();
        if ($exception instanceof SavoraIdempotencyConflict) throw $exception;
        throw $exception;
    }
}

function driver_start_demo_shift(mysqli $conn, int $driverUserId, string $idempotencyKey): array
{
    if (!savora_demo_mode()) return dispatch_result(false, 404, 'Demo shift is unavailable.');
    $profile = dispatch_repository_driver_profile($conn, $driverUserId);
    $latitude = isset($profile['latitude']) ? (float) $profile['latitude'] : null;
    $longitude = isset($profile['longitude']) ? (float) $profile['longitude'] : null;
    if ($latitude === null || $longitude === null) return dispatch_result(false, 409, 'Save a Driver profile location before starting the demo shift.');
    return driver_set_availability($conn, $driverUserId, 'online', $latitude, $longitude, null, null, $idempotencyKey);
}

function dispatch_offer_next_driver(mysqli $conn, int $dispatchId, ?int $actorId = null): array
{
    if ($dispatchId <= 0) throw new InvalidArgumentException('Dispatch is required.');
    $conn->begin_transaction();
    try {
        $result = dispatch_offer_next_driver_in_transaction($conn, $dispatchId, $actorId);
        $conn->commit();
        return $result;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function dispatch_accept_offer(mysqli $conn, int $driverUserId, string $offerReference, string $idempotencyKey): array
{
    $offerReference = trim($offerReference);
    if ($driverUserId <= 0 || $offerReference === '' || $idempotencyKey === '') throw new InvalidArgumentException('Driver, offer reference and idempotency key are required.');
    $hash = savora_idempotency_hash('dispatch_accept_offer', ['offerReference' => $offerReference]);
    $conn->begin_transaction();
    try {
        $stored = savora_idempotency_find($conn, $driverUserId, $idempotencyKey, 'dispatch_accept_offer', $hash);
        if ($stored !== null) { $conn->commit(); return $stored; }
        $offer = dispatch_repository_offer_for_driver($conn, $offerReference, $driverUserId, true);
        if ($offer === []) { $result = dispatch_result(false, 404, 'Delivery offer not found.'); savora_idempotency_store($conn, $driverUserId, $idempotencyKey, 'dispatch_accept_offer', $hash, $result); $conn->commit(); return $result; }
        $dispatch = dispatch_repository_dispatch($conn, (int) $offer['dispatch_id'], true);
        if ((string) $offer['status'] !== 'sent') { $result = dispatch_result(false, 409, 'Delivery offer is no longer active.'); savora_idempotency_store($conn, $driverUserId, $idempotencyKey, 'dispatch_accept_offer', $hash, $result); $conn->commit(); return $result; }
        if ((int) ($offer['is_expired'] ?? 0) === 1) {
            $expired = $conn->prepare("UPDATE delivery_offers SET status='expired',expired_at=NOW(),responded_at=NOW(),response_code='expired',active_dispatch_key=NULL,active_driver_key=NULL WHERE id=? AND status='sent'");
            $offerId = (int) $offer['id']; $expired->bind_param('i', $offerId); $expired->execute(); $expired->close();
            $result = dispatch_result(false, 409, 'Delivery offer has expired.'); savora_idempotency_store($conn, $driverUserId, $idempotencyKey, 'dispatch_accept_offer', $hash, $result); $conn->commit(); return $result;
        }
        $profile = dispatch_repository_driver_profile($conn, $driverUserId, true);
        $busy = dispatch_repository_active_delivery_for_driver($conn, $driverUserId, true);
        if ($profile === [] || (string) $profile['user_status'] !== 'active' || (string) $profile['eligibility_status'] !== 'eligible' || (string) $profile['availability_status'] !== 'online') {
            $result = dispatch_result(false, 409, 'Driver is not currently eligible and online.');
        } elseif ($busy !== []) {
            $result = dispatch_result(false, 409, 'Driver already has an active delivery.');
        } elseif ((string) $dispatch['status'] === 'assigned' || dispatch_repository_one($conn, 'SELECT id FROM deliveries WHERE order_id=? LIMIT 1 FOR UPDATE', 'i', [(int) $dispatch['order_id']]) !== []) {
            $result = dispatch_result(false, 409, 'This order already has an assignment.');
        } elseif ((string) $dispatch['order_status'] !== 'ready_for_pickup') {
            $result = dispatch_result(false, 409, 'Order is not ready for pickup.');
        } else {
            $offerId = (int) $offer['id'];
            $accepted = $conn->prepare("UPDATE delivery_offers SET status='accepted',responded_at=NOW(),response_code='accepted',active_dispatch_key=NULL,active_driver_key=NULL WHERE id=? AND status='sent'");
            $accepted->bind_param('i', $offerId); $accepted->execute(); $didAccept = $accepted->affected_rows === 1; $accepted->close();
            if (!$didAccept) {
                $result = dispatch_result(false, 409, 'Delivery offer was accepted by another request.');
            } else {
                $earning = (float) $dispatch['delivery_fee']; $delivery = $conn->prepare("INSERT INTO deliveries(order_id,driver_user_id,status,earning,accepted_at,version) VALUES(? ,?,'assigned',?,NOW(),1)");
                $orderId = (int) $dispatch['order_id']; $delivery->bind_param('iid', $orderId, $driverUserId, $earning); $delivery->execute(); $deliveryId = (int) $delivery->insert_id; $delivery->close();
                $dispatchStatus = 'assigned'; $dispatchUpdate = $conn->prepare('UPDATE delivery_dispatches SET status=?,assigned_driver_user_id=?,version=version+1 WHERE id=? AND status<>\'assigned\''); $dispatchUpdate->bind_param('sii', $dispatchStatus, $driverUserId, $offer['dispatch_id']); $dispatchUpdate->execute(); $dispatchUpdate->close();
                $orderStatus = 'assigned'; $orderId = (int) $dispatch['order_id']; $orderVersion = (int) $dispatch['order_version']; $orderUpdate = $conn->prepare('UPDATE orders SET status=?,version=version+1 WHERE id=? AND version=?'); $orderUpdate->bind_param('sii', $orderStatus, $orderId, $orderVersion); $orderUpdate->execute(); $orderUpdate->close();
                order_repository_insert_history_event($conn, $orderId, 'assigned', 'driver', $driverUserId, 'Delivery offer accepted.');
                $profileUpdate = $conn->prepare("UPDATE driver_profiles SET availability_status='busy',version=version+1 WHERE user_id=?"); $profileUpdate->bind_param('i', $driverUserId); $profileUpdate->execute(); $profileUpdate->close();
                notification_queue($conn, (int) $dispatch['customer_user_id'], 'delivery_assigned', 'Driver assigned', 'A driver has accepted your order ' . (string) $dispatch['reference_code'] . '.', 'order', $orderId);
                audit_append($conn, $driverUserId, 'dispatch_offer_accepted', 'delivery', $deliveryId, ['offerReference' => $offerReference], ['driverUserId' => $driverUserId, 'orderStatus' => 'assigned'], 'Driver accepted the exclusive delivery offer.', 'DEL-' . strtoupper(bin2hex(random_bytes(5))));
                $deliveryData = ['deliveryId' => $deliveryId, 'driverUserId' => $driverUserId, 'status' => 'assigned', 'earning' => $earning, 'deliveryAddress' => (string) $dispatch['delivery_address'], 'deliveryNote' => (string) ($dispatch['delivery_note'] ?? '')];
                $result = dispatch_result(true, 200, 'Delivery offer accepted.', ['delivery' => $deliveryData, 'offer' => dispatch_repository_offer_contract($dispatch, $offer, dispatch_safe_delivery($dispatch)), 'orderReference' => (string) $dispatch['reference_code'], 'orderStatus' => 'assigned', 'orderVersion' => $orderVersion + 1]);
            }
        }
        savora_idempotency_store($conn, $driverUserId, $idempotencyKey, 'dispatch_accept_offer', $hash, $result);
        $conn->commit();
        return $result;
    } catch (Throwable $exception) {
        $conn->rollback();
        if ($exception instanceof SavoraIdempotencyConflict) throw $exception;
        throw $exception;
    }
}

function dispatch_decline_offer(mysqli $conn, int $driverUserId, string $offerReference, string $idempotencyKey, string $reason = ''): array
{
    $offerReference = trim($offerReference); $reason = mb_substr(trim($reason), 0, 255);
    if ($driverUserId <= 0 || $offerReference === '' || $idempotencyKey === '') throw new InvalidArgumentException('Driver, offer reference and idempotency key are required.');
    $hash = savora_idempotency_hash('dispatch_decline_offer', ['offerReference' => $offerReference, 'reason' => $reason]);
    $conn->begin_transaction();
    try {
        $stored = savora_idempotency_find($conn, $driverUserId, $idempotencyKey, 'dispatch_decline_offer', $hash);
        if ($stored !== null) { $conn->commit(); return $stored; }
        $offer = dispatch_repository_offer_for_driver($conn, $offerReference, $driverUserId, true);
        if ($offer === []) { $result = dispatch_result(false, 404, 'Delivery offer not found.'); }
        elseif ((string) $offer['status'] !== 'sent') { $result = dispatch_result(false, 409, 'Delivery offer is no longer active.'); }
        elseif ((int) ($offer['is_expired'] ?? 0) === 1) {
            $expired = $conn->prepare("UPDATE delivery_offers SET status='expired',expired_at=NOW(),responded_at=NOW(),response_code='expired',active_dispatch_key=NULL,active_driver_key=NULL WHERE id=? AND status='sent'");
            $offerId = (int) $offer['id']; $expired->bind_param('i', $offerId); $expired->execute(); $expired->close();
            $next = dispatch_offer_next_driver_in_transaction($conn, (int) $offer['dispatch_id'], $driverUserId);
            $result = dispatch_result(true, 200, 'Delivery offer expired.', ['offer' => $next['data']['offer'] ?? null]);
        }
        else {
            $update = $conn->prepare("UPDATE delivery_offers SET status='declined',declined_at=NOW(),responded_at=NOW(),response_code='declined',response_reason=?,active_dispatch_key=NULL,active_driver_key=NULL WHERE id=? AND status='sent'");
            $offerId = (int) $offer['id']; $update->bind_param('si', $reason, $offerId); $update->execute(); $update->close();
            $next = dispatch_offer_next_driver_in_transaction($conn, (int) $offer['dispatch_id'], $driverUserId);
            $result = dispatch_result(true, 200, 'Delivery offer declined.', ['offer' => $next['data']['offer'] ?? null, 'dispatchVersion' => $next['data']['dispatchVersion'] ?? null]);
        }
        savora_idempotency_store($conn, $driverUserId, $idempotencyKey, 'dispatch_decline_offer', $hash, $result);
        $conn->commit();
        return $result;
    } catch (Throwable $exception) {
        $conn->rollback();
        if ($exception instanceof SavoraIdempotencyConflict) throw $exception;
        throw $exception;
    }
}

function dispatch_expire_offers(mysqli $conn, ?int $dispatchId = null, ?int $actorId = null): array
{
    $conn->begin_transaction();
    try {
        if ($dispatchId !== null && $dispatchId > 0) {
            $ids = [$dispatchId];
        } else {
            $ids = array_map(static fn (array $row): int => (int) $row['dispatch_id'], dispatch_repository_rows($conn, "SELECT DISTINCT dispatch_id FROM delivery_offers WHERE status='sent' AND expires_at<=NOW()", '', []));
        }
        $expired = 0; $nextOffers = [];
        foreach ($ids as $id) {
            $dispatch = dispatch_repository_dispatch($conn, $id, true);
            if ($dispatch === []) continue;
            $update = $conn->prepare("UPDATE delivery_offers SET status='expired',expired_at=NOW(),responded_at=NOW(),response_code='expired',active_dispatch_key=NULL,active_driver_key=NULL WHERE dispatch_id=? AND status='sent' AND expires_at<=NOW()");
            $update->bind_param('i', $id); $update->execute(); $affected = $update->affected_rows; $expired += $affected; $update->close();
            if ($affected > 0) {
                $next = dispatch_offer_next_driver_in_transaction($conn, $id, $actorId);
                $nextOffers[] = $next['data']['offer'] ?? null;
            }
        }
        $result = dispatch_result(true, 200, 'Expired delivery offers processed.', ['expiredCount' => $expired, 'offers' => $nextOffers]);
        $conn->commit(); return $result;
    } catch (Throwable $exception) { $conn->rollback(); throw $exception; }
}

function dispatch_offers_for_driver(mysqli $conn, int $driverUserId): array
{
    return array_map(static function (array $row): array {
        $dispatch = [
            'reference_code' => $row['reference_code'], 'restaurant_name' => $row['restaurant_name'], 'restaurant_address' => $row['restaurant_address'],
            'restaurant_city' => $row['restaurant_city'], 'payment_method' => $row['payment_method'],
        ];
        return dispatch_repository_offer_contract($dispatch, $row + ['public_id' => $row['public_id'], 'driver_user_id' => $row['driver_user_id'], 'dispatch_version' => $row['dispatch_version'], 'distance_km' => $row['distance_km']]);
    }, dispatch_repository_driver_offers($conn, $driverUserId));
}
