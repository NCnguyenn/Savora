<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $databaseResult = $conn->query('SELECT DATABASE() AS name');
    $database = (string) ($databaseResult?->fetch_assoc()['name'] ?? '');
    if ($database === '') {
        throw new RuntimeException('A database must be selected before applying the catalog contract migration.');
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
                throw new RuntimeException("Unable to add catalog column {$table}.{$column}: {$conn->error}");
            }
            return;
        }
        if (strtolower((string) $existing['COLUMN_TYPE']) !== strtolower($type) || $existing['IS_NULLABLE'] !== $nullable) {
            throw new RuntimeException("Existing catalog column {$table}.{$column} does not match the migration definition.");
        }
    };

    $ensureColumn('restaurants', 'latitude', 'DECIMAL(10,7) NULL', 'decimal(10,7)', 'YES');
    $ensureColumn('restaurants', 'longitude', 'DECIMAL(10,7) NULL', 'decimal(10,7)', 'YES');

    $ensureUniqueIndex = static function (string $table, string $index, array $columns) use ($conn, $database): void {
        $lookup = $conn->prepare(
            'SELECT COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX'
        );
        $lookup->bind_param('sss', $database, $table, $index);
        $lookup->execute();
        $existing = $lookup->get_result()->fetch_all(MYSQLI_ASSOC);
        $lookup->close();
        $actual = array_map(static fn (array $row): string => (string) $row['COLUMN_NAME'], $existing);
        $unique = $existing !== [] && array_reduce($existing, static fn (bool $carry, array $row): bool => $carry && (int) $row['NON_UNIQUE'] === 0, true);
        if ($actual === $columns && $unique) {
            return;
        }
        if ($existing !== []) {
            throw new RuntimeException("Existing catalog index {$table}.{$index} does not match the migration definition.");
        }
        $quotedColumns = implode(',', array_map(static fn (string $column): string => "`{$column}`", $columns));
        if (!$conn->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$index}` ({$quotedColumns})")) {
            throw new RuntimeException("Unable to add catalog index {$table}.{$index}: {$conn->error}");
        }
    };

    $statements = [
        "CREATE TABLE IF NOT EXISTS restaurant_weekly_hours (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id INT NOT NULL,
            weekday TINYINT NOT NULL,
            opens_at TIME NULL,
            closes_at TIME NULL,
            is_closed TINYINT(1) NOT NULL DEFAULT 0,
            version INT NOT NULL DEFAULT 1,
            UNIQUE KEY uq_restaurant_weekday (restaurant_id, weekday),
            CONSTRAINT fk_restaurant_weekly_hours_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS restaurant_special_hours (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id INT NOT NULL,
            special_date DATE NOT NULL,
            opens_at TIME NULL,
            closes_at TIME NULL,
            is_closed TINYINT(1) NOT NULL DEFAULT 0,
            note VARCHAR(255) NULL,
            version INT NOT NULL DEFAULT 1,
            UNIQUE KEY uq_restaurant_special_date (restaurant_id, special_date),
            CONSTRAINT fk_restaurant_special_hours_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS menu_option_groups (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            menu_item_id BIGINT NOT NULL,
            name VARCHAR(120) NOT NULL,
            selection_type VARCHAR(20) NOT NULL,
            minimum_choices INT NOT NULL DEFAULT 0,
            maximum_choices INT NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            version INT NOT NULL DEFAULT 1,
            CONSTRAINT fk_option_group_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS menu_option_choices (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            option_group_id BIGINT NOT NULL,
            public_id VARCHAR(60) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            price_delta DECIMAL(12,2) NOT NULL DEFAULT 0,
            available TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            version INT NOT NULL DEFAULT 1,
            CONSTRAINT fk_option_choice_group FOREIGN KEY (option_group_id) REFERENCES menu_option_groups(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($statements as $sql) {
        if (!$conn->query($sql)) {
            throw new RuntimeException('Catalog schema migration failed: ' . $conn->error);
        }
    }

    $ensureUniqueIndex('restaurant_weekly_hours', 'uq_restaurant_weekday', ['restaurant_id', 'weekday']);
    $ensureUniqueIndex('restaurant_special_hours', 'uq_restaurant_special_date', ['restaurant_id', 'special_date']);
    $ensureUniqueIndex('menu_option_choices', 'public_id', ['public_id']);

    $requiredColumns = [
        'restaurant_weekly_hours' => [
            'id' => ['bigint', 'NO', 'auto_increment', 'PRI'],
            'restaurant_id' => ['int', 'NO'], 'weekday' => ['tinyint', 'NO'], 'opens_at' => ['time', 'YES'],
            'closes_at' => ['time', 'YES'], 'is_closed' => ['tinyint(1)', 'NO'], 'version' => ['int', 'NO'],
        ],
        'restaurant_special_hours' => [
            'id' => ['bigint', 'NO', 'auto_increment', 'PRI'],
            'restaurant_id' => ['int', 'NO'], 'special_date' => ['date', 'NO'], 'opens_at' => ['time', 'YES'],
            'closes_at' => ['time', 'YES'], 'is_closed' => ['tinyint(1)', 'NO'], 'note' => ['varchar(255)', 'YES'], 'version' => ['int', 'NO'],
        ],
        'menu_option_groups' => [
            'id' => ['bigint', 'NO', 'auto_increment', 'PRI'],
            'menu_item_id' => ['bigint', 'NO'], 'name' => ['varchar(120)', 'NO'], 'selection_type' => ['varchar(20)', 'NO'],
            'minimum_choices' => ['int', 'NO'], 'maximum_choices' => ['int', 'NO'], 'sort_order' => ['int', 'NO'], 'version' => ['int', 'NO'],
        ],
        'menu_option_choices' => [
            'id' => ['bigint', 'NO', 'auto_increment', 'PRI'],
            'option_group_id' => ['bigint', 'NO'], 'public_id' => ['varchar(60)', 'NO'], 'name' => ['varchar(120)', 'NO'],
            'price_delta' => ['decimal(12,2)', 'NO'], 'available' => ['tinyint(1)', 'NO'], 'sort_order' => ['int', 'NO'], 'version' => ['int', 'NO'],
        ],
    ];
    $columnLookup = $conn->prepare(
        'SELECT COLUMN_TYPE, IS_NULLABLE, EXTRA, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    try {
        foreach ($requiredColumns as $table => $columns) {
            foreach ($columns as $column => $definition) {
                [$type, $nullable] = $definition;
                $extra = $definition[2] ?? null;
                $columnKey = $definition[3] ?? null;
                $columnLookup->bind_param('sss', $database, $table, $column);
                $columnLookup->execute();
                $existing = $columnLookup->get_result()->fetch_assoc();
                if (
                    !$existing
                    || strtolower((string) $existing['COLUMN_TYPE']) !== $type
                    || $existing['IS_NULLABLE'] !== $nullable
                    || ($extra !== null && strtolower((string) $existing['EXTRA']) !== $extra)
                    || ($columnKey !== null && (string) $existing['COLUMN_KEY'] !== $columnKey)
                ) {
                    throw new RuntimeException("Existing catalog table {$table}.{$column} does not match the migration definition.");
                }
            }
        }
    } finally {
        $columnLookup->close();
    }

    $primaryLookup = $conn->prepare(
        "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX"
    );
    try {
        foreach (array_keys($requiredColumns) as $table) {
            $primaryLookup->bind_param('ss', $database, $table);
            $primaryLookup->execute();
            $primaryColumns = array_column($primaryLookup->get_result()->fetch_all(MYSQLI_ASSOC), 'COLUMN_NAME');
            if ($primaryColumns !== ['id']) {
                throw new RuntimeException("Existing catalog table {$table} primary key does not match the migration definition.");
            }
        }
    } finally {
        $primaryLookup->close();
    }

    $constraintLookup = $conn->prepare(
        'SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE
         FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.REFERENTIAL_CONSTRAINTS r
           ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
         WHERE k.CONSTRAINT_SCHEMA=? AND k.CONSTRAINT_NAME=?'
    );
    $constraints = [
        'fk_restaurant_weekly_hours_restaurant' => ['restaurant_weekly_hours', 'restaurant_id', 'restaurants', 'id', 'CASCADE'],
        'fk_restaurant_special_hours_restaurant' => ['restaurant_special_hours', 'restaurant_id', 'restaurants', 'id', 'CASCADE'],
        'fk_option_group_item' => ['menu_option_groups', 'menu_item_id', 'menu_items', 'id', 'CASCADE'],
        'fk_option_choice_group' => ['menu_option_choices', 'option_group_id', 'menu_option_groups', 'id', 'CASCADE'],
    ];
    try {
        foreach ($constraints as $name => $expected) {
            $constraintLookup->bind_param('ss', $database, $name);
            $constraintLookup->execute();
            $actual = $constraintLookup->get_result()->fetch_assoc();
            if (!$actual || [$actual['TABLE_NAME'], $actual['COLUMN_NAME'], $actual['REFERENCED_TABLE_NAME'], $actual['REFERENCED_COLUMN_NAME'], $actual['DELETE_RULE']] !== $expected) {
                throw new RuntimeException("Existing catalog constraint {$name} does not match the migration definition.");
            }
        }
    } finally {
        $constraintLookup->close();
    }
};
