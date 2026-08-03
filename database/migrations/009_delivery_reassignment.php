<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $database = (string) ($conn->query('SELECT DATABASE() AS name')->fetch_assoc()['name'] ?? '');
    if ($database === '') throw new RuntimeException('A database must be selected before delivery reassignment migration.');

    $unique = $conn->prepare("SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME='deliveries' AND INDEX_NAME='order_id' AND NON_UNIQUE=0");
    $unique->bind_param('s', $database);
    $unique->execute();
    $hasUniqueOrderIndex = (int) ($unique->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $unique->close();

    $foreignKey = $conn->prepare(
        "SELECT k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE
         FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.REFERENTIAL_CONSTRAINTS r
           ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
         WHERE k.CONSTRAINT_SCHEMA=? AND k.TABLE_NAME='deliveries' AND k.CONSTRAINT_NAME='fk_deliveries_order'"
    );
    $foreignKey->bind_param('s', $database);
    $foreignKey->execute();
    $foreignKeyRow = $foreignKey->get_result()->fetch_assoc();
    $foreignKey->close();

    if ($foreignKeyRow && [$foreignKeyRow['COLUMN_NAME'], $foreignKeyRow['REFERENCED_TABLE_NAME'], $foreignKeyRow['REFERENCED_COLUMN_NAME'], $foreignKeyRow['DELETE_RULE']] !== ['order_id', 'orders', 'id', 'RESTRICT']) {
        throw new RuntimeException('Existing delivery order foreign key does not match the reassignment contract.');
    }

    if ($hasUniqueOrderIndex) {
        if ($foreignKeyRow && !$conn->query('ALTER TABLE deliveries DROP FOREIGN KEY fk_deliveries_order')) throw new RuntimeException('Unable to release delivery order key: ' . $conn->error);
        if (!$conn->query('ALTER TABLE deliveries DROP INDEX order_id')) throw new RuntimeException('Unable to allow delivery reassignment history: ' . $conn->error);
        $foreignKeyRow = null;
    }

    if (!$foreignKeyRow && !$conn->query('ALTER TABLE deliveries ADD CONSTRAINT fk_deliveries_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT')) {
        throw new RuntimeException('Unable to restore delivery order foreign key: ' . $conn->error);
    }

    $index = $conn->prepare("SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME='deliveries' AND INDEX_NAME='idx_delivery_order'");
    $index->bind_param('s', $database);
    $index->execute();
    $hasIndex = (int) ($index->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $index->close();
    if (!$hasIndex && !$conn->query('ALTER TABLE deliveries ADD INDEX idx_delivery_order (order_id,status)')) throw new RuntimeException('Unable to add delivery history index: ' . $conn->error);
};
