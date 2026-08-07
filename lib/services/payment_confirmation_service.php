<?php
declare(strict_types=1);

require_once __DIR__ . '/../environment.php';
require_once __DIR__ . '/../idempotency.php';
require_once __DIR__ . '/../repositories/payment_repository.php';
require_once __DIR__ . '/audit_service.php';
require_once __DIR__ . '/notification_service.php';
require_once __DIR__ . '/sepay_webhook_service.php';

function payment_confirmation_result(bool $ok, int $status, string $message, array $data = []): array
{
    $result = ['ok' => $ok, 'status' => $status, 'message' => $message];
    if ($data !== []) $result['data'] = $data;
    return $result;
}

function payment_confirmation_data(string $referenceCode, string $paymentStatus): array
{
    return ['referenceCode' => $referenceCode, 'paymentStatus' => $paymentStatus];
}

function payment_confirmation_rollback_safely(mysqli $conn): void
{
    try {
        $conn->rollback();
    } catch (Throwable $exception) {
        error_log('Payment rollback failed: ' . $exception->getMessage());
    }
}

function payment_confirm_transaction(
    mysqli $conn,
    array $event,
    string $source,
    ?int $customerScope = null,
    ?array $idempotency = null
): array {
    if (($event['state'] ?? '') !== 'process') {
        return payment_confirmation_result(true, 200, 'Payment event ignored.');
    }
    $reference = trim((string) ($event['referenceCode'] ?? ''));
    $provider = trim((string) ($event['transactionId'] ?? ''));
    $transactionStarted = false;
    try {
        $conn->begin_transaction();
        $transactionStarted = true;
        if ($idempotency !== null) {
            $stored = savora_idempotency_find(
                $conn,
                (int) $idempotency['actorId'],
                (string) $idempotency['key'],
                (string) $idempotency['action'],
                (string) $idempotency['requestHash']
            );
            if ($stored !== null) {
                $conn->commit();
                return $stored;
            }
        }

        $target = payment_repository_target_by_reference($conn, $reference, $customerScope, true);
        if ($target === [] || (string) $target['method'] !== 'seapay') {
            $conn->commit();
            return payment_confirmation_result(false, 404, 'SeaPay order was not found.');
        }

        $amountCents = $source === 'demo'
            ? (int) round(((float) $target['amount']) * 100)
            : (int) ($event['amountCents'] ?? 0);
        $seen = payment_repository_by_provider_reference($conn, $provider, true);
        if ($seen !== []) {
            $same = (int) $seen['order_id'] === (int) $target['order_id'];
            $conn->commit();
            return payment_confirmation_result(
                $same,
                $same ? 200 : 409,
                $same ? 'Payment already confirmed.' : 'Provider transaction is already bound.',
                payment_confirmation_data($reference, (string) $target['status'])
            );
        }

        if ((string) $target['status'] !== 'pending' || !sepay_webhook_amount_matches($amountCents, $target['amount'])) {
            $conn->commit();
            return payment_confirmation_result(
                false,
                409,
                'Payment is not pending or the amount does not match.',
                payment_confirmation_data($reference, (string) $target['status'])
            );
        }

        if (!payment_repository_mark_paid($conn, (int) $target['payment_id'], (int) $target['version'], $provider)) {
            throw new RuntimeException('Payment changed.');
        }
        notification_queue(
            $conn,
            (int) $target['customer_user_id'],
            'payment_confirmed',
            'Payment confirmed',
            'Payment for ' . $reference . ' was confirmed.',
            'order',
            (int) $target['order_id']
        );
        audit_append(
            $conn,
            (int) $target['customer_user_id'],
            'payment_confirmed_' . $source,
            'payment',
            (int) $target['payment_id'],
            ['status' => 'pending'],
            ['status' => 'paid', 'providerReference' => $provider],
            'Payment confirmation received.',
            'PAY-' . strtoupper(bin2hex(random_bytes(5)))
        );
        $result = payment_confirmation_result(
            true,
            200,
            'Payment confirmed.',
            payment_confirmation_data($reference, 'paid')
        );
        if ($idempotency !== null) {
            savora_idempotency_store(
                $conn,
                (int) $idempotency['actorId'],
                (string) $idempotency['key'],
                (string) $idempotency['action'],
                (string) $idempotency['requestHash'],
                $result
            );
        }
        $conn->commit();
        return $result;
    } catch (SavoraIdempotencyConflict) {
        if ($transactionStarted) payment_confirmation_rollback_safely($conn);
        return payment_confirmation_result(false, 409, 'Idempotency key was already used for a different demo payment request.');
    } catch (Throwable $exception) {
        if ($transactionStarted) payment_confirmation_rollback_safely($conn);
        error_log('Payment confirmation failed: ' . $exception->getMessage());
        return payment_confirmation_result(false, 500, 'Payment confirmation could not be completed.');
    }
}

function payment_confirm_incoming(mysqli $conn, array $event, string $source): array
{
    return payment_confirm_transaction($conn, $event, $source);
}

function payment_simulate_customer_success(
    mysqli $conn,
    int $customerUserId,
    string $referenceCode,
    string $idempotencyKey
): array {
    if (!savora_demo_mode()) {
        return payment_confirmation_result(false, 404, 'Demo payment is unavailable.');
    }
    $referenceCode = trim($referenceCode);
    $action = 'simulate_seapay_payment';
    $providerReference = 'DEMO-' . strtoupper(substr(hash('sha256', $customerUserId . '|' . $referenceCode . '|' . $idempotencyKey), 0, 24));
    return payment_confirm_transaction(
        $conn,
        [
            'state' => 'process',
            'transactionId' => $providerReference,
            'referenceCode' => $referenceCode,
        ],
        'demo',
        $customerUserId,
        [
            'actorId' => $customerUserId,
            'key' => $idempotencyKey,
            'action' => $action,
            'requestHash' => savora_idempotency_hash($action, ['referenceCode' => $referenceCode]),
        ]
    );
}
