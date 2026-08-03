<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/session_security.php';
require_once __DIR__ . '/../lib/services/rate_limit_service.php';
require_once __DIR__ . '/../lib/location_service.php';
require_once __DIR__ . '/../lib/customer_location_preview.php';

savora_start_session();
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') savora_error(405, 'POST is required.');
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) savora_error(413, 'Location preview request is too large.');
$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (!str_contains($contentType, 'application/json')) savora_error(415, 'JSON is required.');
if (!customer_location_same_origin($_SERVER)) savora_error(403, 'Cross-site location preview is not allowed.');

$remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
if (!rate_limit_consume($conn, $remoteAddress, 'customer_location_preview', 10, 600)) {
    savora_error(429, 'Too many location previews. Please try again later.');
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 4096) savora_error(413, 'Location preview request is too large.');
try {
    $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body)) throw new JsonException('JSON request body must decode to an array.');
} catch (JsonException) {
    savora_error(400, 'Invalid JSON request.');
}

try {
    $location = customer_location_preview($body['latitude'] ?? null, $body['longitude'] ?? null);
    savora_json(['ok' => true, 'data' => ['location' => $location]]);
} catch (InvalidArgumentException $exception) {
    savora_error(422, $exception->getMessage());
} catch (Throwable) {
    savora_error(503, 'Automatic address lookup is temporarily unavailable.');
}
