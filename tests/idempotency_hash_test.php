<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/idempotency.php';

function idempotency_hash_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$first = [
    'details' => ['z' => 'last', 'a' => 'first'],
    'items' => [
        ['sku' => 'first', 'options' => ['b' => 2, 'a' => 1]],
        ['sku' => 'second'],
    ],
];
$sameAssociativePayload = [
    'items' => [
        ['options' => ['a' => 1, 'b' => 2], 'sku' => 'first'],
        ['sku' => 'second'],
    ],
    'details' => ['a' => 'first', 'z' => 'last'],
];

idempotency_hash_expect(
    savora_idempotency_hash('test', $first) === savora_idempotency_hash('test', $sameAssociativePayload),
    'Nested associative key order must not change the hash.'
);
idempotency_hash_expect(
    savora_idempotency_hash('test', $first) !== savora_idempotency_hash('test', ['details' => $first['details'], 'items' => array_reverse($first['items'])]),
    'List order must remain part of the hash.'
);

echo "idempotency hash contract ok\n";
