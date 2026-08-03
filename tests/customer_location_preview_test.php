<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/location_service.php';
require_once __DIR__ . '/../lib/customer_location_preview.php';

function preview_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$resolved = customer_location_preview(
    13.7563,
    100.5018,
    static fn (float $latitude, float $longitude): array => [
        'address' => '100 Server Street, Bangkok',
        'addressLine1' => '100 Server Street',
        'addressLine2' => 'Tower B',
        'city' => 'Bangkok',
        'state' => 'Bangkok',
        'postalCode' => '10200',
        'country' => 'Thailand',
        'raw' => ['secret' => 'must not escape'],
    ]
);
preview_expect($resolved === [
    'address' => '100 Server Street, Bangkok',
    'addressLine1' => '100 Server Street',
    'addressLine2' => 'Tower B',
    'city' => 'Bangkok',
    'state' => 'Bangkok',
    'postalCode' => '10200',
    'country' => 'Thailand',
    'latitude' => 13.7563,
    'longitude' => 100.5018,
], 'Preview must return only normalized address fields and submitted coordinates.');

try {
    customer_location_preview(91, 100.5018, static fn (): array => []);
    throw new RuntimeException('Invalid latitude should fail.');
} catch (InvalidArgumentException) {
}

preview_expect(customer_location_same_origin([]), 'CLI requests without origin headers should be accepted.');
preview_expect(customer_location_same_origin([
    'HTTP_HOST' => 'localhost',
    'REQUEST_SCHEME' => 'http',
    'HTTP_SEC_FETCH_SITE' => 'same-origin',
    'HTTP_ORIGIN' => 'http://localhost',
]), 'Matching same-origin headers should be accepted.');
preview_expect(!customer_location_same_origin([
    'HTTP_HOST' => 'localhost',
    'REQUEST_SCHEME' => 'http',
    'HTTP_SEC_FETCH_SITE' => 'cross-site',
]), 'Cross-site fetches should be rejected.');

echo "PASS: Customer GPS preview normalizes address data and enforces origin checks\n";
