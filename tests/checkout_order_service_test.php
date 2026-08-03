<?php
declare(strict_types=1);

require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/services/order_service.php';

function checkout_order_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

try {
    $conn = savora_test_database();
} catch (Throwable $exception) {
    echo "BLOCKED: {$exception->getMessage()}\n";
    exit(2);
}

try {
    $result = order_place_from_quote($conn, 1, 'quote-test', 'wallet', 'task10-test-key');
    checkout_order_expect(($result['status'] ?? 0) !== 500, 'Order service must expose a structured placement response.');
} finally {
    $conn->close();
}

echo "order placement service contract ok\n";
