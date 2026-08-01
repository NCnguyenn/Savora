<?php
declare(strict_types=1);

require_once __DIR__ . '/support/test_database.php';

function migration_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function migration_constraint(mysqli $conn, string $name): ?array
{
    $database = savora_database_config()['name'];
    $stmt = $conn->prepare(
        'SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE
         FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.REFERENTIAL_CONSTRAINTS r
           ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
          AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
         WHERE k.CONSTRAINT_SCHEMA = ? AND k.CONSTRAINT_NAME = ?'
    );
    $stmt->bind_param('ss', $database, $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function migration_has_leading_index(mysqli $conn, string $table, string $column): bool
{
    $database = savora_database_config()['name'];
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1'
    );
    $stmt->bind_param('sss', $database, $table, $column);
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $count > 0;
}

function migration_drop_constraint(mysqli $conn, string $table, string $constraint): void
{
    migration_expect((bool) preg_match('/^[a-z0-9_]+$/', $table), 'Unsafe test table identifier.');
    migration_expect((bool) preg_match('/^[a-z0-9_]+$/', $constraint), 'Unsafe test constraint identifier.');
    $conn->query("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
}

$conn = savora_test_database();
require_once __DIR__ . '/../lib/migrations.php';

$versions = array_keys(savora_migrations());
migration_expect($versions === ['001_existing_schema', '002_core_integrity'], 'Migration registry order is incorrect.');

$conn->query("DELETE FROM schema_migrations WHERE version IN ('001_existing_schema', '002_core_integrity')");
migration_expect(savora_apply_migrations($conn) === $versions, 'Both migrations must apply in registry order.');
migration_expect(savora_apply_migrations($conn) === [], 'A second migration pass must be a no-op.');

$expected = [
    'fk_orders_customer' => ['orders', 'customer_user_id', 'users', 'id', 'RESTRICT'],
    'fk_orders_restaurant' => ['orders', 'restaurant_id', 'restaurants', 'id', 'RESTRICT'],
    'fk_order_items_order' => ['order_items', 'order_id', 'orders', 'id', 'RESTRICT'],
    'fk_order_history_order' => ['order_status_history', 'order_id', 'orders', 'id', 'RESTRICT'],
    'fk_payments_order' => ['payments', 'order_id', 'orders', 'id', 'RESTRICT'],
    'fk_deliveries_order' => ['deliveries', 'order_id', 'orders', 'id', 'RESTRICT'],
    'fk_user_sessions_user' => ['user_sessions', 'user_id', 'users', 'id', 'RESTRICT'],
    'fk_restaurant_documents_application' => ['restaurant_application_documents', 'application_id', 'restaurant_applications', 'id', 'CASCADE'],
    'fk_driver_documents_application' => ['driver_application_documents', 'application_id', 'driver_applications', 'id', 'CASCADE'],
    'fk_notifications_user' => ['notifications', 'user_id', 'users', 'id', 'RESTRICT'],
    'fk_refunds_order' => ['refunds', 'order_id', 'orders', 'id', 'RESTRICT'],
    'fk_payout_items_payout' => ['payout_items', 'payout_id', 'payouts', 'id', 'RESTRICT'],
    'fk_case_messages_case' => ['case_messages', 'case_id', 'support_cases', 'id', 'RESTRICT'],
];

foreach ($expected as $name => [$table, $column, $parent, $parentColumn, $deleteRule]) {
    $constraint = migration_constraint($conn, $name);
    migration_expect($constraint !== null, "Missing constraint {$name}.");
    migration_expect(
        [$constraint['TABLE_NAME'], $constraint['COLUMN_NAME'], $constraint['REFERENCED_TABLE_NAME'], $constraint['REFERENCED_COLUMN_NAME'], $constraint['DELETE_RULE']]
            === [$table, $column, $parent, $parentColumn, $deleteRule],
        "Constraint {$name} metadata is incorrect."
    );
    migration_expect(migration_has_leading_index($conn, $table, $column), "Missing leading index for {$table}.{$column}.");
}

$conn->query("DELETE FROM schema_migrations WHERE version = '002_core_integrity'");
migration_drop_constraint($conn, 'notifications', 'fk_notifications_user');
$orphanUserId = 2147483647;
$orphanId = 0;
try {
    $orphan = $conn->prepare(
        "INSERT INTO notifications (user_id, event_type, title, message) VALUES (?, 'migration_orphan_test', 'Orphan test', 'Must be rejected before DDL')"
    );
    $orphan->bind_param('i', $orphanUserId);
    $orphan->execute();
    $orphanId = $conn->insert_id;
    $orphan->close();

    $orphanRejected = false;
    try {
        savora_apply_migrations($conn);
    } catch (RuntimeException $exception) {
        $orphanRejected = str_contains($exception->getMessage(), 'notifications.user_id -> users.id')
            && str_contains($exception->getMessage(), '1 orphan');
    }
    migration_expect($orphanRejected, 'Integrity migration must reject and describe orphan rows.');
    migration_expect(migration_constraint($conn, 'fk_notifications_user') === null, 'Orphan preflight must fail before adding constraints.');
    $version = $conn->query("SELECT COUNT(*) AS total FROM schema_migrations WHERE version = '002_core_integrity'")->fetch_assoc();
    migration_expect((int) $version['total'] === 0, 'Failed integrity migration must not record its version.');
} finally {
    if ($orphanId > 0) {
        $deleteOrphan = $conn->prepare('DELETE FROM notifications WHERE id = ?');
        $deleteOrphan->bind_param('i', $orphanId);
        $deleteOrphan->execute();
        $deleteOrphan->close();
    }
}
migration_expect(savora_apply_migrations($conn) === ['002_core_integrity'], 'Integrity migration must recover after orphan cleanup.');
migration_expect(savora_apply_migrations($conn) === [], 'Recovered integrity migration must remain idempotent.');

$conn->begin_transaction();
try {
    $suffix = bin2hex(random_bytes(6));
    $password = password_hash('migration-test', PASSWORD_DEFAULT);
    $user = $conn->prepare("INSERT INTO users (username, password, role, full_name) VALUES (?, ?, 'customer', 'Migration Restrict Test')");
    $username = 'migration-' . $suffix;
    $user->bind_param('ss', $username, $password);
    $user->execute();
    $userId = $conn->insert_id;
    $user->close();

    $owner = $conn->prepare("INSERT INTO users (username, password, role, full_name) VALUES (?, ?, 'restaurant', 'Migration Restaurant Test')");
    $ownerName = 'migration-owner-' . $suffix;
    $owner->bind_param('ss', $ownerName, $password);
    $owner->execute();
    $ownerId = $conn->insert_id;
    $owner->close();

    $restaurant = $conn->prepare("INSERT INTO restaurants (owner_user_id, name) VALUES (?, 'Migration Test Restaurant')");
    $restaurant->bind_param('i', $ownerId);
    $restaurant->execute();
    $restaurantId = $conn->insert_id;
    $restaurant->close();

    $order = $conn->prepare("INSERT INTO orders (reference_code, customer_user_id, restaurant_id) VALUES (?, ?, ?)");
    $reference = 'MIG-' . strtoupper($suffix);
    $order->bind_param('sii', $reference, $userId, $restaurantId);
    $order->execute();
    $orderId = $conn->insert_id;
    $order->close();

    $payment = $conn->prepare("INSERT INTO payments (order_id, method, amount) VALUES (?, 'cash', 1.00)");
    $payment->bind_param('i', $orderId);
    $payment->execute();
    $payment->close();

    $restricted = false;
    try {
        $deleteOrder = $conn->prepare('DELETE FROM orders WHERE id = ?');
        $deleteOrder->bind_param('i', $orderId);
        $deleteOrder->execute();
        $deleteOrder->close();
    } catch (mysqli_sql_exception $exception) {
        $restricted = $exception->getCode() === 1451;
    }
    migration_expect($restricted, 'Financial child rows must restrict order deletion.');
} finally {
    $conn->rollback();
}

$conn->begin_transaction();
try {
    $reference = 'RA-MIG-' . strtoupper(bin2hex(random_bytes(5)));
    $application = $conn->prepare(
        "INSERT INTO restaurant_applications (reference_code, username, owner_name, owner_email, restaurant_name)
         VALUES (?, ?, 'Migration Owner', 'migration@example.test', 'Migration Application')"
    );
    $applicationUser = strtolower($reference);
    $application->bind_param('ss', $reference, $applicationUser);
    $application->execute();
    $applicationId = $conn->insert_id;
    $application->close();

    $document = $conn->prepare(
        "INSERT INTO restaurant_application_documents (application_id, document_type) VALUES (?, 'migration_test')"
    );
    $document->bind_param('i', $applicationId);
    $document->execute();
    $documentId = $conn->insert_id;
    $document->close();

    $deleteApplication = $conn->prepare('DELETE FROM restaurant_applications WHERE id = ?');
    $deleteApplication->bind_param('i', $applicationId);
    $deleteApplication->execute();
    $deleteApplication->close();
    $remainingDocument = $conn->query("SELECT COUNT(*) AS total FROM restaurant_application_documents WHERE id = {$documentId}")->fetch_assoc();
    migration_expect((int) $remainingDocument['total'] === 0, 'Owned application documents must cascade on parent deletion.');
} finally {
    $conn->rollback();
    $conn->close();
}

echo "PASS: migrations are ordered, idempotent, preflight-safe, indexed, and enforce delete rules\n";
