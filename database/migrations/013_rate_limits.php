<?php
declare(strict_types=1);
return static function (mysqli $conn): void {
    if (!$conn->query("CREATE TABLE IF NOT EXISTS rate_limit_buckets (
        bucket_key CHAR(64) PRIMARY KEY,
        window_started_at DATETIME NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        version INT NOT NULL DEFAULT 1,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")) throw new RuntimeException('Rate limit migration failed: ' . $conn->error);
};
