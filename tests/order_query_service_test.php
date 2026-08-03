<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/services/order_query_service.php';

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
    fwrite(STDERR, "BLOCKED: order query integration tests require SAVORA_ENV=test and SAVORA_DB_NAME=savora_test\n");
    exit(2);
}

try {
    require_once __DIR__ . '/support/test_database.php';
    $conn = savora_test_database();
    $customer = orders_for_customer($conn, 1, ['page' => 1, 'pageSize' => 5]);
    if (!isset($customer['orders'], $customer['pagination'])) throw new RuntimeException('Customer order read model is incomplete.');
    $restaurant = orders_for_restaurant($conn, 1, ['status' => 'pending']);
    if (!isset($restaurant['orders'])) throw new RuntimeException('Restaurant order read model is incomplete.');
    $driver = orders_for_driver($conn, 1, ['page' => 1, 'pageSize' => 5]);
    if (!isset($driver['orders'])) throw new RuntimeException('Driver order read model is incomplete.');
    $conn->close();
    echo "order query service ok\n";
} catch (Throwable $exception) {
    $message = strtolower($exception->getMessage());
    if (str_contains($message, 'connection') || str_contains($message, 'refused') || str_contains($message, 'cannot')) {
        fwrite(STDERR, "BLOCKED: {$exception->getMessage()}\n");
        exit(2);
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
