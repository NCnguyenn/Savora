<?php
declare(strict_types=1);

require_once __DIR__ . '/../idempotency.php';
require_once __DIR__ . '/audit_service.php';
require_once __DIR__ . '/notification_service.php';
require_once __DIR__ . '/../repositories/finance_repository.php';

function finance_result(bool $ok, int $status, string $message, array $data = []): array
{
    $result = ['ok' => $ok, 'status' => $status, 'message' => $message, 'referenceId' => 'FIN-' . strtoupper(bin2hex(random_bytes(5)))];
    if ($data !== []) $result['data'] = $data;
    return $result;
}

function finance_admin(mysqli $conn, int $userId): bool
{
    $statement = $conn->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND status='active'");
    $statement->bind_param('i', $userId); $statement->execute(); $ok = $statement->get_result()->fetch_assoc() !== null; $statement->close(); return $ok;
}

function finance_issue_refund(mysqli $conn, int $actorId, int $caseId, float $amount, string $destination, string $reason, int $expectedVersion, string $idempotencyKey): array
{
    if (!finance_admin($conn, $actorId)) return finance_result(false, 403, 'Admin authorization is required.');
    $amount = round($amount, 2); $destination = in_array($destination, ['original_payment', 'wallet'], true) ? $destination : 'original_payment';
    $payload = ['caseId' => $caseId, 'amount' => $amount, 'destination' => $destination, 'reason' => $reason, 'version' => $expectedVersion]; $hash = savora_idempotency_hash('issue_refund', $payload); $conn->begin_transaction();
    try {
        $stored = savora_idempotency_find($conn, $actorId, $idempotencyKey, 'issue_refund', $hash); if ($stored !== null) { $conn->commit(); return $stored; }
        $case = finance_repository_case($conn, $caseId, true); if ($case === [] || (int) $case['version'] !== $expectedVersion) throw new RuntimeException('Case is missing or stale.');
        $orderId = (int) $case['order_id']; $payment = finance_repository_payment($conn, $orderId, true); $refunded = finance_repository_refunded($conn, $orderId); $remaining = round((float) ($payment['amount'] ?? 0) - $refunded, 2);
        if ($amount <= 0 || $payment === [] || !in_array((string) $payment['status'], ['paid', 'refund_pending'], true) || $amount > $remaining) throw new RuntimeException('Refund exceeds the remaining paid amount.');
        $status = $destination === 'wallet' ? 'processed' : 'pending'; $insert = $conn->prepare('INSERT INTO refunds(order_id,case_id,amount,destination,reason,status,actor_user_id) VALUES(?,?,?,?,?,?,?)'); $insert->bind_param('iidsssi', $orderId, $caseId, $amount, $destination, $reason, $status, $actorId); $insert->execute(); $refundId = (int) $insert->insert_id; $insert->close();
        $customerId = (int) $case['reporting_user_id']; $negative = -$amount; $ledgerReference = 'LED-REF-' . $refundId; $method = (string) $payment['method']; $ledger = $conn->prepare("INSERT INTO ledger_entries(reference_code,order_id,entry_type,party_type,party_id,gross_amount,net_amount,payment_method,status) VALUES(?,?,'refund','customer',?,?,?,?,'completed')"); $ledger->bind_param('siidds', $ledgerReference, $orderId, $customerId, $negative, $negative, $method); $ledger->execute(); $ledger->close();
        if ($destination === 'wallet') { $wallet = $conn->prepare('UPDATE customer_profiles SET wallet_balance=wallet_balance+? WHERE user_id=?'); $wallet->bind_param('di', $amount, $customerId); $wallet->execute(); $wallet->close(); $transaction = $conn->prepare("INSERT INTO wallet_transactions(customer_user_id,order_id,type,amount,description) VALUES(?,?,'credit',?,'Finance refund compensation')"); $transaction->bind_param('iid', $customerId, $orderId, $amount); $transaction->execute(); $transaction->close(); }
        $nextPaymentStatus = abs($remaining - $amount) < 0.01 ? ($destination === 'wallet' ? 'refunded' : 'refund_pending') : 'refund_pending'; $paymentUpdate = $conn->prepare('UPDATE payments SET status=?,version=version+1 WHERE id=?'); $paymentUpdate->bind_param('si', $nextPaymentStatus, $payment['id']); $paymentUpdate->execute(); $paymentUpdate->close();
        $caseUpdate = $conn->prepare('UPDATE support_cases SET version=version+1 WHERE id=? AND version=?'); $caseUpdate->bind_param('ii', $caseId, $expectedVersion); $caseUpdate->execute(); if ($caseUpdate->affected_rows !== 1) throw new RuntimeException('Case changed during refund.'); $caseUpdate->close();
        $reference = 'FIN-' . $refundId; audit_append($conn, $actorId, 'issue_refund', 'support_case', $caseId, $case, ['refundId' => $refundId, 'amount' => $amount, 'status' => $nextPaymentStatus], $reason, $reference); notification_queue($conn, $customerId, 'refund_issued', 'Refund issued', 'A refund was recorded for your order.', 'order', $orderId);
        $response = finance_result(true, 200, 'Refund recorded.', ['refundId' => $refundId, 'amount' => $amount, 'remaining' => round($remaining - $amount, 2), 'version' => $expectedVersion + 1]); savora_idempotency_store($conn, $actorId, $idempotencyKey, 'issue_refund', $hash, $response); $conn->commit(); return $response;
    } catch (Throwable $exception) { $conn->rollback(); return finance_result(false, 409, $exception->getMessage()); }
}

