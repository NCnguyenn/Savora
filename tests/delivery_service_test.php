<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/services/delivery_service.php';

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
    $profile = $conn->prepare("INSERT INTO driver_profiles(user_id,city,eligibility_status,availability_status,rating,version) VALUES(?, 'Test City','eligible','online',4.5,1)");
    $profile->bind_param('i', $driver); $profile->execute(); $profile->close();
    $profile = $conn->prepare("INSERT INTO driver_profiles(user_id,city,eligibility_status,availability_status,rating,version) VALUES(?, 'Test City','eligible','offline',4.5,1)");
    $profile->bind_param('i', $otherDriver); $profile->execute(); $profile->close();
    $now = (string) ($conn->query('SELECT NOW() AS now')->fetch_assoc()['now'] ?? date('Y-m-d H:i:s'));
    $location = $conn->prepare('INSERT INTO driver_locations(driver_user_id,latitude,longitude,accuracy_meters,recorded_at,version) VALUES(?,?,?,?,?,1)');
    $lat = 13.7563; $lon = 100.5018; $accuracy = 5.0; $location->bind_param('iddds', $driver, $lat, $lon, $accuracy, $now); $location->execute(); $location->bind_param('iddds', $otherDriver, $lat, $lon, $accuracy, $now); $location->execute(); $location->close();

    $reference = strtoupper($prefix); $order = $conn->prepare("INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,delivery_fee,total,delivery_address) VALUES(?,?,?,'ready_for_pickup','cash',100,10,110,'Delivery address')"); $order->bind_param('sii', $reference, $customer, $restaurantId); $order->execute(); $orderId = (int) $conn->insert_id; $order->close(); $ids['order'] = $orderId;
    $payment = $conn->prepare("INSERT INTO payments(order_id,method,amount,status) VALUES(?,'cash',110,'pending')"); $payment->bind_param('i', $orderId); $payment->execute(); $payment->close();
    $dispatch = $conn->prepare("INSERT INTO delivery_dispatches(order_id,status,attempt_count,version) VALUES(?,'searching_driver',0,1)"); $dispatch->bind_param('i', $orderId); $dispatch->execute(); $dispatchId = (int) $conn->insert_id; $dispatch->close(); $ids['dispatch'] = $dispatchId;
    $offer = dispatch_offer_next_driver($conn, $dispatchId); $offerReference = (string) ($offer['data']['offer']['offerReference'] ?? '');
    delivery_test_expect($offerReference !== '', 'Delivery fixture must create an offer.');
    $accepted = dispatch_accept_offer($conn, $driver, $offerReference, $prefix . '-accept');
    $deliveryId = (int) ($accepted['data']['delivery']['deliveryId'] ?? 0); $ids['delivery'] = $deliveryId;
    delivery_test_expect(($accepted['ok'] ?? false) === true && $deliveryId > 0, 'Delivery fixture must be assigned.');

    $locationResult = driver_update_location($conn, $driver, 13.7564, 100.5019, 4.5, $now, 1, $prefix . '-location');
    delivery_test_expect(($locationResult['ok'] ?? false) === true && ($locationResult['data']['version'] ?? 0) === 2, 'Assigned Driver location should be versioned.');
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
    $paymentStatus = (string) ($conn->query("SELECT status FROM payments WHERE order_id={$orderId}")->fetch_assoc()['status'] ?? '');
    delivery_test_expect($paymentStatus === 'pending', 'Driver delivery must leave cash payment pending for Customer receipt confirmation.');
    $milestones = (int) ($conn->query("SELECT COUNT(*) AS total FROM delivery_milestones WHERE delivery_id={$deliveryId}")->fetch_assoc()['total'] ?? 0);
    delivery_test_expect($milestones === 3, 'Three delivery milestones should retain server timestamps.');

} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    if ($conn instanceof mysqli) {
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
