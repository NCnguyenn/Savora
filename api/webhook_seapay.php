<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/services/sepay_webhook_service.php';

header('Content-Type: application/json; charset=utf-8');

function sepay_webhook_reply(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') sepay_webhook_reply(405, ['success' => false, 'message' => 'Method not allowed']);

$apiKey = sepay_webhook_api_key();
if ($apiKey === '') sepay_webhook_reply(503, ['success' => false, 'message' => 'Webhook is not configured.']);
if (!sepay_webhook_is_authorized($_SERVER, $apiKey)) {
    header('WWW-Authenticate: Apikey realm="Savora"');
    sepay_webhook_reply(401, ['success' => false, 'message' => 'Unauthorized webhook.']);
}

require_once __DIR__ . '/../db.php';

$body = file_get_contents('php://input');
try {
    $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new InvalidArgumentException('Invalid JSON payload.');
    $event = sepay_webhook_parse_payload($data);
} catch (JsonException|InvalidArgumentException) {
    sepay_webhook_reply(400, ['success' => false, 'message' => 'Invalid webhook payload.']);
}

if ($event['state'] === 'ignored') {
    sepay_webhook_reply(200, ['success' => true, 'message' => 'Webhook ignored.']);
}

$conn->begin_transaction();
try {
    $referenceCode = (string) $event['referenceCode'];
    $transactionId = (string) $event['transactionId'];
    $lookup = $conn->prepare(
        "SELECT o.id AS order_id, p.id AS payment_id, p.method, p.amount, p.status, p.provider_reference
         FROM orders o JOIN payments p ON p.order_id=o.id
         WHERE o.reference_code=? LIMIT 1 FOR UPDATE"
    );
    $lookup->bind_param('s', $referenceCode);
    $lookup->execute();
    $payment = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    if ($payment === null || (string) $payment['method'] !== 'seapay') {
        $conn->commit();
        sepay_webhook_reply(200, ['success' => true, 'message' => 'Webhook ignored.']);
    }

    $seen = $conn->prepare('SELECT order_id FROM payments WHERE provider_reference=? LIMIT 1 FOR UPDATE');
    $seen->bind_param('s', $transactionId);
    $seen->execute();
    $seenPayment = $seen->get_result()->fetch_assoc();
    $seen->close();
    if ($seenPayment !== null) {
        $conn->commit();
        sepay_webhook_reply(200, ['success' => true, 'message' => ((int) $seenPayment['order_id'] === (int) $payment['order_id']) ? 'Webhook already processed.' : 'Webhook ignored.']);
    }

    if ((string) $payment['status'] !== 'pending') {
        $conn->commit();
        sepay_webhook_reply(200, ['success' => true, 'message' => 'Payment is not awaiting SeaPay confirmation.']);
    }
    if (!sepay_webhook_amount_matches((int) $event['amountCents'], $payment['amount'])) {
        $conn->commit();
        sepay_webhook_reply(200, ['success' => true, 'message' => 'Payment amount does not match.']);
    }

    $paymentId = (int) $payment['payment_id'];
    $update = $conn->prepare(
        "UPDATE payments SET status='paid', provider_reference=?, paid_at=NOW(), version=version+1
         WHERE id=? AND status='pending' AND (provider_reference IS NULL OR provider_reference='')"
    );
    $update->bind_param('si', $transactionId, $paymentId);
    $update->execute();
    if ($update->affected_rows !== 1) throw new RuntimeException('Payment confirmation was not applied.');
    $update->close();
    $conn->commit();
    sepay_webhook_reply(200, ['success' => true, 'message' => 'Payment confirmed.']);
} catch (Throwable) {
    $conn->rollback();
    sepay_webhook_reply(500, ['success' => false, 'message' => 'Webhook could not be processed.']);
}
