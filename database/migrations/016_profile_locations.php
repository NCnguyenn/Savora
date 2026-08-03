<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $database = (string) ($conn->query('SELECT DATABASE() AS name')->fetch_assoc()['name'] ?? '');
    if ($database === '') throw new RuntimeException('A database must be selected before the profile location migration.');

    $ensureColumn = static function (string $table, string $name, string $definition, string $expectedType, string $nullable) use ($conn, $database): void {
        $lookup = $conn->prepare('SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
        $lookup->bind_param('sss', $database, $table, $name);
        $lookup->execute();
        $existing = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (!$existing) {
            if (!$conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}")) throw new RuntimeException("Unable to add {$table}.{$name}: {$conn->error}");
            return;
        }
        $actual = strtolower((string) $existing['COLUMN_TYPE']);
        if ($actual !== strtolower($expectedType) || (string) $existing['IS_NULLABLE'] !== $nullable) throw new RuntimeException("Existing location column {$table}.{$name} does not match the migration contract.");
    };

    foreach (['customer_profiles', 'restaurants', 'driver_profiles'] as $table) {
        $ensureColumn($table, 'latitude', 'DECIMAL(10,7) NULL', 'decimal(10,7)', 'YES');
        $ensureColumn($table, 'longitude', 'DECIMAL(10,7) NULL', 'decimal(10,7)', 'YES');
        $ensureColumn($table, 'location_method', "VARCHAR(10) NOT NULL DEFAULT 'manual'", 'varchar(10)', 'NO');
        $ensureColumn($table, 'location_updated_at', 'DATETIME NULL', 'datetime', 'YES');
    }
    $ensureColumn('driver_profiles', 'address', 'VARCHAR(500) NULL', 'varchar(500)', 'YES');
    $ensureColumn('restaurants', 'address_line1', 'VARCHAR(150) NULL', 'varchar(150)', 'YES');
    $ensureColumn('restaurants', 'address_line2', 'VARCHAR(150) NULL', 'varchar(150)', 'YES');
    $ensureColumn('restaurants', 'state', 'VARCHAR(100) NULL', 'varchar(100)', 'YES');
    $ensureColumn('restaurants', 'postal_code', 'VARCHAR(30) NULL', 'varchar(30)', 'YES');
    $ensureColumn('restaurants', 'country', 'VARCHAR(100) NULL', 'varchar(100)', 'YES');
};
