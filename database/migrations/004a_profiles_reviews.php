<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $databaseResult = $conn->query('SELECT DATABASE() AS name');
    $database = (string) ($databaseResult?->fetch_assoc()['name'] ?? '');
    if ($database === '') {
        throw new RuntimeException('A database must be selected before applying the profile and review migration.');
    }

    $column = $conn->prepare(
        'SELECT DATA_TYPE,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    $table = 'customer_profiles';
    $name = 'version';
    $column->bind_param('sss', $database, $table, $name);
    $column->execute();
    $profileVersion = $column->get_result()->fetch_assoc();
    if (!$profileVersion) {
        if (!$conn->query('ALTER TABLE customer_profiles ADD COLUMN version INT NOT NULL DEFAULT 1')) {
            throw new RuntimeException('Unable to add customer profile version: ' . $conn->error);
        }
    } elseif (strtolower((string) $profileVersion['DATA_TYPE']) !== 'int' || $profileVersion['IS_NULLABLE'] !== 'NO') {
        throw new RuntimeException('Existing customer_profiles.version does not match the migration contract.');
    }

    $statements = [
        "CREATE TABLE IF NOT EXISTS customer_addresses (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            customer_user_id INT NOT NULL,
            public_id VARCHAR(60) NOT NULL,
            label VARCHAR(80) NOT NULL,
            recipient_name VARCHAR(120) NOT NULL,
            phone VARCHAR(40) NOT NULL,
            address_line1 VARCHAR(200) NOT NULL,
            address_line2 VARCHAR(200) NULL,
            city VARCHAR(100) NOT NULL,
            region VARCHAR(100) NULL,
            postal_code VARCHAR(30) NULL,
            latitude DECIMAL(10,7) NOT NULL,
            longitude DECIMAL(10,7) NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            version INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_customer_address_public (customer_user_id,public_id),
            CONSTRAINT fk_customer_addresses_user FOREIGN KEY (customer_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS customer_favorites (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            customer_user_id INT NOT NULL,
            favorite_type VARCHAR(20) NOT NULL,
            entity_public_id VARCHAR(60) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_customer_favorite (customer_user_id,favorite_type,entity_public_id),
            CONSTRAINT fk_customer_favorites_user FOREIGN KEY (customer_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS restaurant_reviews (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(60) NOT NULL,
            order_id BIGINT NOT NULL,
            customer_user_id INT NOT NULL,
            restaurant_id INT NOT NULL,
            rating TINYINT NOT NULL,
            comment VARCHAR(1000) NOT NULL,
            reply_text VARCHAR(1000) NULL,
            reply_status VARCHAR(20) NOT NULL DEFAULT 'none',
            replied_at DATETIME NULL,
            version INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_restaurant_review_public (public_id),
            UNIQUE KEY uq_restaurant_review_order (order_id),
            CONSTRAINT fk_restaurant_reviews_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
            CONSTRAINT fk_restaurant_reviews_customer FOREIGN KEY (customer_user_id) REFERENCES users(id) ON DELETE RESTRICT,
            CONSTRAINT fk_restaurant_reviews_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($statements as $sql) {
        if (!$conn->query($sql)) {
            throw new RuntimeException('Profile and review schema migration failed: ' . $conn->error);
        }
    }

    $required = [
        'customer_addresses' => ['id' => 'bigint', 'customer_user_id' => 'int', 'public_id' => 'varchar(60)', 'latitude' => 'decimal(10,7)', 'longitude' => 'decimal(10,7)', 'is_default' => 'tinyint(1)', 'version' => 'int'],
        'customer_favorites' => ['id' => 'bigint', 'customer_user_id' => 'int', 'favorite_type' => 'varchar(20)', 'entity_public_id' => 'varchar(60)'],
        'restaurant_reviews' => ['id' => 'bigint', 'public_id' => 'varchar(60)', 'order_id' => 'bigint', 'customer_user_id' => 'int', 'restaurant_id' => 'int', 'rating' => 'tinyint', 'reply_status' => 'varchar(20)', 'version' => 'int'],
    ];
    foreach ($required as $requiredTable => $columns) {
        foreach ($columns as $requiredColumn => $expectedType) {
            $column->bind_param('sss', $database, $requiredTable, $requiredColumn);
            $column->execute();
            $actual = $column->get_result()->fetch_assoc();
            $integer = in_array($expectedType, ['bigint', 'int', 'tinyint'], true);
            $matches = $integer
                ? strtolower((string) ($actual['DATA_TYPE'] ?? '')) === $expectedType
                : strtolower((string) ($actual['COLUMN_TYPE'] ?? '')) === $expectedType;
            if (!$actual || !$matches || $actual['IS_NULLABLE'] !== 'NO') {
                throw new RuntimeException("Existing profile/review column {$requiredTable}.{$requiredColumn} does not match the migration contract.");
            }
        }
    }
    $column->close();

    $index = $conn->prepare(
        'SELECT COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX'
    );
    $indexes = [
        ['customer_addresses', 'uq_customer_address_public', ['customer_user_id', 'public_id']],
        ['customer_favorites', 'uq_customer_favorite', ['customer_user_id', 'favorite_type', 'entity_public_id']],
        ['restaurant_reviews', 'uq_restaurant_review_public', ['public_id']],
        ['restaurant_reviews', 'uq_restaurant_review_order', ['order_id']],
    ];
    foreach ($indexes as [$indexTable, $indexName, $expectedColumns]) {
        $index->bind_param('sss', $database, $indexTable, $indexName);
        $index->execute();
        $rows = $index->get_result()->fetch_all(MYSQLI_ASSOC);
        if (array_column($rows, 'COLUMN_NAME') !== $expectedColumns || array_filter($rows, static fn (array $row): bool => (int) $row['NON_UNIQUE'] !== 0) !== []) {
            throw new RuntimeException("Existing profile/review index {$indexTable}.{$indexName} does not match the migration contract.");
        }
    }
    $index->close();

    $primary = $conn->prepare(
        "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX"
    );
    foreach (['customer_addresses', 'customer_favorites', 'restaurant_reviews'] as $requiredTable) {
        $primary->bind_param('ss', $database, $requiredTable);
        $primary->execute();
        $columns = array_column($primary->get_result()->fetch_all(MYSQLI_ASSOC), 'COLUMN_NAME');
        if ($columns !== ['id']) {
            throw new RuntimeException("Existing profile/review table {$requiredTable} primary key does not match the migration contract.");
        }
    }
    $primary->close();

    $constraintLookup = $conn->prepare(
        'SELECT k.TABLE_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE
         FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.REFERENTIAL_CONSTRAINTS r
           ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
         WHERE k.CONSTRAINT_SCHEMA=? AND k.CONSTRAINT_NAME=?'
    );
    $constraints = [
        'fk_customer_addresses_user' => ['customer_addresses', 'customer_user_id', 'users', 'id', 'CASCADE'],
        'fk_customer_favorites_user' => ['customer_favorites', 'customer_user_id', 'users', 'id', 'CASCADE'],
        'fk_restaurant_reviews_order' => ['restaurant_reviews', 'order_id', 'orders', 'id', 'RESTRICT'],
        'fk_restaurant_reviews_customer' => ['restaurant_reviews', 'customer_user_id', 'users', 'id', 'RESTRICT'],
        'fk_restaurant_reviews_restaurant' => ['restaurant_reviews', 'restaurant_id', 'restaurants', 'id', 'RESTRICT'],
    ];
    foreach ($constraints as $constraintName => $expected) {
        $constraintLookup->bind_param('ss', $database, $constraintName);
        $constraintLookup->execute();
        $actual = $constraintLookup->get_result()->fetch_assoc();
        if (!$actual || [$actual['TABLE_NAME'], $actual['COLUMN_NAME'], $actual['REFERENCED_TABLE_NAME'], $actual['REFERENCED_COLUMN_NAME'], $actual['DELETE_RULE']] !== $expected) {
            throw new RuntimeException("Existing profile/review constraint {$constraintName} does not match the migration contract.");
        }
    }
    $constraintLookup->close();
};
