<?php
declare(strict_types=1);

require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/services/pricing_service.php';

function pricing_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $conn = savora_test_database();
} catch (Throwable $exception) {
    echo "BLOCKED: {$exception->getMessage()}\n";
    exit(2);
}

try {
    $result = pricing_create_quote($conn, 1, [[
        'itemPublicId' => 'ITEM-1',
        'quantity' => 2,
        'optionPublicIds' => ['OPT-CHEESE'],
        'unitPrice' => 0.01,
    ]], 'ADDR-CUSTOMER-A', null);
    pricing_expect(($result['status'] ?? 0) !== 500, 'Pricing service must expose a structured validation or quote response.');
} finally {
    $conn->close();
}

echo "pricing service contract ok\n";
