<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/request_security.php';
require_once __DIR__ . '/../lib/repositories/analytics_repository.php';
require_once __DIR__ . '/../lib/services/export_service.php';

$actor = savora_request_actor($conn, ['restaurant', 'admin']);
$restaurantId = (int) ($_GET['restaurantId'] ?? 0);
if ((string) $actor['role'] === 'restaurant') {
    $owner = admin_one($conn, 'SELECT id FROM restaurants WHERE owner_user_id=? LIMIT 1', 'i', [(int) $actor['userId']]);
    $restaurantId = (int) ($owner['id'] ?? 0);
}
if ($restaurantId <= 0) savora_error(403, 'A Restaurant scope is required.');

$report = analytics_repository_report($conn, [
    'from' => $_GET['from'] ?? null,
    'to' => $_GET['to'] ?? null,
    'restaurantId' => $restaurantId,
    'driverId' => $_GET['driverId'] ?? 0,
    'orderType' => $_GET['orderType'] ?? $_GET['payment_method'] ?? '',
    'status' => $_GET['status'] ?? '',
]);
if ((string) ($_GET['export'] ?? '') === 'csv') {
    $rows = array_map(static fn (array $row): array => [$row['reference_code'], $row['status'], $row['payment_method'], $row['total'], $row['placed_at']], $report['rows']);
    export_send_csv('savora-restaurant-analytics.csv', ['Order', 'Status', 'Payment method', 'Total', 'Placed at'], $rows);
}
savora_json(['ok' => true, 'data' => $report]);
