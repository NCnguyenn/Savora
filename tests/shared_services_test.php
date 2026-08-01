<?php
declare(strict_types=1);

require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/services/audit_service.php';
require_once __DIR__ . '/../lib/services/notification_service.php';

function service_expect(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

savora_test_transaction(static function (mysqli $conn): void {
    $admin = $conn->query("SELECT id FROM users WHERE role='admin' AND status='active' LIMIT 1")->fetch_assoc();
    if (!$admin) {
        throw new RuntimeException('Active Admin fixture is missing.');
    }
    $actorId = (int) $admin['id'];

    $notificationId = notification_queue($conn, $actorId, 'task4_contract', "Driver's update", 'Prepared binding: café', 'user', $actorId);
    service_expect($notificationId > 0, 'notification_queue() must return the inserted ID.');
    $notification = $conn->query("SELECT user_id,event_type,title,message,entity_type,entity_id FROM notifications WHERE id={$notificationId}")->fetch_assoc();
    service_expect($notification !== null, 'notification_queue() must insert one row.');
    service_expect((int) $notification['user_id'] === $actorId && $notification['event_type'] === 'task4_contract' && $notification['title'] === "Driver's update" && $notification['message'] === 'Prepared binding: café' && $notification['entity_type'] === 'user' && (int) $notification['entity_id'] === $actorId, 'notification_queue() must preserve all prepared values.');

    $reference = 'TASK4-' . strtoupper(bin2hex(random_bytes(6)));
    $before = ['status' => 'pending', 'note' => "Driver's café"];
    $after = ['status' => 'confirmed', 'count' => 1];
    audit_append($conn, $actorId, 'task4_contract', 'order', 42, $before, $after, 'Contract verification', $reference);
    $auditLookup = $conn->prepare('SELECT actor_user_id,action,entity_type,entity_id,before_summary,after_summary,reason,reference_id FROM audit_logs WHERE reference_id=?');
    $auditLookup->bind_param('s', $reference);
    $auditLookup->execute();
    $audit = $auditLookup->get_result()->fetch_assoc();
    $auditLookup->close();
    service_expect($audit !== null, 'audit_append() must insert one row.');
    service_expect((int) $audit['actor_user_id'] === $actorId && $audit['action'] === 'task4_contract' && $audit['entity_type'] === 'order' && (int) $audit['entity_id'] === 42 && json_decode($audit['before_summary'], true, 512, JSON_THROW_ON_ERROR) === $before && json_decode($audit['after_summary'], true, 512, JSON_THROW_ON_ERROR) === $after && $audit['reason'] === 'Contract verification' && $audit['reference_id'] === $reference, 'audit_append() must preserve prepared metadata and JSON summaries.');

    $invalidReference = 'TASK4-BAD-' . strtoupper(bin2hex(random_bytes(4)));
    try {
        audit_append($conn, $actorId, 'task4_invalid_utf8', 'order', 42, ['bad' => "\xB1\x31"], null, 'Must fail', $invalidReference);
        throw new RuntimeException('audit_append() accepted invalid UTF-8.');
    } catch (JsonException) {
    }
    $invalidCount = $conn->prepare('SELECT COUNT(*) total FROM audit_logs WHERE reference_id=?');
    $invalidCount->bind_param('s', $invalidReference);
    $invalidCount->execute();
    service_expect((int) $invalidCount->get_result()->fetch_assoc()['total'] === 0, 'Invalid UTF-8 must fail before an audit insert.');
    $invalidCount->close();

    $resource = fopen('php://memory', 'rb');
    try {
        audit_append($conn, $actorId, 'task4_unsupported', 'order', 42, null, ['stream' => $resource], 'Must fail', 'TASK4-RESOURCE-' . strtoupper(bin2hex(random_bytes(4))));
        throw new RuntimeException('audit_append() accepted an unsupported value.');
    } catch (JsonException) {
    } finally {
        fclose($resource);
    }
});

echo "shared services contract ok\n";
