<?php
declare(strict_types=1);

function platform_response_store(mysqli $conn, int $actorId, string $key, string $action, array $response): void
{
    $json = json_encode(
        $response,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );

    $store = $conn->prepare('INSERT INTO idempotency_keys(actor_user_id,idempotency_key,action,response_json) VALUES(?,?,?,?)');
    $store->bind_param('isss', $actorId, $key, $action, $json);
    $store->execute();
    $store->close();
}

function platform_response_find(mysqli $conn, int $actorId, string $key): ?array
{
    $lookup = $conn->prepare('SELECT response_json FROM idempotency_keys WHERE actor_user_id=? AND idempotency_key=?');
    $lookup->bind_param('is', $actorId, $key);
    $lookup->execute();
    $stored = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$stored) {
        return null;
    }

    $response = json_decode((string) $stored['response_json'], true, 512, JSON_THROW_ON_ERROR);
    if (
        !is_array($response)
        || array_is_list($response)
        || !array_key_exists('ok', $response)
        || !is_bool($response['ok'])
    ) {
        throw new JsonException('Stored platform response must use the canonical response envelope.');
    }

    return $response;
}
