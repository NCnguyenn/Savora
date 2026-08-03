<?php
declare(strict_types=1);

function savora_migrations(): array
{
    return [
        '001_existing_schema' => __DIR__ . '/../database/migrations/001_existing_schema.php',
        '002_core_integrity' => __DIR__ . '/../database/migrations/002_core_integrity.php',
        '003_idempotency_request_hash' => __DIR__ . '/../database/migrations/003_idempotency_request_hash.php',
        '004_catalog_contract' => __DIR__ . '/../database/migrations/004_catalog_contract.php',
        '004a_profiles_reviews' => __DIR__ . '/../database/migrations/004a_profiles_reviews.php',
        '005_checkout_quotes' => __DIR__ . '/../database/migrations/005_checkout_quotes.php',
        '006_dispatch_location' => __DIR__ . '/../database/migrations/006_dispatch_location.php',
        '007_delivery_evidence' => __DIR__ . '/../database/migrations/007_delivery_evidence.php',
        '008_driver_profile_authority' => __DIR__ . '/../database/migrations/008_driver_profile_authority.php',
        '009_delivery_reassignment' => __DIR__ . '/../database/migrations/009_delivery_reassignment.php',
        '010_notification_outbox' => __DIR__ . '/../database/migrations/010_notification_outbox.php',
        '011_notification_version' => __DIR__ . '/../database/migrations/011_notification_version.php',
        '012_commercial_rule_versions' => __DIR__ . '/../database/migrations/012_commercial_rule_versions.php',
        '013_rate_limits' => __DIR__ . '/../database/migrations/013_rate_limits.php',
        '014_partner_document_storage' => __DIR__ . '/../database/migrations/014_partner_document_storage.php',
        '015_auth_onboarding' => __DIR__ . '/../database/migrations/015_auth_onboarding.php',
    ];
}

function savora_apply_migrations(mysqli $conn): array
{
    if (!$conn->query(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(100) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    )) {
        throw new RuntimeException('Unable to create the schema migration registry: ' . $conn->error);
    }

    $databaseResult = $conn->query('SELECT DATABASE() AS name');
    $database = (string) ($databaseResult?->fetch_assoc()['name'] ?? '');
    if ($database === '') {
        throw new RuntimeException('A database must be selected before applying migrations.');
    }

    $applied = [];
    foreach (savora_migrations() as $version => $path) {
        $lockName = "savora:{$database}:{$version}";
        $lock = $conn->prepare('SELECT GET_LOCK(?, 30) AS acquired');
        $lock->bind_param('s', $lockName);
        $lock->execute();
        $acquired = (int) ($lock->get_result()->fetch_assoc()['acquired'] ?? 0);
        $lock->close();
        if ($acquired !== 1) {
            throw new RuntimeException("Unable to acquire migration lock for {$version}.");
        }

        try {
            $conn->begin_transaction();
            $existing = $conn->prepare('SELECT version FROM schema_migrations WHERE version = ? FOR UPDATE');
            $existing->bind_param('s', $version);
            $existing->execute();
            $alreadyApplied = $existing->get_result()->fetch_assoc() !== null;
            $existing->close();

            if ($alreadyApplied) {
                $conn->commit();
                continue;
            }

            if (!is_file($path)) {
                throw new RuntimeException("Migration file is missing for {$version}.");
            }
            $migration = require $path;
            if (!is_callable($migration)) {
                throw new RuntimeException("Migration {$version} must return a callable.");
            }
            $migration($conn);

            $record = $conn->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
            $record->bind_param('s', $version);
            $record->execute();
            $record->close();
            $conn->commit();
            $applied[] = $version;
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        } finally {
            $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
            $release->bind_param('s', $lockName);
            $release->execute();
            $release->close();
        }
    }

    return $applied;
}
