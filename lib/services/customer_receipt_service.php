<?php
declare(strict_types=1);

require_once __DIR__ . '/../idempotency.php';
require_once __DIR__ . '/audit_service.php';
require_once __DIR__ . '/notification_service.php';
require_once __DIR__ . '/../repositories/order_repository.php';

function customer_receipt_result(bool $ok, int $status, string $message, array $data = []): array
{
    $result = ['ok' => $ok, 'status' => $status, 'message' => $message];
    if ($data !== []) $result['data'] = $data;
    return $result;
}

function customer_confirm_receipt(
    mysqli $conn,
    int $customerUserId,
    string $referenceCode,
    int $expectedVersion,
    string $idempotencyKey
): array {
    $referenceCode = trim($referenceCode);
    if ($customerUserId <= 0 || $referenceCode === '' || $expectedVersion < 1 || $idempotencyKey === '') {
        throw new InvalidArgumentException('Customer, order reference, version and idempotency key are required.');
    }

    $action = 'customer_confirm_receipt';
    $payload = ['referenceCode' => $referenceCode, 'expectedVersion' => $expectedVersion];
    $requestHash = savora_idempotency_hash($action, $payload);
    $conn->begin_transaction();
    try {
        $stored = savora_idempotency_find($conn, $customerUserId, $idempotencyKey, $action, $requestHash);
        if ($stored !== null) {
            $conn->commit();
            return $stored;
        }

        $statement = $conn->prepare(
            'SELECT o.id,o.reference_code,o.customer_user_id,o.status,o.payment_method,o.version,
                    p.status AS payment_status,p.version AS payment_version,
                    r.owner_user_id,d.driver_user_id
             FROM orders o
             JOIN payments p ON p.order_id=o.id
             JOIN restaurants r ON r.id=o.restaurant_id
             JOIN deliveries d ON d.order_id=o.id AND d.superseded_at IS NULL
             WHERE o.reference_code=?
             LIMIT 1 FOR UPDATE'
        );
        $statement->bind_param('s', $referenceCode);
        $statement->execute();
        $order = $statement->get_result()->fetch_assoc() ?: [];
        $statement->close();

        if ($order === [] || (int) $order['customer_user_id'] !== $customerUserId) {
            $conn->rollback();
            return customer_receipt_result(false, 404, 'Order was not found.');
        }
        if ((string) $order['status'] !== 'delivered') {
            $conn->rollback();
            return customer_receipt_result(false, 409, 'The Driver must deliver this order first.');
        }
        if ((int) $order['version'] !== $expectedVersion) {
            $conn->rollback();
            return customer_receipt_result(false, 409, 'Order changed. Refresh and try again.');
        }
        if ((string) $order['payment_method'] !== 'cash' && (string) $order['payment_status'] !== 'paid') {
            $conn->rollback();
            return customer_receipt_result(false, 409, 'Online payment has not been confirmed.');
        }
        if (!order_repository_set_status($conn, (int) $order['id'], 'completed', $expectedVersion)) {
            $conn->rollback();
            return customer_receipt_result(false, 409, 'Order changed. Refresh and try again.');
        }

        $orderId = (int) $order['id'];
        order_repository_insert_history_event($conn, $orderId, 'completed', 'customer', $customerUserId, 'Customer confirmed receipt.');
        if ((string) $order['payment_method'] === 'cash') {
            $paid = $conn->prepare("UPDATE payments SET status='paid',paid_at=NOW(),version=version+1 WHERE order_id=? AND status='pending'");
            $paid->bind_param('i', $orderId);
            $paid->execute();
            $settled = $paid->affected_rows === 1;
            $paid->close();
            if (!$settled) throw new RuntimeException('COD payment changed.');
        }

        $message = 'Customer confirmed receipt for order ' . $referenceCode . '.';
        notification_queue($conn, (int) $order['owner_user_id'], 'order_completed', 'Order completed', $message, 'order', $orderId);
        notification_queue($conn, (int) $order['driver_user_id'], 'order_completed', 'Order completed', $message, 'order', $orderId);
        audit_append(
            $conn,
            $customerUserId,
            $action,
            'order',
            $orderId,
            ['status' => 'delivered', 'version' => $expectedVersion, 'paymentStatus' => (string) $order['payment_status']],
            ['status' => 'completed', 'version' => $expectedVersion + 1, 'paymentStatus' => 'paid'],
            'Customer confirmed receipt.',
            'RCP-' . strtoupper(bin2hex(random_bytes(5)))
        );
        $result = customer_receipt_result(true, 200, 'Receipt confirmed.', [
            'referenceCode' => $referenceCode,
            'status' => 'completed',
            'paymentStatus' => 'paid',
            'version' => $expectedVersion + 1,
        ]);
        savora_idempotency_store($conn, $customerUserId, $idempotencyKey, $action, $requestHash, $result);
        $conn->commit();
        return $result;
    } catch (SavoraIdempotencyConflict) {
        $conn->rollback();
        throw new SavoraIdempotencyConflict('Idempotency key was already used for a different receipt confirmation.');
    } catch (Throwable) {
        $conn->rollback();
        return customer_receipt_result(false, 500, 'Receipt confirmation could not be completed.');
    }
}
