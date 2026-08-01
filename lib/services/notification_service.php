<?php
declare(strict_types=1);

function notification_queue(mysqli $conn, int $userId, string $eventType, string $title, string $message, ?string $entityType, ?int $entityId): int
{
    $stmt = $conn->prepare('INSERT INTO notifications(user_id,event_type,title,message,entity_type,entity_id) VALUES(?,?,?,?,?,?)');
    $stmt->bind_param('issssi', $userId, $eventType, $title, $message, $entityType, $entityId);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}