function finance_update_payout(mysqli $conn, int $actorId, int $payoutId, string $action, string $reason, int $expectedVersion, string $idempotencyKey): array
{
    if (!finance_admin($conn, $actorId)) return finance_result(false, 403, 'Admin authorization is required.'); $status = $action === 'hold_payout' ? 'held' : 'scheduled'; $payload = ['payoutId' => $payoutId, 'reason' => $reason, 'version' => $expectedVersion]; $hash = savora_idempotency_hash($action, $payload); $conn->begin_transaction();
    try { $stored = savora_idempotency_find($conn, $actorId, $idempotencyKey, $action, $hash); if ($stored !== null) { $conn->commit(); return $stored; } $payout = finance_repository_payout($conn, $payoutId, true); if ($payout === [] || (int) $payout['version'] !== $expectedVersion) throw new RuntimeException('Payout is missing or stale.'); $update = $conn->prepare('UPDATE payouts SET status=?,hold_reason=?,version=version+1 WHERE id=? AND version=?'); $update->bind_param('ssii', $status, $reason, $payoutId, $expectedVersion); $update->execute(); if ($update->affected_rows !== 1) throw new RuntimeException('Payout changed.'); $update->close(); $response = finance_result(true, 200, 'Payout status updated.', ['payoutId' => $payoutId, 'status' => $status, 'version' => $expectedVersion + 1]); audit_append($conn, $actorId, $action, 'payout', $payoutId, $payout, $response['data'], $reason, 'FIN-PAY-' . $payoutId); savora_idempotency_store($conn, $actorId, $idempotencyKey, $action, $hash, $response); $conn->commit(); return $response; } catch (Throwable $exception) { $conn->rollback(); return finance_result(false, 409, $exception->getMessage()); }
}
function finance_hold_payout(mysqli $conn, int $actorId, int $payoutId, string $reason, int $expectedVersion, string $idempotencyKey): array { return finance_update_payout($conn, $actorId, $payoutId, 'hold_payout', $reason, $expectedVersion, $idempotencyKey); }
function finance_release_payout(mysqli $conn, int $actorId, int $payoutId, string $reason, int $expectedVersion, string $idempotencyKey): array { return finance_update_payout($conn, $actorId, $payoutId, 'release_payout', $reason, $expectedVersion, $idempotencyKey); }

function finance_settle_cod(mysqli $conn, int $actorId, int $reconciliationId, float $amount, string $reason, int $expectedVersion, string $idempotencyKey): array
{
    if (!finance_admin($conn, $actorId)) return finance_result(false, 403, 'Admin authorization is required.'); $amount = round($amount, 2); $payload = ['id' => $reconciliationId, 'amount' => $amount, 'version' => $expectedVersion]; $hash = savora_idempotency_hash('settle_cod', $payload); $conn->begin_transaction();
    try { $stored = savora_idempotency_find($conn, $actorId, $idempotencyKey, 'settle_cod', $hash); if ($stored !== null) { $conn->commit(); return $stored; } $cod = finance_repository_cod($conn, $reconciliationId, true); if ($cod === [] || (int) $cod['version'] !== $expectedVersion) throw new RuntimeException('COD reconciliation is missing or stale.'); $remaining = round((float) $cod['due_amount'] - (float) $cod['settled_amount'], 2); if ($amount <= 0) $amount = $remaining; if ($amount <= 0 || $amount > $remaining) throw new RuntimeException('Settlement exceeds outstanding COD.'); $next = round((float) $cod['settled_amount'] + $amount, 2); $status = $next + 0.001 >= (float) $cod['due_amount'] ? 'settled' : 'open'; $update = $conn->prepare('UPDATE cod_reconciliations SET settled_amount=?,status=?,reconciled_at=NOW(),version=version+1 WHERE id=? AND version=?'); $update->bind_param('dsii', $next, $status, $reconciliationId, $expectedVersion); $update->execute(); if ($update->affected_rows !== 1) throw new RuntimeException('COD reconciliation changed.'); $update->close(); $reference = 'LED-COD-' . $reconciliationId . '-' . $expectedVersion; $driverId = (int) $cod['driver_user_id']; $ledger = $conn->prepare("INSERT INTO ledger_entries(reference_code,entry_type,party_type,party_id,gross_amount,net_amount,status) VALUES(?,'cod_settlement','driver',?,?,?,'completed')"); $ledger->bind_param('sidd', $reference, $driverId, $amount, $amount); $ledger->execute(); $ledger->close(); $response = finance_result(true, 200, 'COD settlement recorded.', ['reconciliationId' => $reconciliationId, 'settledAmount' => $next, 'status' => $status, 'version' => $expectedVersion + 1]); audit_append($conn, $actorId, 'settle_cod', 'cod_reconciliation', $reconciliationId, $cod, $response['data'], $reason, 'FIN-COD-' . $reconciliationId); savora_idempotency_store($conn, $actorId, $idempotencyKey, 'settle_cod', $hash, $response); $conn->commit(); return $response; } catch (Throwable $exception) { $conn->rollback(); return finance_result(false, 409, $exception->getMessage()); }
}
