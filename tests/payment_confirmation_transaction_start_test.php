<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/services/payment_confirmation_service.php';

function payment_transaction_start_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$conn = mysqli_init();
$response = null;
$escaped = null;
try {
    $response = payment_confirm_incoming($conn, [
        'state' => 'process',
        'transactionId' => 'SEPAY-START-FAILURE',
        'referenceCode' => 'SVR-START-FAILURE',
        'amountVnd' => 126,
    ], 'seapay');
} catch (Throwable $exception) {
    $escaped = $exception;
}

payment_transaction_start_expect(
    $escaped === null,
    'Transaction startup failure must not escape the payment confirmation service.'
);
payment_transaction_start_expect(
    ($response['ok'] ?? true) === false
        && ($response['status'] ?? 0) === 500
        && ($response['message'] ?? '') === 'Payment confirmation could not be completed.',
    'Transaction startup failure must return the canonical sanitized 500 response.'
);

echo "PASS: payment transaction startup failures are sanitized\n";
