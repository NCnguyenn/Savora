<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/services/catalog_service.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    savora_error(405, 'Method not allowed.');
}

$result = catalog_storefront_for_customer($conn, (string) ($_GET['restaurant'] ?? ''));
$status = (int) ($result['status'] ?? 200);
unset($result['status']);
savora_json($result, $status);
