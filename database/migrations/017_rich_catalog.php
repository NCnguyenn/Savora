<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $databaseResult = $conn->query('SELECT DATABASE() AS name');
    $database = (string) ($databaseResult?->fetch_assoc()['name'] ?? '');
    if ($database === '') {
        throw new RuntimeException('A database must be selected before applying the rich catalog migration.');
    }

    $ensureColumn = static function (string $table, string $column, string $definition, string $type, string $nullable) use ($conn, $database): void {
        $lookup = $conn->prepare(
            'SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?'
        );
        $lookup->bind_param('sss', $database, $table, $column);
        $lookup->execute();
        $existing = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (!$existing) {
            if (!$conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}")) {
                throw new RuntimeException("Unable to add rich catalog column {$table}.{$column}: {$conn->error}");
            }
            return;
        }
        $actualType = strtolower((string) $existing['COLUMN_TYPE']);
        $actualType = (string) preg_replace('/^int\(\d+\)$/', 'int', $actualType);
        if ($actualType !== strtolower($type) || (string) $existing['IS_NULLABLE'] !== $nullable) {
            throw new RuntimeException("Existing rich catalog column {$table}.{$column} does not match the migration definition.");
        }
    };

    $restaurantColumns = [
        ['description', 'VARCHAR(1000) NULL', 'varchar(1000)', 'YES'],
        ['hero_image', 'VARCHAR(255) NULL', 'varchar(255)', 'YES'],
        ['demo_key', 'VARCHAR(80) NULL', 'varchar(80)', 'YES'],
    ];
    foreach ($restaurantColumns as [$column, $definition, $type, $nullable]) {
        $ensureColumn('restaurants', $column, $definition, $type, $nullable);
    }

    $menuColumns = [
        ['description', 'VARCHAR(600) NULL', 'varchar(600)', 'YES'],
        ['image_path', 'VARCHAR(255) NULL', 'varchar(255)', 'YES'],
        ['category', 'VARCHAR(80) NULL', 'varchar(80)', 'YES'],
        ['prep_time_minutes', 'INT NULL', 'int', 'YES'],
        ['calories', 'INT NULL', 'int', 'YES'],
        ['dietary_tags', 'VARCHAR(255) NULL', 'varchar(255)', 'YES'],
        ['allergens', 'VARCHAR(255) NULL', 'varchar(255)', 'YES'],
        ['ingredients', 'TEXT NULL', 'text', 'YES'],
        ['sort_order', 'INT NOT NULL DEFAULT 0', 'int', 'NO'],
    ];
    foreach ($menuColumns as [$column, $definition, $type, $nullable]) {
        $ensureColumn('menu_items', $column, $definition, $type, $nullable);
    }

    $lookup = $conn->prepare(
        'SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX'
    );
    $indexName = 'uq_restaurants_demo_key';
    $tableName = 'restaurants';
    $lookup->bind_param('sss', $database, $tableName, $indexName);
    $lookup->execute();
    $indexRows = $lookup->get_result()->fetch_all(MYSQLI_ASSOC);
    $lookup->close();
    $indexColumns = array_map(static fn (array $row): string => (string) $row['COLUMN_NAME'], $indexRows);
    $isUnique = $indexRows !== [] && array_reduce(
        $indexRows,
        static fn (bool $carry, array $row): bool => $carry && (int) $row['NON_UNIQUE'] === 0,
        true
    );
    if ($indexColumns !== ['demo_key'] || !$isUnique) {
        if ($indexRows !== []) {
            throw new RuntimeException('Existing restaurants demo key index does not match the migration definition.');
        }
        if (!$conn->query('ALTER TABLE restaurants ADD UNIQUE KEY uq_restaurants_demo_key (demo_key)')) {
            throw new RuntimeException('Unable to add restaurants demo key index: ' . $conn->error);
        }
    }
};
