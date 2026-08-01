<?php
declare(strict_types=1);

function savora_database_config(): array
{
    $name = (string) (getenv('SAVORA_DB_NAME') ?: 'savora_db');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('Invalid database name.');
    }
    return [
        'host' => (string) (getenv('SAVORA_DB_HOST') ?: '127.0.0.1'),
        'port' => (int) (getenv('SAVORA_DB_PORT') ?: 3306),
        'user' => (string) (getenv('SAVORA_DB_USER') ?: 'root'),
        'password' => (string) (getenv('SAVORA_DB_PASSWORD') ?: ''),
        'name' => $name,
    ];
}

function savora_database_connect(bool $selectDatabase = true): mysqli
{
    $config = savora_database_config();
    $database = $selectDatabase ? $config['name'] : '';
    $conn = new mysqli($config['host'], $config['user'], $config['password'], $database, $config['port']);
    $conn->set_charset('utf8mb4');
    return $conn;
}
