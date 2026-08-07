<?php
declare(strict_types=1);
require_once __DIR__ . '/environment.php';

function savora_database_config(): array
{
    $name = (string) (getenv('SAVORA_DB_NAME') ?: 'savora_db');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('Invalid database name.');
    }
    $config = [
        'host' => 'localhost',
        'port' => (int) (getenv('SAVORA_DB_PORT') ?: 3307),
        'user' => (string) (getenv('SAVORA_DB_USER') ?: 'root'),
        'password' => (string) (getenv('SAVORA_DB_PASSWORD') ?: ''),
        'name' => $name,
    ];
    savora_require_production_database_config($config);
    return $config;
}

function savora_database_connect(bool $selectDatabase = true): mysqli
{
    $config = savora_database_config();
    $database = $selectDatabase ? $config['name'] : '';
    $conn = new mysqli($config['host'], $config['user'], $config['password'], $database, $config['port']);
    $conn->set_charset('utf8mb4');
    return $conn;
}
