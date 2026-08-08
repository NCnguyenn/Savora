<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/services/payment_confirmation_service.php';

final class PaymentRollbackFailureMysqli extends mysqli
{
    public int $rollbackAttempts = 0;

    public function __construct()
    {
    }

    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        return true;
    }

    public function prepare(string $query): mysqli_stmt|false
    {
        throw new RuntimeException('Simulated transaction body failure.');
    }

    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        $this->rollbackAttempts++;
        throw new RuntimeException('Simulated rollback failure.');
    }
}

function payment_rollback_failure_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$conn = new PaymentRollbackFailureMysqli();
$response = null;
$escaped = null;
try {
    $response = payment_confirm_incoming($conn, [
        'state' => 'process',
        'transactionId' => 'SEPAY-ROLLBACK-FAILURE',
        'referenceCode' => 'SVR-ROLLBACK-FAILURE',
        'amountVnd' => 126,
    ], 'seapay');
} catch (Throwable $exception) {
    $escaped = $exception;
}

payment_rollback_failure_expect($conn->rollbackAttempts === 1, 'The failed transaction must attempt rollback once.');
payment_rollback_failure_expect(
    $escaped === null,
    'Rollback failure must not escape or replace the original payment confirmation outcome.'
);
payment_rollback_failure_expect(
    ($response['ok'] ?? true) === false
        && ($response['status'] ?? 0) === 500
        && ($response['message'] ?? '') === 'Payment confirmation could not be completed.',
    'The original transaction body failure must retain the canonical sanitized 500 response.'
);

echo "PASS: payment rollback failures cannot replace the original outcome\n";
