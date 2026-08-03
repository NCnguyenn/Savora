<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $databaseResult = $conn->query('SELECT DATABASE() AS name');
    $database = (string) ($databaseResult?->fetch_assoc()['name'] ?? '');
    if ($database === '') throw new RuntimeException('A database must be selected before partner document migration.');

    $columns = [
        ['restaurant_application_documents', 'file_size', 'INT NOT NULL DEFAULT 0'],
        ['restaurant_application_documents', 'sha256', 'CHAR(64) NULL'],
        ['driver_application_documents', 'file_size', 'INT NOT NULL DEFAULT 0'],
        ['driver_application_documents', 'sha256', 'CHAR(64) NULL'],
    ];
    $check = $conn->prepare('SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
    foreach ($columns as [$table, $column, $definition]) {
        $check->bind_param('sss', $database, $table, $column);
        $check->execute();
        $exists = (int) ($check->get_result()->fetch_assoc()['total'] ?? 0) > 0;
        if (!$exists && !$conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}")) {
            $check->close();
            throw new RuntimeException("Unable to add {$table}.{$column}: {$conn->error}");
        }
    }
    $check->close();
};
