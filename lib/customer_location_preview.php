<?php
declare(strict_types=1);

function customer_location_request_origin(array $server): string
{
    $scheme = strtolower(trim((string) ($server['REQUEST_SCHEME'] ?? '')));
    if (!in_array($scheme, ['http', 'https'], true)) {
        $scheme = !empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off' ? 'https' : 'http';
    }
    $host = trim((string) ($server['HTTP_HOST'] ?? ($server['SERVER_NAME'] ?? '')));
    if ($host === '') return '';
    return $scheme . '://' . strtolower($host);
}

function customer_location_same_origin(array $server): bool
{
    $fetchSite = strtolower(trim((string) ($server['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($fetchSite !== '' && $fetchSite !== 'same-origin') return false;

    $origin = trim((string) ($server['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') return true;
    $originParts = parse_url($origin);
    if (!is_array($originParts) || !isset($originParts['scheme'], $originParts['host'])) return false;
    if (isset($originParts['path']) || isset($originParts['query']) || isset($originParts['fragment']) || isset($originParts['user'])) return false;
    $port = isset($originParts['port']) ? ':' . (int) $originParts['port'] : '';
    $normalizedOrigin = strtolower((string) $originParts['scheme']) . '://' . strtolower((string) $originParts['host']) . $port;
    return hash_equals(customer_location_request_origin($server), $normalizedOrigin);
}

function customer_location_preview(mixed $latitude, mixed $longitude, ?callable $reverseGeocode = null): array
{
    $coordinates = savora_validate_coordinates($latitude, $longitude);
    $resolver = $reverseGeocode ?? 'savora_reverse_geocode';
    $resolved = $resolver($coordinates['latitude'], $coordinates['longitude']);
    if (!is_array($resolved)) throw new RuntimeException('Automatic address lookup is temporarily unavailable.');

    return [
        'address' => savora_location_text($resolved['address'] ?? '', 500),
        'addressLine1' => savora_location_text($resolved['addressLine1'] ?? '', 200),
        'addressLine2' => savora_location_text($resolved['addressLine2'] ?? '', 200),
        'city' => savora_location_text($resolved['city'] ?? '', 100),
        'state' => savora_location_text($resolved['state'] ?? '', 100),
        'postalCode' => savora_location_text($resolved['postalCode'] ?? '', 30),
        'country' => savora_location_text($resolved['country'] ?? '', 100),
        'latitude' => $coordinates['latitude'],
        'longitude' => $coordinates['longitude'],
    ];
}
