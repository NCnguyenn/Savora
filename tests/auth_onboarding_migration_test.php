<?php
declare(strict_types=1);

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
    fwrite(STDERR, "BLOCKED: onboarding migration tests require savora_test\n");
    exit(2);
}

require_once __DIR__ . '/support/test_database.php';

function onboarding_migration_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function onboarding_migration_column(mysqli $conn, string $table, string $column): ?array
{
    $database = savora_test_selected_database($conn);
    $statement = $conn->prepare(
        'SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    $statement->bind_param('sss', $database, $table, $column);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    return $row ?: null;
}

function onboarding_migration_index_columns(mysqli $conn, string $table, string $index): array
{
    $database = savora_test_selected_database($conn);
    $statement = $conn->prepare(
        'SELECT COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX'
    );
    $statement->bind_param('sss', $database, $table, $index);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function onboarding_migration_constraint(mysqli $conn, string $name): ?array
{
    $database = savora_test_selected_database($conn);
    $statement = $conn->prepare(
        'SELECT k.TABLE_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE
         FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.REFERENTIAL_CONSTRAINTS r
           ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
         WHERE k.CONSTRAINT_SCHEMA=? AND k.CONSTRAINT_NAME=?'
    );
    $statement->bind_param('ss', $database, $name);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    return $row ?: null;
}

$conn = savora_test_database();
try {
    $path = __DIR__ . '/../database/migrations/015_auth_onboarding.php';
    onboarding_migration_expect(is_file($path), 'Migration 015 must exist.');
    $migration = require $path;
    onboarding_migration_expect(is_callable($migration), 'Migration 015 must return a callable.');
    $migration($conn);
    $migration($conn);

    $required = [
        'customer_profiles' => ['default_delivery_notes'],
        'restaurant_applications' => ['description', 'restaurant_phone', 'opens_at', 'closes_at'],
        'driver_applications' => ['vehicle_color'],
        'restaurants' => ['description', 'logo_media_id'],
        'identity_claims' => ['identifier_type', 'normalized_value', 'owner_kind', 'owner_id'],
        'media_assets' => ['public_id', 'owner_kind', 'owner_id', 'purpose', 'stored_path', 'mime_type', 'file_size', 'sha256', 'visibility', 'status'],
        'admin_profiles' => ['user_id', 'privilege_level', 'created_by'],
    ];
    foreach ($required as $table => $columns) {
        foreach ($columns as $column) {
            onboarding_migration_expect(onboarding_migration_column($conn, $table, $column) !== null, "Missing {$table}.{$column}.");
        }
    }

    $claimIndex = onboarding_migration_index_columns($conn, 'identity_claims', 'uq_identity_claim');
    onboarding_migration_expect(array_column($claimIndex, 'COLUMN_NAME') === ['identifier_type', 'normalized_value'], 'Identity claim unique key columns are incorrect.');
    onboarding_migration_expect(array_filter($claimIndex, static fn(array $row): bool => (int) $row['NON_UNIQUE'] !== 0) === [], 'Identity claim key must be unique.');

    $logoConstraint = onboarding_migration_constraint($conn, 'fk_restaurant_logo_media');
    onboarding_migration_expect(
        $logoConstraint !== null
        && [$logoConstraint['TABLE_NAME'], $logoConstraint['COLUMN_NAME'], $logoConstraint['REFERENCED_TABLE_NAME'], $logoConstraint['REFERENCED_COLUMN_NAME'], $logoConstraint['DELETE_RULE']]
            === ['restaurants', 'logo_media_id', 'media_assets', 'id', 'SET NULL'],
        'Restaurant logo media foreign key is incorrect.'
    );

    $expectedUserClaims = (int) $conn->query(
        "SELECT COUNT(*) AS total FROM users WHERE TRIM(username)<>''"
    )->fetch_assoc()['total'] + (int) $conn->query(
        "SELECT COUNT(*) AS total FROM users WHERE email IS NOT NULL AND TRIM(email)<>''"
    )->fetch_assoc()['total'];
    $actualUserClaims = (int) $conn->query(
        "SELECT COUNT(*) AS total FROM identity_claims WHERE owner_kind='user'"
    )->fetch_assoc()['total'];
    onboarding_migration_expect($actualUserClaims === $expectedUserClaims, 'Existing user identity claims were not backfilled exactly once.');

    $adminCount = (int) $conn->query("SELECT COUNT(*) AS total FROM users WHERE role='admin'")->fetch_assoc()['total'];
    $profileCount = (int) $conn->query(
        "SELECT COUNT(*) AS total FROM admin_profiles ap JOIN users u ON u.id=ap.user_id WHERE u.role='admin'"
    )->fetch_assoc()['total'];
    onboarding_migration_expect($profileCount === $adminCount, 'Every Admin account must have exactly one Admin profile.');
    $superCount = (int) $conn->query("SELECT COUNT(*) AS total FROM admin_profiles WHERE privilege_level='super_admin'")->fetch_assoc()['total'];
    onboarding_migration_expect($superCount >= 1, 'The migrated system must retain at least one Super Admin.');

    echo "PASS: onboarding migration is repeatable and preserves identity/media/admin contracts\n";
} finally {
    $conn->close();
}
