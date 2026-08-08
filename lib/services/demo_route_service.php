<?php
declare(strict_types=1);

require_once __DIR__ . '/../environment.php';
require_once __DIR__ . '/../idempotency.php';
require_once __DIR__ . '/audit_service.php';
require_once __DIR__ . '/notification_service.php';
require_once __DIR__ . '/../repositories/demo_route_repository.php';
require_once __DIR__ . '/../repositories/delivery_repository.php';
require_once __DIR__ . '/../repositories/order_repository.php';

function demo_route_result(bool $ok, int $status, string $message, array $data = []): array
{
    $result = ['ok' => $ok, 'status' => $status, 'message' => $message];
    if ($data !== []) $result['data'] = $data;
    return $result;
}

function demo_route_calculate_point(array $route, DateTimeImmutable $now): array
{
    $startTime = new DateTimeImmutable((string) $route['started_at']);
    $elapsed = max(0, $now->getTimestamp() - $startTime->getTimestamp());
    $duration = max(1, (int) $route['duration_seconds']);
    $progress = (float) min(1.0, $elapsed / $duration);
    $startLat = (float) $route['start_latitude'];
    $startLng = (float) $route['start_longitude'];
    $endLat = (float) $route['end_latitude'];
    $endLng = (float) $route['end_longitude'];
    $curve = sin(M_PI * $progress) * 0.0015;
    $current = $progress >= 1.0
        ? ['latitude' => $endLat, 'longitude' => $endLng]
        : [
            'latitude' => $startLat + (($endLat - $startLat) * $progress) + $curve,
            'longitude' => $startLng + (($endLng - $startLng) * $progress) - ($curve * 0.6),
        ];
    return ['progress' => $progress, 'current' => $current, 'arrived' => $progress >= 1.0];
}

function demo_route_server_now(mysqli $conn): DateTimeImmutable
{
    $row = $conn->query('SELECT NOW() AS now')->fetch_assoc() ?: [];
    return new DateTimeImmutable((string) ($row['now'] ?? date('Y-m-d H:i:s')));
}

function demo_route_coordinates_valid(array $target): bool
{
    foreach (['start_latitude', 'start_longitude', 'end_latitude', 'end_longitude'] as $field) {
        if ($target[$field] === null || $target[$field] === '') return false;
    }
    $startLatitude = (float) $target['start_latitude'];
    $endLatitude = (float) $target['end_latitude'];
    $startLongitude = (float) $target['start_longitude'];
    $endLongitude = (float) $target['end_longitude'];
    return $startLatitude >= -90 && $startLatitude <= 90
        && $endLatitude >= -90 && $endLatitude <= 90
        && $startLongitude >= -180 && $startLongitude <= 180
        && $endLongitude >= -180 && $endLongitude <= 180;
}

function demo_route_route_payload(array $route, DateTimeImmutable $now): array
{
    $point = demo_route_calculate_point($route, $now);
    return [
        'status' => (string) ($route['route_status'] ?? $route['status'] ?? 'running'),
        'startedAt' => (string) $route['started_at'],
        'durationSeconds' => (int) $route['duration_seconds'],
        'start' => ['latitude' => (float) $route['start_latitude'], 'longitude' => (float) $route['start_longitude']],
        'current' => $point['current'],
        'end' => ['latitude' => (float) $route['end_latitude'], 'longitude' => (float) $route['end_longitude']],
        'progress' => $point['progress'],
        'arrived' => $point['arrived'],
        'completedAt' => ($route['completed_at'] ?? null) === null ? null : (string) $route['completed_at'],
        'version' => (int) ($route['route_version'] ?? $route['version'] ?? 1),
    ];
}

function demo_route_normalize_result(array $result): array
{
    if (!is_array($result['data']['route'] ?? null)) return $result;
    foreach (['start', 'current', 'end'] as $pointName) {
        if (!is_array($result['data']['route'][$pointName] ?? null)) continue;
        $result['data']['route'][$pointName]['latitude'] = (float) $result['data']['route'][$pointName]['latitude'];
        $result['data']['route'][$pointName]['longitude'] = (float) $result['data']['route'][$pointName]['longitude'];
    }
    $result['data']['route']['progress'] = (float) $result['data']['route']['progress'];
    return $result;
}

