<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $sql = "CREATE TABLE IF NOT EXISTS delivery_demo_routes (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        delivery_id BIGINT NOT NULL,
        driver_user_id INT NOT NULL,
        start_latitude DECIMAL(10,7) NOT NULL,
        start_longitude DECIMAL(10,7) NOT NULL,
        end_latitude DECIMAL(10,7) NOT NULL,
        end_longitude DECIMAL(10,7) NOT NULL,
        started_at DATETIME NOT NULL,
        duration_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 60,
        status VARCHAR(20) NOT NULL DEFAULT 'running',
        completed_at DATETIME NULL,
        version INT NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_demo_route_delivery (delivery_id),
        KEY idx_demo_route_driver_status (driver_user_id,status),
        CONSTRAINT fk_demo_route_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
        CONSTRAINT fk_demo_route_driver FOREIGN KEY (driver_user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) throw new RuntimeException('Unable to create demo delivery routes: ' . $conn->error);
};
