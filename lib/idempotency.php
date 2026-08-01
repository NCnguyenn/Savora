<?php
declare(strict_types=1);

final class SavoraIdempotencyConflict extends RuntimeException
{
}

function savora_idempotency_canonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map(static fn (mixed $item): mixed => savora_idempotency_canonicalize($item), $value);
    }

    $canonical = [];
    foreach ($value as $key => $item) {
        $canonical[$key] = savora_idempotency_canonicalize($item);
    }
    ksort($canonical, SORT_STRING);
    return $canonical;
}

function savora_idempotency_hash(string $action, array $payload): string
{
    $canonicalJson = json_encode(
        savora_idempotency_canonicalize($payload),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    return hash('sha256', $action . "\n" . $canonicalJson);
}

function savora_idempotency_response(string $json): array
{
    $response = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
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

function savora_idempotency_find(mysqli $conn, int $actorId, string $key, string $action, string $requestHash): ?array
{
    $lookup = $conn->prepare(
        'SELECT action, request_hash, response_json FROM idempotency_keys WHERE actor_user_id=? AND idempotency_key=? LIMIT 1'
    );
    $lookup->bind_param('is', $actorId, $key);
    $lookup->execute();
    $stored = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$stored) {
        return null;
    }
    if (!hash_equals((string) $stored['action'], $action) || !hash_equals((string) $stored['request_hash'], $requestHash)) {
        throw new SavoraIdempotencyConflict('Idempotency key was already used for a different request.');
    }
    return savora_idempotency_response((string) $stored['response_json']);
}

function savora_idempotency_store(
    mysqli $conn,
    int $actorId,
    string $key,
    string $action,
    string $requestHash,
    array $response
): void {
    $json = json_encode(
        $response,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    $store = $conn->prepare(
        'INSERT INTO idempotency_keys(actor_user_id,idempotency_key,action,request_hash,response_json) VALUES(?,?,?,?,?)'
    );
    $store->bind_param('issss', $actorId, $key, $action, $requestHash, $json);
    $store->execute();
    $store->close();
}
