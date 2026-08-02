<?php
declare(strict_types=1);
putenv('SAVORA_SEED_DEMO=1');

putenv('SAVORA_DB_NAME=savora_test');
require __DIR__ . '/../db.php';

function admin_schema_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

admin_schema_assert(($dbname ?? '') === 'savora_test', 'db.php must honor SAVORA_DB_NAME');
admin_schema_assert(function_exists('platform_migrate'), 'platform_migrate must exist');
admin_schema_assert(function_exists('platform_seed'), 'platform_seed must exist');

platform_migrate($conn);
platform_seed($conn);
platform_migrate($conn);
platform_seed($conn);

$requiredTables = [
    'users',
    'customer_profiles',
    'restaurant_applications',
    'restaurant_application_documents',
    'driver_applications',
    'driver_application_documents',
    'restaurants',
    'driver_profiles',
    'account_status_history',
    'orders',
    'order_items',
    'order_status_history',
    'delivery_dispatches',
    'delivery_offers',
    'deliveries',
    'delivery_milestones',
    'payments',
    'wallet_transactions',
    'ledger_entries',
    'refunds',
    'payouts',
    'payout_items',
    'cod_reconciliations',
    'support_cases',
    'case_messages',
    'case_attachments',
    'notifications',
    'promotions',
    'promotion_redemptions',
    'service_areas',
    'fee_rules',
    'platform_settings',
    'audit_logs',
    'idempotency_keys',
    'user_sessions',
];

$stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
foreach ($requiredTables as $table) {
    $stmt->bind_param('ss', $dbname, $table);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->free_result();
    admin_schema_assert((int) $count === 1, "missing table {$table}");
}
$stmt->close();

$locationColumns = [
    'customer_profiles' => ['latitude', 'longitude', 'location_method', 'location_updated_at'],
    'driver_profiles' => ['address', 'latitude', 'longitude', 'location_method', 'location_updated_at'],
    'restaurants' => ['address_line1', 'address_line2', 'state', 'postal_code', 'country', 'latitude', 'longitude', 'location_method', 'location_updated_at'],
];
$columnStmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
foreach ($locationColumns as $table => $columns) {
    foreach ($columns as $column) {
        $columnStmt->bind_param('sss', $dbname, $table, $column);
        $columnStmt->execute();
        $columnStmt->bind_result($columnCount);
        $columnStmt->fetch();
        $columnStmt->free_result();
        admin_schema_assert((int) $columnCount === 1, "missing location column {$table}.{$column}");
    }
}
$columnStmt->close();

$result = $conn->query("SELECT username, COUNT(*) AS total FROM users GROUP BY username HAVING COUNT(*) > 1");
admin_schema_assert($result !== false && $result->num_rows === 0, 'demo usernames must remain unique');

$result = $conn->query("SELECT COUNT(*) AS total FROM users WHERE username IN ('customer','restaurant','driver','admin')");
$row = $result ? $result->fetch_assoc() : null;
admin_schema_assert((int) ($row['total'] ?? 0) === 4, 'all four primary demo users must exist');

echo "PASS: admin schema is idempotent and demo identities are preserved\n";
