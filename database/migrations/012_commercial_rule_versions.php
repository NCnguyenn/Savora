<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $databaseResult = $conn->query('SELECT DATABASE() AS name');
    $database = (string) ($databaseResult?->fetch_assoc()['name'] ?? '');
    if ($database === '') {
        throw new RuntimeException('A database must be selected before applying commercial rule migrations.');
    }

    $check = $conn->prepare("SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'fee_rules' AND COLUMN_NAME = 'version'");
    $check->bind_param('s', $database);
    $check->execute();
    $exists = (int) ($check->get_result()->fetch_assoc()['total'] ?? 0) === 1;
    $check->close();

    if (!$exists && !$conn->query('ALTER TABLE fee_rules ADD COLUMN version INT NOT NULL DEFAULT 1 AFTER created_at')) {
        throw new RuntimeException('Commercial rule version migration failed: ' . $conn->error);
    }
};
