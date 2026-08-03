<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/migrations.php';
require_once __DIR__ . '/../lib/platform_schema.php';

$conn = savora_database_connect();
if (in_array(getenv('SAVORA_ENV'), ['development', 'test'], true)) {
    platform_migrate($conn);
    savora_apply_migrations($conn);
    platform_seed($conn);
}
