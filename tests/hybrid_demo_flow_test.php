<?php
declare(strict_types=1);

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
    fwrite(STDERR, "BLOCKED: hybrid demo integration tests require SAVORA_ENV=test and SAVORA_DB_NAME=savora_test\n");
    exit(2);
}

require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/migrations.php';
require_once __DIR__ . '/../lib/services/payment_confirmation_service.php';
require_once __DIR__ . '/../lib/services/order_transition_service.php';
require_once __DIR__ . '/../lib/services/demo_route_service.php';
require_once __DIR__ . '/../lib/services/delivery_service.php';
require_once __DIR__ . '/../lib/services/customer_receipt_service.php';

function hybrid_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function hybrid_insert_user(mysqli $conn, string $username, string $role, string $name): int
{
    $password = password_hash('hybrid-demo-flow', PASSWORD_DEFAULT);
    $statement = $conn->prepare("INSERT INTO users(username,password,role,full_name,status) VALUES(?,?,?,?,'active')");
    $statement->bind_param('ssss', $username, $password, $role, $name);
    $statement->execute();
    $id = (int) $statement->insert_id;
    $statement->close();
    return $id;
}

function hybrid_insert_order(mysqli $conn, string $reference, int $customerId, int $restaurantId, string $method): int
{
    $order = $conn->prepare(
        "INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,delivery_fee,total,delivery_address)
         VALUES(?,?,?,'pending',?,100,10,110,'Hybrid demo delivery address')"
    );
    $order->bind_param('siis', $reference, $customerId, $restaurantId, $method);
    $order->execute();
    $orderId = (int) $order->insert_id;
    $order->close();

    $payment = $conn->prepare('INSERT INTO payments(order_id,method,amount,status) VALUES(?,?,110,\'pending\')');
    $payment->bind_param('is', $orderId, $method);
    $payment->execute();
    $payment->close();
    return $orderId;
}

function hybrid_order_state(mysqli $conn, int $orderId): array
{
    $statement = $conn->prepare(
        'SELECT o.status,o.version,p.status AS payment_status,p.version AS payment_version,d.status AS delivery_status,d.version AS delivery_version
         FROM orders o JOIN payments p ON p.order_id=o.id LEFT JOIN deliveries d ON d.order_id=o.id AND d.superseded_at IS NULL WHERE o.id=?'
    );
    $statement->bind_param('i', $orderId);
    $statement->execute();
    $state = $statement->get_result()->fetch_assoc() ?: [];
    $statement->close();
    return $state;
}

function hybrid_offer_reference(mysqli $conn, int $dispatchId): string
{
    $statement = $conn->prepare("SELECT public_id FROM delivery_offers WHERE dispatch_id=? AND status='sent' ORDER BY id DESC LIMIT 1");
    $statement->bind_param('i', $dispatchId);
    $statement->execute();
    $reference = (string) ($statement->get_result()->fetch_assoc()['public_id'] ?? '');
    $statement->close();
    return $reference;
}

function hybrid_assert_history(mysqli $conn, int $orderId): void
{
    $statement = $conn->prepare('SELECT status,actor_role FROM order_status_history WHERE order_id=? ORDER BY id ASC');
    $statement->bind_param('i', $orderId);
    $statement->execute();
    $history = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    hybrid_expect($history === [
        ['status' => 'confirmed', 'actor_role' => 'restaurant'],
        ['status' => 'ready_for_pickup', 'actor_role' => 'restaurant'],
        ['status' => 'assigned', 'actor_role' => 'driver'],
        ['status' => 'picked_up', 'actor_role' => 'driver'],
        ['status' => 'delivered', 'actor_role' => 'driver'],
        ['status' => 'completed', 'actor_role' => 'customer'],
    ], 'Order history must record each status with its authoritative actor role.');
}

function hybrid_assert_notifications(mysqli $conn, int $orderId, bool $isSeaPay): void
{
    $statement = $conn->prepare('SELECT event_type,COUNT(*) AS total FROM notifications WHERE entity_id=? GROUP BY event_type');
    $statement->bind_param('i', $orderId);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    $counts = [];
    foreach ($rows as $row) $counts[(string) $row['event_type']] = (int) $row['total'];
    foreach (['order_status_changed', 'delivery_assigned', 'delivery_picked_up', 'delivery_delivered'] as $event) {
        hybrid_expect(($counts[$event] ?? 0) >= 1, "Notification {$event} must exist.");
    }
    hybrid_expect(($counts['order_completed'] ?? 0) === 2, 'Customer completion must notify both Restaurant and Driver exactly once.');
    hybrid_expect(($counts['payment_confirmed'] ?? 0) === ($isSeaPay ? 1 : 0), 'Only SeaPay simulation must create the payment confirmation notification.');
}

