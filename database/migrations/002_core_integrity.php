<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $relations = [
        ['fk_orders_customer', 'orders', 'customer_user_id', 'users', 'id', 'RESTRICT', 'idx_orders_customer'],
        ['fk_orders_restaurant', 'orders', 'restaurant_id', 'restaurants', 'id', 'RESTRICT', 'idx_orders_restaurant'],
        ['fk_order_items_order', 'order_items', 'order_id', 'orders', 'id', 'RESTRICT', 'idx_order_items_order'],
        ['fk_order_history_order', 'order_status_history', 'order_id', 'orders', 'id', 'RESTRICT', 'idx_order_history_order'],
        ['fk_payments_order', 'payments', 'order_id', 'orders', 'id', 'RESTRICT', 'idx_payments_order'],
        ['fk_deliveries_order', 'deliveries', 'order_id', 'orders', 'id', 'RESTRICT', 'idx_deliveries_order'],
        ['fk_user_sessions_user', 'user_sessions', 'user_id', 'users', 'id', 'RESTRICT', 'idx_user_sessions_user'],
        ['fk_restaurant_documents_application', 'restaurant_application_documents', 'application_id', 'restaurant_applications', 'id', 'CASCADE', 'idx_restaurant_documents_application'],
        ['fk_driver_documents_application', 'driver_application_documents', 'application_id', 'driver_applications', 'id', 'CASCADE', 'idx_driver_documents_application'],
        ['fk_notifications_user', 'notifications', 'user_id', 'users', 'id', 'RESTRICT', 'idx_notifications_user'],
        ['fk_refunds_order', 'refunds', 'order_id', 'orders', 'id', 'RESTRICT', 'idx_refunds_order'],
        ['fk_payout_items_payout', 'payout_items', 'payout_id', 'payouts', 'id', 'RESTRICT', 'idx_payout_items_payout'],
        ['fk_case_messages_case', 'case_messages', 'case_id', 'support_cases', 'id', 'RESTRICT', 'idx_case_messages_case'],
    ];

    $databaseResult = $conn->query('SELECT DATABASE() AS name');
    $database = (string) ($databaseResult?->fetch_assoc()['name'] ?? '');
    if ($database === '') {
        throw new RuntimeException('A database must be selected before applying core integrity constraints.');
    }

    foreach ($relations as [$constraint, $table, $column, $parent, $parentColumn]) {
        foreach ([$constraint, $table, $column, $parent, $parentColumn] as $identifier) {
            if (!preg_match('/^[a-z0-9_]+$/', $identifier)) {
                throw new RuntimeException('Unsafe identifier in the core integrity migration.');
            }
        }
        $sql = "SELECT COUNT(*) AS total
                FROM `{$table}` child
                LEFT JOIN `{$parent}` parent ON child.`{$column}` = parent.`{$parentColumn}`
                WHERE child.`{$column}` IS NOT NULL AND parent.`{$parentColumn}` IS NULL";
        $result = $conn->query($sql);
        if (!$result) {
            throw new RuntimeException("Unable to preflight {$table}.{$column}: {$conn->error}");
        }
        $orphans = (int) $result->fetch_assoc()['total'];
        if ($orphans > 0) {
            throw new RuntimeException(
                "Orphan rows prevent 002_core_integrity: {$table}.{$column} -> {$parent}.{$parentColumn} "
                . "({$orphans} orphan" . ($orphans === 1 ? '' : 's') . ').'
            );
        }
    }

    $constraintLookup = $conn->prepare(
        'SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE
         FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.REFERENTIAL_CONSTRAINTS r
           ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
          AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
         WHERE k.CONSTRAINT_SCHEMA = ? AND k.CONSTRAINT_NAME = ?'
    );
    $indexLookup = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1'
    );

    try {
        foreach ($relations as [$constraint, $table, $column, $parent, $parentColumn, $deleteRule, $index]) {
            $constraintLookup->bind_param('ss', $database, $constraint);
            $constraintLookup->execute();
            $existing = $constraintLookup->get_result()->fetch_assoc();
            if ($existing) {
                $actual = [
                    $existing['TABLE_NAME'],
                    $existing['COLUMN_NAME'],
                    $existing['REFERENCED_TABLE_NAME'],
                    $existing['REFERENCED_COLUMN_NAME'],
                    $existing['DELETE_RULE'],
                ];
                if ($actual !== [$table, $column, $parent, $parentColumn, $deleteRule]) {
                    throw new RuntimeException("Existing constraint {$constraint} does not match the migration definition.");
                }
                continue;
            }

            $indexLookup->bind_param('sss', $database, $table, $column);
            $indexLookup->execute();
            $indexed = (int) $indexLookup->get_result()->fetch_assoc()['total'] > 0;
            if (!$indexed && !$conn->query("ALTER TABLE `{$table}` ADD INDEX `{$index}` (`{$column}`)")) {
                throw new RuntimeException("Unable to index {$table}.{$column}: {$conn->error}");
            }

            $sql = "ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` "
                . "FOREIGN KEY (`{$column}`) REFERENCES `{$parent}` (`{$parentColumn}`) ON DELETE {$deleteRule}";
            if (!$conn->query($sql)) {
                throw new RuntimeException("Unable to add constraint {$constraint}: {$conn->error}");
            }
        }
    } finally {
        $constraintLookup->close();
        $indexLookup->close();
    }
};
