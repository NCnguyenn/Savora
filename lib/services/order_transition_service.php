<?php
declare(strict_types=1);

require_once __DIR__ . '/../domain/order_status.php';
require_once __DIR__ . '/../idempotency.php';
require_once __DIR__ . '/audit_service.php';
require_once __DIR__ . '/notification_service.php';
require_once __DIR__ . '/../repositories/order_repository.php';
require_once __DIR__ . '/dispatch_service.php';

function order_transition_result(bool $ok, int $status, string $message, array $data = []): array
{
    $result = ['ok' => $ok, 'status' => $status, 'message' => $message];
    if ($data !== []) $result['data'] = $data;
    return $result;
}

function order_transition_finish(mysqli $conn, int $actorId, string $key, string $hash, array $result): array
{
    savora_idempotency_store($conn, $actorId, $key, 'order_transition', $hash, $result);
    $conn->commit();
    return $result;
}

function order_transition(mysqli $conn, array $actor, string $referenceCode, string $nextStatus, int $expectedVersion, string $idempotencyKey, string $reason = ''): array
{
    $actorId = (int) ($actor['userId'] ?? 0);
    $role = trim((string) ($actor['role'] ?? ''));
    $referenceCode = trim($referenceCode);
    $nextStatus = trim($nextStatus);
    $reason = mb_substr(trim($reason), 0, 500);
    if ($actorId <= 0 || !in_array($role, ['restaurant', 'driver', 'admin'], true)) throw new InvalidArgumentException('A transition role is required.');
    if ($referenceCode === '' || $expectedVersion < 1) throw new InvalidArgumentException('Order reference and expected version are required.');
    $requestHash = savora_idempotency_hash('order_transition', [
        'referenceCode' => $referenceCode, 'nextStatus' => $nextStatus, 'expectedVersion' => $expectedVersion, 'reason' => $reason,
    ]);
    $conn->begin_transaction();
    try {
        $stored = savora_idempotency_find($conn, $actorId, $idempotencyKey, 'order_transition', $requestHash);
        if ($stored !== null) { $conn->commit(); return $stored; }
        $order = order_repository_transition_target($conn, $referenceCode, true);
        if ($order === []) return order_transition_finish($conn, $actorId, $idempotencyKey, $requestHash, order_transition_result(false, 404, 'Order not found.'));
        if ($role === 'restaurant' && (int) $order['owner_user_id'] !== $actorId) {
            return order_transition_finish($conn, $actorId, $idempotencyKey, $requestHash, order_transition_result(false, 403, 'This Restaurant cannot change the order.'));
        }
        if ($role === 'restaurant' && (string) $order['payment_method'] !== 'cash' && (string) ($order['payment_status'] ?? 'pending') !== 'paid') {
            return order_transition_finish($conn, $actorId, $idempotencyKey, $requestHash, order_transition_result(false, 409, 'Online payment must be confirmed before the Restaurant can process this order.'));
        }
        if ($role === 'driver' && ((int) ($order['driver_user_id'] ?? 0) !== $actorId || $order['delivery_id'] === null)) {
            return order_transition_finish($conn, $actorId, $idempotencyKey, $requestHash, order_transition_result(false, 403, 'This Driver is not assigned to the order.'));
        }
        if ((int) $order['version'] !== $expectedVersion) {
            return order_transition_finish($conn, $actorId, $idempotencyKey, $requestHash, order_transition_result(false, 409, 'Order changed. Refresh before retrying.'));
        }
        if (!in_array($nextStatus, SAVORA_ORDER_STATUSES, true) || !savora_order_can_transition((string) $order['status'], $nextStatus, $role)) {
            return order_transition_finish($conn, $actorId, $idempotencyKey, $requestHash, order_transition_result(false, 409, 'This order transition is not allowed.'));
        }
        if (!order_repository_set_status($conn, (int) $order['id'], $nextStatus, $expectedVersion)) {
            return order_transition_finish($conn, $actorId, $idempotencyKey, $requestHash, order_transition_result(false, 409, 'Order changed. Refresh before retrying.'));
        }
        order_repository_insert_history_event($conn, (int) $order['id'], $nextStatus, $role, $actorId, $reason);
        $dispatch = null;
        if ($nextStatus === 'ready_for_pickup') {
            $dispatchId = order_repository_create_dispatch($conn, (int) $order['id']);
            if ($dispatchId <= 0) throw new RuntimeException('Dispatch was not created.');
            $offerResult = dispatch_offer_next_driver_in_transaction($conn, $dispatchId, $actorId);
            if (!$offerResult['ok']) throw new RuntimeException('Dispatch offer could not be created.');
            $offer = $offerResult['data']['offer'] ?? null;
            $dispatch = [
                'dispatchId' => $dispatchId,
                'status' => $offer === null ? 'searching_driver' : 'offered',
                'version' => (int) ($offerResult['data']['dispatchVersion'] ?? $offer['dispatchVersion'] ?? 0),
                'offerExpiresAt' => $offer['expiresAt'] ?? null,
            ];
        }
        notification_queue($conn, (int) $order['customer_user_id'], 'order_status_changed', 'Order status updated', 'Your order ' . $referenceCode . ' is now ' . str_replace('_', ' ', $nextStatus) . '.', 'order', (int) $order['id']);
        $auditReference = 'ORD-' . strtoupper(bin2hex(random_bytes(5)));
        audit_append($conn, $actorId, 'order_transition', 'order', (int) $order['id'], ['status' => (string) $order['status'], 'version' => $expectedVersion], ['status' => $nextStatus, 'version' => $expectedVersion + 1], $reason, $auditReference);
        return order_transition_finish($conn, $actorId, $idempotencyKey, $requestHash, order_transition_result(true, 200, 'Order status updated.', [
            'referenceCode' => $referenceCode, 'status' => $nextStatus, 'version' => $expectedVersion + 1, 'dispatch' => $dispatch,
        ]));
    } catch (SavoraIdempotencyConflict) {
        $conn->rollback();
        throw new SavoraIdempotencyConflict('Idempotency key was already used for a different order transition.');
    } catch (InvalidArgumentException) {
        $conn->rollback();
        throw new InvalidArgumentException('Order transition payload is invalid.');
    } catch (Throwable) {
        $conn->rollback();
        return order_transition_result(false, 500, 'Order transition could not be completed.');
    }
}
