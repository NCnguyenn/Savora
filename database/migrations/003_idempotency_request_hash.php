<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $databaseResult = $conn->query('SELECT DATABASE() AS name');
    $database = (string) ($databaseResult?->fetch_assoc()['name'] ?? '');
    if ($database === '') {
        throw new RuntimeException('A database must be selected before applying the idempotency request hash migration.');
    }

    $column = $conn->prepare(
        'SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    $table = 'idempotency_keys';
    $name = 'request_hash';
    $column->bind_param('sss', $database, $table, $name);
    $column->execute();
    $existing = $column->get_result()->fetch_assoc();
    $column->close();

    if (!$existing) {
        $legacyCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM idempotency_keys')->fetch_assoc()['total'] ?? 0);
        if ($legacyCount > 0) {
            throw new RuntimeException(
                "003_idempotency_request_hash cannot safely backfill {$legacyCount} legacy idempotency records: "
                . 'request payload data is unavailable.'
            );
        }
        if (!$conn->query("ALTER TABLE `idempotency_keys` ADD COLUMN `request_hash` CHAR(64) NOT NULL AFTER `action`")) {
            throw new RuntimeException('Unable to add idempotency request_hash: ' . $conn->error);
        }
        return;
    }

    if (strtolower((string) $existing['COLUMN_TYPE']) !== 'char(64)' || $existing['IS_NULLABLE'] !== 'NO') {
        throw new RuntimeException('Existing idempotency_keys.request_hash does not match the migration definition.');
    }
    $invalid = (int) ($conn->query("SELECT COUNT(*) AS total FROM idempotency_keys WHERE request_hash NOT REGEXP '^[0-9a-fA-F]{64}$'")->fetch_assoc()['total'] ?? 0);
    if ($invalid > 0) {
        throw new RuntimeException(
            "003_idempotency_request_hash cannot safely replay {$invalid} records with invalid request hashes."
        );
    }
};
