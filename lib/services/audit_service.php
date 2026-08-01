<?php
declare(strict_types=1);

function audit_append(mysqli $conn, int $actorId, string $action, string $entityType, ?int $entityId, mixed $before, mixed $after, string $reason, string $referenceId): void
{
    $beforeJson = $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $afterJson = $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ipAddress = mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 64);
    $sessionId = session_status() === PHP_SESSION_ACTIVE ? session_id() : '';
    $stmt = $conn->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_summary, after_summary, reason, ip_address, session_id, result, reference_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'success', ?)");
    $stmt->bind_param('ississssss', $actorId, $action, $entityType, $entityId, $beforeJson, $afterJson, $reason, $ipAddress, $sessionId, $referenceId);
    $stmt->execute();
    $stmt->close();
}