function hybrid_run_order_flow(mysqli $conn, array $actors, int $orderId, string $reference, bool $isSeaPay, string $keyPrefix): void
{
    $customerId = (int) $actors['customer'];
    $ownerId = (int) $actors['owner'];
    $driverId = (int) $actors['driver'];

    $initial = hybrid_order_state($conn, $orderId);
    hybrid_expect(($initial['status'] ?? '') === 'pending' && ($initial['payment_status'] ?? '') === 'pending' && (int) ($initial['version'] ?? 0) === 1, 'New hybrid order must begin pending with a pending payment and version one.');

    if ($isSeaPay) {
        $paid = payment_simulate_customer_success($conn, $customerId, $reference, $keyPrefix . '-payment');
        hybrid_expect(($paid['ok'] ?? false) === true && ($paid['data']['paymentStatus'] ?? '') === 'paid', 'SeaPay demo payment must settle before Restaurant processing.');
        $paidReplay = payment_simulate_customer_success($conn, $customerId, $reference, $keyPrefix . '-payment');
        hybrid_expect($paidReplay === $paid, 'SeaPay payment replay must return the stored response exactly.');
        hybrid_expect((hybrid_order_state($conn, $orderId)['payment_version'] ?? 0) === 2, 'SeaPay simulation must increment payment version once.');
    }

    $confirmed = order_transition($conn, ['userId' => $ownerId, 'role' => 'restaurant'], $reference, 'confirmed', 1, $keyPrefix . '-confirm');
    hybrid_expect(($confirmed['ok'] ?? false) === true && ($confirmed['data']['version'] ?? 0) === 2, 'Restaurant must confirm version one order.');
    $confirmedReplay = order_transition($conn, ['userId' => $ownerId, 'role' => 'restaurant'], $reference, 'confirmed', 1, $keyPrefix . '-confirm');
    hybrid_expect($confirmedReplay === $confirmed, 'Restaurant confirmation replay must return the stored response exactly.');
    $ready = order_transition($conn, ['userId' => $ownerId, 'role' => 'restaurant'], $reference, 'ready_for_pickup', 2, $keyPrefix . '-ready');
    $dispatchId = (int) ($ready['data']['dispatch']['dispatchId'] ?? 0);
    hybrid_expect(($ready['ok'] ?? false) === true && ($ready['data']['version'] ?? 0) === 3 && $dispatchId > 0, 'Restaurant ready state must create a dispatch and increment order version.');
    $readyReplay = order_transition($conn, ['userId' => $ownerId, 'role' => 'restaurant'], $reference, 'ready_for_pickup', 2, $keyPrefix . '-ready');
    hybrid_expect($readyReplay === $ready, 'Restaurant ready replay must return the stored response exactly.');

    $offerReference = hybrid_offer_reference($conn, $dispatchId);
    hybrid_expect($offerReference !== '', 'Ready order must automatically offer the saved, online Driver a delivery.');
    $accepted = dispatch_accept_offer($conn, $driverId, $offerReference, $keyPrefix . '-accept');
    $deliveryId = (int) ($accepted['data']['delivery']['deliveryId'] ?? 0);
    hybrid_expect(($accepted['ok'] ?? false) === true && ($accepted['data']['orderVersion'] ?? 0) === 4 && $deliveryId > 0, 'Driver assignment must advance the order to version four.');
    $acceptedReplay = dispatch_accept_offer($conn, $driverId, $offerReference, $keyPrefix . '-accept');
    hybrid_expect($acceptedReplay === $accepted, 'Driver offer acceptance replay must return the stored response exactly.');

    $started = demo_route_start($conn, $driverId, $deliveryId, 1, $keyPrefix . '-start-route');
    hybrid_expect(($started['ok'] ?? false) === true && ($started['data']['deliveryStatus'] ?? '') === 'picked_up' && ($started['data']['orderVersion'] ?? 0) === 5, 'Demo route must atomically pick up the assigned order.');
    $startedReplay = demo_route_start($conn, $driverId, $deliveryId, 1, $keyPrefix . '-start-route');
    hybrid_expect($startedReplay === $started, 'Demo route replay must return the stored response exactly.');

    $route = $conn->query('SELECT * FROM delivery_demo_routes WHERE delivery_id=' . $deliveryId)->fetch_assoc() ?: [];
    $startedAt = new DateTimeImmutable((string) ($route['started_at'] ?? ''));
    $halfway = demo_route_calculate_point($route, $startedAt->modify('+30 seconds'));
    hybrid_expect(abs((float) ($halfway['progress'] ?? -1) - 0.5) < 0.0001, 'Injected thirty seconds must produce 0.5 route progress.');
    $early = delivery_record_completion($conn, $driverId, $deliveryId, 2, $keyPrefix . '-early-delivery');
    hybrid_expect(($early['status'] ?? 0) === 409, 'Driver must not complete delivery before the demo route arrives.');
    $arrived = demo_route_calculate_point($route, $startedAt->modify('+61 seconds'));
    hybrid_expect(($arrived['arrived'] ?? false) === true, 'Injected sixty-one seconds must mark the route arrived.');
    $conn->query('UPDATE delivery_demo_routes SET started_at=DATE_SUB(NOW(), INTERVAL 61 SECOND) WHERE delivery_id=' . $deliveryId);
    $delivered = delivery_record_completion($conn, $driverId, $deliveryId, 2, $keyPrefix . '-delivered');
    hybrid_expect(($delivered['ok'] ?? false) === true && ($delivered['data']['version'] ?? 0) === 3 && ($delivered['data']['orderStatus'] ?? '') === 'delivered', 'Arrived Driver must deliver the route-backed order.');
    $deliveredReplay = delivery_record_completion($conn, $driverId, $deliveryId, 2, $keyPrefix . '-delivered');
    hybrid_expect($deliveredReplay === $delivered, 'Driver delivery replay must return the stored response exactly.');

    $deliveredState = hybrid_order_state($conn, $orderId);
    hybrid_expect(($deliveredState['status'] ?? '') === 'delivered' && (int) ($deliveredState['version'] ?? 0) === 6 && ($deliveredState['payment_status'] ?? '') === ($isSeaPay ? 'paid' : 'pending'), 'Driver delivery must preserve the correct payment state and order version six.');
    $receipt = customer_confirm_receipt($conn, $customerId, $reference, 6, $keyPrefix . '-receipt');
    hybrid_expect(($receipt['ok'] ?? false) === true && ($receipt['data']['status'] ?? '') === 'completed' && ($receipt['data']['paymentStatus'] ?? '') === 'paid' && ($receipt['data']['version'] ?? 0) === 7, 'Customer receipt confirmation must complete version six order and report paid.');
    $receiptReplay = customer_confirm_receipt($conn, $customerId, $reference, 6, $keyPrefix . '-receipt');
    hybrid_expect($receiptReplay === $receipt, 'Customer receipt replay must return the stored response exactly.');

    $completed = hybrid_order_state($conn, $orderId);
    hybrid_expect(($completed['status'] ?? '') === 'completed' && (int) ($completed['version'] ?? 0) === 7 && ($completed['payment_status'] ?? '') === 'paid' && (int) ($completed['delivery_version'] ?? 0) === 3, 'Completed hybrid order must retain all current state versions.');
    hybrid_assert_history($conn, $orderId);
    hybrid_assert_notifications($conn, $orderId, $isSeaPay);
}