function demo_route_start(
    mysqli $conn,
    int $driverUserId,
    int $deliveryId,
    int $expectedDeliveryVersion,
    string $idempotencyKey
): array {
    if (!savora_demo_mode()) return demo_route_result(false, 404, 'Demo route is unavailable.');
    if ($driverUserId <= 0 || $deliveryId <= 0 || $expectedDeliveryVersion < 1 || trim($idempotencyKey) === '') {
        throw new InvalidArgumentException('Driver, delivery, version and idempotency key are required.');
    }

    $action = 'demo_route_start';
    $payload = ['deliveryId' => $deliveryId, 'expectedDeliveryVersion' => $expectedDeliveryVersion];
    $requestHash = savora_idempotency_hash($action, $payload);
    $conn->begin_transaction();
    try {
        $stored = savora_idempotency_find($conn, $driverUserId, $idempotencyKey, $action, $requestHash);
        if ($stored !== null) {
            $conn->commit();
            return demo_route_normalize_result($stored);
        }

        $target = demo_route_repository_start_target($conn, $deliveryId);
        $route = demo_route_repository_route_for_delivery($conn, $deliveryId, true);
        if ($target === []) {
            $result = demo_route_result(false, 404, 'Delivery was not found.');
        } elseif ((int) $target['driver_user_id'] !== $driverUserId) {
            $result = demo_route_result(false, 403, 'Driver is not assigned to this delivery.');
        } elseif ($route !== [] && (string) $route['status'] === 'running') {
            $result = demo_route_result(false, 409, 'A demo route is already active.');
        } elseif ((string) $target['delivery_status'] !== 'assigned' || (string) $target['order_status'] !== 'assigned') {
            $result = demo_route_result(false, 409, 'Delivery is not ready to start.');
        } elseif ((int) $target['delivery_version'] !== $expectedDeliveryVersion) {
            $result = demo_route_result(false, 409, 'Delivery changed. Refresh before retrying.');
        } elseif (!demo_route_coordinates_valid($target)) {
            $result = demo_route_result(false, 422, 'Restaurant and quoted delivery coordinates are required.');
        } else {
            if (!demo_route_repository_pick_up_delivery($conn, $deliveryId, $expectedDeliveryVersion)) {
                throw new RuntimeException('Delivery changed while starting the demo route.');
            }
            if (!demo_route_repository_pick_up_order($conn, (int) $target['order_id'], (int) $target['order_version'])) {
                throw new RuntimeException('Order changed while starting the demo route.');
            }

            delivery_repository_add_milestone($conn, $deliveryId, 'arrived', $driverUserId, 'Demo Driver reached the Restaurant.');
            delivery_repository_add_milestone($conn, $deliveryId, 'picked_up', $driverUserId, 'Demo Driver picked up the order.');
            order_repository_insert_history_event($conn, (int) $target['order_id'], 'picked_up', 'driver', $driverUserId, 'Server-timed demo route started.');
            demo_route_repository_upsert($conn, $target);
            demo_route_repository_set_driver_location($conn, $driverUserId, (float) $target['start_latitude'], (float) $target['start_longitude']);

            $storedRoute = demo_route_repository_route_for_delivery($conn, $deliveryId);
            $routePayload = demo_route_route_payload($storedRoute, new DateTimeImmutable((string) $storedRoute['started_at']));
            notification_queue(
                $conn,
                (int) $target['customer_user_id'],
                'delivery_picked_up',
                'Driver is on the way',
                'Your order ' . (string) $target['reference_code'] . ' has been picked up.',
                'order',
                (int) $target['order_id']
            );
            audit_append(
                $conn,
                $driverUserId,
                $action,
                'delivery',
                $deliveryId,
                ['deliveryStatus' => 'assigned', 'deliveryVersion' => $expectedDeliveryVersion, 'orderStatus' => (string) $target['order_status']],
                ['deliveryStatus' => 'picked_up', 'deliveryVersion' => $expectedDeliveryVersion + 1, 'orderStatus' => 'picked_up', 'routeDurationSeconds' => 60],
                'Server-timed demo route started.',
                'RTE-' . strtoupper(bin2hex(random_bytes(5)))
            );
            $result = demo_route_result(true, 200, 'Demo route started.', [
                'referenceCode' => (string) $target['reference_code'],
                'deliveryId' => $deliveryId,
                'deliveryStatus' => 'picked_up',
                'deliveryVersion' => $expectedDeliveryVersion + 1,
                'orderStatus' => 'picked_up',
                'orderVersion' => (int) $target['order_version'] + 1,
                'route' => $routePayload,
            ]);
        }

        savora_idempotency_store($conn, $driverUserId, $idempotencyKey, $action, $requestHash, $result);
        $storedResult = savora_idempotency_find($conn, $driverUserId, $idempotencyKey, $action, $requestHash);
        $conn->commit();
        return demo_route_normalize_result($storedResult ?? $result);
    } catch (SavoraIdempotencyConflict) {
        $conn->rollback();
        throw new SavoraIdempotencyConflict('Idempotency key was already used for a different demo route request.');
    } catch (Throwable $exception) {
        $conn->rollback();
        error_log('Demo route start failed: ' . $exception->getMessage());
        return demo_route_result(false, 500, 'Demo route could not be started.');
    }
}

