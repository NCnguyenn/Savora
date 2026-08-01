<?php
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../lib/request_security.php';

$sessionPath = (string) getenv('SAVORA_PROBE_SESSION_PATH');
if ($sessionPath !== '') {
    session_save_path($sessionPath);
}
session_id((string) getenv('SAVORA_PROBE_SESSION_ID'));
savora_start_session();

$_SESSION = [
    'user_id' => (int) getenv('SAVORA_PROBE_USER_ID'),
    'role' => (string) getenv('SAVORA_PROBE_ROLE'),
    'session_version' => (int) getenv('SAVORA_PROBE_SESSION_VERSION'),
];
$csrf = (string) getenv('SAVORA_PROBE_CSRF');
if ($csrf !== '') {
    $_SESSION['admin_csrf'] = $csrf;
}

register_shutdown_function(static function (): void {
    fwrite(STDERR, 'STATUS=' . http_response_code());
});

$mode = (string) getenv('SAVORA_PROBE_MODE');
if ($mode === 'actor') {
    echo json_encode(savora_request_actor($conn, ['admin']), JSON_THROW_ON_ERROR);
    exit;
}
if ($mode === 'wrong_role') {
    savora_request_actor($conn, ['customer']);
}
if ($mode === 'legacy') {
    savora_request_actor($conn, ['admin']);
}
if ($mode === 'csrf_valid') {
    savora_require_csrf(['x-csrf-token' => $csrf]);
    echo '{"ok":true}';
    exit;
}
if ($mode === 'csrf_invalid') {
    try {
        savora_require_csrf(['X-CSRF-Token' => 'wrong-token']);
    } catch (InvalidArgumentException $exception) {
        echo json_encode(['class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
        exit;
    }
}

throw new RuntimeException('Unknown request security probe.');
