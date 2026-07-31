<?php
declare(strict_types=1);

$host = (string) (getenv('SAVORA_DB_HOST') ?: '127.0.0.1');
$username = (string) (getenv('SAVORA_DB_USER') ?: 'root');
$password = (string) (getenv('SAVORA_DB_PASSWORD') ?: '');
$dbname = (string) (getenv('SAVORA_DB_NAME') ?: 'savora_db');
$dbPort = (int) (getenv('SAVORA_DB_PORT') ?: 3306);

if (!preg_match('/^[A-Za-z0-9_]+$/', $dbname)) {
    throw new RuntimeException('Invalid database name.');
}

$conn = new mysqli($host, $username, $password, '', $dbPort);
$conn->set_charset('utf8mb4');

if (!$conn->query("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
    throw new RuntimeException('Unable to initialize the Savora database.');
}

if (!$conn->select_db($dbname)) {
    throw new RuntimeException('Unable to select the Savora database.');
}

require_once __DIR__ . '/lib/platform_schema.php';
platform_migrate($conn);
platform_seed($conn);
