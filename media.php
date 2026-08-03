<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/session_security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/services/media_service.php';

savora_start_session();
$asset = media_find_asset($conn, trim((string) ($_GET['asset'] ?? '')));
if ($asset === []) { http_response_code(404); exit; }
$public = $asset['status'] === 'active' && $asset['visibility'] === 'public';
$admin = false;
if (($_SESSION['role'] ?? '') === 'admin') {
    $admin = (bool) (savora_validate_session($conn, $_SESSION, session_id(), 'admin')['ok'] ?? false);
}
if (!$public && !$admin) { http_response_code(404); exit; }
try {
    $path = media_safe_absolute_path((string) $asset['stored_path']);
} catch (Throwable) {
    http_response_code(404);
    exit;
}
if (!is_file($path) || (int) filesize($path) !== (int) $asset['file_size']) { http_response_code(404); exit; }
header('Content-Type: ' . (string) $asset['mime_type']);
header('Content-Length: ' . (string) $asset['file_size']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: ' . ($public ? 'public, max-age=3600' : 'private, no-store'));
readfile($path);
exit;
