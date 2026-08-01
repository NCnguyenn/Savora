<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/domain/order_status.php';

function expect_true(bool $value, string $message): void
{
    if (!$value) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

expect_true(savora_order_can_transition('pending', 'confirmed', 'restaurant'), 'Restaurant must confirm pending orders.');
expect_true(!savora_order_can_transition('pending', 'delivered', 'restaurant'), 'Restaurant must not deliver orders.');
expect_true(savora_order_can_transition('assigned', 'picked_up', 'driver'), 'Driver must pick up assigned orders.');
expect_true(savora_order_can_transition('picked_up', 'delivered', 'driver'), 'Driver must deliver picked-up orders.');
expect_true(savora_order_can_transition('preparing', 'cancelled', 'admin'), 'Admin exception cancellation must be explicit.');
echo "order contract ok\n";
