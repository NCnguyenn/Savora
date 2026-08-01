<?php
declare(strict_types=1);

require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/idempotency.php';

function response_expect(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

$conn = savora_test_database();
$admin = $conn->query("SELECT id FROM users WHERE role='admin' AND status='active' LIMIT 1")->fetch_assoc();
if (!$admin) {
    throw new RuntimeException('Active Admin fixture is missing.');
}
$actorId = (int) $admin['id'];
$keyPrefix = 'task4-envelope-' . bin2hex(random_bytes(6));
$failedKey = $keyPrefix . '-failed';
$validTrueKey = $keyPrefix . '-valid-true';
$validFalseKey = $keyPrefix . '-valid-false';
$eventType = 'task4_response_' . bin2hex(random_bytes(5));

try {
    $conn->begin_transaction();
    $mutation = $conn->prepare("INSERT INTO notifications(user_id,event_type,title,message) VALUES(?,?,'Task 4 mutation','Must roll back')");
    $mutation->bind_param('is', $actorId, $eventType);
    $mutation->execute();
    $mutation->close();
    try {
        savora_idempotency_store($conn, $actorId, $failedKey, 'serialization_failure', savora_idempotency_hash('serialization_failure', []), ['ok' => true, 'data' => ['bad' => "\xB1\x31"]]);
        throw new RuntimeException('Invalid response serialization was accepted.');
    } catch (JsonException) {
        $conn->rollback();
    }
    $failedStore = $conn->prepare('SELECT COUNT(*) total FROM idempotency_keys WHERE actor_user_id=? AND idempotency_key=?');
    $failedStore->bind_param('is', $actorId, $failedKey);
    $failedStore->execute();
    response_expect((int) $failedStore->get_result()->fetch_assoc()['total'] === 0, 'Serialization failure must not store an idempotent response.');
    $failedStore->close();
    $failedMutation = $conn->prepare('SELECT COUNT(*) total FROM notifications WHERE user_id=? AND event_type=?');
    $failedMutation->bind_param('is', $actorId, $eventType);
    $failedMutation->execute();
    response_expect((int) $failedMutation->get_result()->fetch_assoc()['total'] === 0, 'Serialization failure must roll back the domain mutation.');
    $failedMutation->close();

    $trueAction = 'valid_true_response';
    $trueHash = savora_idempotency_hash($trueAction, []);
    $expectedTrue = ['ok' => true, 'message' => 'Stored safely.', 'data' => ['value' => 7]];
    $conn->begin_transaction();
    savora_idempotency_store($conn, $actorId, $validTrueKey, $trueAction, $trueHash, $expectedTrue);
    $conn->commit();
    response_expect(savora_idempotency_find($conn, $actorId, $validTrueKey, $trueAction, $trueHash) === $expectedTrue, 'A valid ok=true response must replay exactly.');

    $falseAction = 'valid_false_response';
    $falseHash = savora_idempotency_hash($falseAction, []);
    $expectedFalse = ['ok' => false, 'message' => 'Stored failure.'];
    $conn->begin_transaction();
    savora_idempotency_store($conn, $actorId, $validFalseKey, $falseAction, $falseHash, $expectedFalse);
    $conn->commit();
    response_expect(savora_idempotency_find($conn, $actorId, $validFalseKey, $falseAction, $falseHash) === $expectedFalse, 'A valid ok=false response must replay exactly.');
    response_expect(savora_idempotency_find($conn, $actorId, 'task4-missing-' . bin2hex(random_bytes(4)), $falseAction, $falseHash) === null, 'Missing idempotency keys must not replay.');

    $action = 'corrupt_response';
    $requestHash = savora_idempotency_hash($action, []);
    $corrupt = $conn->prepare('INSERT INTO idempotency_keys(actor_user_id,idempotency_key,action,request_hash,response_json) VALUES(?,?,?,?,?)');
    $invalidResponses = [
        'invalid-json' => '',
        'list' => '[]',
        'empty-object' => '{}',
        'missing-ok' => '{"message":"Missing ok."}',
        'non-boolean-ok' => '{"ok":"true"}',
    ];
    foreach ($invalidResponses as $label => $corruptJson) {
        $corruptKey = $keyPrefix . '-invalid-' . $label;
        $corrupt->bind_param('issss', $actorId, $corruptKey, $action, $requestHash, $corruptJson);
        $corrupt->execute();
        try {
            savora_idempotency_find($conn, $actorId, $corruptKey, $action, $requestHash);
            throw new RuntimeException("Invalid {$label} response replayed successfully.");
        } catch (JsonException) {
        }
    }
    $corrupt->close();
} finally {
    $cleanupPattern = $keyPrefix . '%';
    $cleanup = $conn->prepare('DELETE FROM idempotency_keys WHERE actor_user_id=? AND idempotency_key LIKE ?');
    $cleanup->bind_param('is', $actorId, $cleanupPattern);
    $cleanup->execute();
    $cleanup->close();
    $conn->close();
}

echo "platform response contract ok\n";
