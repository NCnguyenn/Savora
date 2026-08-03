<?php
declare(strict_types=1);
if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') { fwrite(STDERR, "BLOCKED: rate-limit tests require savora_test\n"); exit(2); }
require_once __DIR__ . '/../lib/services/rate_limit_service.php';
require_once __DIR__ . '/support/test_database.php';
$conn = savora_test_database();
$actor = 'rate-test-' . bin2hex(random_bytes(8));
if (!rate_limit_consume($conn, $actor, 'login', 2, 60) || !rate_limit_consume($conn, $actor, 'login', 2, 60) || rate_limit_consume($conn, $actor, 'login', 2, 60)) { fwrite(STDERR, "Rate limit window enforcement failed\n"); exit(1); }
$conn->close(); echo "PASS: rate limit window holds\n";
