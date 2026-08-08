<?php
declare(strict_types=1);

require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/migrations.php';
require_once __DIR__ . '/../lib/services/order_query_service.php';
require_once __DIR__ . '/../lib/services/order_transition_service.php';

function payment_gate_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function payment_gate_user(mysqli $conn, string $username, string $role, string $name): int
{
    $password = password_hash('task-3-payment-gate-test', PASSWORD_DEFAULT);
    $statement = $conn->prepare("INSERT INTO users(username,password,role,full_name,status) VALUES(?,?,?,?,'active')");
    $statement->bind_param('ssss', $username, $password, $role, $name);
    $statement->execute();
    $id = (int) $statement->insert_id;
    $statement->close();
    return $id;
}

function payment_gate_order(mysqli $conn, string $reference, int $customerId, int $restaurantId, string $method, string $paymentStatus): int
{
    $order = $conn->prepare(
        "INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,total,delivery_address)
         VALUES(?,?,?,'pending',?,25.00,25.00,'Payment gate test address')"
    );
    $order->bind_param('siis', $reference, $customerId, $restaurantId, $method);
    $order->execute();
    $orderId = (int) $order->insert_id;
    $order->close();

    $payment = $conn->prepare('INSERT INTO payments(order_id,method,amount,status,paid_at) VALUES(?,?,25.00,?,IF(?=\'paid\',NOW(),NULL))');
    $payment->bind_param('isss', $orderId, $method, $paymentStatus, $paymentStatus);
    $payment->execute();
    $payment->close();
    return $orderId;
}

$conn = null;
$userIds = [];
$restaurantId = 0;
$orderIds = [];
$suffix = strtoupper(bin2hex(random_bytes(5)));
$pendingSeaPay = 'SVR-' . $suffix . '-PENDING';
$paidSeaPay = 'SVR-' . $suffix . '-PAID';
$pendingCash = 'SVR-' . $suffix . '-CASH';

try {
    $conn = savora_test_database();
    savora_apply_migrations($conn);

    $customerId = payment_gate_user($conn, strtolower('gate-' . $suffix . '-customer'), 'customer', 'Payment Gate Customer');
    $ownerId = payment_gate_user($conn, strtolower('gate-' . $suffix . '-restaurant'), 'restaurant', 'Payment Gate Restaurant');
    $userIds = [$customerId, $ownerId];
    $restaurant = $conn->prepare("INSERT INTO restaurants(owner_user_id,name,status) VALUES(?,'Payment Gate Restaurant','active')");
    $restaurant->bind_param('i', $ownerId);
    $restaurant->execute();
    $restaurantId = (int) $restaurant->insert_id;
    $restaurant->close();

    $orderIds[] = payment_gate_order($conn, $pendingSeaPay, $customerId, $restaurantId, 'seapay', 'pending');
    $orderIds[] = payment_gate_order($conn, $paidSeaPay, $customerId, $restaurantId, 'seapay', 'paid');
    $orderIds[] = payment_gate_order($conn, $pendingCash, $customerId, $restaurantId, 'cash', 'pending');

    $visible = orders_for_restaurant($conn, $ownerId, ['page' => 1, 'pageSize' => 20]);
    $references = array_map(static fn (array $order): string => (string) $order['referenceCode'], $visible['orders']);
    payment_gate_expect(!in_array($pendingSeaPay, $references, true), 'Restaurant reads must hide pending SeaPay orders.');
    payment_gate_expect(in_array($paidSeaPay, $references, true), 'Restaurant reads must include paid SeaPay orders.');
    payment_gate_expect(in_array($pendingCash, $references, true), 'Restaurant reads must include pending cash orders.');
    payment_gate_expect((int) ($visible['pagination']['total'] ?? 0) === 2, 'Restaurant pagination total must match the payment-filtered list.');

    $transition = order_transition($conn, ['userId' => $ownerId, 'role' => 'restaurant'], $pendingSeaPay, 'confirmed', 1, 'payment-gate-' . strtolower($suffix));
    payment_gate_expect(($transition['status'] ?? 0) === 409, 'Restaurant transitions must reject a crafted pending SeaPay reference.');
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
            $conn->query('DELETE FROM payments WHERE order_id IN (' . $orderList . ')');
            $conn->query('DELETE FROM orders WHERE id IN (' . $orderList . ')');
        }
        if ($restaurantId > 0) $conn->query('DELETE FROM restaurants WHERE id=' . $restaurantId);
        if ($userIds !== []) {
            $conn->query('DELETE FROM customer_profiles WHERE user_id IN (' . $userList . ')');
            $conn->query('DELETE FROM users WHERE id IN (' . $userList . ')');
        }
        $conn->close();
    }
}

echo "PASS: Restaurant payment timing gate protects reads, counts, and crafted transitions\n";
