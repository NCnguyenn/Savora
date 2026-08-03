<?php
declare(strict_types=1);

require_once __DIR__ . '/../repositories/order_repository.php';

function orders_for_customer(mysqli $conn, int $userId, array $filters): array
{
    if ($userId <= 0) throw new InvalidArgumentException('Customer identity is required.');
    return order_repository_scoped($conn, 'customer', $userId, $filters);
}

function orders_for_restaurant(mysqli $conn, int $ownerUserId, array $filters): array
{
    if ($ownerUserId <= 0) throw new InvalidArgumentException('Restaurant owner identity is required.');
    return order_repository_scoped($conn, 'restaurant', $ownerUserId, $filters);
}

function orders_for_driver(mysqli $conn, int $driverUserId, array $filters): array
{
    if ($driverUserId <= 0) throw new InvalidArgumentException('Driver identity is required.');
    return order_repository_scoped($conn, 'driver', $driverUserId, $filters);
}

function order_for_admin(mysqli $conn, int $orderId): array
{
    if ($orderId <= 0) throw new InvalidArgumentException('Order identity is required.');
    return order_repository_admin($conn, $orderId);
}
