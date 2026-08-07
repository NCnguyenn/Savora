<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $databaseResult = $conn->query('SELECT DATABASE() AS name');
    $database = (string) ($databaseResult?->fetch_assoc()['name'] ?? '');
    if ($database === '') {
        throw new RuntimeException('A database must be selected before applying the SeaPay migration.');
    }

    $duplicates = $conn->query(
        "SELECT provider_reference FROM payments
         WHERE provider_reference IS NOT NULL AND provider_reference <> ''
         GROUP BY provider_reference HAVING COUNT(*) > 1 LIMIT 1"
    );
    if ($duplicates !== false && $duplicates->fetch_assoc() !== null) {
        throw new RuntimeException('SeaPay migration cannot add idempotency protection while provider references are duplicated.');
    }

    $index = $conn->prepare(
        "SELECT COUNT(*) AS total FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA=? AND TABLE_NAME='payments' AND INDEX_NAME='uq_payments_provider_reference'"
    );
    $index->bind_param('s', $database);
    $index->execute();
    $hasIndex = (int) ($index->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $index->close();

    if (!$hasIndex && !$conn->query('ALTER TABLE payments ADD UNIQUE KEY uq_payments_provider_reference (provider_reference)')) {
        throw new RuntimeException('Unable to add SeaPay provider reference uniqueness: ' . $conn->error);
    }
};
