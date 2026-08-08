<?php
declare(strict_types=1);

function dispatch_repository_one(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $statement = $conn->prepare($sql);
    if ($types !== '') $statement->bind_param($types, ...$params);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc() ?: [];
    $statement->close();
    return $row;
}

function dispatch_repository_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $statement = $conn->prepare($sql);
    if ($types !== '') $statement->bind_param($types, ...$params);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function dispatch_repository_dispatch(mysqli $conn, int $dispatchId, bool $forUpdate = false): array
{
    $sql = 'SELECT dd.id,dd.order_id,dd.status,dd.assigned_driver_user_id,dd.attempt_count,dd.version,dd.last_offered_at,
                   o.reference_code,o.customer_user_id,o.status AS order_status,o.payment_method,o.delivery_fee,o.total,o.delivery_address,o.delivery_note,o.version AS order_version,
                   r.name AS restaurant_name,r.address AS restaurant_address,r.city AS restaurant_city,r.latitude AS pickup_latitude,r.longitude AS pickup_longitude
            FROM delivery_dispatches dd JOIN orders o ON o.id=dd.order_id JOIN restaurants r ON r.id=o.restaurant_id
            WHERE dd.id=? LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return dispatch_repository_one($conn, $sql, 'i', [$dispatchId]);
}

function dispatch_repository_active_offer_for_dispatch(mysqli $conn, int $dispatchId, bool $forUpdate = false): array
{
    $sql = "SELECT do.id,do.public_id,do.dispatch_id,do.driver_user_id,do.status,do.offered_at,do.expires_at,do.responded_at,
                   do.dispatch_version,do.response_code,do.response_reason,(do.expires_at<=NOW()) AS is_expired
            FROM delivery_offers do WHERE do.dispatch_id=? AND do.status='sent' AND do.expires_at>NOW()
            ORDER BY do.id ASC LIMIT 1";
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return dispatch_repository_one($conn, $sql, 'i', [$dispatchId]);
}

function dispatch_repository_offer_for_driver(mysqli $conn, string $offerReference, int $driverUserId, bool $forUpdate = false): array
{
    $sql = 'SELECT do.id,do.public_id,do.dispatch_id,do.driver_user_id,do.status,do.offered_at,do.expires_at,do.responded_at,
                   do.dispatch_version,do.response_code,do.response_reason,(do.expires_at<=NOW()) AS is_expired,
                   dd.order_id,dd.status AS dispatch_status,dd.version AS dispatch_current_version,
                   o.reference_code,o.customer_user_id,o.status AS order_status,o.payment_method,o.delivery_fee,o.total,o.delivery_address,o.delivery_note,o.version AS order_version,
                   r.name AS restaurant_name,r.address AS restaurant_address,r.city AS restaurant_city,r.latitude AS pickup_latitude,r.longitude AS pickup_longitude
            FROM delivery_offers do JOIN delivery_dispatches dd ON dd.id=do.dispatch_id JOIN orders o ON o.id=dd.order_id
            JOIN restaurants r ON r.id=o.restaurant_id WHERE do.public_id=? AND do.driver_user_id=? LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return dispatch_repository_one($conn, $sql, 'si', [$offerReference, $driverUserId]);
}

function dispatch_repository_driver_profile(mysqli $conn, int $driverUserId, bool $forUpdate = false): array
{
    $sql = 'SELECT dp.user_id,dp.eligibility_status,dp.availability_status,dp.rating,dp.latitude,dp.longitude,dp.version,u.status AS user_status
            FROM driver_profiles dp JOIN users u ON u.id=dp.user_id WHERE dp.user_id=? LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return dispatch_repository_one($conn, $sql, 'i', [$driverUserId]);
}

function dispatch_repository_driver_location(mysqli $conn, int $driverUserId): array
{
    return dispatch_repository_one($conn, 'SELECT latitude,longitude,accuracy_meters,recorded_at,version FROM driver_locations WHERE driver_user_id=? LIMIT 1', 'i', [$driverUserId]);
}

function dispatch_repository_active_delivery_for_driver(mysqli $conn, int $driverUserId, bool $forUpdate = false): array
{
    $sql = "SELECT id,order_id,driver_user_id,status,version FROM deliveries
            WHERE driver_user_id=? AND status IN ('assigned','arrived','picked_up') AND superseded_at IS NULL
            ORDER BY id ASC LIMIT 1";
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return dispatch_repository_one($conn, $sql, 'i', [$driverUserId]);
}

