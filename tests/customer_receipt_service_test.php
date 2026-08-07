<?php
declare(strict_types=1);

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
    fwrite(STDERR, "BLOCKED: Customer receipt integration tests require SAVORA_ENV=test and SAVORA_DB_NAME=savora_test\n");
    exit(2);
}

require_once __DIR__ . '/support/test_database.php';

$servicePath = __DIR__ . '/../lib/services/customer_receipt_service.php';
if (!is_file($servicePath)) {
    throw new RuntimeException('Customer receipt service is missing.');
}
require_once $servicePath;

function receipt_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function receipt_insert_user(mysqli $conn, string $username, string $role, string $name): int
{
    $password = password_hash('task-4-receipt-test', PASSWORD_DEFAULT);
    $statement = $conn->prepare("INSERT INTO users(username,password,role,full_name,status) VALUES(?,?,?,?,'active')");
    $statement->bind_param('ssss', $username, $password, $role, $name);
    $statement->execute();
    $id = (int) $statement->insert_id;
    $statement->close();
    return $id;
}

function receipt_insert_order(
    mysqli $conn,
    string $reference,
    int $customerId,
    int $restaurantId,
    int $driverId,
    string $orderStatus,
    int $orderVersion,
    string $paymentMethod,
    string $paymentStatus
): int {
    $order = $conn->prepare(
        'INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,delivery_fee,total,delivery_address,version)
         VALUES(?,?,?,?,?,100,10,110,\'Receipt address\',?)'
    );
    $order->bind_param('siissi', $reference, $customerId, $restaurantId, $orderStatus, $paymentMethod, $orderVersion);
    $order->execute();
    $orderId = (int) $order->insert_id;
    $order->close();

    $payment = $conn->prepare('INSERT INTO payments(order_id,method,amount,status) VALUES(?,?,110,?)');
    $payment->bind_param('iss', $orderId, $paymentMethod, $paymentStatus);
    $payment->execute();
    $payment->close();

    $delivery = $conn->prepare('INSERT INTO deliveries(order_id,driver_user_id,status,accepted_at,delivered_at,version) VALUES(?,?,?,NOW(),IF(?=\'delivered\',NOW(),NULL),3)');
    $delivery->bind_param('iiss', $orderId, $driverId, $orderStatus, $orderStatus);
    $delivery->execute();
    $delivery->close();
    return $orderId;
}

function receipt_order_state(mysqli $conn, int $orderId): array
{
    $statement = $conn->prepare('SELECT o.status,o.version,p.status AS payment_status,p.version AS payment_version,p.paid_at FROM orders o JOIN payments p ON p.order_id=o.id WHERE o.id=?');
    $statement->bind_param('i', $orderId);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc() ?: [];
    $statement->close();
    return $row;
}

$conn = null;
$failure = null;
$userIds = [];
$orderIds = [];
$restaurantId = 0;
$suffix = strtoupper(bin2hex(random_bytes(5)));
$references = [
    'cod' => 'SVR-' . $suffix . '-COD',
    'prepaid' => 'SVR-' . $suffix . '-PREPAID',
    'early' => 'SVR-' . $suffix . '-EARLY',
    'stale' => 'SVR-' . $suffix . '-STALE',
    'unpaid' => 'SVR-' . $suffix . '-UNPAID',
];

try {
    $conn = savora_test_database();
    $customerId = receipt_insert_user($conn, strtolower('receipt-' . $suffix . '-customer'), 'customer', 'Receipt Customer');
    $otherCustomerId = receipt_insert_user($conn, strtolower('receipt-' . $suffix . '-other'), 'customer', 'Other Customer');
    $ownerId = receipt_insert_user($conn, strtolower('receipt-' . $suffix . '-owner'), 'restaurant', 'Receipt Restaurant');
    $driverId = receipt_insert_user($conn, strtolower('receipt-' . $suffix . '-driver'), 'driver', 'Receipt Driver');
    $userIds = [$customerId, $otherCustomerId, $ownerId, $driverId];

    $restaurantPublicId = strtolower('receipt-' . $suffix);
    $restaurant = $conn->prepare("INSERT INTO restaurants(owner_user_id,public_id,name,address,city,status,accepting_orders) VALUES(?,?,'Receipt Restaurant','Pickup street','Test City','active',1)");
    $restaurant->bind_param('is', $ownerId, $restaurantPublicId);
    $restaurant->execute();
    $restaurantId = (int) $restaurant->insert_id;
    $restaurant->close();

    $orderIds['cod'] = receipt_insert_order($conn, $references['cod'], $customerId, $restaurantId, $driverId, 'delivered', 3, 'cash', 'pending');
    $orderIds['prepaid'] = receipt_insert_order($conn, $references['prepaid'], $customerId, $restaurantId, $driverId, 'delivered', 2, 'seapay', 'paid');
    $orderIds['early'] = receipt_insert_order($conn, $references['early'], $customerId, $restaurantId, $driverId, 'picked_up', 2, 'cash', 'pending');
    $orderIds['stale'] = receipt_insert_order($conn, $references['stale'], $customerId, $restaurantId, $driverId, 'delivered', 4, 'cash', 'pending');
    $orderIds['unpaid'] = receipt_insert_order($conn, $references['unpaid'], $customerId, $restaurantId, $driverId, 'delivered', 2, 'seapay', 'pending');

    $cod = customer_confirm_receipt($conn, $customerId, $references['cod'], 3, 'receipt-cod-1-' . $suffix);
    receipt_expect(($cod['data']['status'] ?? '') === 'completed', 'Customer must complete delivered COD order.');
    receipt_expect(($cod['data']['paymentStatus'] ?? '') === 'paid', 'Customer confirmation must settle COD.');
    receipt_expect(($cod['data']['version'] ?? 0) === 4, 'Customer completion must increment the order version.');
    $codState = receipt_order_state($conn, $orderIds['cod']);
    receipt_expect(($codState['status'] ?? '') === 'completed' && ($codState['payment_status'] ?? '') === 'paid', 'COD order and payment must commit together.');
    receipt_expect((int) ($codState['payment_version'] ?? 0) === 2 && ($codState['paid_at'] ?? null) !== null, 'COD settlement must be timestamped and versioned once.');

    $codReplay = customer_confirm_receipt($conn, $customerId, $references['cod'], 3, 'receipt-cod-1-' . $suffix);
    receipt_expect($codReplay === $cod, 'Receipt replay must return the stored response exactly.');
    $completionNotifications = (int) $conn->query("SELECT COUNT(*) AS total FROM notifications WHERE event_type='order_completed' AND entity_id=" . $orderIds['cod'])->fetch_assoc()['total'];
    receipt_expect($completionNotifications === 2, 'Receipt replay must not duplicate Restaurant or Driver notifications.');
    $completionHistory = (int) $conn->query("SELECT COUNT(*) AS total FROM order_status_history WHERE order_id=" . $orderIds['cod'] . " AND status='completed' AND actor_role='customer'")->fetch_assoc()['total'];
    receipt_expect($completionHistory === 1, 'Receipt replay must not duplicate order history.');
    $completionAudit = (int) $conn->query("SELECT COUNT(*) AS total FROM audit_logs WHERE actor_user_id={$customerId} AND action='customer_confirm_receipt' AND entity_id=" . $orderIds['cod'])->fetch_assoc()['total'];
    receipt_expect($completionAudit === 1, 'Receipt replay must not duplicate audit records.');
    $codReplayState = receipt_order_state($conn, $orderIds['cod']);
    receipt_expect((int) ($codReplayState['payment_version'] ?? 0) === 2, 'Receipt replay must not settle COD twice.');

    $prepaidBefore = receipt_order_state($conn, $orderIds['prepaid']);
    $prepaid = customer_confirm_receipt($conn, $customerId, $references['prepaid'], 2, 'receipt-prepaid-1-' . $suffix);
    receipt_expect(($prepaid['data']['status'] ?? '') === 'completed', 'Customer must complete a paid SeaPay order.');
    receipt_expect(($prepaid['data']['paymentStatus'] ?? '') === 'paid', 'Prepaid completion must report paid.');
    $prepaidAfter = receipt_order_state($conn, $orderIds['prepaid']);
    receipt_expect(($prepaidAfter['payment_status'] ?? '') === 'paid' && (int) $prepaidAfter['payment_version'] === (int) $prepaidBefore['payment_version'], 'Prepaid completion must not mutate the settled payment.');

    $foreign = customer_confirm_receipt($conn, $otherCustomerId, $references['cod'], 3, 'receipt-foreign-1-' . $suffix);
    receipt_expect(($foreign['status'] ?? 0) === 404, 'Customer must not confirm another order.');

    $early = customer_confirm_receipt($conn, $customerId, $references['early'], 2, 'receipt-early-1-' . $suffix);
    receipt_expect(($early['status'] ?? 0) === 409, 'Customer must not confirm before Driver delivery.');
    receipt_expect((receipt_order_state($conn, $orderIds['early'])['status'] ?? '') === 'picked_up', 'Early confirmation must not change the order.');

    $stale = customer_confirm_receipt($conn, $customerId, $references['stale'], 3, 'receipt-stale-1-' . $suffix);
    receipt_expect(($stale['status'] ?? 0) === 409, 'Stale order versions must be rejected.');
    receipt_expect((receipt_order_state($conn, $orderIds['stale'])['status'] ?? '') === 'delivered', 'Stale confirmation must not change the order.');

    $unpaid = customer_confirm_receipt($conn, $customerId, $references['unpaid'], 2, 'receipt-unpaid-1-' . $suffix);
    receipt_expect(($unpaid['status'] ?? 0) === 409, 'Unpaid online orders must not complete.');
    $unpaidState = receipt_order_state($conn, $orderIds['unpaid']);
    receipt_expect(($unpaidState['status'] ?? '') === 'delivered' && ($unpaidState['payment_status'] ?? '') === 'pending', 'Rejected online completion must preserve both states.');

} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    if ($conn instanceof mysqli) {
        if ($userIds !== []) {
            $userList = implode(',', array_map('intval', $userIds));
            $conn->query('DELETE FROM audit_logs WHERE actor_user_id IN (' . $userList . ')');
            $conn->query('DELETE FROM idempotency_keys WHERE actor_user_id IN (' . $userList . ')');
            $conn->query('DELETE FROM notifications WHERE user_id IN (' . $userList . ')');
        }
        if ($orderIds !== []) {
            $orderList = implode(',', array_map('intval', $orderIds));
            $conn->query('DELETE FROM order_status_history WHERE order_id IN (' . $orderList . ')');
            $conn->query('DELETE FROM deliveries WHERE order_id IN (' . $orderList . ')');
            $conn->query('DELETE FROM payments WHERE order_id IN (' . $orderList . ')');
            $conn->query('DELETE FROM orders WHERE id IN (' . $orderList . ')');
        }
        if ($restaurantId > 0) $conn->query('DELETE FROM restaurants WHERE id=' . $restaurantId);
        if ($userIds !== []) $conn->query('DELETE FROM users WHERE id IN (' . $userList . ')');
        $conn->close();
    }
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}
echo "PASS: Customer receipt is the final, scoped, versioned, atomic, and idempotent authority\n";
