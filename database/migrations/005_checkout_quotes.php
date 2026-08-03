<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $databaseResult = $conn->query('SELECT DATABASE() AS name');
    $database = (string) ($databaseResult?->fetch_assoc()['name'] ?? '');
    if ($database === '') {
        throw new RuntimeException('A database must be selected before applying the checkout quote migration.');
    }

    $ensureColumn = static function (string $table, string $column, string $definition, string $expectedType, string $nullable, ?string $default = null) use ($conn, $database): void {
        $lookup = $conn->prepare(
            'SELECT DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?'
        );
        $lookup->bind_param('sss', $database, $table, $column);
        $lookup->execute();
        $existing = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (!$existing) {
            if (!$conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}")) {
                throw new RuntimeException("Unable to add checkout column {$table}.{$column}: {$conn->error}");
            }
            return;
        }
        $integer = in_array($expectedType, ['bigint', 'int'], true);
        $actualType = $integer ? strtolower((string) $existing['DATA_TYPE']) : strtolower((string) $existing['COLUMN_TYPE']);
        if ($actualType !== strtolower($expectedType) || $existing['IS_NULLABLE'] !== $nullable || ($default !== null && (string) ($existing['COLUMN_DEFAULT'] ?? '') !== $default)) {
            throw new RuntimeException("Existing checkout column {$table}.{$column} does not match the migration definition.");
        }
    };

    $ensureColumn('orders', 'discount_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'decimal(12,2)', 'NO', '0.00');
    $ensureColumn('orders', 'quote_id', 'BIGINT NULL', 'bigint', 'YES');
    $ensureColumn('orders', 'promotion_id', 'INT NULL', 'int', 'YES');
    $ensureColumn('orders', 'fee_rule_id', 'INT NULL', 'int', 'YES');
    $ensureColumn('order_items', 'item_public_id', 'VARCHAR(60) NULL', 'varchar(60)', 'YES');

    $statement = "CREATE TABLE IF NOT EXISTS checkout_quotes (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        public_id VARCHAR(60) NOT NULL,
        customer_user_id INT NOT NULL,
        restaurant_id INT NOT NULL,
        address_id BIGINT NOT NULL,
        cart_hash CHAR(64) NOT NULL,
        items_json LONGTEXT NOT NULL,
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
        discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        currency CHAR(3) NOT NULL DEFAULT 'USD',
        promotion_code VARCHAR(50) NULL,
        promotion_id INT NULL,
        fee_rule_id INT NULL,
        expires_at DATETIME NOT NULL,
        consumed_at DATETIME NULL,
        version INT NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_checkout_quote_public (public_id),
        KEY idx_checkout_quote_customer (customer_user_id),
        KEY idx_checkout_quote_restaurant (restaurant_id),
        CONSTRAINT fk_checkout_quotes_customer FOREIGN KEY (customer_user_id) REFERENCES users(id) ON DELETE RESTRICT,
        CONSTRAINT fk_checkout_quotes_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE RESTRICT,
        CONSTRAINT fk_checkout_quotes_address FOREIGN KEY (address_id) REFERENCES customer_addresses(id) ON DELETE RESTRICT,
        CONSTRAINT fk_checkout_quotes_promotion FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE SET NULL,
        CONSTRAINT fk_checkout_quotes_fee_rule FOREIGN KEY (fee_rule_id) REFERENCES fee_rules(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($statement)) {
        throw new RuntimeException('Checkout quote schema migration failed: ' . $conn->error);
    }

    $ensureUniqueIndex = static function (string $table, string $index, array $columns) use ($conn, $database): void {
        $lookup = $conn->prepare(
            'SELECT COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX'
        );
        $lookup->bind_param('sss', $database, $table, $index);
        $lookup->execute();
        $rows = $lookup->get_result()->fetch_all(MYSQLI_ASSOC);
        $lookup->close();
        $actual = array_column($rows, 'COLUMN_NAME');
        $unique = $rows !== [] && array_reduce($rows, static fn (bool $carry, array $row): bool => $carry && (int) $row['NON_UNIQUE'] === 0, true);
        if ($actual === $columns && $unique) return;
        if ($rows !== []) throw new RuntimeException("Existing checkout index {$table}.{$index} does not match the migration definition.");
        $quoted = implode(',', array_map(static fn (string $column): string => "`{$column}`", $columns));
        if (!$conn->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$index}` ({$quoted})")) {
            throw new RuntimeException("Unable to add checkout index {$table}.{$index}: {$conn->error}");
        }
    };
    $ensureUniqueIndex('checkout_quotes', 'uq_checkout_quote_public', ['public_id']);

    $addIndex = static function (string $table, string $index, string $column) use ($conn, $database): void {
        $lookup = $conn->prepare('SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?');
        $lookup->bind_param('sss', $database, $table, $index);
        $lookup->execute();
        $exists = (int) $lookup->get_result()->fetch_assoc()['total'] > 0;
        $lookup->close();
        if (!$exists && !$conn->query("ALTER TABLE `{$table}` ADD INDEX `{$index}` (`{$column}`)")) {
            throw new RuntimeException("Unable to add checkout index {$table}.{$index}: {$conn->error}");
        }
    };
    $addIndex('orders', 'idx_orders_quote', 'quote_id');
    $addIndex('orders', 'idx_orders_promotion', 'promotion_id');
    $addIndex('orders', 'idx_orders_fee_rule', 'fee_rule_id');

    $constraintLookup = $conn->prepare(
        'SELECT k.TABLE_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE
         FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.REFERENTIAL_CONSTRAINTS r
           ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
         WHERE k.CONSTRAINT_SCHEMA=? AND k.CONSTRAINT_NAME=?'
    );
    $constraints = [
        'fk_checkout_quotes_customer' => ['checkout_quotes', 'customer_user_id', 'users', 'id', 'RESTRICT'],
        'fk_checkout_quotes_restaurant' => ['checkout_quotes', 'restaurant_id', 'restaurants', 'id', 'RESTRICT'],
        'fk_checkout_quotes_address' => ['checkout_quotes', 'address_id', 'customer_addresses', 'id', 'RESTRICT'],
        'fk_checkout_quotes_promotion' => ['checkout_quotes', 'promotion_id', 'promotions', 'id', 'SET NULL'],
        'fk_checkout_quotes_fee_rule' => ['checkout_quotes', 'fee_rule_id', 'fee_rules', 'id', 'SET NULL'],
    ];
    foreach ($constraints as $name => $expected) {
        $constraintLookup->bind_param('ss', $database, $name);
        $constraintLookup->execute();
        $actual = $constraintLookup->get_result()->fetch_assoc();
        if (!$actual || [$actual['TABLE_NAME'], $actual['COLUMN_NAME'], $actual['REFERENCED_TABLE_NAME'], $actual['REFERENCED_COLUMN_NAME'], $actual['DELETE_RULE']] !== $expected) {
            throw new RuntimeException("Existing checkout constraint {$name} does not match the migration definition.");
        }
    }
    $constraintLookup->close();

    $orderConstraints = [
        'fk_orders_quote' => ['orders', 'quote_id', 'checkout_quotes', 'id', 'RESTRICT'],
        'fk_orders_promotion' => ['orders', 'promotion_id', 'promotions', 'id', 'SET NULL'],
        'fk_orders_fee_rule' => ['orders', 'fee_rule_id', 'fee_rules', 'id', 'SET NULL'],
    ];
    foreach ($orderConstraints as $name => $expected) {
        $constraintLookup = $conn->prepare(
            'SELECT k.TABLE_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
             WHERE k.CONSTRAINT_SCHEMA=? AND k.CONSTRAINT_NAME=?'
        );
        $constraintLookup->bind_param('ss', $database, $name);
        $constraintLookup->execute();
        $actual = $constraintLookup->get_result()->fetch_assoc();
        $constraintLookup->close();
        if ($actual === null) {
            [$table, $column, $parent, $parentColumn, $deleteRule] = $expected;
            $sql = "ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`) REFERENCES `{$parent}` (`{$parentColumn}`) ON DELETE {$deleteRule}";
            if (!$conn->query($sql)) throw new RuntimeException("Unable to add checkout constraint {$name}: {$conn->error}");
        } elseif ([$actual['TABLE_NAME'], $actual['COLUMN_NAME'], $actual['REFERENCED_TABLE_NAME'], $actual['REFERENCED_COLUMN_NAME'], $actual['DELETE_RULE']] !== $expected) {
            throw new RuntimeException("Existing checkout constraint {$name} does not match the migration definition.");
        }
    }
};