function dispatch_repository_driver_offers(mysqli $conn, int $driverUserId): array
{
    return dispatch_repository_rows($conn, "SELECT do.public_id,do.driver_user_id,do.dispatch_version,do.offered_at,do.expires_at,
        o.reference_code,o.payment_method,r.name AS restaurant_name,r.address AS restaurant_address,r.city AS restaurant_city,
        r.latitude AS pickup_latitude,r.longitude AS pickup_longitude,
        IF(r.latitude IS NULL OR r.longitude IS NULL OR dl.latitude IS NULL OR dl.longitude IS NULL,NULL,
           6371 * 2 * ASIN(SQRT(POW(SIN(RADIANS(dl.latitude-r.latitude)/2),2) + COS(RADIANS(r.latitude))*COS(RADIANS(dl.latitude))*POW(SIN(RADIANS(dl.longitude-r.longitude)/2),2)))) AS distance_km
        FROM delivery_offers do JOIN delivery_dispatches dd ON dd.id=do.dispatch_id JOIN orders o ON o.id=dd.order_id
        JOIN restaurants r ON r.id=o.restaurant_id LEFT JOIN driver_locations dl ON dl.driver_user_id=do.driver_user_id
        WHERE do.driver_user_id=? AND do.status='sent' AND do.expires_at>NOW() ORDER BY do.expires_at ASC", 'i', [$driverUserId]);
}

function dispatch_repository_candidate_driver(mysqli $conn, array $dispatch): array
{
    $sql = "SELECT dp.user_id,dp.rating,dl.latitude AS driver_latitude,dl.longitude AS driver_longitude,
                   IF(? IS NULL OR ? IS NULL,NULL,
                      6371 * 2 * ASIN(SQRT(POW(SIN(RADIANS(dl.latitude-?)/2),2) + COS(RADIANS(?))*COS(RADIANS(dl.latitude))*POW(SIN(RADIANS(dl.longitude-?)/2),2)))) AS distance_km
            FROM driver_profiles dp JOIN users u ON u.id=dp.user_id JOIN driver_locations dl ON dl.driver_user_id=dp.user_id
            LEFT JOIN deliveries active_delivery ON active_delivery.driver_user_id=dp.user_id
              AND active_delivery.status IN ('assigned','arrived','picked_up') AND active_delivery.superseded_at IS NULL
            WHERE u.status='active' AND dp.eligibility_status='eligible' AND dp.availability_status='online'
              AND dl.recorded_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
              AND active_delivery.id IS NULL
              AND NOT EXISTS (SELECT 1 FROM delivery_offers active_offer WHERE active_offer.driver_user_id=dp.user_id
                              AND active_offer.status='sent' AND active_offer.expires_at>NOW())
              AND NOT EXISTS (SELECT 1 FROM delivery_offers prior_offer WHERE prior_offer.dispatch_id=?
                              AND prior_offer.driver_user_id=dp.user_id AND prior_offer.status IN ('declined','expired'))
            ORDER BY (distance_km IS NULL) ASC,distance_km ASC,dp.rating DESC,dp.user_id ASC LIMIT 1";
    $pickupLat = $dispatch['pickup_latitude'] === null ? null : (float) $dispatch['pickup_latitude'];
    $pickupLon = $dispatch['pickup_longitude'] === null ? null : (float) $dispatch['pickup_longitude'];
    $statement = $conn->prepare($sql);
    $dispatchId = (int) $dispatch['id'];
    $statement->bind_param('dddddi', $pickupLat, $pickupLon, $pickupLat, $pickupLat, $pickupLon, $dispatchId);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc() ?: [];
    $statement->close();
    return $row;
}

function dispatch_repository_offer_contract(array $dispatch, array $offer, ?array $deliverySafe = null): array
{
    $data = [
        'orderReference' => (string) $dispatch['reference_code'],
        'offerReference' => (string) $offer['public_id'],
        'driverUserId' => (int) $offer['driver_user_id'],
        'pickup' => [
            'restaurantName' => (string) $dispatch['restaurant_name'],
            'address' => (string) ($dispatch['restaurant_address'] ?? ''),
            'city' => (string) ($dispatch['restaurant_city'] ?? ''),
        ],
        'distanceKm' => ($offer['distance_km'] ?? null) === null ? null : round((float) $offer['distance_km'], 2),
        'paymentMethod' => (string) $dispatch['payment_method'],
        'expiresAt' => (string) $offer['expires_at'],
        'dispatchVersion' => (int) $offer['dispatch_version'],
    ];
    if ($deliverySafe !== null) $data['deliverySafe'] = $deliverySafe;
    return $data;
}
