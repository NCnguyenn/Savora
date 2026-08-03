<?php
declare(strict_types=1);
return static function (mysqli $conn): void {
    if (!$conn->query("CREATE TABLE IF NOT EXISTS notification_outbox (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        notification_id BIGINT NOT NULL,
        channel VARCHAR(30) NOT NULL DEFAULT 'in_app',
        payload_json JSON NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        attempt_count INT NOT NULL DEFAULT 0,
        next_attempt_at DATETIME NULL,
        last_error_reference VARCHAR(80) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_notification_outbox_channel (notification_id,channel),
        KEY idx_notification_outbox_status (status,next_attempt_at),
        CONSTRAINT fk_notification_outbox_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")) throw new RuntimeException('Notification outbox migration failed: ' . $conn->error);
};
