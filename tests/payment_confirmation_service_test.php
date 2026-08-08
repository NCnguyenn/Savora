<?php
declare(strict_types=1);

require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/migrations.php';

$servicePath = __DIR__ . '/../lib/services/payment_confirmation_service.php';
if (!is_file($servicePath)) {
    throw new RuntimeException('Shared payment confirmation service is missing.');
}
require_once $servicePath;

function payment_test_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function payment_test_insert_user(mysqli $conn, string $username, string $role, string $name): int
{
    $password = password_hash('task-2-payment-test', PASSWORD_DEFAULT);
    $statement = $conn->prepare("INSERT INTO users(username,password,role,full_name,status) VALUES(?,?,?,?,'active')");
    $statement->bind_param('ssss', $username, $password, $role, $name);
    $statement->execute();
    $id = (int) $statement->insert_id;
    $statement->close();
    return $id;
}

function payment_test_insert_order(mysqli $conn, string $reference, int $customerId, int $restaurantId): int
{
    $statement = $conn->prepare(
        "INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,total)
         VALUES(?,?,?,'pending','seapay',125.50,125.50)"
    );
    $statement->bind_param('sii', $reference, $customerId, $restaurantId);
    $statement->execute();
    $orderId = (int) $statement->insert_id;
    $statement->close();

    $payment = $conn->prepare("INSERT INTO payments(order_id,method,amount,status) VALUES(?,'seapay',125.50,'pending')");
    $payment->bind_param('i', $orderId);
    $payment->execute();
    $payment->close();
    return $orderId;
}

function payment_test_status(mysqli $conn, string $reference): string
{
    $statement = $conn->prepare('SELECT p.status FROM payments p JOIN orders o ON o.id=p.order_id WHERE o.reference_code=?');
    $statement->bind_param('s', $reference);
    $statement->execute();
    $status = (string) ($statement->get_result()->fetch_assoc()['status'] ?? '');
    $statement->close();
    return $status;
}

$previousDemoMode = getenv('SAVORA_DEMO_MODE');
$conn = null;
$userIds = [];
$restaurantId = 0;
$orderIds = [];
$suffix = strtoupper(bin2hex(random_bytes(5)));
$reference = 'SVR-' . $suffix . '-EXACT';
$wrongReference = 'SVR-' . $suffix . '-WRONG';
$secondReference = 'SVR-' . $suffix . '-DEMO';
$thirdReference = 'SVR-' . $suffix . '-OWNED';

