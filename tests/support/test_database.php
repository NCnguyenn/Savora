<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/database.php';

function savora_test_database(): mysqli
{
    if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
        throw new RuntimeException('Integration tests require SAVORA_ENV=test and SAVORA_DB_NAME=savora_test.');
    }
    return savora_database_connect();
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
