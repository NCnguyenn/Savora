<?php
declare(strict_types=1);

function admin_execute_action(mysqli $conn, string $action, array $payload, int $actorId, string $idempotencyKey): array
{
    $lookup = $conn->prepare('SELECT response_json FROM idempotency_keys WHERE actor_user_id = ? AND idempotency_key = ? LIMIT 1');
    $lookup->bind_param('is', $actorId, $idempotencyKey);
    $lookup->execute();
    $existing = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if ($existing) {
        $decoded = json_decode((string) $existing['response_json'], true);
        return is_array($decoded) ? $decoded : ['ok' => false, 'message' => 'Stored response is invalid.'];
    }

    return [
        'ok' => false,
        'message' => 'Unsupported Admin action.',
        'errors' => ['action' => "The action {$action} is not available."],
        'referenceId' => admin_reference_id(),
    ];
}
