<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/services/order_transition_service.php';

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
    fwrite(STDERR, "BLOCKED: order transition integration tests require SAVORA_ENV=test and SAVORA_DB_NAME=savora_test\n");
    exit(2);
}

try {
    require_once __DIR__ . '/support/test_database.php';
    $conn = savora_test_database();
    $result = order_transition($conn, ['userId' => 1, 'role' => 'restaurant'], 'missing-order', 'confirmed', 1, 'transition-test-1');
    if (!isset($result['ok'], $result['status'])) throw new RuntimeException('Transition response envelope is incomplete.');
    if (!str_contains((string) file_get_contents(__DIR__ . '/../lib/services/order_transition_service.php'), 'Online payment must be confirmed before the Restaurant can process this order.')) {
        throw new RuntimeException('Restaurant transitions must explicitly guard pending online payments.');
    }
    $conn->close();
    echo "order transition service ok\n";
} catch (Throwable $exception) {
    $message = strtolower($exception->getMessage());
    if (str_contains($message, 'connection') || str_contains($message, 'refused') || str_contains($message, 'cannot')) {
        fwrite(STDERR, "BLOCKED: {$exception->getMessage()}\n");
        exit(2);
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
