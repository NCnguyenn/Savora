<?php
declare(strict_types=1);

require_once __DIR__ . '/../idempotency.php';
require_once __DIR__ . '/audit_service.php';
require_once __DIR__ . '/notification_service.php';
require_once __DIR__ . '/../admin_security.php';

function admin_open_incident(mysqli $conn, int $actorId, int $orderId, string $reason, int $expectedVersion, string $idempotencyKey): array
{
    $reason = mb_substr(trim($reason), 0, 500); $referenceId = admin_reference_id();
    if ($orderId <= 0 || $reason === '') return ['ok' => false, 'message' => 'An order and audit reason are required.', 'errors' => ['reason' => 'Explain the incident.'], 'referenceId' => $referenceId];
    $payload = ['order_id' => $orderId, 'reason' => $reason, 'version' => $expectedVersion]; $hash = savora_idempotency_hash('open_incident', $payload); $conn->begin_transaction();
    try {
        $stored = savora_idempotency_find($conn, $actorId, $idempotencyKey, 'open_incident', $hash); if ($stored !== null) { $conn->commit(); return $stored; }
        $lock = $conn->prepare('SELECT id,reference_code,version FROM orders WHERE id=? FOR UPDATE'); $lock->bind_param('i', $orderId); $lock->execute(); $order = $lock->get_result()->fetch_assoc(); $lock->close();
        if (!$order) throw new RuntimeException('Order not found.'); if ((int) $order['version'] !== $expectedVersion) throw new RuntimeException('Order has a stale version.');
        $reference = 'CASE-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3))); $subject = 'Admin incident for ' . $order['reference_code'];
        $case = $conn->prepare("INSERT INTO support_cases(reference_code,order_id,case_type,reporting_role,reporting_user_id,priority,status,subject,sla_due_at) VALUES(?,?, 'operational_incident','admin',?,'high','open',?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))");
        $case->bind_param('siis', $reference, $orderId, $actorId, $subject); $case->execute(); $caseId = (int) $case->insert_id; $case->close();
        audit_append($conn, $actorId, 'open_incident', 'support_case', $caseId, $order, ['reference_code' => $reference, 'status' => 'open', 'version' => 1], $reason, $referenceId);
        $response = ['ok' => true, 'message' => 'Operational incident opened.', 'data' => ['case_id' => $caseId, 'reference_code' => $reference, 'version' => 1], 'referenceId' => $referenceId];
        savora_idempotency_store($conn, $actorId, $idempotencyKey, 'open_incident', $hash, $response); $conn->commit(); return $response;
    } catch (Throwable $exception) { $conn->rollback(); return ['ok' => false, 'message' => 'The incident could not be opened.', 'errors' => ['reason' => $exception->getMessage()], 'referenceId' => $referenceId]; }
}

