<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $databaseResult = $conn->query('SELECT DATABASE() AS name');
    $database = (string) ($databaseResult?->fetch_assoc()['name'] ?? '');
    if ($database === '') throw new RuntimeException('A database must be selected before applying delivery evidence migration.');

    $column = static function (string $table, string $name, string $definition, string $type, string $nullable) use ($conn, $database): void {
        $lookup = $conn->prepare('SELECT DATA_TYPE,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
        $lookup->bind_param('sss', $database, $table, $name); $lookup->execute(); $existing = $lookup->get_result()->fetch_assoc(); $lookup->close();
        if (!$existing) {
            if (!$conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}")) throw new RuntimeException("Unable to add delivery evidence column {$table}.{$name}: {$conn->error}");
            return;
        }
        $integer = in_array($type, ['bigint','int','tinyint'], true);
        $actual = $integer ? strtolower((string) $existing['DATA_TYPE']) : strtolower((string) $existing['COLUMN_TYPE']);
        $expected = $integer ? strtolower($type) : strtolower($type);
        if ($actual !== $expected || (string) $existing['IS_NULLABLE'] !== $nullable) throw new RuntimeException("Existing delivery evidence column {$table}.{$name} does not match the migration definition.");
    };

    $column('deliveries', 'proof_required', 'TINYINT(1) NOT NULL DEFAULT 0', 'tinyint', 'NO');
    $column('deliveries', 'failed_at', 'DATETIME NULL', 'datetime', 'YES');
    $column('deliveries', 'failure_reason', 'VARCHAR(500) NULL', 'varchar(500)', 'YES');
    $column('delivery_milestones', 'note', 'VARCHAR(255) NULL', 'varchar(255)', 'YES');

    if (!$conn->query("CREATE TABLE IF NOT EXISTS delivery_evidence (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        delivery_id BIGINT NOT NULL,
        evidence_type VARCHAR(40) NOT NULL,
        stored_path VARCHAR(500) NOT NULL,
        mime_type VARCHAR(100) NOT NULL,
        size_bytes INT NOT NULL,
        sha256 CHAR(64) NOT NULL,
        captured_at DATETIME NULL,
        uploaded_by INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_delivery_evidence_delivery (delivery_id),
        KEY idx_delivery_evidence_uploader (uploaded_by),
        CONSTRAINT fk_delivery_evidence_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
        CONSTRAINT fk_delivery_evidence_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")) throw new RuntimeException('Delivery evidence schema migration failed: ' . $conn->error);
};
