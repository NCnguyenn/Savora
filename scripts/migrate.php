<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/platform_schema.php';

$config = savora_database_config();
$conn = savora_database_connect(false);

if (!$conn->query("CREATE DATABASE IF NOT EXISTS `{$config['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
    throw new RuntimeException('Unable to initialize the Savora database.');
}

if (!$conn->select_db($config['name'])) {
    throw new RuntimeException('Unable to select the Savora database.');
}

platform_migrate($conn);