function admin_commercial_action(mysqli $conn, string $action, array $payload, int $actorId, string $idempotencyKey): array
{
    $reason = mb_substr(trim((string) ($payload['reason'] ?? '')), 0, 500); $referenceId = admin_reference_id();
    if ($reason === '') return ['ok' => false, 'message' => 'An audit reason is required.', 'errors' => ['reason' => 'Explain the controlled intervention.'], 'referenceId' => $referenceId];
    $hash = savora_idempotency_hash($action, $payload); $conn->begin_transaction();
    try {
        $stored = savora_idempotency_find($conn, $actorId, $idempotencyKey, $action, $hash); if ($stored !== null) { $conn->commit(); return $stored; }
        $before = null; $after = []; $entityId = null;
        if ($action === 'pause_promotion') {
            $id = max(0, (int) ($payload['promotion_id'] ?? 0)); $version = admin_expected_version($payload); $lock = $conn->prepare('SELECT * FROM promotions WHERE id=? FOR UPDATE'); $lock->bind_param('i', $id); $lock->execute(); $before = $lock->get_result()->fetch_assoc(); $lock->close();
            if (!$before || (int) $before['version'] !== $version) throw new RuntimeException('Promotion is missing or stale.'); $stmt = $conn->prepare("UPDATE promotions SET status='paused',version=version+1 WHERE id=? AND version=?"); $stmt->bind_param('ii', $id, $version); $stmt->execute(); if ($stmt->affected_rows !== 1) throw new RuntimeException('Promotion changed.'); $stmt->close(); $entityId = $id; $after = ['status' => 'paused', 'version' => $version + 1];
        } elseif ($action === 'save_promotion') {
            $code = strtoupper(mb_substr(trim((string) ($payload['code'] ?? '')), 0, 50)); $value = (float) ($payload['discount_value'] ?? 0); $starts = (string) ($payload['starts_at'] ?? ''); $ends = (string) ($payload['ends_at'] ?? ''); $budget = max(0, (float) ($payload['budget'] ?? 0));
            if (!preg_match('/^[A-Z0-9_-]{3,50}$/', $code) || $value <= 0 || strtotime($ends) <= strtotime($starts)) throw new RuntimeException('Code, value and valid schedule are required.');
            $audience = 'all_customers'; $type = 'percentage'; $scope = 'all_restaurants'; $stmt = $conn->prepare("INSERT INTO promotions(code,audience,discount_type,discount_value,budget,starts_at,ends_at,status,scope) VALUES(?,?,?,?,?,?,?,'scheduled',?)"); $stmt->bind_param('sssddsss', $code, $audience, $type, $value, $budget, $starts, $ends, $scope); $stmt->execute(); $entityId = (int) $stmt->insert_id; $stmt->close(); $after = ['code' => $code, 'status' => 'scheduled', 'version' => 1];
        } elseif ($action === 'schedule_fee_rule') {
            $name = mb_substr(trim((string) ($payload['name'] ?? '')), 0, 120); $amount = (float) ($payload['amount'] ?? 0); $effective = (string) ($payload['effective_at'] ?? ''); if ($name === '' || $amount < 0 || strtotime($effective) <= time()) throw new RuntimeException('A future-effective fee rule is required.'); $type = 'platform_commission'; $unit = 'percent'; $stmt = $conn->prepare("INSERT INTO fee_rules(rule_type,name,amount,unit,effective_at,status,created_by,version) VALUES(?,?,?,?,?,'scheduled',?,1)"); $stmt->bind_param('ssdssi', $type, $name, $amount, $unit, $effective, $actorId); $stmt->execute(); $entityId = (int) $stmt->insert_id; $stmt->close(); $after = ['name' => $name, 'effective_at' => $effective, 'version' => 1];
        } elseif ($action === 'set_service_area_status') {
            $id = max(0, (int) ($payload['service_area_id'] ?? 0)); $version = admin_expected_version($payload); $status = in_array(($payload['status'] ?? ''), ['active', 'paused'], true) ? (string) $payload['status'] : 'paused'; $lock = $conn->prepare('SELECT * FROM service_areas WHERE id=? FOR UPDATE'); $lock->bind_param('i', $id); $lock->execute(); $before = $lock->get_result()->fetch_assoc(); $lock->close(); if (!$before || (int) $before['version'] !== $version) throw new RuntimeException('Service area is missing or stale.'); $stmt = $conn->prepare('UPDATE service_areas SET status=?,version=version+1 WHERE id=? AND version=?'); $stmt->bind_param('sii', $status, $id, $version); $stmt->execute(); if ($stmt->affected_rows !== 1) throw new RuntimeException('Service area changed.'); $stmt->close(); $entityId = $id; $after = ['status' => $status, 'version' => $version + 1];
        } else { throw new RuntimeException('Unsupported commercial action.'); }
        audit_append($conn, $actorId, $action, 'commercial_rule', $entityId, $before, $after, $reason, $referenceId); $response = ['ok' => true, 'message' => 'Commercial rule updated.', 'data' => $after, 'referenceId' => $referenceId]; savora_idempotency_store($conn, $actorId, $idempotencyKey, $action, $hash, $response); $conn->commit(); return $response;
    } catch (Throwable $exception) { $conn->rollback(); return ['ok' => false, 'message' => 'The commercial rule could not be updated.', 'errors' => ['reason' => $exception->getMessage()], 'referenceId' => $referenceId]; }
}