$conn = null;
$failure = null;
$userIds = [];
$orderIds = [];
$addressIds = [];
$restaurantId = 0;
$suffix = strtolower(bin2hex(random_bytes(5)));
$previousDemoMode = getenv('SAVORA_DEMO_MODE');

try {
    putenv('SAVORA_DEMO_MODE=1');
    $conn = savora_test_database();
    savora_apply_migrations($conn);

    $customerId = hybrid_insert_user($conn, 'hybrid-' . $suffix . '-customer', 'customer', 'Hybrid Customer');
    $ownerId = hybrid_insert_user($conn, 'hybrid-' . $suffix . '-restaurant', 'restaurant', 'Hybrid Restaurant');
    $driverId = hybrid_insert_user($conn, 'hybrid-' . $suffix . '-driver', 'driver', 'Hybrid Driver');
    $userIds = [$customerId, $ownerId, $driverId];
    $restaurant = $conn->prepare("INSERT INTO restaurants(owner_user_id,public_id,name,address,city,status,accepting_orders,latitude,longitude) VALUES(?,?, 'Hybrid Restaurant','Demo pickup','Test City','active',1,13.7563000,100.5018000)");
    $publicId = 'hybrid-' . $suffix;
    $restaurant->bind_param('is', $ownerId, $publicId);
    $restaurant->execute();
    $restaurantId = (int) $restaurant->insert_id;
    $restaurant->close();

    $profile = $conn->prepare("INSERT INTO driver_profiles(user_id,city,eligibility_status,availability_status,rating,latitude,longitude,version) VALUES(?,'Test City','eligible','offline',4.8,13.7000000,100.6000000,1)");
    $profile->bind_param('i', $driverId);
    $profile->execute();
    $profile->close();
    $address = $conn->prepare("INSERT INTO customer_addresses(customer_user_id,public_id,label,recipient_name,phone,address_line1,city,latitude,longitude,is_default) VALUES(?,?, 'Demo','Hybrid Customer','0800000000','Demo destination','Test City',13.7600000,100.6100000,1)");
    $addressPublicId = 'hybrid-address-' . $suffix;
    $address->bind_param('is', $customerId, $addressPublicId);
    $address->execute();
    $addressIds[] = (int) $address->insert_id;
    $address->close();

    $shift = driver_start_demo_shift($conn, $driverId, 'hybrid-' . $suffix . '-shift');
    hybrid_expect(($shift['ok'] ?? false) === true, 'Driver must start a demo shift from saved profile coordinates before dispatch.');
    $shiftReplay = driver_start_demo_shift($conn, $driverId, 'hybrid-' . $suffix . '-shift');
    hybrid_expect($shiftReplay === $shift, 'Demo shift replay must return the stored response exactly.');
    $location = $conn->query('SELECT latitude,longitude FROM driver_locations WHERE driver_user_id=' . $driverId)->fetch_assoc() ?: [];
    hybrid_expect((float) ($location['latitude'] ?? 0) === 13.7 && (float) ($location['longitude'] ?? 0) === 100.6, 'Demo shift must refresh the Driver location from saved profile coordinates.');

    $seapayReference = strtoupper('SVR-HYBRID-' . $suffix . '-SEAPAY');
    $codReference = strtoupper('SVR-HYBRID-' . $suffix . '-COD');
    $orderIds[] = hybrid_insert_order($conn, $seapayReference, $customerId, $restaurantId, 'seapay');
    $orderIds[] = hybrid_insert_order($conn, $codReference, $customerId, $restaurantId, 'cash');
    $actors = ['customer' => $customerId, 'owner' => $ownerId, 'driver' => $driverId];
    hybrid_run_order_flow($conn, $actors, $orderIds[0], $seapayReference, true, 'hybrid-' . $suffix . '-seapay');
    hybrid_run_order_flow($conn, $actors, $orderIds[1], $codReference, false, 'hybrid-' . $suffix . '-cod');
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    putenv($previousDemoMode === false ? 'SAVORA_DEMO_MODE' : 'SAVORA_DEMO_MODE=' . $previousDemoMode);
    if ($conn instanceof mysqli) {
        if ($userIds !== []) {
            $userList = implode(',', array_map('intval', $userIds));
            $conn->query('DELETE FROM audit_logs WHERE actor_user_id IN (' . $userList . ')');
            $conn->query('DELETE FROM idempotency_keys WHERE actor_user_id IN (' . $userList . ')');
            $conn->query('DELETE FROM notifications WHERE user_id IN (' . $userList . ')');
            $conn->query('DELETE FROM driver_locations WHERE driver_user_id IN (' . $userList . ')');
            $conn->query('DELETE FROM driver_profiles WHERE user_id IN (' . $userList . ')');
        }
        if ($orderIds !== []) {
            $orderList = implode(',', array_map('intval', $orderIds));
            $conn->query('DELETE FROM order_status_history WHERE order_id IN (' . $orderList . ')');
            $conn->query('DELETE FROM delivery_milestones WHERE delivery_id IN (SELECT id FROM deliveries WHERE order_id IN (' . $orderList . '))');
            $conn->query('DELETE FROM delivery_demo_routes WHERE delivery_id IN (SELECT id FROM deliveries WHERE order_id IN (' . $orderList . '))');
            $conn->query('DELETE FROM delivery_offers WHERE dispatch_id IN (SELECT id FROM delivery_dispatches WHERE order_id IN (' . $orderList . '))');
            $conn->query('DELETE FROM deliveries WHERE order_id IN (' . $orderList . ')');
            $conn->query('DELETE FROM delivery_dispatches WHERE order_id IN (' . $orderList . ')');
            $conn->query('DELETE FROM payments WHERE order_id IN (' . $orderList . ')');
            $conn->query('DELETE FROM orders WHERE id IN (' . $orderList . ')');
        }
        if ($addressIds !== []) $conn->query('DELETE FROM customer_addresses WHERE id IN (' . implode(',', array_map('intval', $addressIds)) . ')');
        if ($restaurantId > 0) $conn->query('DELETE FROM restaurants WHERE id=' . $restaurantId);
        if ($userIds !== []) $conn->query('DELETE FROM users WHERE id IN (' . implode(',', array_map('intval', $userIds)) . ')');
        $conn->close();
    }
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}

echo "PASS: SeaPay and COD hybrid payment GPS demo flows are stateful, versioned, notified, and idempotent\n";
