<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/services/payment_confirmation_service.php';

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

$response = payment_confirm_incoming($conn, $event, 'seapay');
$serviceStatus = (int) ($response['status'] ?? 500);
if ($serviceStatus >= 500) {
    sepay_webhook_reply(500, ['success' => false, 'message' => 'Payment confirmation could not be completed.']);
}
// Acknowledge valid, parsed provider events even when Savora safely ignores or
// rejects the business match, so SeaPay does not retry an unprocessable event.
sepay_webhook_reply(200, ['success' => true, 'message' => (string) ($response['message'] ?? 'Payment event acknowledged.')]);
