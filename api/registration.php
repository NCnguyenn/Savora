<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/session_security.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/admin_security.php';
require_once __DIR__ . '/../lib/services/rate_limit_service.php';
require_once __DIR__ . '/../lib/services/registration_service.php';

savora_start_session();
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') savora_error(405, 'POST is required.');

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
try {
    $body = str_contains($contentType, 'application/json') ? savora_read_json() : $_POST;
} catch (JsonException) {
    savora_error(400, 'Invalid JSON request.');
}

$csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? ''));
if (!admin_verify_csrf($csrf)) savora_error(419, 'Your secure form expired. Refresh and try again.');
if (!rate_limit_consume($conn, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 'registration', 8, 900)) {
    savora_error(429, 'Too many registration attempts. Please try again later.');
}

$action = trim((string) ($body['action'] ?? 'register_customer'));
if ($action !== 'register_customer') savora_error(422, 'Unsupported registration action.');
$payload = is_array($body['payload'] ?? null) ? $body['payload'] : $body;
$result = registration_register_customer($conn, $payload);
$status = (int) ($result['status'] ?? 500);
unset($result['status']);
savora_json($result, $status);
