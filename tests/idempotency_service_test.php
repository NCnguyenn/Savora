<?php
declare(strict_types=1);

require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/migrations.php';
require_once __DIR__ . '/../lib/idempotency.php';

function idempotency_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$firstPayload = [
    'details' => ['z' => 'last', 'a' => 'first'],
    'items' => [
        ['sku' => 'first', 'options' => ['b' => 2, 'a' => 1]],
        ['sku' => 'second'],
    ],
];
$samePayloadDifferentKeyOrder = [
    'items' => [
        ['options' => ['a' => 1, 'b' => 2], 'sku' => 'first'],
        ['sku' => 'second'],
    ],
    'details' => ['a' => 'first', 'z' => 'last'],
];

$hash = savora_idempotency_hash('update_setting', $firstPayload);
idempotency_expect(
    $hash === savora_idempotency_hash('update_setting', $samePayloadDifferentKeyOrder),
    'Associative payload key order must not change the request hash.'
);
idempotency_expect(
    $hash !== savora_idempotency_hash('update_setting', ['items' => array_reverse($firstPayload['items']), 'details' => $firstPayload['details']]),
    'List order must remain significant in the request hash.'
);

$conn = savora_test_database();
try {
    savora_apply_migrations($conn);
    $actor = $conn->query("SELECT id FROM users WHERE role='admin' AND status='active' LIMIT 1")->fetch_assoc();
    if (!$actor) {
        throw new RuntimeException('Active admin fixture is missing.');
    }
    $actorId = (int) $actor['id'];
    $key = 'task6-idempotency-' . bin2hex(random_bytes(6));
    $response = ['ok' => true, 'message' => 'Stored once.', 'data' => ['value' => 7]];

    $conn->begin_transaction();
    savora_idempotency_store($conn, $actorId, $key, 'update_setting', $hash, $response);
    $conn->commit();

    idempotency_expect(
        savora_idempotency_find($conn, $actorId, $key, 'update_setting', $hash) === $response,
        'The same actor, key, action, and payload hash must replay the original response.'
    );

    foreach ([
        ['other_action', $hash],
        ['update_setting', savora_idempotency_hash('update_setting', ['different' => true])],
    ] as [$action, $requestHash]) {
        try {
            savora_idempotency_find($conn, $actorId, $key, $action, $requestHash);
            throw new RuntimeException('A mismatched idempotency request replayed or mutated state.');
        } catch (SavoraIdempotencyConflict) {
        }
    }

    $storedRows = $conn->prepare('SELECT COUNT(*) AS total FROM idempotency_keys WHERE actor_user_id=? AND idempotency_key=?');
    $storedRows->bind_param('is', $actorId, $key);
    $storedRows->execute();
    idempotency_expect(
        (int) $storedRows->get_result()->fetch_assoc()['total'] === 1,
        'A mismatched idempotency request must not create another response record.'
    );
    $storedRows->close();
} finally {
    if (isset($actorId, $key)) {
        $cleanup = $conn->prepare('DELETE FROM idempotency_keys WHERE actor_user_id=? AND idempotency_key=?');
        $cleanup->bind_param('is', $actorId, $key);
        $cleanup->execute();
        $cleanup->close();
    }
    $conn->close();
}

echo "idempotency service contract ok\n";
