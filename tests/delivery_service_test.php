<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/services/delivery_service.php';
require_once __DIR__ . '/../lib/services/demo_route_service.php';

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
    fwrite(STDERR, "BLOCKED: delivery integration tests require SAVORA_ENV=test and SAVORA_DB_NAME=savora_test\n");
    exit(2);
}

require_once __DIR__ . '/support/test_database.php';

function delivery_test_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$conn = null;
$failure = null;
$ids = [];
$prefix = 'task16-delivery-' . bin2hex(random_bytes(5));
$uploadRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix;
$finishFailureTrigger = 'task6_finish_' . bin2hex(random_bytes(5));

try {
    if (!mkdir($uploadRoot, 0700, true) && !is_dir($uploadRoot)) throw new RuntimeException('Delivery evidence upload root could not be created.');
    putenv('SAVORA_UPLOAD_ROOT=' . $uploadRoot);
    $conn = savora_test_database();
    $password = password_hash('delivery-test', PASSWORD_DEFAULT);
    $user = $conn->prepare("INSERT INTO users(username,password,role,full_name,status) VALUES(?,?,?,?, 'active')");
    $role = 'customer'; $name = 'Delivery Customer'; $username = $prefix . '-customer'; $user->bind_param('ssss', $username, $password, $role, $name); $user->execute(); $customer = (int) $conn->insert_id; $ids['customer'] = $customer;
    $role = 'restaurant'; $name = 'Delivery Restaurant'; $username = $prefix . '-restaurant'; $user->bind_param('ssss', $username, $password, $role, $name); $user->execute(); $owner = (int) $conn->insert_id; $ids['owner'] = $owner;
    $role = 'driver'; $name = 'Delivery Driver'; $username = $prefix . '-driver'; $user->bind_param('ssss', $username, $password, $role, $name); $user->execute(); $driver = (int) $conn->insert_id; $ids['driver'] = $driver;
    $role = 'driver'; $name = 'Other Driver'; $username = $prefix . '-other'; $user->bind_param('ssss', $username, $password, $role, $name); $user->execute(); $otherDriver = (int) $conn->insert_id; $ids['other'] = $otherDriver; $user->close();

    $restaurant = $conn->prepare("INSERT INTO restaurants(owner_user_id,public_id,name,address,city,status,accepting_orders,latitude,longitude) VALUES(?,?,?,?, 'Test City','active',1,13.7563000,100.5018000)");
    $restaurantPublicId = strtolower($prefix); $restaurantName = 'Delivery Restaurant'; $address = 'Pickup Street'; $restaurant->bind_param('isss', $owner, $restaurantPublicId, $restaurantName, $address); $restaurant->execute(); $restaurantId = (int) $conn->insert_id; $restaurant->close(); $ids['restaurant'] = $restaurantId;
    $profile = $conn->prepare("INSERT INTO driver_profiles(user_id,city,eligibility_status,availability_status,rating,latitude,longitude,version) VALUES(?, 'Test City','eligible','online',4.5,13.7000000,100.6000000,1)");
    $profile->bind_param('i', $driver); $profile->execute(); $profile->close();
    $profile = $conn->prepare("INSERT INTO driver_profiles(user_id,city,eligibility_status,availability_status,rating,version) VALUES(?, 'Test City','eligible','offline',4.5,1)");
    $profile->bind_param('i', $otherDriver); $profile->execute(); $profile->close();
    $now = (string) ($conn->query('SELECT NOW() AS now')->fetch_assoc()['now'] ?? date('Y-m-d H:i:s'));
    $location = $conn->prepare('INSERT INTO driver_locations(driver_user_id,latitude,longitude,accuracy_meters,recorded_at,version) VALUES(?,?,?,?,?,1)');
    $lat = 13.7563; $lon = 100.5018; $accuracy = 5.0; $location->bind_param('iddds', $driver, $lat, $lon, $accuracy, $now); $location->execute(); $location->bind_param('iddds', $otherDriver, $lat, $lon, $accuracy, $now); $location->execute(); $location->close();

    putenv('SAVORA_DEMO_MODE=0');
    delivery_test_expect(function_exists('driver_start_demo_shift'), 'Driver demo shift service must exist.');
    $demoOff = driver_start_demo_shift($conn, $driver, $prefix . '-shift-off');
    delivery_test_expect(($demoOff['status'] ?? 0) === 404, 'Demo shift must be unavailable when demo mode is off.');
    putenv('SAVORA_DEMO_MODE=1');
    $conn->query("UPDATE driver_locations SET latitude=0,longitude=0,recorded_at=DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE driver_user_id={$driver}");
    $shiftBefore = new DateTimeImmutable((string) ($conn->query('SELECT NOW() AS now')->fetch_assoc()['now'] ?? $now));
    $demoShift = driver_start_demo_shift($conn, $driver, $prefix . '-shift-start');
    $shiftAfter = new DateTimeImmutable((string) ($conn->query('SELECT NOW() AS now')->fetch_assoc()['now'] ?? $now));
    $shiftLocation = $conn->query("SELECT latitude,longitude,recorded_at FROM driver_locations WHERE driver_user_id={$driver}")->fetch_assoc() ?: [];
    delivery_test_expect(($demoShift['ok'] ?? false) === true, 'Saved Driver profile coordinates must start a demo shift.');
    delivery_test_expect((float) ($shiftLocation['latitude'] ?? 0) === 13.7 && (float) ($shiftLocation['longitude'] ?? 0) === 100.6, 'Demo shift must copy saved profile coordinates.');
    $shiftRecordedAt = new DateTimeImmutable((string) ($shiftLocation['recorded_at'] ?? ''));
    delivery_test_expect($shiftRecordedAt >= $shiftBefore && $shiftRecordedAt <= $shiftAfter, 'Demo shift freshness must use MySQL NOW().');

    $address = $conn->prepare("INSERT INTO customer_addresses(customer_user_id,public_id,label,recipient_name,phone,address_line1,city,latitude,longitude,is_default) VALUES(?,?,'Demo','Delivery Customer','0800000000','Delivery address','Test City',13.7600000,100.6100000,1)");
    $addressPublicId = strtolower($prefix) . '-address'; $address->bind_param('is', $customer, $addressPublicId); $address->execute(); $address->close();

    $reference = strtoupper($prefix); $order = $conn->prepare("INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,delivery_fee,total,delivery_address) VALUES(?,?,?,'ready_for_pickup','cash',100,10,110,'Delivery address')"); $order->bind_param('sii', $reference, $customer, $restaurantId); $order->execute(); $orderId = (int) $conn->insert_id; $order->close(); $ids['order'] = $orderId;
    $payment = $conn->prepare("INSERT INTO payments(order_id,method,amount,status) VALUES(?,'cash',110,'pending')"); $payment->bind_param('i', $orderId); $payment->execute(); $payment->close();
    $dispatch = $conn->prepare("INSERT INTO delivery_dispatches(order_id,status,attempt_count,version) VALUES(?,'searching_driver',0,1)"); $dispatch->bind_param('i', $orderId); $dispatch->execute(); $dispatchId = (int) $conn->insert_id; $dispatch->close(); $ids['dispatch'] = $dispatchId;
    $offer = dispatch_offer_next_driver($conn, $dispatchId); $offerReference = (string) ($offer['data']['offer']['offerReference'] ?? '');
    delivery_test_expect($offerReference !== '', 'Delivery fixture must create an offer.');
    $accepted = dispatch_accept_offer($conn, $driver, $offerReference, $prefix . '-accept');
    $deliveryId = (int) ($accepted['data']['delivery']['deliveryId'] ?? 0); $ids['delivery'] = $deliveryId;
    delivery_test_expect(($accepted['ok'] ?? false) === true && $deliveryId > 0, 'Delivery fixture must be assigned.');

    $locationResult = driver_update_location($conn, $driver, 13.7564, 100.5019, 4.5, $now, 2, $prefix . '-location');
    delivery_test_expect(($locationResult['ok'] ?? false) === true && ($locationResult['data']['version'] ?? 0) === 3, 'Assigned Driver location should be versioned.');
    $foreignLocation = driver_update_location($conn, $otherDriver, 13.7564, 100.5019, 4.5, $now, 1, $prefix . '-foreign-location', $deliveryId);
    delivery_test_expect(($foreignLocation['status'] ?? 0) === 403, 'A non-owner Driver must not write this delivery location.');

    $arrived = delivery_record_arrival($conn, $driver, $deliveryId, 1, $prefix . '-arrival');
    delivery_test_expect(($arrived['ok'] ?? false) === true && ($arrived['data']['version'] ?? 0) === 2, 'Assigned delivery should move to arrived.');
    $stale = delivery_record_arrival($conn, $driver, $deliveryId, 1, $prefix . '-arrival-stale');
    delivery_test_expect(($stale['status'] ?? 0) === 409, 'Stale delivery versions must be rejected.');
    $pickedUp = delivery_record_pickup($conn, $driver, $deliveryId, 2, $prefix . '-pickup');
    delivery_test_expect(($pickedUp['ok'] ?? false) === true && ($pickedUp['data']['version'] ?? 0) === 3, 'Arrived delivery should move to picked_up.');

    $conn->query("UPDATE deliveries SET proof_required=1 WHERE id=" . $deliveryId);
    $missingEvidence = delivery_record_completion($conn, $driver, $deliveryId, 3, $prefix . '-complete-missing', []);
    delivery_test_expect(($missingEvidence['status'] ?? 0) === 422, 'Configured proof-of-delivery must be required.');
    $forgedEvidence = [[
        'type' => 'photo', 'storedPath' => 'proof/' . $prefix . '.jpg', 'mimeType' => 'image/jpeg', 'sizeBytes' => 1024,
        'sha256' => hash('sha256', $prefix), 'capturedAt' => $now,
    ]];
    $forged = delivery_record_completion($conn, $driver, $deliveryId, 3, $prefix . '-complete-forged', $forgedEvidence);
    delivery_test_expect(($forged['status'] ?? 0) === 422, 'Client-supplied proof metadata must not complete a delivery.');
    $source = $uploadRoot . DIRECTORY_SEPARATOR . 'proof-source.png';
    file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
    $storedEvidence = delivery_store_evidence_upload($conn, $driver, $deliveryId, 'photo', ['name' => 'proof.png', 'tmp_name' => $source, 'error' => UPLOAD_ERR_OK, 'size' => filesize($source)]);
    delivery_test_expect((int) ($storedEvidence['evidenceId'] ?? 0) > 0 && !isset($storedEvidence['storedPath']), 'Verified proof bytes must be stored server-side without exposing paths.');
    $completed = delivery_record_completion($conn, $driver, $deliveryId, 3, $prefix . '-complete', [(int) $storedEvidence['evidenceId']]);
    delivery_test_expect(($completed['ok'] ?? false) === true && ($completed['data']['status'] ?? '') === 'delivered', 'Picked-up cash delivery should complete with proof.');
    $proofRead = order_repository_admin($conn, $orderId);
    delivery_test_expect(($proofRead['assignment']['proofRequired'] ?? false) === true, 'Driver order reads must expose proof requirements.');
    $paymentStatus = (string) ($conn->query("SELECT status FROM payments WHERE order_id={$orderId}")->fetch_assoc()['status'] ?? '');
    delivery_test_expect($paymentStatus === 'pending', 'Driver delivery must leave cash payment pending for Customer receipt confirmation.');
    $milestones = (int) ($conn->query("SELECT COUNT(*) AS total FROM delivery_milestones WHERE delivery_id={$deliveryId}")->fetch_assoc()['total'] ?? 0);
    delivery_test_expect($milestones === 3, 'Three delivery milestones should retain server timestamps.');

    $demoReference = strtoupper($prefix . '-demo');
    $demoOrder = $conn->prepare("INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,delivery_fee,total,delivery_address) VALUES(?,?,?,'ready_for_pickup','cash',100,10,110,'Delivery address')");
    $demoOrder->bind_param('sii', $demoReference, $customer, $restaurantId); $demoOrder->execute(); $demoOrderId = (int) $conn->insert_id; $demoOrder->close();
    $demoPayment = $conn->prepare("INSERT INTO payments(order_id,method,amount,status) VALUES(?,'cash',110,'pending')"); $demoPayment->bind_param('i', $demoOrderId); $demoPayment->execute(); $demoPayment->close();
    $demoDispatch = $conn->prepare("INSERT INTO delivery_dispatches(order_id,status,attempt_count,version) VALUES(?,'searching_driver',0,1)"); $demoDispatch->bind_param('i', $demoOrderId); $demoDispatch->execute(); $demoDispatchId = (int) $conn->insert_id; $demoDispatch->close();
    $demoOffer = dispatch_offer_next_driver($conn, $demoDispatchId);
    $demoOfferReference = (string) ($demoOffer['data']['offer']['offerReference'] ?? '');
    delivery_test_expect($demoOfferReference !== '', 'Demo completion fixture must create an offer.');
    $demoAccepted = dispatch_accept_offer($conn, $driver, $demoOfferReference, $prefix . '-demo-accept');
    $demoDeliveryId = (int) ($demoAccepted['data']['delivery']['deliveryId'] ?? 0);
    delivery_test_expect(($demoAccepted['ok'] ?? false) === true && $demoDeliveryId > 0, 'Demo completion fixture must be assigned.');
    $demoStarted = demo_route_start($conn, $driver, $demoDeliveryId, 1, $prefix . '-demo-start');
    delivery_test_expect(($demoStarted['ok'] ?? false) === true, 'Assigned demo delivery must start atomically.');

    $earlyCompletion = delivery_record_completion($conn, $driver, $demoDeliveryId, 2, $prefix . '-demo-early', []);
    delivery_test_expect(($earlyCompletion['status'] ?? 0) === 409, 'Running demo route must reject early delivery completion.');
    $earlyState = $conn->query("SELECT d.status AS delivery_status,o.status AS order_status,dr.status AS route_status FROM deliveries d JOIN orders o ON o.id=d.order_id JOIN delivery_demo_routes dr ON dr.delivery_id=d.id WHERE d.id={$demoDeliveryId}")->fetch_assoc() ?: [];
    delivery_test_expect(($earlyState['delivery_status'] ?? '') === 'picked_up' && ($earlyState['order_status'] ?? '') === 'picked_up' && ($earlyState['route_status'] ?? '') === 'running', 'Early completion must leave delivery, order, and route unchanged.');

    $conn->query("UPDATE delivery_demo_routes SET started_at=DATE_SUB(NOW(), INTERVAL 61 SECOND) WHERE delivery_id={$demoDeliveryId}");
    $conn->query("CREATE TRIGGER {$finishFailureTrigger} BEFORE UPDATE ON delivery_demo_routes FOR EACH ROW BEGIN IF NEW.delivery_id={$demoDeliveryId} AND NEW.status='finished' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced route finish failure'; END IF; END");
    $finishFailed = false;
    try { delivery_record_completion($conn, $driver, $demoDeliveryId, 2, $prefix . '-demo-finish-failure', []); }
    catch (Throwable) { $finishFailed = true; }
    delivery_test_expect($finishFailed, 'A route finish failure must abort delivery completion.');
    $rolledBackState = $conn->query("SELECT d.status AS delivery_status,o.status AS order_status,dr.status AS route_status FROM deliveries d JOIN orders o ON o.id=d.order_id JOIN delivery_demo_routes dr ON dr.delivery_id=d.id WHERE d.id={$demoDeliveryId}")->fetch_assoc() ?: [];
    delivery_test_expect(($rolledBackState['delivery_status'] ?? '') === 'picked_up' && ($rolledBackState['order_status'] ?? '') === 'picked_up' && ($rolledBackState['route_status'] ?? '') === 'running', 'Route finish failure must roll back delivery and order completion.');
    $conn->query("DROP TRIGGER IF EXISTS {$finishFailureTrigger}");

    $demoCompleted = delivery_record_completion($conn, $driver, $demoDeliveryId, 2, $prefix . '-demo-complete', []);
    delivery_test_expect(($demoCompleted['ok'] ?? false) === true, 'Arrived demo route must complete without fabricated proof.');
    $demoCompletedState = $conn->query("SELECT d.status AS delivery_status,o.status AS order_status,dr.status AS route_status FROM deliveries d JOIN orders o ON o.id=d.order_id JOIN delivery_demo_routes dr ON dr.delivery_id=d.id WHERE d.id={$demoDeliveryId}")->fetch_assoc() ?: [];
    delivery_test_expect(($demoCompletedState['delivery_status'] ?? '') === 'delivered' && ($demoCompletedState['order_status'] ?? '') === 'delivered' && ($demoCompletedState['route_status'] ?? '') === 'finished', 'Successful completion must finish delivery, order, and route together.');

} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    if ($conn instanceof mysqli) {
        $conn->query("DROP TRIGGER IF EXISTS {$finishFailureTrigger}");
        $pattern = $prefix . '%';
        $queries = [
            'DELETE FROM notifications WHERE user_id IN (SELECT id FROM users WHERE username LIKE ?)',
            'DELETE FROM idempotency_keys WHERE actor_user_id IN (SELECT id FROM users WHERE username LIKE ?)',
            'DELETE FROM order_status_history WHERE order_id IN (SELECT id FROM orders WHERE reference_code LIKE ?)',
            'DELETE FROM delivery_milestones WHERE delivery_id IN (SELECT id FROM deliveries WHERE order_id IN (SELECT id FROM orders WHERE reference_code LIKE ?))',
            'DELETE FROM delivery_evidence WHERE delivery_id IN (SELECT id FROM deliveries WHERE order_id IN (SELECT id FROM orders WHERE reference_code LIKE ?))',
            'DELETE FROM delivery_offers WHERE dispatch_id IN (SELECT id FROM delivery_dispatches WHERE order_id IN (SELECT id FROM orders WHERE reference_code LIKE ?))',
            'DELETE FROM deliveries WHERE order_id IN (SELECT id FROM orders WHERE reference_code LIKE ?)',
            'DELETE FROM delivery_dispatches WHERE order_id IN (SELECT id FROM orders WHERE reference_code LIKE ?)',
            'DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE reference_code LIKE ?)',
            'DELETE FROM orders WHERE reference_code LIKE ?',
            'DELETE FROM customer_addresses WHERE customer_user_id IN (SELECT id FROM users WHERE username LIKE ?)',
            'DELETE FROM driver_locations WHERE driver_user_id IN (SELECT id FROM users WHERE username LIKE ?)',
            'DELETE FROM driver_profiles WHERE user_id IN (SELECT id FROM users WHERE username LIKE ?)',
            'DELETE FROM restaurants WHERE owner_user_id IN (SELECT id FROM users WHERE username LIKE ?)',
        ];
        foreach ($queries as $sql) { $stmt = $conn->prepare($sql); $stmt->bind_param('s', $pattern); $stmt->execute(); $stmt->close(); }
        $idsToDelete = array_values($ids); $idsToDelete = array_filter($idsToDelete, static fn ($value): bool => is_int($value) && $value > 0 && !in_array($value, [$ids['restaurant'] ?? -1, $ids['order'] ?? -1, $ids['dispatch'] ?? -1, $ids['delivery'] ?? -1], true));
        if ($idsToDelete !== []) { $placeholders = implode(',', array_fill(0, count($idsToDelete), '?')); $types = str_repeat('i', count($idsToDelete)); $stmt = $conn->prepare("DELETE FROM users WHERE id IN ({$placeholders})"); $stmt->bind_param($types, ...$idsToDelete); $stmt->execute(); $stmt->close(); }
        $conn->close();
    }
    if (getenv('SAVORA_UPLOAD_ROOT') === $uploadRoot) putenv('SAVORA_UPLOAD_ROOT');
    if (is_dir($uploadRoot)) { $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); rmdir($uploadRoot); }
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}
echo "PASS: authoritative delivery location, milestones, and proof metadata hold\n";
