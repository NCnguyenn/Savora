<?php
declare(strict_types=1);

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
    fwrite(STDERR, "BLOCKED: completed order consumer tests require savora_test\n");
    exit(2);
}

require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/admin_repository.php';
require_once __DIR__ . '/../lib/repositories/analytics_repository.php';
require_once __DIR__ . '/../lib/repositories/pricing_repository.php';

function completed_consumer_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$conn = null;
$prefix = 'completed-consumer-' . bin2hex(random_bytes(5));
$userIds = [];
$restaurantId = 0;
$orderId = 0;

try {
    $conn = savora_test_database();
    $beforeOrders = admin_orders_data($conn, []);
    $beforeLive = (int) ($beforeOrders['summary']['live_orders'] ?? 0);

    $password = password_hash('completed-consumer', PASSWORD_DEFAULT);
    $user = $conn->prepare("INSERT INTO users(username,password,role,full_name,status) VALUES(?,?,?,?,'active')");
    foreach ([['customer', 'Customer'], ['restaurant', 'Restaurant'], ['driver', 'Driver']] as [$role, $label]) {
        $username = $prefix . '-' . $role; $name = 'Completed ' . $label;
        $user->bind_param('ssss', $username, $password, $role, $name); $user->execute(); $userIds[$role] = (int) $user->insert_id;
    }
    $user->close();
    $publicId = $prefix . '-restaurant';
    $restaurant = $conn->prepare("INSERT INTO restaurants(owner_user_id,public_id,name,status,accepting_orders) VALUES(?,?,'Completed Restaurant','active',1)");
    $restaurant->bind_param('is', $userIds['restaurant'], $publicId); $restaurant->execute(); $restaurantId = (int) $restaurant->insert_id; $restaurant->close();
    $reference = strtoupper($prefix);
    $order = $conn->prepare("INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,delivery_fee,total,delivery_address,version) VALUES(?,?,?,'completed','cash',35,7,42,'Completed address',7)");
    $order->bind_param('sii', $reference, $userIds['customer'], $restaurantId); $order->execute(); $orderId = (int) $order->insert_id; $order->close();
    $delivery = $conn->prepare("INSERT INTO deliveries(order_id,driver_user_id,status,earning,accepted_at,delivered_at,version) VALUES(?,?,'delivered',7,DATE_SUB(NOW(), INTERVAL 30 MINUTE),NOW(),3)");
    $delivery->bind_param('ii', $orderId, $userIds['driver']); $delivery->execute(); $delivery->close();

    completed_consumer_expect(pricing_repository_customer_has_delivered_order($conn, $userIds['customer']), 'Completed order must disqualify a Customer from new-customer pricing.');
    $overview = admin_overview_data($conn);
    completed_consumer_expect(!in_array($reference, array_column($overview['live_orders'], 'reference_code'), true), 'Admin live operations must exclude completed orders.');
    $afterOrders = admin_orders_data($conn, ['id' => $orderId]);
    completed_consumer_expect((int) ($afterOrders['summary']['live_orders'] ?? -1) === $beforeLive, 'Admin live order count must not increase for completed orders.');

    $today = date('Y-m-d');
    $adminAnalytics = admin_analytics_data($conn, ['from' => $today, 'to' => $today]);
    $restaurantRow = array_values(array_filter($adminAnalytics['restaurants'], static fn (array $row): bool => (int) $row['id'] === $restaurantId))[0] ?? [];
    completed_consumer_expect((float) ($restaurantRow['revenue'] ?? 0) === 42.0, 'Admin Restaurant revenue must include completed orders.');
    $restaurantAnalytics = analytics_repository_report($conn, ['from' => $today, 'to' => $today, 'restaurantId' => $restaurantId]);
    completed_consumer_expect((float) ($restaurantAnalytics['kpis']['netRevenue'] ?? 0) === 42.0, 'Restaurant analytics revenue must include completed orders.');
    completed_consumer_expect((float) ($restaurantAnalytics['kpis']['completionRate'] ?? 0) === 100.0, 'Restaurant analytics completion rate must count completed orders.');
    completed_consumer_expect(in_array('completed', array_column($restaurantAnalytics['status'], 'status'), true), 'Restaurant analytics must expose completed status rows.');

    echo "PASS: completed orders remain terminal and fulfilled across pricing, Admin, and Restaurant consumers\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} finally {
    if ($conn instanceof mysqli) {
        if ($orderId > 0) { $conn->query('DELETE FROM deliveries WHERE order_id=' . $orderId); $conn->query('DELETE FROM orders WHERE id=' . $orderId); }
        if ($restaurantId > 0) $conn->query('DELETE FROM restaurants WHERE id=' . $restaurantId);
        if ($userIds !== []) $conn->query('DELETE FROM users WHERE id IN (' . implode(',', array_map('intval', $userIds)) . ')');
        $conn->close();
    }
}
