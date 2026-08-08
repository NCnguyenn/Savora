<?php
declare(strict_types=1);

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
    fwrite(STDERR, "BLOCKED: demo route integration tests require SAVORA_ENV=test and SAVORA_DB_NAME=savora_test\n");
    exit(2);
}

$servicePath = __DIR__ . '/../lib/services/demo_route_service.php';
if (!is_file($servicePath)) {
    fwrite(STDERR, "FAIL: demo route service is missing.\n");
    exit(1);
}

require_once __DIR__ . '/support/test_database.php';
require_once $servicePath;
require_once __DIR__ . '/../lib/services/order_query_service.php';

function route_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function route_insert_user(mysqli $conn, string $username, string $role, string $name): int
{
    $password = password_hash('task-5-demo-route', PASSWORD_DEFAULT);
    $statement = $conn->prepare("INSERT INTO users(username,password,role,full_name,status) VALUES(?,?,?,?,'active')");
    $statement->bind_param('ssss', $username, $password, $role, $name);
    $statement->execute();
    $id = (int) $statement->insert_id;
    $statement->close();
    return $id;
}

function route_insert_address(mysqli $conn, int $customerId, string $publicId, bool $isDefault, ?float $latitude, ?float $longitude): int
{
    $label = $isDefault ? 'Default' : 'Quoted';
    $line = $label . ' address';
    $default = $isDefault ? 1 : 0;
    $statement = $conn->prepare(
        'INSERT INTO customer_addresses(customer_user_id,public_id,label,recipient_name,phone,address_line1,city,latitude,longitude,is_default)
         VALUES(?,?,?,\'Route Customer\',\'0800000000\',?,\'Test City\',?,?,?)'
    );
    $statement->bind_param('isssddi', $customerId, $publicId, $label, $line, $latitude, $longitude, $default);
    $statement->execute();
    $id = (int) $statement->insert_id;
    $statement->close();
    return $id;
}

function route_insert_quote(mysqli $conn, string $publicId, int $customerId, int $restaurantId, int $addressId): int
{
    $cartHash = hash('sha256', $publicId);
    $items = '[]';
    $statement = $conn->prepare(
        'INSERT INTO checkout_quotes(public_id,customer_user_id,restaurant_id,address_id,cart_hash,items_json,subtotal,delivery_fee,total,expires_at)
         VALUES(?,?,?,?,?,?,100,10,110,DATE_ADD(NOW(), INTERVAL 1 HOUR))'
    );
    $statement->bind_param('siiiss', $publicId, $customerId, $restaurantId, $addressId, $cartHash, $items);
    $statement->execute();
    $id = (int) $statement->insert_id;
    $statement->close();
    return $id;
}

function route_insert_order_delivery(
    mysqli $conn,
    string $reference,
    int $customerId,
    int $restaurantId,
    int $driverId,
    ?int $quoteId,
    string $status = 'assigned'
): array {
    $statement = $conn->prepare(
        'INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,delivery_fee,total,delivery_address,quote_id)
         VALUES(?,?,?,?,\'cash\',100,10,110,\'Quoted delivery address\',?)'
    );
    $statement->bind_param('siisi', $reference, $customerId, $restaurantId, $status, $quoteId);
    $statement->execute();
    $orderId = (int) $statement->insert_id;
    $statement->close();

    $payment = $conn->prepare("INSERT INTO payments(order_id,method,amount,status) VALUES(?,'cash',110,'pending')");
    $payment->bind_param('i', $orderId);
    $payment->execute();
    $payment->close();

    $delivery = $conn->prepare('INSERT INTO deliveries(order_id,driver_user_id,status,version) VALUES(?,?,?,1)');
    $delivery->bind_param('iis', $orderId, $driverId, $status);
    $delivery->execute();
    $deliveryId = (int) $delivery->insert_id;
    $delivery->close();

    return ['orderId' => $orderId, 'deliveryId' => $deliveryId];
}

$route = [
    'start_latitude' => '10.0000000',
    'start_longitude' => '106.0000000',
    'end_latitude' => '10.0100000',
    'end_longitude' => '106.0200000',
    'started_at' => '2026-08-07 10:00:00',
    'duration_seconds' => 60,
];
$start = demo_route_calculate_point($route, new DateTimeImmutable('2026-08-07 10:00:00'));
route_expect($start['progress'] === 0.0, 'Route must start at zero progress.');
route_expect($start['current'] === ['latitude' => 10.0, 'longitude' => 106.0], 'Route must start exactly at the Restaurant.');
$half = demo_route_calculate_point($route, new DateTimeImmutable('2026-08-07 10:00:30'));
route_expect(abs($half['progress'] - 0.5) < 0.0001, 'Thirty seconds must be 50 percent.');
route_expect($half['current']['latitude'] >= 10.0 && $half['current']['latitude'] <= 10.012, 'Latitude must remain near the route bounds.');
$exactEnd = demo_route_calculate_point($route, new DateTimeImmutable('2026-08-07 10:01:00'));
route_expect($exactEnd['progress'] === 1.0 && $exactEnd['arrived'] === true, 'Route must arrive at exactly 60 seconds.');
route_expect($exactEnd['current'] === ['latitude' => 10.01, 'longitude' => 106.02], 'Exact arrival must use the Customer endpoint.');
$ended = demo_route_calculate_point($route, new DateTimeImmutable('2026-08-07 10:01:30'));
route_expect($ended['progress'] === 1.0, 'Progress must clamp at one.');
route_expect($ended['current'] === ['latitude' => 10.01, 'longitude' => 106.02], 'Ended route must stop at Customer.');

$conn = null;
$failure = null;
$userIds = [];
$orderIds = [];
$addressIds = [];
$quoteIds = [];
$restaurantId = 0;
$suffix = strtolower(bin2hex(random_bytes(5)));
$reference = strtoupper('SVR-ROUTE-' . $suffix);
$realReference = strtoupper('SVR-REAL-' . $suffix);

try {
    $conn = savora_test_database();
    $customerId = route_insert_user($conn, 'route-' . $suffix . '-customer', 'customer', 'Route Customer');
    $otherCustomerId = route_insert_user($conn, 'route-' . $suffix . '-other-customer', 'customer', 'Other Customer');
    $ownerId = route_insert_user($conn, 'route-' . $suffix . '-owner', 'restaurant', 'Route Restaurant');
    $otherOwnerId = route_insert_user($conn, 'route-' . $suffix . '-other-owner', 'restaurant', 'Other Restaurant');
    $driverId = route_insert_user($conn, 'route-' . $suffix . '-driver', 'driver', 'Route Driver');
    $otherDriverId = route_insert_user($conn, 'route-' . $suffix . '-other-driver', 'driver', 'Other Driver');
    $adminId = route_insert_user($conn, 'route-' . $suffix . '-admin', 'admin', 'Route Admin');
    $userIds = [$customerId, $otherCustomerId, $ownerId, $otherOwnerId, $driverId, $otherDriverId, $adminId];

    $restaurantPublicId = 'route-' . $suffix;
    $restaurant = $conn->prepare(
        "INSERT INTO restaurants(owner_user_id,public_id,name,address,city,status,accepting_orders,latitude,longitude)
         VALUES(?,?,'Route Restaurant','Restaurant start','Test City','active',1,10.0000000,106.0000000)"
    );
    $restaurant->bind_param('is', $ownerId, $restaurantPublicId);
    $restaurant->execute();
    $restaurantId = (int) $restaurant->insert_id;
    $restaurant->close();

    $defaultAddressId = route_insert_address($conn, $customerId, 'default-' . $suffix, true, 11.0, 107.0);
    $quotedAddressId = route_insert_address($conn, $customerId, 'quoted-' . $suffix, false, 10.01, 106.02);
    $addressIds = [$defaultAddressId, $quotedAddressId];
    $quoteId = route_insert_quote($conn, 'quote-' . $suffix, $customerId, $restaurantId, $quotedAddressId);
    $quoteIds = [$quoteId];
    $primary = route_insert_order_delivery($conn, $reference, $customerId, $restaurantId, $driverId, $quoteId);
    $real = route_insert_order_delivery($conn, $realReference, $customerId, $restaurantId, $driverId, null);
    $orderIds = [(int) $primary['orderId'], (int) $real['orderId']];

    putenv('SAVORA_ENV=production');
    $production = demo_route_start($conn, $driverId, (int) $primary['deliveryId'], 1, 'route-production-' . $suffix);
    route_expect(($production['status'] ?? 0) === 404, 'Demo route must be unavailable in production.');
    putenv('SAVORA_ENV=test');

    $foreign = demo_route_start($conn, $otherDriverId, (int) $primary['deliveryId'], 1, 'route-foreign-' . $suffix);
    route_expect(($foreign['status'] ?? 0) === 403, 'Another Driver must not start the route.');
    $stale = demo_route_start($conn, $driverId, (int) $primary['deliveryId'], 99, 'route-stale-' . $suffix);
    route_expect(($stale['status'] ?? 0) === 409, 'A stale delivery version must be rejected.');

    $conn->query('UPDATE restaurants SET latitude=NULL,longitude=NULL WHERE id=' . $restaurantId);
    $missingCoordinates = demo_route_start($conn, $driverId, (int) $primary['deliveryId'], 1, 'route-missing-' . $suffix);
    route_expect(($missingCoordinates['status'] ?? 0) === 422, 'Missing route coordinates must be rejected.');
    $conn->query('UPDATE restaurants SET latitude=10.0000000,longitude=106.0000000 WHERE id=' . $restaurantId);

    $serverBefore = new DateTimeImmutable((string) $conn->query('SELECT NOW() AS now')->fetch_assoc()['now']);
    $started = demo_route_start($conn, $driverId, (int) $primary['deliveryId'], 1, 'route-start-' . $suffix);
    $serverAfter = new DateTimeImmutable((string) $conn->query('SELECT NOW() AS now')->fetch_assoc()['now']);
    route_expect(($started['ok'] ?? false) === true, 'Assigned Driver must start the demo route.');
    route_expect(($started['data']['route']['durationSeconds'] ?? 0) === 60, 'Demo route duration must be exactly 60 seconds.');
    $startedAt = new DateTimeImmutable((string) ($started['data']['route']['startedAt'] ?? ''));
    route_expect($startedAt >= $serverBefore && $startedAt <= $serverAfter, 'Route start must use server time.');
    route_expect(($started['data']['route']['start'] ?? null) === ['latitude' => 10.0, 'longitude' => 106.0], 'Start payload must use Restaurant coordinates.');
    route_expect(($started['data']['route']['end'] ?? null) === ['latitude' => 10.01, 'longitude' => 106.02], 'Route must use the quoted address instead of the mutable default.');

    $replayed = demo_route_start($conn, $driverId, (int) $primary['deliveryId'], 1, 'route-start-' . $suffix);
    route_expect($replayed === $started, 'Route start replay must return the stored response exactly.');
    $state = $conn->query('SELECT d.status AS delivery_status,d.version AS delivery_version,o.status AS order_status,o.version AS order_version FROM deliveries d JOIN orders o ON o.id=d.order_id WHERE d.id=' . (int) $primary['deliveryId'])->fetch_assoc() ?: [];
    route_expect(($state['delivery_status'] ?? '') === 'picked_up' && (int) ($state['delivery_version'] ?? 0) === 2, 'Start must transition delivery to picked_up once.');
    route_expect(($state['order_status'] ?? '') === 'picked_up' && (int) ($state['order_version'] ?? 0) === 2, 'Start must transition order to picked_up once.');
    $milestones = (int) ($conn->query('SELECT COUNT(*) AS total FROM delivery_milestones WHERE delivery_id=' . (int) $primary['deliveryId'])->fetch_assoc()['total'] ?? 0);
    route_expect($milestones === 2, 'Start must record arrived and picked_up milestones once.');
    $history = (int) ($conn->query("SELECT COUNT(*) AS total FROM order_status_history WHERE order_id=" . (int) $primary['orderId'] . " AND status='picked_up'")->fetch_assoc()['total'] ?? 0);
    $notifications = (int) ($conn->query("SELECT COUNT(*) AS total FROM notifications WHERE user_id={$customerId} AND event_type='delivery_picked_up' AND entity_id=" . (int) $primary['orderId'])->fetch_assoc()['total'] ?? 0);
    $audits = (int) ($conn->query("SELECT COUNT(*) AS total FROM audit_logs WHERE actor_user_id={$driverId} AND action='demo_route_start' AND entity_id=" . (int) $primary['deliveryId'])->fetch_assoc()['total'] ?? 0);
    $startLocation = $conn->query('SELECT latitude,longitude FROM driver_locations WHERE driver_user_id=' . $driverId)->fetch_assoc() ?: [];
    route_expect($history === 1 && $notifications === 1 && $audits === 1, 'Start and replay must persist history, notification, and audit exactly once.');
    route_expect((float) ($startLocation['latitude'] ?? 0) === 10.0 && (float) ($startLocation['longitude'] ?? 0) === 106.0, 'Start must place Driver location at the Restaurant.');
    $routeRow = $conn->query('SELECT * FROM delivery_demo_routes WHERE delivery_id=' . (int) $primary['deliveryId'])->fetch_assoc() ?: [];
    route_expect((int) ($routeRow['duration_seconds'] ?? 0) === 60, 'Stored route must last exactly 60 seconds.');
    route_expect((float) ($routeRow['end_latitude'] ?? 0) === 10.01 && (float) ($routeRow['end_longitude'] ?? 0) === 106.02, 'Stored destination must come from the quoted address.');

    $conn->query("UPDATE deliveries d JOIN orders o ON o.id=d.order_id SET d.status='assigned',d.version=2,o.status='assigned' WHERE d.id=" . (int) $primary['deliveryId']);
    $duplicate = demo_route_start($conn, $driverId, (int) $primary['deliveryId'], 2, 'route-duplicate-' . $suffix);
    route_expect(($duplicate['status'] ?? 0) === 409, 'A second active route must be rejected.');
    $conn->query("UPDATE deliveries d JOIN orders o ON o.id=d.order_id SET d.status='picked_up',o.status='picked_up' WHERE d.id=" . (int) $primary['deliveryId']);

    $customerSnapshot = demo_route_snapshot($conn, ['role' => 'customer', 'userId' => $customerId], $reference);
    $ownerSnapshot = demo_route_snapshot($conn, ['role' => 'restaurant', 'userId' => $ownerId], $reference);
    $driverSnapshot = demo_route_snapshot($conn, ['role' => 'driver', 'userId' => $driverId], $reference);
    $adminSnapshot = demo_route_snapshot($conn, ['role' => 'admin', 'userId' => $adminId], $reference);
    foreach ([$customerSnapshot, $ownerSnapshot, $driverSnapshot, $adminSnapshot] as $snapshot) {
        route_expect(($snapshot['ok'] ?? false) === true, 'Authorized actors must read tracking.');
        route_expect(array_keys($snapshot['data'] ?? []) === ['referenceCode', 'orderStatus', 'orderVersion', 'payment', 'assignment', 'route'], 'Tracking payload must expose only the approved top-level fields.');
        route_expect(($snapshot['data']['route']['durationSeconds'] ?? 0) === 60, 'Snapshot must retain the server route duration.');
    }
    $foreignCustomer = demo_route_snapshot($conn, ['role' => 'customer', 'userId' => $otherCustomerId], $reference);
    $foreignOwner = demo_route_snapshot($conn, ['role' => 'restaurant', 'userId' => $otherOwnerId], $reference);
    $foreignDriver = demo_route_snapshot($conn, ['role' => 'driver', 'userId' => $otherDriverId], $reference);
    $unknownRole = demo_route_snapshot($conn, ['role' => 'support', 'userId' => $adminId], $reference);
    $missing = demo_route_snapshot($conn, ['role' => 'admin', 'userId' => $adminId], 'MISSING-' . $suffix);
    foreach ([$foreignCustomer, $foreignOwner, $foreignDriver, $unknownRole, $missing] as $hidden) {
        route_expect(($hidden['status'] ?? 0) === 404, 'Absent and unauthorized tracking must be indistinguishable.');
        route_expect(!isset($hidden['data']), 'Hidden tracking responses must not leak order data.');
    }

    $orderRead = order_repository_admin($conn, (int) $primary['orderId']);
    route_expect(($orderRead['deliveryLocation'] ?? null) === ['latitude' => 10.01, 'longitude' => 106.02], 'Order reads must use the quoted address coordinates.');
    route_expect(demo_route_is_arrived($conn, (int) $real['deliveryId']) === null, 'Real delivery without a demo route must return null.');
    route_expect(demo_route_is_arrived($conn, (int) $primary['deliveryId']) === false, 'New route must not be arrived.');

    $conn->query('UPDATE delivery_demo_routes SET started_at=DATE_SUB(NOW(), INTERVAL 60 SECOND) WHERE delivery_id=' . (int) $primary['deliveryId']);
    route_expect(demo_route_is_arrived($conn, (int) $primary['deliveryId']) === true, 'Route must arrive after exactly 60 seconds.');

    $conn->begin_transaction();
    demo_route_finish($conn, (int) $primary['deliveryId']);
    $finished = $conn->query('SELECT status,completed_at FROM delivery_demo_routes WHERE delivery_id=' . (int) $primary['deliveryId'])->fetch_assoc() ?: [];
    $location = $conn->query('SELECT latitude,longitude FROM driver_locations WHERE driver_user_id=' . $driverId)->fetch_assoc() ?: [];
    route_expect(($finished['status'] ?? '') === 'finished' && ($finished['completed_at'] ?? null) !== null, 'Finish must mark an arrived route complete.');
    route_expect((float) ($location['latitude'] ?? 0) === 10.01 && (float) ($location['longitude'] ?? 0) === 106.02, 'Finish must place Driver location at the destination.');
    $conn->rollback();
    $rolledBack = (string) ($conn->query('SELECT status FROM delivery_demo_routes WHERE delivery_id=' . (int) $primary['deliveryId'])->fetch_assoc()['status'] ?? '');
    route_expect($rolledBack === 'running', 'Finish must participate in the caller transaction without committing it.');
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    putenv('SAVORA_ENV=test');
    if ($conn instanceof mysqli) {
        if ($userIds !== []) {
            $userList = implode(',', array_map('intval', $userIds));
            $conn->query('DELETE FROM audit_logs WHERE actor_user_id IN (' . $userList . ')');
            $conn->query('DELETE FROM idempotency_keys WHERE actor_user_id IN (' . $userList . ')');
            $conn->query('DELETE FROM notifications WHERE user_id IN (' . $userList . ')');
            $conn->query('DELETE FROM driver_locations WHERE driver_user_id IN (' . $userList . ')');
        }
        if ($orderIds !== []) {
            $orderList = implode(',', array_map('intval', $orderIds));
            $conn->query('DELETE FROM order_status_history WHERE order_id IN (' . $orderList . ')');
            $conn->query('DELETE FROM delivery_milestones WHERE delivery_id IN (SELECT id FROM deliveries WHERE order_id IN (' . $orderList . '))');
            $conn->query('DELETE FROM delivery_demo_routes WHERE delivery_id IN (SELECT id FROM deliveries WHERE order_id IN (' . $orderList . '))');
            $conn->query('DELETE FROM deliveries WHERE order_id IN (' . $orderList . ')');
            $conn->query('DELETE FROM payments WHERE order_id IN (' . $orderList . ')');
            $conn->query('DELETE FROM orders WHERE id IN (' . $orderList . ')');
        }
        if ($quoteIds !== []) $conn->query('DELETE FROM checkout_quotes WHERE id IN (' . implode(',', array_map('intval', $quoteIds)) . ')');
        if ($addressIds !== []) $conn->query('DELETE FROM customer_addresses WHERE id IN (' . implode(',', array_map('intval', $addressIds)) . ')');
        if ($restaurantId > 0) $conn->query('DELETE FROM restaurants WHERE id=' . $restaurantId);
        if ($userIds !== []) $conn->query('DELETE FROM users WHERE id IN (' . $userList . ')');
        $conn->close();
    }
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}

echo "PASS: demo route timing, scope, quoted destination, idempotency, and transaction boundaries hold\n";
