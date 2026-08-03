<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $databaseResult = $conn->query('SELECT DATABASE() AS name');
    $database = (string) ($databaseResult?->fetch_assoc()['name'] ?? '');
    if ($database === '') {
        throw new RuntimeException('A database must be selected before applying the dispatch/location migration.');
    }

    $column = static function (string $table, string $name, string $definition, string $type, string $nullable) use ($conn, $database): void {
        $lookup = $conn->prepare('SELECT DATA_TYPE,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
        $lookup->bind_param('sss', $database, $table, $name);
        $lookup->execute();
        $existing = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (!$existing) {
            if (!$conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}")) {
                throw new RuntimeException("Unable to add dispatch column {$table}.{$name}: {$conn->error}");
            }
            return;
        }
        $integer = in_array($type, ['bigint', 'int', 'tinyint', 'decimal'], true);
        $actual = $integer ? strtolower((string) $existing['DATA_TYPE']) : strtolower((string) $existing['COLUMN_TYPE']);
        $expected = $integer ? strtolower(strtok($type, '(')) : strtolower($type);
        $alreadyFinalizedOfferReference = $table === 'delivery_offers' && $name === 'public_id' && (string) $existing['IS_NULLABLE'] === 'NO';
        if ($actual !== $expected || ((string) $existing['IS_NULLABLE'] !== $nullable && !$alreadyFinalizedOfferReference)) {
            throw new RuntimeException("Existing dispatch column {$table}.{$name} does not match the migration definition.");
        }
    };

    if (!$conn->query("CREATE TABLE IF NOT EXISTS driver_locations (
        driver_user_id INT PRIMARY KEY,
        latitude DECIMAL(10,7) NOT NULL,
        longitude DECIMAL(10,7) NOT NULL,
        accuracy_meters DECIMAL(10,2) NULL,
        recorded_at DATETIME NOT NULL,
        version INT NOT NULL DEFAULT 1,
        CONSTRAINT fk_driver_location_user FOREIGN KEY (driver_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")) {
        throw new RuntimeException('Driver location schema migration failed: ' . $conn->error);
    }

    $column('deliveries', 'superseded_at', 'DATETIME NULL', 'datetime', 'YES');
    $column('deliveries', 'superseded_by_delivery_id', 'BIGINT NULL', 'bigint', 'YES');
    $column('delivery_dispatches', 'last_offered_at', 'DATETIME NULL', 'datetime', 'YES');
    $column('delivery_offers', 'public_id', 'VARCHAR(60) NULL', 'varchar(60)', 'YES');
    $column('delivery_offers', 'dispatch_version', 'INT NOT NULL DEFAULT 1', 'int', 'NO');
    $column('delivery_offers', 'active_dispatch_key', 'VARCHAR(100) NULL', 'varchar(100)', 'YES');
    $column('delivery_offers', 'active_driver_key', 'VARCHAR(100) NULL', 'varchar(100)', 'YES');
    $column('delivery_offers', 'declined_at', 'DATETIME NULL', 'datetime', 'YES');
    $column('delivery_offers', 'expired_at', 'DATETIME NULL', 'datetime', 'YES');
    $column('delivery_offers', 'response_code', 'VARCHAR(30) NULL', 'varchar(30)', 'YES');
    $column('delivery_offers', 'response_reason', 'VARCHAR(255) NULL', 'varchar(255)', 'YES');

    $backfill = $conn->query("UPDATE delivery_offers SET public_id=CONCAT('OFF-', id) WHERE public_id IS NULL OR public_id=''");
    if (!$backfill) throw new RuntimeException('Unable to backfill delivery offer references: ' . $conn->error);
    $activeBackfill = $conn->query("UPDATE delivery_offers SET active_dispatch_key=CONCAT('dispatch:', dispatch_id, ':', id), active_driver_key=CONCAT('driver:', driver_user_id, ':', id) WHERE status='sent' AND expires_at > NOW() AND active_dispatch_key IS NULL");
    if (!$activeBackfill) throw new RuntimeException('Unable to backfill active delivery offer keys: ' . $conn->error);

    $makeNotNull = static function (string $table, string $name, string $definition) use ($conn): void {
        if (!$conn->query("ALTER TABLE `{$table}` MODIFY COLUMN `{$name}` {$definition}")) {
            throw new RuntimeException("Unable to finalize dispatch column {$table}.{$name}: {$conn->error}");
        }
    };
    $makeNotNull('delivery_offers', 'public_id', 'VARCHAR(60) NOT NULL');

    $index = static function (string $table, string $name, string $columns, bool $unique = false) use ($conn, $database): void {
        $lookup = $conn->prepare('SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?');
        $lookup->bind_param('sss', $database, $table, $name);
        $lookup->execute();
        $exists = (int) ($lookup->get_result()->fetch_assoc()['total'] ?? 0) > 0;
        $lookup->close();
        if (!$exists) {
            $kind = $unique ? 'UNIQUE ' : '';
            if (!$conn->query("ALTER TABLE `{$table}` ADD {$kind}INDEX `{$name}` ({$columns})")) {
                throw new RuntimeException("Unable to add dispatch index {$table}.{$name}: {$conn->error}");
            }
        }
    };
    $index('driver_locations', 'idx_driver_location_recorded', '`recorded_at`');
    $index('driver_profiles', 'idx_driver_eligibility_availability', '`eligibility_status`,`availability_status`');
    $index('delivery_offers', 'uq_delivery_offer_public', '`public_id`', true);
    $index('delivery_offers', 'uq_delivery_offer_active_dispatch', '`active_dispatch_key`', true);
    $index('delivery_offers', 'uq_delivery_offer_active_driver', '`active_driver_key`', true);
    $index('delivery_offers', 'idx_delivery_offer_expiry', '`status`,`expires_at`');
    $index('delivery_offers', 'idx_delivery_offer_dispatch_status', '`dispatch_id`,`status`');
    $index('delivery_offers', 'idx_delivery_offer_driver_status', '`driver_user_id`,`status`');
    $index('deliveries', 'idx_delivery_driver_active', '`driver_user_id`,`status`,`superseded_at`');
    $index('deliveries', 'idx_delivery_supersession', '`superseded_by_delivery_id`');
};
