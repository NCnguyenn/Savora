<?php
declare(strict_types=1);

function notification_queue(mysqli $conn, int $userId, string $eventType, string $title, string $message, ?string $entityType, ?int $entityId): int
{
    $stmt = $conn->prepare('INSERT INTO notifications(user_id,event_type,title,message,entity_type,entity_id) VALUES(?,?,?,?,?,?)');
    $stmt->bind_param('issssi', $userId, $eventType, $title, $message, $entityType, $entityId);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    $payload = json_encode(['eventType' => $eventType, 'title' => $title, 'message' => $message, 'entityType' => $entityType, 'entityId' => $entityId], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $outbox = $conn->prepare("INSERT INTO notification_outbox(notification_id,channel,payload_json,status,attempt_count) VALUES(?,'in_app',?,'pending',0)");
    $outbox->bind_param('is', $id, $payload); $outbox->execute(); $outbox->close();
    return $id;
}

function notification_mark_read(mysqli $conn, int $userId, int $notificationId, int $expectedVersion, string $idempotencyKey): array
{
    require_once __DIR__ . '/../idempotency.php'; require_once __DIR__ . '/../repositories/notification_repository.php';
    $hash = savora_idempotency_hash('mark_notification_read', ['notificationId' => $notificationId, 'version' => $expectedVersion]); $conn->begin_transaction();
    try { $stored = savora_idempotency_find($conn, $userId, $idempotencyKey, 'mark_notification_read', $hash); if ($stored !== null) { $conn->commit(); return $stored; } $row = notification_repository_one($conn, $userId, $notificationId, true); if ($row === []) { $conn->rollback(); return ['ok' => false, 'status' => 404, 'message' => 'Notification not found.']; } if ((int) $row['version'] !== $expectedVersion) throw new RuntimeException('Notification is stale.'); $update = $conn->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()),version=version+1 WHERE id=? AND user_id=? AND version=?'); $update->bind_param('iii', $notificationId, $userId, $expectedVersion); $update->execute(); if ($update->affected_rows !== 1) throw new RuntimeException('Notification changed.'); $update->close(); $response = ['ok' => true, 'status' => 200, 'message' => 'Notification marked read.', 'data' => ['notificationId' => $notificationId, 'version' => $expectedVersion + 1]]; savora_idempotency_store($conn, $userId, $idempotencyKey, 'mark_notification_read', $hash, $response); $conn->commit(); return $response; } catch (Throwable $exception) { $conn->rollback(); return ['ok' => false, 'status' => 409, 'message' => $exception->getMessage()]; }
}
