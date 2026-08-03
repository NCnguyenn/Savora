<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/services/dispatch_service.php';

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
    fwrite(STDERR, "BLOCKED: dispatch integration tests require SAVORA_ENV=test and SAVORA_DB_NAME=savora_test\n");
    exit(2);
}

require_once __DIR__ . '/support/test_database.php';

function dispatch_test_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$conn = null;
$ids = [];
$prefix = 'task15-dispatch-' . bin2hex(random_bytes(5));

try {
    try { $conn = savora_test_database(); }
    catch (Throwable $exception) { fwrite(STDERR, "BLOCKED: {$exception->getMessage()}\n"); exit(2); }

    $password = password_hash('dispatch-test', PASSWORD_DEFAULT);
    $insertUser = $conn->prepare("INSERT INTO users(username,password,role,full_name,status) VALUES(?,?,?,?, 'active')");
    $role = 'customer'; $name = 'Dispatch Customer'; $username = $prefix . '-customer';
    $insertUser->bind_param('ssss', $username, $password, $role, $name); $insertUser->execute(); $customer = (int) $conn->insert_id; $ids['customer'] = $customer;
    $role = 'restaurant'; $name = 'Dispatch Restaurant'; $username = $prefix . '-restaurant';
    $insertUser->bind_param('ssss', $username, $password, $role, $name); $insertUser->execute(); $owner = (int) $conn->insert_id; $ids['owner'] = $owner;
    $driverIds = [];
    for ($i = 1; $i <= 3; $i++) {
        $role = 'driver'; $name = 'Dispatch Driver ' . $i; $username = $prefix . '-driver-' . $i;
        $insertUser->bind_param('ssss', $username, $password, $role, $name); $insertUser->execute();
        $driverIds[$i] = (int) $conn->insert_id; $ids['driver' . $i] = $driverIds[$i];
    }
    $insertUser->close();

    $restaurant = $conn->prepare("INSERT INTO restaurants(owner_user_id,name,address,city,status,accepting_orders,latitude,longitude) VALUES(?,?,?,'Test City','active',1,13.7563000,100.5018000)");
    $restaurantName = 'Dispatch Restaurant'; $address = 'Pickup Street';
    $restaurant->bind_param('iss', $owner, $restaurantName, $address); $restaurant->execute(); $restaurantId = (int) $conn->insert_id; $restaurant->close(); $ids['restaurant'] = $restaurantId;

    $profiles = $conn->prepare("INSERT INTO driver_profiles(user_id,city,eligibility_status,availability_status,rating,version) VALUES(?, 'Test City', ?, ?, 4.50, 1)");
    $eligibility = 'eligible'; $availability = 'online';
    foreach ($driverIds as $driverId) { $profiles->bind_param('iss', $driverId, $eligibility, $availability); $profiles->execute(); }
    $profiles->close();

    $now = (string) ($conn->query('SELECT NOW() AS now')->fetch_assoc()['now'] ?? date('Y-m-d H:i:s'));
    $locations = $conn->prepare('INSERT INTO driver_locations(driver_user_id,latitude,longitude,accuracy_meters,recorded_at,version) VALUES(?,?,?,?,?,1)');
    $accuracy = 5.0; $lat = 13.7563000; $lon = 100.5018000;
    foreach ($driverIds as $index => $driverId) {
        $lat = 13.7563000 + ($index * 0.01); $lon = 100.5018000;
        $locations->bind_param('iddds', $driverId, $lat, $lon, $accuracy, $now); $locations->execute();
    }
    $locations->close();

    $insertOrder = $conn->prepare("INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,delivery_fee,total,delivery_address) VALUES(?,?,?,'ready_for_pickup','cash',100,10,110,'Safe delivery address')");
    $reference = strtoupper($prefix); $insertOrder->bind_param('sii', $reference, $customer, $restaurantId); $insertOrder->execute(); $orderId = (int) $conn->insert_id; $insertOrder->close(); $ids['order'] = $orderId;
    $dispatch = $conn->prepare("INSERT INTO delivery_dispatches(order_id,status,attempt_count,version) VALUES(?,'searching_driver',0,1)"); $dispatch->bind_param('i', $orderId); $dispatch->execute(); $dispatchId = (int) $conn->insert_id; $dispatch->close(); $ids['dispatch'] = $dispatchId;

    $offer = dispatch_offer_next_driver($conn, $dispatchId);
    dispatch_test_expect(($offer['ok'] ?? false) === true, 'Eligible online driver should receive an offer.');
    dispatch_test_expect(($offer['data']['offer']['orderReference'] ?? '') === $reference, 'Offer must use the public order reference.');
    dispatch_test_expect(isset($offer['data']['offer']['offerReference'], $offer['data']['offer']['expiresAt']), 'Offer must expose its reference and expiry.');
    dispatch_test_expect(!array_key_exists('customerName', $offer['data']['offer']), 'Offer must not expose Customer PII before acceptance.');
    dispatch_test_expect(($offer['data']['offer']['distanceKm'] ?? -1) >= 0, 'Offer must contain a distance estimate.');

    $offerReference = (string) $offer['data']['offer']['offerReference'];
    $firstDriverId = (int) $offer['data']['offer']['driverUserId'];
    $retry = dispatch_offer_next_driver($conn, $dispatchId);
    dispatch_test_expect(($retry['data']['offer']['offerReference'] ?? '') === $offerReference, 'A dispatch may have only one active offer.');

    $declined = dispatch_decline_offer($conn, $firstDriverId, $offerReference, $prefix . '-decline-1', 'not_available');
    dispatch_test_expect(($declined['ok'] ?? false) === true, 'Driver decline should succeed.');
    $next = $declined['data']['offer'] ?? [];
    dispatch_test_expect(($next['driverUserId'] ?? 0) !== $firstDriverId && ($next['driverUserId'] ?? 0) > 0, 'Declining an offer must advance to another eligible driver.');

    $nextDriverId = (int) $next['driverUserId'];
    $acceptKey = $prefix . '-accept-1';
    $accepted = dispatch_accept_offer($conn, $nextDriverId, (string) $next['offerReference'], $acceptKey);
    dispatch_test_expect(($accepted['ok'] ?? false) === true, 'Driver acceptance should succeed.');
    dispatch_test_expect(($accepted['data']['delivery']['driverUserId'] ?? 0) === $nextDriverId, 'Accepted offer must assign the accepting driver.');
    dispatch_test_expect(($accepted['data']['delivery']['deliveryAddress'] ?? '') === 'Safe delivery address', 'Delivery-safe address is returned only after acceptance.');

    $sameKey = dispatch_accept_offer($conn, $nextDriverId, (string) $next['offerReference'], $acceptKey);
    dispatch_test_expect(($sameKey['ok'] ?? false) === true, 'Same-key acceptance retry must replay the response.');
    $count = $conn->query("SELECT COUNT(*) AS total FROM deliveries WHERE order_id={$orderId}")->fetch_assoc();
    dispatch_test_expect((int) $count['total'] === 1, 'Exactly one delivery must be created.');

    $availability = driver_set_availability($conn, $firstDriverId, 'offline', null, null, null, null, $prefix . '-offline');
    dispatch_test_expect(($availability['ok'] ?? false) === true, 'Driver may explicitly go offline.');
    $conn->query("UPDATE driver_profiles SET availability_status='offline',eligibility_status='ineligible' WHERE user_id=" . (int) $driverIds[3]);
    $secondReference = strtoupper($prefix . '-SECOND');
    $insertSecond = $conn->prepare("INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,delivery_fee,total,delivery_address) VALUES(?,?,?,'ready_for_pickup','cash',50,5,55,'Second safe address')");
    $insertSecond->bind_param('sii', $secondReference, $customer, $restaurantId); $insertSecond->execute(); $secondOrderId = (int) $conn->insert_id; $insertSecond->close();
    $insertSecondDispatch = $conn->prepare("INSERT INTO delivery_dispatches(order_id,status,attempt_count,version) VALUES(?,'searching_driver',0,1)"); $insertSecondDispatch->bind_param('i', $secondOrderId); $insertSecondDispatch->execute(); $secondDispatchId = (int) $conn->insert_id; $insertSecondDispatch->close();
    $none = dispatch_offer_next_driver($conn, $secondDispatchId);
    dispatch_test_expect(($none['data']['offer'] ?? null) === null, 'Offline, ineligible and busy drivers must not receive an offer.');

    $online = driver_set_availability($conn, $firstDriverId, 'online', 13.7563, 100.5018, 5.0, null, $prefix . '-online');
    dispatch_test_expect(($online['ok'] ?? false) === true, 'Eligible driver may return online with a location.');
    $thirdReference = strtoupper($prefix . '-THIRD');
    $insertThird = $conn->prepare("INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,delivery_fee,total,delivery_address) VALUES(?,?,?,'ready_for_pickup','card',40,4,44,'Third safe address')");
    $insertThird->bind_param('sii', $thirdReference, $customer, $restaurantId); $insertThird->execute(); $thirdOrderId = (int) $conn->insert_id; $insertThird->close();
    $insertThirdDispatch = $conn->prepare("INSERT INTO delivery_dispatches(order_id,status,attempt_count,version) VALUES(?,'searching_driver',0,1)"); $insertThirdDispatch->bind_param('i', $thirdOrderId); $insertThirdDispatch->execute(); $thirdDispatchId = (int) $conn->insert_id; $insertThirdDispatch->close();
    $expiring = dispatch_offer_next_driver($conn, $thirdDispatchId);
    dispatch_test_expect(($expiring['data']['offer']['offerReference'] ?? '') !== '', 'A fresh dispatch should create an offer for the restored driver.');
    $expireReference = (string) $expiring['data']['offer']['offerReference'];
    $expireUpdate = $conn->prepare("UPDATE delivery_offers SET expires_at=DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE public_id=?"); $expireUpdate->bind_param('s', $expireReference); $expireUpdate->execute(); $expireUpdate->close();
    $expired = dispatch_expire_offers($conn, $thirdDispatchId);
    dispatch_test_expect((int) ($expired['data']['expiredCount'] ?? 0) === 1, 'Expired offers must be recorded after the 30-second window.');

    echo "PASS: authoritative dispatch offer lifecycle holds\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} finally {
    if ($conn instanceof mysqli) {
        $pattern = $prefix . '%';
        $queries = [
            'DELETE o FROM audit_logs o WHERE o.reference_id LIKE ?',
            'DELETE n FROM notifications n WHERE n.message LIKE ?',
            'DELETE oi FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.reference_code LIKE ?',
            'DELETE df FROM delivery_offers df JOIN delivery_dispatches dd ON dd.id=df.dispatch_id JOIN orders o ON o.id=dd.order_id WHERE o.reference_code LIKE ?',
            'DELETE FROM deliveries WHERE order_id IN (SELECT id FROM orders WHERE reference_code LIKE ?)',
            'DELETE FROM delivery_dispatches WHERE order_id IN (SELECT id FROM orders WHERE reference_code LIKE ?)',
            'DELETE FROM order_status_history WHERE order_id IN (SELECT id FROM orders WHERE reference_code LIKE ?)',
            'DELETE FROM orders WHERE reference_code LIKE ?',
            'DELETE FROM driver_locations WHERE driver_user_id IN (SELECT id FROM users WHERE username LIKE ?)',
            'DELETE FROM driver_profiles WHERE user_id IN (SELECT id FROM users WHERE username LIKE ?)',
        ];
        foreach ($queries as $sql) { $stmt = $conn->prepare($sql); $stmt->bind_param('s', $pattern); $stmt->execute(); $stmt->close(); }
        if ($ids['restaurant'] ?? null) { $stmt = $conn->prepare('DELETE FROM restaurants WHERE id=?'); $stmt->bind_param('i', $ids['restaurant']); $stmt->execute(); $stmt->close(); }
        $userIds = array_values($ids); $userIds = array_filter($userIds, static fn ($value) => is_int($value) && $value > 0 && $value !== ($ids['restaurant'] ?? -1) && $value !== ($ids['order'] ?? -1) && $value !== ($ids['dispatch'] ?? -1));
        if ($userIds !== []) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?')); $types = str_repeat('i', count($userIds));
            $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id IN ({$placeholders})"); $stmt->bind_param($types, ...$userIds); $stmt->execute(); $stmt->close();
            $stmt = $conn->prepare("DELETE FROM idempotency_keys WHERE actor_user_id IN ({$placeholders})"); $stmt->bind_param($types, ...$userIds); $stmt->execute(); $stmt->close();
            $stmt = $conn->prepare("DELETE FROM users WHERE id IN ({$placeholders})"); $stmt->bind_param($types, ...$userIds); $stmt->execute(); $stmt->close();
        }
        $conn->close();
    }
}
