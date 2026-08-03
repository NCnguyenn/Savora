<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/location_service.php';

function location_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pair = savora_validate_coordinates('13.7563', '100.5018');
location_test_assert($pair === ['latitude' => 13.7563, 'longitude' => 100.5018], 'coordinates normalize');

foreach ([[91, 0], [0, 181], ['NaN', 0], [null, 0]] as $invalid) {
    try {
        savora_validate_coordinates($invalid[0], $invalid[1]);
        throw new RuntimeException('invalid coordinates accepted');
    } catch (InvalidArgumentException) {
    }
}

$normalized = savora_normalize_geoapify(['features' => [['properties' => [
    'formatted' => '12 Đường Lê Lợi, Quận 1, Hồ Chí Minh, Việt Nam',
    'housenumber' => '12',
    'street' => 'Đường Lê Lợi',
    'suburb' => 'Phường Bến Nghé',
    'city' => 'Hồ Chí Minh',
    'state' => 'Hồ Chí Minh',
    'postcode' => '700000',
    'country' => 'Việt Nam',
]]]]);
location_test_assert($normalized['addressLine1'] === '12 Đường Lê Lợi', 'street normalized');
location_test_assert($normalized['city'] === 'Hồ Chí Minh', 'city normalized');

putenv('GEOAPIFY_API_KEY=test-secret-key');
$transport = static function (string $url, array $options): array {
    location_test_assert(str_contains($url, 'lang=vi'), 'Vietnamese requested');
    location_test_assert(($options['timeout'] ?? 0) <= 8, 'timeout bounded');
    return ['status' => 200, 'body' => json_encode(['features' => [['properties' => [
        'formatted' => 'Bangkok, Thailand',
        'city' => 'Bangkok',
        'country' => 'Thailand',
    ]]]], JSON_THROW_ON_ERROR)];
};

$result = savora_reverse_geocode(13.7563, 100.5018, $transport);
location_test_assert($result['address'] === 'Bangkok, Thailand', 'transport normalized');

echo "location_service_test: ok\n";
