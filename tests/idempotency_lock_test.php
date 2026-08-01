<?php
declare(strict_types=1);

require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/idempotency.php';

function lock_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function lock_schema_blocker(mysqli $conn): ?string
{
    $database = savora_test_selected_database($conn);
    $tables = $conn->prepare(
        'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN (\'idempotency_keys\',\'schema_migrations\')'
    );
    $tables->bind_param('s', $database);
    $tables->execute();
    $presentTables = array_column($tables->get_result()->fetch_all(MYSQLI_ASSOC), 'TABLE_NAME');
    $tables->close();
    if (!in_array('idempotency_keys', $presentTables, true) || !in_array('schema_migrations', $presentTables, true)) {
        return 'savora_test is missing the idempotency migration metadata tables.';
    }

    $column = $conn->prepare(
        'SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=\'idempotency_keys\' AND COLUMN_NAME=\'request_hash\''
    );
    $column->bind_param('s', $database);
    $column->execute();
    $definition = $column->get_result()->fetch_assoc();
    $column->close();
    if ($definition !== ['COLUMN_TYPE' => 'char(64)', 'IS_NULLABLE' => 'NO']) {
        return 'savora_test is missing idempotency_keys.request_hash CHAR(64) NOT NULL.';
    }

    $migration = $conn->prepare('SELECT 1 FROM schema_migrations WHERE version=? LIMIT 1');
    $version = '003_idempotency_request_hash';
    $migration->bind_param('s', $version);
    $migration->execute();
    $applied = $migration->get_result()->fetch_assoc();
    $migration->close();
    if (!$applied) {
        return 'savora_test has not recorded migration 003_idempotency_request_hash.';
    }

    return null;
}

$first = null;
$second = null;
$actorId = null;
$key = null;
$eventType = null;
$firstTransactionOpen = false;
try {
    $first = savora_test_database();
    $blocker = lock_schema_blocker($first);
    if ($blocker !== null) {
        echo "BLOCKED: {$blocker}\n";
        return;
    }
    $second = savora_test_database();
    $admin = $first->query("SELECT id FROM users WHERE role='admin' AND status='active' LIMIT 1")->fetch_assoc();
    if (!$admin) {
        throw new RuntimeException('Active admin fixture is missing.');
    }
    $actorId = (int) $admin['id'];
    $key = 'task6-lock-replay-' . bin2hex(random_bytes(6));
    $eventType = 'task6_lock_replay_' . bin2hex(random_bytes(6));
    $action = 'task6_lock_replay';
    $requestHash = savora_idempotency_hash($action, ['eventType' => $eventType]);
    $response = ['ok' => true, 'data' => ['eventType' => $eventType]];

    savora_idempotency_lock($first, $actorId, $key, 1);
    try {
        savora_idempotency_lock($second, $actorId, $key, 1);
        throw new RuntimeException('A concurrent same-key request acquired the reservation.');
    } catch (RuntimeException $expected) {
        lock_expect(str_contains($expected->getMessage(), 'lock'), 'Lock timeout must be descriptive.');
    }

    $first->begin_transaction();
    $firstTransactionOpen = true;
    lock_expect(
        savora_idempotency_find($first, $actorId, $key, $action, $requestHash) === null,
        'The first request must not find a stored response.'
    );
    $mutation = $first->prepare("INSERT INTO notifications(user_id,event_type,title,message) VALUES(?,?,'Task 6 idempotency lock','Mutate exactly once')");
    $mutation->bind_param('is', $actorId, $eventType);
    $mutation->execute();
    $mutation->close();
    savora_idempotency_store($first, $actorId, $key, $action, $requestHash, $response);
    $first->commit();
    $firstTransactionOpen = false;

    savora_idempotency_unlock($first, $actorId, $key);
    savora_idempotency_lock($second, $actorId, $key, 1);
    lock_expect(
        savora_idempotency_find($second, $actorId, $key, $action, $requestHash) === $response,
        'The second request must replay the committed response.'
    );
    $mutations = $second->prepare('SELECT COUNT(*) AS total FROM notifications WHERE user_id=? AND event_type=?');
    $mutations->bind_param('is', $actorId, $eventType);
    $mutations->execute();
    lock_expect(
        (int) $mutations->get_result()->fetch_assoc()['total'] === 1,
        'A replay must not perform the domain mutation twice.'
    );
    $mutations->close();
    $stored = $second->prepare('SELECT COUNT(*) AS total FROM idempotency_keys WHERE actor_user_id=? AND idempotency_key=?');
    $stored->bind_param('is', $actorId, $key);
    $stored->execute();
    lock_expect(
        (int) $stored->get_result()->fetch_assoc()['total'] === 1,
        'The replay control flow must retain one stored response.'
    );
    $stored->close();
    savora_idempotency_unlock($second, $actorId, $key);
} finally {
    if ($first instanceof mysqli && $firstTransactionOpen) {
        $first->rollback();
    }
    if ($first instanceof mysqli && $actorId !== null && $key !== null) {
        savora_idempotency_unlock($first, $actorId, $key);
    }
    if ($second instanceof mysqli && $actorId !== null && $key !== null) {
        savora_idempotency_unlock($second, $actorId, $key);
    }
    if ($first instanceof mysqli && $actorId !== null && $key !== null) {
        $cleanup = $first->prepare('DELETE FROM idempotency_keys WHERE actor_user_id=? AND idempotency_key=?');
        $cleanup->bind_param('is', $actorId, $key);
        $cleanup->execute();
        $cleanup->close();
    }
    if ($first instanceof mysqli && $actorId !== null && $eventType !== null) {
        $cleanup = $first->prepare('DELETE FROM notifications WHERE user_id=? AND event_type=?');
        $cleanup->bind_param('is', $actorId, $eventType);
        $cleanup->execute();
        $cleanup->close();
    }
    if ($first instanceof mysqli) {
        $first->close();
    }
    if ($second instanceof mysqli) {
        $second->close();
    }
}

echo "idempotency lock replay contract ok\n";
