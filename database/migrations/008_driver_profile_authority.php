<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $database = (string) ($conn->query('SELECT DATABASE() AS name')->fetch_assoc()['name'] ?? '');
    if ($database === '') throw new RuntimeException('A database must be selected before the Driver profile migration.');
    $column = static function (string $table, string $name, string $definition) use ($conn, $database): void {
        $check = $conn->prepare('SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
        $check->bind_param('sss', $database, $table, $name); $check->execute(); $exists = (int) ($check->get_result()->fetch_assoc()['total'] ?? 0) === 1; $check->close();
        if (!$exists && !$conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}")) throw new RuntimeException("Unable to add {$table}.{$name}: {$conn->error}");
    };
    $column('driver_profiles', 'vehicle_color', "VARCHAR(80) NULL");
    $column('driver_profiles', 'preferences_json', "JSON NULL");
    if (!$conn->query("CREATE TABLE IF NOT EXISTS driver_change_requests (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        public_id VARCHAR(60) NOT NULL UNIQUE,
        driver_user_id INT NOT NULL,
        change_type VARCHAR(40) NOT NULL,
        requested_json JSON NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        reviewer_user_id INT NULL,
        reviewer_reason VARCHAR(500) NULL,
        reviewed_at DATETIME NULL,
        version INT NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_driver_change_request_owner (driver_user_id,status),
        CONSTRAINT fk_driver_change_request_driver FOREIGN KEY (driver_user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_driver_change_request_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")) throw new RuntimeException('Driver change request migration failed: ' . $conn->error);
    if (!$conn->query("CREATE TABLE IF NOT EXISTS driver_documents (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        driver_user_id INT NOT NULL,
        document_type VARCHAR(60) NOT NULL,
        verification_status VARCHAR(30) NOT NULL DEFAULT 'pending',
        expires_at DATETIME NULL,
        reviewer_note VARCHAR(500) NULL,
        version INT NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_driver_document_type (driver_user_id,document_type),
        CONSTRAINT fk_driver_document_driver FOREIGN KEY (driver_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")) throw new RuntimeException('Driver document migration failed: ' . $conn->error);
};
