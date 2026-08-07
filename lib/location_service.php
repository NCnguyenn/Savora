<?php
declare(strict_types=1);

function savora_location_text(mixed $value, int $limit = 180): string
{
    $text = trim((string) $value);
    return function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);
}

function savora_validate_coordinates(mixed $latitude, mixed $longitude): array
{
    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        throw new InvalidArgumentException('Coordinates must be numeric.');
    }
    $lat = (float) $latitude;
    $lon = (float) $longitude;
    if (!is_finite($lat) || !is_finite($lon) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        throw new InvalidArgumentException('Coordinates are outside the valid range.');
    }
    return ['latitude' => $lat, 'longitude' => $lon];
}

function savora_normalize_geoapify(array $payload): array
{
    $features = $payload['features'] ?? [];
    $properties = is_array($features) && isset($features[0]['properties']) && is_array($features[0]['properties'])
        ? $features[0]['properties']
        : [];
    if (!$properties) {
        throw new InvalidArgumentException('No readable address was found.');
    }

    $house = savora_location_text($properties['housenumber'] ?? '', 40);
    $street = savora_location_text($properties['street'] ?? '', 120);
    $line1 = trim(implode(' ', array_filter([$house, $street])));
    if ($line1 === '') {
        $line1 = savora_location_text($properties['name'] ?? '', 150);
    }
    $line2 = trim(implode(', ', array_filter([
        savora_location_text($properties['unit'] ?? ($properties['unit_name'] ?? ''), 80),
        savora_location_text($properties['suburb'] ?? '', 100),
        savora_location_text($properties['district'] ?? '', 100),
    ])));
    $city = savora_location_text($properties['city'] ?? ($properties['town'] ?? ($properties['village'] ?? '')), 100);
    $state = savora_location_text($properties['state'] ?? ($properties['state_code'] ?? ''), 100);
    $postalCode = savora_location_text($properties['postcode'] ?? '', 30);
    $country = savora_location_text($properties['country'] ?? '', 100);
    $formatted = savora_location_text($properties['formatted'] ?? '', 500);
    $address = $formatted !== '' ? $formatted : trim(implode(', ', array_filter([
        $line1,
        $line2,
        $city,
        $state,
        $postalCode,
        $country,
    ])));
    if ($address === '') {
        throw new InvalidArgumentException('No readable address was found.');
    }
    return [
        'address' => $address,
        'addressLine1' => $line1 !== '' ? $line1 : $address,
        'addressLine2' => $line2,
        'city' => $city,
        'state' => $state,
        'postalCode' => $postalCode,
        'country' => $country,
    ];
}

function savora_geoapify_transport(string $url, array $options): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Automatic address lookup is temporarily unavailable.');
    }
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Automatic address lookup is temporarily unavailable.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => (int) ($options['connectTimeout'] ?? 3),
        CURLOPT_TIMEOUT => (int) ($options['timeout'] ?? 8),
        CURLOPT_USERAGENT => 'Savora GPS address demo/1.0',
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false || $error !== '' || strlen((string) $body) > (int) ($options['maxBytes'] ?? 262144)) {
        throw new RuntimeException('Automatic address lookup is temporarily unavailable.');
    }
    return ['status' => $status, 'body' => (string) $body];
}

function savora_geoapify_api_key(?string $localPath = null): string
{
    $environmentKey = trim((string) getenv('GEOAPIFY_API_KEY'));
    if ($environmentKey !== '' && !preg_match('/^(PASTE_YOUR_|YOUR_API_KEY|<.*>)/i', $environmentKey)) {
        return $environmentKey;
    }

    $localPath ??= __DIR__ . '/../config/local.php';
    if (!is_file($localPath)) return '';
    try {
        $local = require $localPath;
    } catch (Throwable) {
        return '';
    }
    if (!is_array($local)) return '';
    $key = trim((string) ($local['GEOAPIFY_API_KEY'] ?? ''));
    return preg_match('/^(PASTE_YOUR_|YOUR_API_KEY|<.*>)/i', $key) ? '' : $key;
}

function savora_reverse_geocode(float $latitude, float $longitude, ?callable $transport = null): array
{
    $coordinates = savora_validate_coordinates($latitude, $longitude);
    $key = savora_geoapify_api_key();
    if ($key === '') {
        throw new RuntimeException('Automatic address lookup is not configured.');
    }
    $url = 'https://api.geoapify.com/v1/geocode/reverse?' . http_build_query([
        'lat' => $coordinates['latitude'],
        'lon' => $coordinates['longitude'],
        'lang' => 'vi',
        'format' => 'geojson',
        'limit' => 1,
        'apiKey' => $key,
    ], '', '&', PHP_QUERY_RFC3986);
    $request = $transport ?? 'savora_geoapify_transport';
    $response = $request($url, [
        'connectTimeout' => 3,
        'timeout' => 8,
        'maxBytes' => 262144,
    ]);
    if (!is_array($response) || (int) ($response['status'] ?? 0) !== 200) {
        throw new RuntimeException('Automatic address lookup is temporarily unavailable.');
    }
    try {
        $decoded = json_decode((string) ($response['body'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
        return savora_normalize_geoapify(is_array($decoded) ? $decoded : []);
    } catch (JsonException|InvalidArgumentException) {
        throw new RuntimeException('No readable address was found for this location.');
    }
}
