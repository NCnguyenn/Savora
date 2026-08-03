<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/admin_security.php';
require_once __DIR__ . '/../lib/repositories/analytics_repository.php';
require_once __DIR__ . '/../lib/services/export_service.php';

admin_require_role();

$type = (string) ($_GET['type'] ?? 'analytics');
if ($type === 'analytics') {
    $report = analytics_repository_report($conn, [
        'from' => $_GET['from'] ?? null,
        'to' => $_GET['to'] ?? null,
        'restaurantId' => $_GET['restaurant_id'] ?? 0,
        'driverId' => $_GET['driver_id'] ?? 0,
        'orderType' => $_GET['payment_method'] ?? $_GET['order_type'] ?? '',
        'status' => $_GET['status'] ?? '',
    ]);
    $rows = array_map(static fn (array $row): array => [
        $row['reference_code'], $row['status'], $row['payment_method'], $row['total'], $row['placed_at'], $row['restaurant_name'], $row['customer_name'],
    ], $report['rows']);
    export_send_csv('savora-analytics.csv', ['Order', 'Status', 'Payment method', 'Total', 'Placed at', 'Restaurant', 'Customer'], $rows);
}

if ($type === 'accounts') {
    $accounts = admin_account_rows($conn, ['role' => $_GET['role'] ?? null]);
    $rows = array_map(static fn (array $row): array => [$row['id'], $row['username'], $row['role'], $row['full_name'], $row['email'], $row['status'], $row['created_at']], $accounts);
    export_send_csv('savora-accounts.csv', ['ID', 'Username', 'Role', 'Name', 'Email', 'Status', 'Created at'], $rows);
}

http_response_code(404);
echo 'Export not found.';
