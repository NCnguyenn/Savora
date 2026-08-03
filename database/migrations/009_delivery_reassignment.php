<?php
declare(strict_types=1);
return static function (mysqli $conn): void {
    $database = (string) ($conn->query('SELECT DATABASE() AS name')->fetch_assoc()['name'] ?? '');
    if ($database === '') throw new RuntimeException('A database must be selected before delivery reassignment migration.');
    $check = $conn->prepare("SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME='deliveries' AND INDEX_NAME='order_id' AND NON_UNIQUE=0"); $check->bind_param('s', $database); $check->execute(); $exists = (int) ($check->get_result()->fetch_assoc()['total'] ?? 0) > 0; $check->close();
    if ($exists) {
        $fk = $conn->prepare("SELECT COUNT(*) AS total FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND TABLE_NAME='deliveries' AND CONSTRAINT_NAME='fk_deliveries_order'"); $fk->bind_param('s', $database); $fk->execute(); $hasFk = (int) ($fk->get_result()->fetch_assoc()['total'] ?? 0) > 0; $fk->close();
        if ($hasFk && !$conn->query('ALTER TABLE deliveries DROP FOREIGN KEY fk_deliveries_order')) throw new RuntimeException('Unable to release delivery order key: ' . $conn->error);
        if (!$conn->query('ALTER TABLE deliveries DROP INDEX order_id')) throw new RuntimeException('Unable to allow delivery reassignment history: ' . $conn->error);
        if (!$conn->query('ALTER TABLE deliveries ADD CONSTRAINT fk_deliveries_order FOREIGN KEY (order_id) REFERENCES orders(id)')) throw new RuntimeException('Unable to restore delivery order foreign key: ' . $conn->error);
    }
    $index = $conn->prepare("SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME='deliveries' AND INDEX_NAME='idx_delivery_order'"); $index->bind_param('s', $database); $index->execute(); $hasIndex = (int) ($index->get_result()->fetch_assoc()['total'] ?? 0) > 0; $index->close();
    if (!$hasIndex && !$conn->query('ALTER TABLE deliveries ADD INDEX idx_delivery_order (order_id,status)')) throw new RuntimeException('Unable to add delivery history index: ' . $conn->error);
};
