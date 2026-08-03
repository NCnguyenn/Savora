<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/session_security.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/admin_security.php';
require_once __DIR__ . '/../lib/services/rate_limit_service.php';
require_once __DIR__ . '/../lib/services/partner_application_service.php';

savora_start_session();
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') savora_error(405, 'POST is required.');

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
$files = [];
if (str_starts_with($contentType, 'multipart/form-data')) {
    $body = $_POST;
    if (is_array($_FILES['logo'] ?? null)) $files['logo'] = $_FILES['logo'];
} else {
    try { $body = savora_read_json(); } catch (JsonException) { savora_error(400, 'Invalid JSON request.'); }
}

$csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? ''));
if (!admin_verify_csrf($csrf)) savora_error(419, 'Your secure form expired. Refresh and try again.');
if (!rate_limit_consume($conn, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 'partner_application', 6, 900)) {
    savora_error(429, 'Too many application attempts. Please try again later.');
}

$action = trim((string) ($body['action'] ?? 'submit_application'));
$payload = is_array($body['payload'] ?? null) ? $body['payload'] : $body;
if ($action === 'submit_application') {
    $response = partner_submit_application($conn, (string) ($payload['type'] ?? ''), $payload, $files);
    $status = (int) ($response['status'] ?? 500);
    unset($response['status']);
    savora_json($response, $status);
}
savora_error(422, 'Unsupported application action.');
