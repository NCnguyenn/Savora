<?php
declare(strict_types=1);

function demo_route_repository_one(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $statement = $conn->prepare($sql);
    if ($types !== '') $statement->bind_param($types, ...$params);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc() ?: [];
    $statement->close();
    return $row;
}

function demo_route_repository_start_target(mysqli $conn, int $deliveryId): array
{
    return demo_route_repository_one(
        $conn,
        'SELECT d.id AS delivery_id,d.driver_user_id,d.status AS delivery_status,d.version AS delivery_version,
                o.id AS order_id,o.reference_code,o.status AS order_status,o.version AS order_version,o.customer_user_id,
                r.owner_user_id,r.latitude AS start_latitude,r.longitude AS start_longitude,
                COALESCE(qa.latitude,da.latitude) AS end_latitude,
                COALESCE(qa.longitude,da.longitude) AS end_longitude
         FROM deliveries d
         JOIN orders o ON o.id=d.order_id
         JOIN restaurants r ON r.id=o.restaurant_id
         LEFT JOIN checkout_quotes q ON q.id=o.quote_id
         LEFT JOIN customer_addresses qa ON qa.id=q.address_id AND qa.customer_user_id=o.customer_user_id
         LEFT JOIN customer_addresses da ON da.id=(
             SELECT fallback_address.id
             FROM customer_addresses fallback_address
             WHERE fallback_address.customer_user_id=o.customer_user_id AND fallback_address.is_default=1
             ORDER BY fallback_address.updated_at DESC,fallback_address.id DESC
             LIMIT 1
         )
         WHERE d.id=? LIMIT 1 FOR UPDATE',
        'i',
        [$deliveryId]
    );
}

function demo_route_repository_route_for_delivery(mysqli $conn, int $deliveryId, bool $forUpdate = false): array
{
    $sql = 'SELECT id,delivery_id,driver_user_id,start_latitude,start_longitude,end_latitude,end_longitude,
                   started_at,duration_seconds,status,completed_at,version
            FROM delivery_demo_routes WHERE delivery_id=? LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return demo_route_repository_one($conn, $sql, 'i', [$deliveryId]);
}

function demo_route_repository_route_by_reference(mysqli $conn, string $referenceCode): array
{
    return demo_route_repository_one(
        $conn,
        'SELECT o.reference_code,o.customer_user_id,o.status AS order_status,o.version AS order_version,
                p.method AS payment_method,p.amount AS payment_amount,p.status AS payment_status,p.paid_at,
                r.owner_user_id,d.id AS delivery_id,d.driver_user_id,d.status AS delivery_status,d.version AS delivery_version,
                dr.start_latitude,dr.start_longitude,dr.end_latitude,dr.end_longitude,
                dr.started_at,dr.duration_seconds,dr.status AS route_status,dr.completed_at,dr.version AS route_version
         FROM orders o
         JOIN restaurants r ON r.id=o.restaurant_id
         JOIN deliveries d ON d.order_id=o.id AND d.superseded_at IS NULL
         JOIN delivery_demo_routes dr ON dr.delivery_id=d.id
         LEFT JOIN payments p ON p.order_id=o.id
         WHERE o.reference_code=? LIMIT 1',
        's',
        [$referenceCode]
    );
}

function demo_route_repository_upsert(mysqli $conn, array $target): void
{
    $deliveryId = (int) $target['delivery_id'];
    $driverUserId = (int) $target['driver_user_id'];
    $startLatitude = (float) $target['start_latitude'];
    $startLongitude = (float) $target['start_longitude'];
    $endLatitude = (float) $target['end_latitude'];
    $endLongitude = (float) $target['end_longitude'];
    $statement = $conn->prepare(
        "INSERT INTO delivery_demo_routes(
            delivery_id,driver_user_id,start_latitude,start_longitude,end_latitude,end_longitude,started_at,duration_seconds,status
         ) VALUES(?,?,?,?,?,?,NOW(),60,'running')
         ON DUPLICATE KEY UPDATE
            driver_user_id=VALUES(driver_user_id),start_latitude=VALUES(start_latitude),start_longitude=VALUES(start_longitude),
            end_latitude=VALUES(end_latitude),end_longitude=VALUES(end_longitude),started_at=NOW(),duration_seconds=60,
            status='running',completed_at=NULL,version=version+1"
    );
    $statement->bind_param('iidddd', $deliveryId, $driverUserId, $startLatitude, $startLongitude, $endLatitude, $endLongitude);
    $statement->execute();
    $statement->close();
}

function demo_route_repository_pick_up_delivery(mysqli $conn, int $deliveryId, int $expectedVersion): bool
{
    $statement = $conn->prepare(
        "UPDATE deliveries
         SET status='picked_up',accepted_at=COALESCE(accepted_at,NOW()),version=version+1
         WHERE id=? AND version=? AND status='assigned'"
    );
    $statement->bind_param('ii', $deliveryId, $expectedVersion);
    $statement->execute();
    $updated = $statement->affected_rows === 1;
    $statement->close();
    return $updated;
}

function demo_route_repository_pick_up_order(mysqli $conn, int $orderId, int $expectedVersion): bool
{
    $statement = $conn->prepare("UPDATE orders SET status='picked_up',version=version+1 WHERE id=? AND version=? AND status='assigned'");
    $statement->bind_param('ii', $orderId, $expectedVersion);
    $statement->execute();
    $updated = $statement->affected_rows === 1;
    $statement->close();
    return $updated;
}

function demo_route_repository_set_driver_location(mysqli $conn, int $driverUserId, float $latitude, float $longitude): void
{
    $statement = $conn->prepare(
        'INSERT INTO driver_locations(driver_user_id,latitude,longitude,accuracy_meters,recorded_at,version)
         VALUES(?,?,?,NULL,NOW(),1)
         ON DUPLICATE KEY UPDATE latitude=VALUES(latitude),longitude=VALUES(longitude),accuracy_meters=NULL,recorded_at=NOW(),version=version+1'
    );
    $statement->bind_param('idd', $driverUserId, $latitude, $longitude);
    $statement->execute();
    $statement->close();
}

function demo_route_repository_mark_finished(mysqli $conn, int $deliveryId): bool
{
    $statement = $conn->prepare(
        "UPDATE delivery_demo_routes
         SET status='finished',completed_at=NOW(),version=version+1
         WHERE delivery_id=? AND status='running'
           AND DATE_ADD(started_at, INTERVAL duration_seconds SECOND)<=NOW()"
    );
    $statement->bind_param('i', $deliveryId);
    $statement->execute();
    $updated = $statement->affected_rows === 1;
    $statement->close();
    return $updated;
}
