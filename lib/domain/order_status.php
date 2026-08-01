<?php
declare(strict_types=1);

const SAVORA_ORDER_STATUSES = ['pending', 'confirmed', 'preparing', 'ready_for_pickup', 'assigned', 'picked_up', 'delivered', 'cancelled', 'refunded'];

function savora_order_transitions(): array
{
    return [
        'restaurant' => [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['preparing', 'cancelled'],
            'preparing' => ['ready_for_pickup', 'cancelled'],
        ],
        'driver' => [
            'assigned' => ['picked_up'],
            'picked_up' => ['delivered'],
        ],
        'admin' => [
            'pending' => ['cancelled'],
            'confirmed' => ['cancelled'],
            'preparing' => ['cancelled'],
            'ready_for_pickup' => ['cancelled', 'assigned'],
            'assigned' => ['cancelled', 'assigned'],
        ],
    ];
}

function savora_order_can_transition(string $from, string $to, string $role): bool
{
    return in_array($to, savora_order_transitions()[$role][$from] ?? [], true);
}
