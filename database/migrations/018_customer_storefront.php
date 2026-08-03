<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $database = (string) ($conn->query('SELECT DATABASE() AS name')?->fetch_assoc()['name'] ?? '');
    if ($database === '') {
        throw new RuntimeException('A database must be selected before applying the storefront migration.');
    }

    $ensureColumn = static function (string $table, string $column, string $definition) use ($conn, $database): void {
        $lookup = $conn->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
        $lookup->bind_param('sss', $database, $table, $column);
        $lookup->execute();
        $exists = $lookup->get_result()->fetch_assoc() !== null;
        $lookup->close();
        if (!$exists && !$conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}")) {
            throw new RuntimeException("Unable to add storefront column {$table}.{$column}: {$conn->error}");
        }
    };

    $ensureColumn('restaurants', 'public_id', 'VARCHAR(60) NULL');
    $ensureColumn('restaurants', 'slogan', 'VARCHAR(180) NULL');
    $ensureColumn('restaurants', 'logo_path', 'VARCHAR(255) NULL');
    $ensureColumn('menu_items', 'item_type', "VARCHAR(20) NOT NULL DEFAULT 'food'");

    if (!$conn->query("UPDATE restaurants SET public_id=CONCAT('restaurant-',id) WHERE public_id IS NULL OR public_id=''")) {
        throw new RuntimeException('Unable to backfill restaurant public IDs: ' . $conn->error);
    }
    if (!$conn->query('ALTER TABLE restaurants MODIFY public_id VARCHAR(60) NOT NULL')) {
        throw new RuntimeException('Unable to enforce restaurant public IDs: ' . $conn->error);
    }

    $index = $conn->prepare('SELECT COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=\'restaurants\' AND INDEX_NAME=\'uq_restaurants_public_id\' ORDER BY SEQ_IN_INDEX');
    $index->bind_param('s', $database);
    $index->execute();
    $rows = $index->get_result()->fetch_all(MYSQLI_ASSOC);
    $index->close();
    if ($rows === [] && !$conn->query('ALTER TABLE restaurants ADD UNIQUE KEY uq_restaurants_public_id (public_id)')) {
        throw new RuntimeException('Unable to add restaurant public ID index: ' . $conn->error);
    }
    if ($rows !== [] && (count($rows) !== 1 || $rows[0]['COLUMN_NAME'] !== 'public_id' || (int) $rows[0]['NON_UNIQUE'] !== 0)) {
        throw new RuntimeException('Existing restaurant public ID index does not match the migration definition.');
    }
};
