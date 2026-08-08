<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/request_security.php';
require_once __DIR__ . '/../lib/services/demo_route_service.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$actor = savora_request_actor($conn, ['customer', 'restaurant', 'driver', 'admin']);
if ($method !== 'GET') savora_error(405, 'Method not allowed.');

$referenceCode = trim((string) ($_GET['order'] ?? ''));
if ($referenceCode === '') savora_error(422, 'Order reference is required.');

try {
    $result = demo_route_snapshot($conn, $actor, $referenceCode);
} catch (Throwable) {
    savora_error(500, 'Tracking is temporarily unavailable.');
}

$status = (int) ($result['status'] ?? 200);
unset($result['status']);
savora_json($result, $status);
