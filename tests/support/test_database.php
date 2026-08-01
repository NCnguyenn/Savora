<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/database.php';

function savora_test_selected_database(mysqli $conn): string
{
    $result = $conn->query('SELECT DATABASE() AS name');
    if (!$result) {
        throw new RuntimeException('Integration tests could not determine the selected database.');
    }
    return (string) ($result->fetch_assoc()['name'] ?? '');
}

function savora_test_database(): mysqli
{
    if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
        throw new RuntimeException('Integration tests require SAVORA_ENV=test and SAVORA_DB_NAME=savora_test.');
    }
    $conn = savora_database_connect();
    try {
        $selectedDatabase = savora_test_selected_database($conn);
        if ($selectedDatabase !== 'savora_test') {
            throw new RuntimeException("Integration tests require selected database savora_test; got {$selectedDatabase}.");
        }
        return $conn;
    } catch (Throwable $exception) {
        $conn->close();
        throw $exception;
    }
}

function savora_test_transaction(callable $test): void
{
    $conn = savora_test_database();
    $conn->begin_transaction();
    try {
        $test($conn);
    } finally {
        $conn->rollback();
        $conn->close();
    }
}
