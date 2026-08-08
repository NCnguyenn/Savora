<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/request_security.php';
require_once __DIR__ . '/../lib/services/sepay_checkout_service.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    savora_error(405, 'Method not allowed.');
}

$actor = savora_request_actor($conn, ['customer']);
$referenceCode = strtoupper(trim((string) ($_GET['order'] ?? '')));
if (preg_match('/^SVR-[A-Z0-9-]+$/', $referenceCode) !== 1) {
    savora_error(422, 'A valid order reference is required.');
}

$snapshot = sepay_checkout_snapshot($conn, (int) $actor['userId'], $referenceCode);
if ($snapshot === []) savora_error(404, 'SePay payment was not found.');

savora_json(['ok' => true, 'data' => $snapshot]);
