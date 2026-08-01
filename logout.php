<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/session_security.php';
require_once __DIR__ . '/db.php';
savora_start_session();
savora_revoke_current_session($conn);
savora_end_session();
header('Location: index.php');
exit();
