<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/services/sepay_webhook_service.php';

function sepay_test_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

sepay_test_expect(
    sepay_webhook_is_authorized(['HTTP_AUTHORIZATION' => 'Apikey demo-secret'], 'demo-secret'),
    'SePay API key authorization should accept the documented header.'
);
sepay_test_expect(
    !sepay_webhook_is_authorized(['HTTP_AUTHORIZATION' => 'Apikey wrong-secret'], 'demo-secret'),
    'SePay API key authorization should reject an incorrect key.'
);
sepay_test_expect(
    !sepay_webhook_is_authorized(['HTTP_AUTHORIZATION' => 'demo-secret'], 'demo-secret'),
    'SePay API key authorization should reject a header without the Apikey scheme.'
);

$payment = sepay_webhook_parse_payload([
    'id' => 7821,
    'transferType' => 'in',
    'transferAmount' => '125.50',
    'content' => 'Savora thanh toan SVR-ABC123',
]);
sepay_test_expect($payment['state'] === 'process', 'Incoming Savora transfers should be processable.');
sepay_test_expect($payment['transactionId'] === '7821', 'The provider transaction id should be normalized to text.');
sepay_test_expect($payment['referenceCode'] === 'SVR-ABC123', 'The Savora order reference should be extracted safely.');
sepay_test_expect($payment['amountCents'] === 12550, 'The transfer amount should be converted to integer cents.');

sepay_test_expect(
    sepay_webhook_parse_payload(['id' => 7822, 'transferType' => 'out', 'transferAmount' => 99, 'content' => 'SVR-ABC123'])['state'] === 'ignored',
    'Outgoing transfers should not update payments.'
);
sepay_test_expect(
    sepay_webhook_amount_matches(12550, '125.50'),
    'An exact transfer amount should match the payment amount.'
);
sepay_test_expect(
    !sepay_webhook_amount_matches(12549, '125.50'),
    'An underpayment should be rejected.'
);

$missingIdRejected = false;
try {
    sepay_webhook_parse_payload([
        'transferType' => 'in',
        'transferAmount' => 125.50,
        'content' => 'SVR-ABC123',
    ]);
} catch (InvalidArgumentException) {
    $missingIdRejected = true;
}
sepay_test_expect($missingIdRejected, 'Incoming transfers without a provider id must be rejected.');

echo "PASS: SeaPay webhook contract is authenticated, amount-bound, and idempotency-ready\n";
