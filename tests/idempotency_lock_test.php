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

$first = savora_test_database();
$second = savora_test_database();
$admin = $first->query("SELECT id FROM users WHERE role='admin' AND status='active' LIMIT 1")->fetch_assoc();
if (!$admin) {
    throw new RuntimeException('Active admin fixture is missing.');
}
$actorId = (int) $admin['id'];
$key = 'task6-lock-' . bin2hex(random_bytes(6));

try {
    savora_idempotency_lock($first, $actorId, $key, 1);
    try {
        savora_idempotency_lock($second, $actorId, $key, 1);
        throw new RuntimeException('A concurrent same-key request acquired the reservation.');
    } catch (RuntimeException $expected) {
        lock_expect(str_contains($expected->getMessage(), 'lock'), 'Lock timeout must be descriptive.');
    }
    savora_idempotency_unlock($first, $actorId, $key);
    savora_idempotency_lock($second, $actorId, $key, 1);
    savora_idempotency_unlock($second, $actorId, $key);
} finally {
    savora_idempotency_unlock($first, $actorId, $key);
    savora_idempotency_unlock($second, $actorId, $key);
    $first->close();
    $second->close();
}

echo "idempotency lock contract ok\n";