function demo_route_snapshot(mysqli $conn, array $actor, string $referenceCode): array
{
    $referenceCode = trim($referenceCode);
    if ($referenceCode === '' || !savora_demo_mode()) return demo_route_result(false, 404, 'Tracking was not found.');
    $row = demo_route_repository_route_by_reference($conn, $referenceCode);
    if ($row === []) return demo_route_result(false, 404, 'Tracking was not found.');

    $allowed = match ((string) ($actor['role'] ?? '')) {
        'customer' => (int) $row['customer_user_id'] === (int) ($actor['userId'] ?? 0),
        'restaurant' => (int) $row['owner_user_id'] === (int) ($actor['userId'] ?? 0),
        'driver' => (int) $row['driver_user_id'] === (int) ($actor['userId'] ?? 0),
        'admin' => true,
        default => false,
    };
    if (!$allowed) return demo_route_result(false, 404, 'Tracking was not found.');

    return demo_route_result(true, 200, 'Tracking loaded.', [
        'referenceCode' => (string) $row['reference_code'],
        'orderStatus' => (string) $row['order_status'],
        'orderVersion' => (int) $row['order_version'],
        'payment' => [
            'method' => (string) ($row['payment_method'] ?? ''),
            'amount' => (float) ($row['payment_amount'] ?? 0),
            'status' => (string) ($row['payment_status'] ?? 'pending'),
            'paidAt' => $row['paid_at'] === null ? null : (string) $row['paid_at'],
        ],
        'assignment' => [
            'deliveryId' => (int) $row['delivery_id'],
            'driverUserId' => (int) $row['driver_user_id'],
            'status' => (string) $row['delivery_status'],
            'version' => (int) $row['delivery_version'],
        ],
        'route' => demo_route_route_payload($row, demo_route_server_now($conn)),
    ]);
}

function demo_route_is_arrived(mysqli $conn, int $deliveryId, ?DateTimeImmutable $now = null): ?bool
{
    if ($deliveryId <= 0) return null;
    $route = demo_route_repository_route_for_delivery($conn, $deliveryId);
    if ($route === []) return null;
    $point = demo_route_calculate_point($route, $now ?? demo_route_server_now($conn));
    return (bool) $point['arrived'];
}

function demo_route_finish(mysqli $conn, int $deliveryId): void
{
    if ($deliveryId <= 0) throw new InvalidArgumentException('Delivery identity is required.');
    $route = demo_route_repository_route_for_delivery($conn, $deliveryId, true);
    if ($route === []) return;
    $point = demo_route_calculate_point($route, demo_route_server_now($conn));
    if (!$point['arrived']) throw new RuntimeException('Demo route has not arrived.');
    if ((string) $route['status'] !== 'finished' && !demo_route_repository_mark_finished($conn, $deliveryId)) {
        throw new RuntimeException('Demo route changed while finishing.');
    }
    demo_route_repository_set_driver_location(
        $conn,
        (int) $route['driver_user_id'],
        (float) $route['end_latitude'],
        (float) $route['end_longitude']
    );
}
