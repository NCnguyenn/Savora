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

function migration_has_index_named(mysqli $conn, string $table, string $index): bool
{
    migration_expect((bool) preg_match('/^[a-z0-9_]+$/', $table), 'Unsafe test table identifier.');
    migration_expect((bool) preg_match('/^[a-z0-9_]+$/', $index), 'Unsafe test index identifier.');
    $database = savora_test_selected_database($conn);
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->bind_param('sss', $database, $table, $index);
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $count > 0;
}

function migration_column(mysqli $conn, string $table, string $column): ?array
{
    $database = savora_test_selected_database($conn);
    $stmt = $conn->prepare(
        'SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    $stmt->bind_param('sss', $database, $table, $column);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result ?: null;
}

function migration_column_matches(?array $column, string $type, string $nullable): bool
{
    if ($column === null) return false;
    $actualType = strtolower((string) ($column['COLUMN_TYPE'] ?? ''));
    $actualType = (string) preg_replace('/^int\(\d+\)$/', 'int', $actualType);
    return $actualType === strtolower($type) && (string) ($column['IS_NULLABLE'] ?? '') === $nullable;
}

function migration_drop_constraint(mysqli $conn, string $table, string $constraint): void
{
    migration_expect((bool) preg_match('/^[a-z0-9_]+$/', $table), 'Unsafe test table identifier.');
    migration_expect((bool) preg_match('/^[a-z0-9_]+$/', $constraint), 'Unsafe test constraint identifier.');
    $conn->query("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
}

function migration_drop_index(mysqli $conn, string $table, string $index): void
{
    migration_expect((bool) preg_match('/^[a-z0-9_]+$/', $table), 'Unsafe test table identifier.');
    migration_expect((bool) preg_match('/^[a-z0-9_]+$/', $index), 'Unsafe test index identifier.');
    if (migration_has_index_named($conn, $table, $index)) {
        $conn->query("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
    }
}

function migration_drop_retry_fixture(mysqli $conn): void
{
    $conn->query('DROP TABLE IF EXISTS migration_retry_child');
    $conn->query('DROP TABLE IF EXISTS migration_retry_parent');
}

$conn = savora_test_database();
require_once __DIR__ . '/../lib/migrations.php';

migration_expect(savora_test_selected_database($conn) === 'savora_test', 'Integration fixtures require a live savora_test connection.');

$versions = array_keys(savora_migrations());
migration_expect($versions === ['001_existing_schema', '002_core_integrity', '003_idempotency_request_hash', '004_catalog_contract', '004a_profiles_reviews', '005_checkout_quotes', '006_dispatch_location', '007_delivery_evidence', '008_driver_profile_authority', '009_delivery_reassignment', '010_notification_outbox', '011_notification_version', '012_commercial_rule_versions', '013_rate_limits', '014_partner_document_storage', '015_auth_onboarding', '016_profile_locations', '017_rich_catalog', '018_customer_storefront', '019_customer_gps_confirmation', '020_sepay_webhook_hardening', '021_hybrid_payment_gps_demo'], 'Migration registry order is incorrect.');

$conn->query('DROP TABLE IF EXISTS delivery_demo_routes');
$deleteMigration = $conn->prepare('DELETE FROM schema_migrations WHERE version=?');
foreach ($versions as $version) { $deleteMigration->bind_param('s', $version); $deleteMigration->execute(); }
$deleteMigration->close();
migration_expect(savora_apply_migrations($conn) === $versions, 'Both migrations must apply in registry order.');
migration_expect(savora_apply_migrations($conn) === [], 'A second migration pass must be a no-op.');
$routeTable = $conn->query("SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='delivery_demo_routes'")->fetch_assoc();
migration_expect((int) $routeTable['total'] === 1, 'Demo route table must exist.');
$deliveryIndex = $conn->query("SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='delivery_demo_routes' AND INDEX_NAME='uq_demo_route_delivery'")->fetch_assoc();
migration_expect($deliveryIndex !== null && (int) $deliveryIndex['NON_UNIQUE'] === 0, 'Each delivery must have at most one demo route.');
migration_expect(
    migration_column($conn, 'idempotency_keys', 'request_hash') === ['COLUMN_TYPE' => 'char(64)', 'IS_NULLABLE' => 'NO'],
    'Idempotency request hashes must be stored as non-null SHA-256 values.'
);
$driverFileSize = migration_column($conn, 'driver_application_documents', 'file_size');
$driverSha256 = migration_column($conn, 'driver_application_documents', 'sha256');
$restaurantFileSize = migration_column($conn, 'restaurant_application_documents', 'file_size');
$restaurantSha256 = migration_column($conn, 'restaurant_application_documents', 'sha256');
migration_expect(migration_column_matches($driverFileSize, 'int', 'NO'), 'Driver document size metadata must exist: ' . json_encode($driverFileSize));
migration_expect(migration_column_matches($driverSha256, 'char(64)', 'YES'), 'Driver document hash metadata must exist: ' . json_encode($driverSha256));
migration_expect(migration_column_matches($restaurantFileSize, 'int', 'NO'), 'Restaurant document size metadata must exist: ' . json_encode($restaurantFileSize));
migration_expect(migration_column_matches($restaurantSha256, 'char(64)', 'YES'), 'Restaurant document hash metadata must exist: ' . json_encode($restaurantSha256));
migration_expect(
    migration_column_matches(migration_column($conn, 'customer_profiles', 'delivery_details'), 'varchar(300)', 'YES'),
    'Customer profile delivery details must be nullable VARCHAR(300).'
);
migration_expect(
    migration_column_matches(migration_column($conn, 'customer_addresses', 'delivery_details'), 'varchar(300)', 'YES'),
    'Customer address delivery details must be nullable VARCHAR(300).'
);
migration_expect(
    migration_column_matches(migration_column($conn, 'customer_addresses', 'latitude'), 'decimal(10,7)', 'YES'),
    'Customer address latitude must be nullable for manual saves.'
);
migration_expect(
    migration_column_matches(migration_column($conn, 'customer_addresses', 'longitude'), 'decimal(10,7)', 'YES'),
    'Customer address longitude must be nullable for manual saves.'
);

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

$conn->query("DELETE FROM schema_migrations WHERE version = '009_delivery_reassignment'");
migration_drop_constraint($conn, 'deliveries', 'fk_deliveries_order');
migration_expect(migration_constraint($conn, 'fk_deliveries_order') === null, 'Interrupted reassignment fixture must remove the delivery-order foreign key.');
migration_expect(savora_apply_migrations($conn) === ['009_delivery_reassignment'], 'Reassignment migration retry must run independently of the removed unique index.');
$restoredDeliveryForeignKey = migration_constraint($conn, 'fk_deliveries_order');
migration_expect(
    $restoredDeliveryForeignKey !== null
        && [$restoredDeliveryForeignKey['TABLE_NAME'], $restoredDeliveryForeignKey['COLUMN_NAME'], $restoredDeliveryForeignKey['REFERENCED_TABLE_NAME'], $restoredDeliveryForeignKey['REFERENCED_COLUMN_NAME'], $restoredDeliveryForeignKey['DELETE_RULE']]
            === ['deliveries', 'order_id', 'orders', 'id', 'RESTRICT'],
    'Reassignment migration retry must restore the delivery-order foreign key after partial MySQL DDL.'
);

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

$partialFixtureStarted = false;
try {
    $partialFixtureStarted = true;
    $conn->query("DELETE FROM schema_migrations WHERE version = '002_core_integrity'");
    migration_drop_constraint($conn, 'orders', 'fk_orders_customer');
    migration_drop_index($conn, 'orders', 'idx_orders_customer');
    migration_expect(!migration_has_leading_index($conn, 'orders', 'customer_user_id'), 'Partial-DDL fixture must remove the orders customer index.');
    migration_drop_constraint($conn, 'notifications', 'fk_notifications_user');
    migration_drop_retry_fixture($conn);
    $conn->query('CREATE TABLE migration_retry_parent (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $conn->query('CREATE TABLE migration_retry_child (id INT NOT NULL PRIMARY KEY, parent_id INT NOT NULL, KEY idx_migration_retry_parent (parent_id), CONSTRAINT fk_notifications_user FOREIGN KEY (parent_id) REFERENCES migration_retry_parent(id)) ENGINE=InnoDB');

    $partialFailure = false;
    try {
        savora_apply_migrations($conn);
    } catch (RuntimeException $exception) {
        $partialFailure = $exception->getMessage() === 'Existing constraint fk_notifications_user does not match the migration definition.';
    }
    migration_expect($partialFailure, 'A later conflicting constraint must fail after earlier DDL.');
    migration_expect(migration_constraint($conn, 'fk_orders_customer') !== null, 'Earlier foreign-key DDL must remain after a later migration failure.');
    migration_expect(migration_has_index_named($conn, 'orders', 'idx_orders_customer'), 'Migration-created indexes must use their configured names.');
    $version = $conn->query("SELECT COUNT(*) AS total FROM schema_migrations WHERE version = '002_core_integrity'")->fetch_assoc();
    migration_expect((int) $version['total'] === 0, 'A partial-DDL failure must not record the migration version.');

    migration_drop_retry_fixture($conn);
    migration_expect(savora_apply_migrations($conn) === ['002_core_integrity'], 'Retry must recognize earlier DDL and record the migration once.');
    migration_expect(savora_apply_migrations($conn) === [], 'Recovered partial-DDL migration must remain idempotent.');
} finally {
    migration_drop_retry_fixture($conn);
    if ($partialFixtureStarted) {
        savora_apply_migrations($conn);
    }
}

$reusedIndexFixtureStarted = false;
try {
    $reusedIndexFixtureStarted = true;
    $conn->query("DELETE FROM schema_migrations WHERE version = '002_core_integrity'");
    migration_drop_constraint($conn, 'orders', 'fk_orders_restaurant');
    migration_drop_index($conn, 'orders', 'idx_orders_restaurant');
    migration_drop_index($conn, 'orders', 'idx_migration_reused_orders_restaurant');
    $conn->query('ALTER TABLE orders ADD INDEX idx_migration_reused_orders_restaurant (restaurant_id)');
    migration_expect(savora_apply_migrations($conn) === ['002_core_integrity'], 'Migration must accept an equivalent pre-existing leading index.');
    migration_expect(migration_has_index_named($conn, 'orders', 'idx_migration_reused_orders_restaurant'), 'Equivalent pre-existing index must remain in use.');
    migration_expect(!migration_has_index_named($conn, 'orders', 'idx_orders_restaurant'), 'Migration must not add a duplicate configured index when an equivalent index exists.');
} finally {
    if ($reusedIndexFixtureStarted) {
        $conn->query("DELETE FROM schema_migrations WHERE version = '002_core_integrity'");
        migration_drop_constraint($conn, 'orders', 'fk_orders_restaurant');
        migration_drop_index($conn, 'orders', 'idx_migration_reused_orders_restaurant');
        savora_apply_migrations($conn);
    }
}

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
