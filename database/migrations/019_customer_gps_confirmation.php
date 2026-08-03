<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $databaseResult = $conn->query('SELECT DATABASE() AS name');
    $database = (string) ($databaseResult?->fetch_assoc()['name'] ?? '');
    if ($database === '') {
        throw new RuntimeException('A database must be selected before the Customer GPS confirmation migration.');
    }

    $ensureColumn = static function (
        string $table,
        string $name,
        string $definition,
        string $expectedType,
        string $nullable
    ) use ($conn, $database): void {
        $lookup = $conn->prepare(
            'SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?'
        );
        $lookup->bind_param('sss', $database, $table, $name);
        $lookup->execute();
        $existing = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (!$existing) {
            if (!$conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}")) {
                throw new RuntimeException("Unable to add {$table}.{$name}: {$conn->error}");
            }
            return;
        }
        if (strtolower((string) $existing['COLUMN_TYPE']) !== strtolower($expectedType) || (string) $existing['IS_NULLABLE'] !== $nullable) {
            if (!$conn->query("ALTER TABLE `{$table}` MODIFY COLUMN `{$name}` {$definition}")) {
                throw new RuntimeException("Unable to align {$table}.{$name}: {$conn->error}");
            }
        }
    };

    $ensureColumn('customer_profiles', 'delivery_details', 'VARCHAR(300) NULL', 'varchar(300)', 'YES');
    $ensureColumn('customer_addresses', 'delivery_details', 'VARCHAR(300) NULL', 'varchar(300)', 'YES');
    $ensureColumn('customer_addresses', 'latitude', 'DECIMAL(10,7) NULL', 'decimal(10,7)', 'YES');
    $ensureColumn('customer_addresses', 'longitude', 'DECIMAL(10,7) NULL', 'decimal(10,7)', 'YES');
};