try {
    putenv('SAVORA_DEMO_MODE=1');
    $conn = savora_test_database();
    savora_apply_migrations($conn);

    $conn->begin_transaction();
    $customerId = payment_test_insert_user($conn, strtolower('pay-' . $suffix . '-customer'), 'customer', 'Payment Customer');
    $otherCustomerId = payment_test_insert_user($conn, strtolower('pay-' . $suffix . '-other'), 'customer', 'Other Customer');
    $ownerId = payment_test_insert_user($conn, strtolower('pay-' . $suffix . '-owner'), 'restaurant', 'Payment Restaurant Owner');
    $userIds = [$customerId, $otherCustomerId, $ownerId];
    $restaurant = $conn->prepare("INSERT INTO restaurants(owner_user_id,name,status) VALUES(?,'Payment Test Restaurant','active')");
    $restaurant->bind_param('i', $ownerId);
    $restaurant->execute();
    $restaurantId = (int) $restaurant->insert_id;
    $restaurant->close();
    foreach ([$reference, $wrongReference, $secondReference, $thirdReference] as $fixtureReference) {
        $orderIds[] = payment_test_insert_order($conn, $fixtureReference, $customerId, $restaurantId);
    }
    $conn->commit();

    $event = [
        'state' => 'process',
        'transactionId' => 'SEPAY-TEST-' . bin2hex(random_bytes(4)),
        'referenceCode' => $reference,
        'amountCents' => 12550,
    ];
    $confirmed = payment_confirm_incoming($conn, $event, 'seapay');
    payment_test_expect(($confirmed['ok'] ?? false) === true, 'Exact incoming payment must succeed.');
    payment_test_expect(($confirmed['data']['paymentStatus'] ?? '') === 'paid', 'Payment must become paid.');

    $duplicate = payment_confirm_incoming($conn, $event, 'seapay');
    payment_test_expect(($duplicate['ok'] ?? false) === true, 'Provider retry must be idempotent.');
    payment_test_expect(payment_test_status($conn, $reference) === 'paid', 'Provider retry must retain the paid state.');
    $notificationCount = (int) $conn->query(
        'SELECT COUNT(*) AS total FROM notifications WHERE event_type=\'payment_confirmed\' AND entity_id=' . (int) $orderIds[0]
    )->fetch_assoc()['total'];
    payment_test_expect($notificationCount === 1, 'Provider retry must not duplicate confirmation side effects.');

    $wrongEvent = $event;
    $wrongEvent['transactionId'] = $event['transactionId'] . '-WRONG';
    $wrongEvent['referenceCode'] = $wrongReference;
    $wrongEvent['amountCents'] = 12549;
    $wrong = payment_confirm_incoming($conn, $wrongEvent, 'seapay');
    payment_test_expect(($wrong['status'] ?? 0) === 409, 'Wrong amount must remain pending/rejected.');
    payment_test_expect(($wrong['data']['paymentStatus'] ?? '') === 'pending', 'Wrong amount response must report the pending payment.');
    payment_test_expect(payment_test_status($conn, $wrongReference) === 'pending', 'Wrong amount must not change the pending payment.');

    $demo = payment_simulate_customer_success($conn, $customerId, $secondReference, 'demo-pay-key-1');
    payment_test_expect(($demo['data']['paymentStatus'] ?? '') === 'paid', 'Owned demo payment must use the same confirmation path.');
    $demoRetry = payment_simulate_customer_success($conn, $customerId, $secondReference, 'demo-pay-key-1');
    payment_test_expect($demoRetry === $demo, 'Demo retry must replay the stored response exactly.');

    $forbidden = payment_simulate_customer_success($conn, $otherCustomerId, $thirdReference, 'demo-pay-key-2');
    payment_test_expect(($forbidden['status'] ?? 0) === 404, 'Another Customer must not simulate this payment.');
    payment_test_expect(payment_test_status($conn, $thirdReference) === 'pending', 'Cross-Customer denial must not change the payment.');

    $conflict = payment_simulate_customer_success($conn, $customerId, $thirdReference, 'demo-pay-key-1');
    payment_test_expect(($conflict['status'] ?? 0) === 409, 'A demo idempotency key reused for another order must conflict.');
    payment_test_expect(payment_test_status($conn, $thirdReference) === 'pending', 'Idempotency conflict must not change another payment.');
} finally {
    putenv($previousDemoMode === false ? 'SAVORA_DEMO_MODE' : 'SAVORA_DEMO_MODE=' . $previousDemoMode);
    if ($conn instanceof mysqli) {
        if ($userIds !== []) {
            $userList = implode(',', array_map('intval', $userIds));
            $conn->query('DELETE FROM audit_logs WHERE actor_user_id IN (' . $userList . ')');
            $conn->query('DELETE FROM idempotency_keys WHERE actor_user_id IN (' . $userList . ')');
            $conn->query('DELETE FROM notifications WHERE user_id IN (' . $userList . ')');
        }
        if ($orderIds !== []) {
            $orderList = implode(',', array_map('intval', $orderIds));
            $conn->query('DELETE FROM payments WHERE order_id IN (' . $orderList . ')');
            $conn->query('DELETE FROM orders WHERE id IN (' . $orderList . ')');
        }
        if ($restaurantId > 0) {
            $conn->query('DELETE FROM restaurants WHERE id=' . $restaurantId);
        }
        if ($userIds !== []) {
            $conn->query('DELETE FROM customer_profiles WHERE user_id IN (' . $userList . ')');
            $conn->query('DELETE FROM users WHERE id IN (' . $userList . ')');
        }
        $conn->close();
    }
}

echo "PASS: shared SeaPay confirmation is exact, idempotent, demo-safe, and Customer-scoped\n";
