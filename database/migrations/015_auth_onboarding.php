<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $database = (string) ($conn->query('SELECT DATABASE() AS name')->fetch_assoc()['name'] ?? '');
    if ($database === '') throw new RuntimeException('A database must be selected before the auth onboarding migration.');

    $column = static function (string $table, string $name, string $definition, string $expectedType, string $nullable) use ($conn, $database): void {
        $lookup = $conn->prepare('SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
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
        $actual = strtolower((string) $existing['COLUMN_TYPE']);
        $actual = (string) preg_replace('/^int\(\d+\)$/', 'int', $actual);
        $actual = (string) preg_replace('/^bigint\(\d+\)$/', 'bigint', $actual);
        if ($actual !== strtolower($expectedType) || (string) $existing['IS_NULLABLE'] !== $nullable) {
            throw new RuntimeException("Existing onboarding column {$table}.{$name} does not match the migration contract.");
        }
    };

    foreach ([
        "CREATE TABLE IF NOT EXISTS identity_claims (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            identifier_type VARCHAR(20) NOT NULL,
            normalized_value VARCHAR(190) NOT NULL,
            owner_kind VARCHAR(40) NOT NULL,
            owner_id BIGINT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_identity_claim (identifier_type,normalized_value),
            KEY idx_identity_owner (owner_kind,owner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS media_assets (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(60) NOT NULL,
            owner_kind VARCHAR(40) NOT NULL,
            owner_id BIGINT NOT NULL,
            purpose VARCHAR(40) NOT NULL,
            stored_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size INT NOT NULL,
            sha256 CHAR(64) NOT NULL,
            visibility VARCHAR(20) NOT NULL DEFAULT 'private',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_media_public_id (public_id),
            KEY idx_media_owner (owner_kind,owner_id,purpose)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS admin_profiles (
            user_id INT NOT NULL PRIMARY KEY,
            privilege_level VARCHAR(30) NOT NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_admin_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_admin_profile_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ] as $sql) {
        if (!$conn->query($sql)) throw new RuntimeException('Auth onboarding table migration failed: ' . $conn->error);
    }

    $columns = [
        ['customer_profiles', 'default_delivery_notes', 'VARCHAR(500) NULL', 'varchar(500)', 'YES'],
        ['restaurant_applications', 'description', 'VARCHAR(1000) NULL', 'varchar(1000)', 'YES'],
        ['restaurant_applications', 'restaurant_phone', 'VARCHAR(40) NULL', 'varchar(40)', 'YES'],
        ['restaurant_applications', 'opens_at', 'TIME NULL', 'time', 'YES'],
        ['restaurant_applications', 'closes_at', 'TIME NULL', 'time', 'YES'],
        ['driver_applications', 'vehicle_color', 'VARCHAR(80) NULL', 'varchar(80)', 'YES'],
        ['restaurants', 'description', 'VARCHAR(1000) NULL', 'varchar(1000)', 'YES'],
        ['restaurants', 'logo_media_id', 'BIGINT NULL', 'bigint', 'YES'],
    ];
    foreach ($columns as [$table, $name, $definition, $expectedType, $nullable]) {
        $column($table, $name, $definition, $expectedType, $nullable);
    }

    $duplicates = $conn->query(
        "SELECT identifier_type,normalized_value,COUNT(*) AS total FROM (
            SELECT 'username' AS identifier_type,LOWER(TRIM(username)) AS normalized_value FROM users WHERE TRIM(username)<>''
            UNION ALL SELECT 'email',LOWER(TRIM(email)) FROM users WHERE email IS NOT NULL AND TRIM(email)<>''
            UNION ALL SELECT 'username',LOWER(TRIM(username)) FROM restaurant_applications WHERE status IN ('pending','in_review','changes_requested') AND password_hash IS NOT NULL
            UNION ALL SELECT 'email',LOWER(TRIM(owner_email)) FROM restaurant_applications WHERE status IN ('pending','in_review','changes_requested') AND password_hash IS NOT NULL AND TRIM(owner_email)<>''
            UNION ALL SELECT 'username',LOWER(TRIM(username)) FROM driver_applications WHERE status IN ('pending','in_review','changes_requested') AND password_hash IS NOT NULL
            UNION ALL SELECT 'email',LOWER(TRIM(email)) FROM driver_applications WHERE status IN ('pending','in_review','changes_requested') AND password_hash IS NOT NULL AND TRIM(email)<>''
        ) identifiers
        WHERE normalized_value<>''
        GROUP BY identifier_type,normalized_value HAVING COUNT(*)>1 LIMIT 1"
    );
    if ($duplicates->fetch_assoc()) throw new RuntimeException('Existing identity values collide after normalization.');

    $claimSql = [
        "INSERT INTO identity_claims(identifier_type,normalized_value,owner_kind,owner_id)
         SELECT 'username',LOWER(TRIM(username)),'user',id FROM users WHERE TRIM(username)<>''
         ON DUPLICATE KEY UPDATE owner_kind=VALUES(owner_kind),owner_id=VALUES(owner_id)",
        "INSERT INTO identity_claims(identifier_type,normalized_value,owner_kind,owner_id)
         SELECT 'email',LOWER(TRIM(email)),'user',id FROM users WHERE email IS NOT NULL AND TRIM(email)<>''
         ON DUPLICATE KEY UPDATE owner_kind=VALUES(owner_kind),owner_id=VALUES(owner_id)",
        "INSERT INTO identity_claims(identifier_type,normalized_value,owner_kind,owner_id)
         SELECT 'username',LOWER(TRIM(username)),'restaurant_application',id FROM restaurant_applications
         WHERE status IN ('pending','in_review','changes_requested') AND password_hash IS NOT NULL
         ON DUPLICATE KEY UPDATE owner_kind=VALUES(owner_kind),owner_id=VALUES(owner_id)",
        "INSERT INTO identity_claims(identifier_type,normalized_value,owner_kind,owner_id)
         SELECT 'email',LOWER(TRIM(owner_email)),'restaurant_application',id FROM restaurant_applications
         WHERE status IN ('pending','in_review','changes_requested') AND password_hash IS NOT NULL AND TRIM(owner_email)<>''
         ON DUPLICATE KEY UPDATE owner_kind=VALUES(owner_kind),owner_id=VALUES(owner_id)",
        "INSERT INTO identity_claims(identifier_type,normalized_value,owner_kind,owner_id)
         SELECT 'username',LOWER(TRIM(username)),'driver_application',id FROM driver_applications
         WHERE status IN ('pending','in_review','changes_requested') AND password_hash IS NOT NULL
         ON DUPLICATE KEY UPDATE owner_kind=VALUES(owner_kind),owner_id=VALUES(owner_id)",
        "INSERT INTO identity_claims(identifier_type,normalized_value,owner_kind,owner_id)
         SELECT 'email',LOWER(TRIM(email)),'driver_application',id FROM driver_applications
         WHERE status IN ('pending','in_review','changes_requested') AND password_hash IS NOT NULL AND TRIM(email)<>''
         ON DUPLICATE KEY UPDATE owner_kind=VALUES(owner_kind),owner_id=VALUES(owner_id)",
    ];
    foreach ($claimSql as $sql) {
        if (!$conn->query($sql)) throw new RuntimeException('Unable to backfill identity claims: ' . $conn->error);
    }

    if (!$conn->query(
        "INSERT IGNORE INTO admin_profiles(user_id,privilege_level,created_by)
         SELECT id,'super_admin',NULL FROM users WHERE role='admin'"
    )) throw new RuntimeException('Unable to backfill Admin profiles: ' . $conn->error);

    $indexLookup = $conn->prepare(
        'SELECT COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX'
    );
    $table = 'users'; $index = 'uq_users_email';
    $indexLookup->bind_param('sss', $database, $table, $index);
    $indexLookup->execute();
    $emailIndex = $indexLookup->get_result()->fetch_all(MYSQLI_ASSOC);
    if ($emailIndex === []) {
        if (!$conn->query('ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)')) {
            throw new RuntimeException('Unable to enforce user email uniqueness: ' . $conn->error);
        }
    } elseif (array_column($emailIndex, 'COLUMN_NAME') !== ['email'] || (int) $emailIndex[0]['NON_UNIQUE'] !== 0) {
        throw new RuntimeException('Existing users.uq_users_email does not match the migration contract.');
    }

    $constraintLookup = $conn->prepare(
        'SELECT k.TABLE_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE
         FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.REFERENTIAL_CONSTRAINTS r
           ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
         WHERE k.CONSTRAINT_SCHEMA=? AND k.CONSTRAINT_NAME=?'
    );
    $constraint = 'fk_restaurant_logo_media';
    $constraintLookup->bind_param('ss', $database, $constraint);
    $constraintLookup->execute();
    $existingConstraint = $constraintLookup->get_result()->fetch_assoc();
    $constraintLookup->close();
    if (!$existingConstraint) {
        if (!$conn->query('ALTER TABLE restaurants ADD CONSTRAINT fk_restaurant_logo_media FOREIGN KEY (logo_media_id) REFERENCES media_assets(id) ON DELETE SET NULL')) {
            throw new RuntimeException('Unable to add Restaurant logo media constraint: ' . $conn->error);
        }
    } elseif ([$existingConstraint['TABLE_NAME'], $existingConstraint['COLUMN_NAME'], $existingConstraint['REFERENCED_TABLE_NAME'], $existingConstraint['REFERENCED_COLUMN_NAME'], $existingConstraint['DELETE_RULE']] !== ['restaurants', 'logo_media_id', 'media_assets', 'id', 'SET NULL']) {
        throw new RuntimeException('Existing Restaurant logo media constraint does not match the migration contract.');
    }

    $indexLookup->close();
};
