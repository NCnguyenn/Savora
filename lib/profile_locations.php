<?php
declare(strict_types=1);

require_once __DIR__ . '/location_service.php';

function savora_location_empty(): array
{
    return [
        'address' => '',
        'addressLine1' => '',
        'addressLine2' => '',
        'city' => '',
        'state' => '',
        'postalCode' => '',
        'country' => '',
        'latitude' => null,
        'longitude' => null,
        'locationMethod' => 'manual',
        'locationUpdatedAt' => null,
    ];
}

function savora_location_query(string $role): array
{
    return match ($role) {
        'customer' => [
            'sql' => 'SELECT address, latitude, longitude, location_method, location_updated_at FROM customer_profiles WHERE user_id=? LIMIT 1',
            'address' => 'address',
            'key' => 'user_id',
        ],
        'driver' => [
            'sql' => 'SELECT address, latitude, longitude, location_method, location_updated_at FROM driver_profiles WHERE user_id=? LIMIT 1',
            'address' => 'address',
            'key' => 'user_id',
        ],
        'restaurant' => [
            'sql' => 'SELECT address, address_line1, address_line2, city, state, postal_code, country, latitude, longitude, location_method, location_updated_at FROM restaurants WHERE owner_user_id=? LIMIT 1',
            'address' => 'address',
            'key' => 'owner_user_id',
        ],
        default => throw new InvalidArgumentException('Location is not available for this role.'),
    };
}

function savora_location_from_row(array $row): array
{
    $location = savora_location_empty();
    $location['address'] = savora_location_text($row['address'] ?? '', 500);
    $location['addressLine1'] = savora_location_text($row['address_line1'] ?? '', 150);
    $location['addressLine2'] = savora_location_text($row['address_line2'] ?? '', 150);
    $location['city'] = savora_location_text($row['city'] ?? '', 100);
    $location['state'] = savora_location_text($row['state'] ?? '', 100);
    $location['postalCode'] = savora_location_text($row['postal_code'] ?? '', 30);
    $location['country'] = savora_location_text($row['country'] ?? '', 100);
    if ($location['addressLine1'] === '' && $location['address'] !== '') {
        $location['addressLine1'] = $location['address'];
    }
    $location['latitude'] = $row['latitude'] !== null ? (float) $row['latitude'] : null;
    $location['longitude'] = $row['longitude'] !== null ? (float) $row['longitude'] : null;
    $location['locationMethod'] = ($row['location_method'] ?? 'manual') === 'gps' && $location['latitude'] !== null && $location['longitude'] !== null ? 'gps' : 'manual';
    $location['locationUpdatedAt'] = $row['location_updated_at'] ?? null;
    return $location;
}

function savora_profile_location(mysqli $conn, string $role, int $userId): array
{
    $query = savora_location_query($role);
    $stmt = $conn->prepare($query['sql']);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row ? savora_location_from_row($row) : savora_location_empty();
}

function savora_location_payload_text(array $payload, string $key, int $limit): string
{
    return savora_location_text($payload[$key] ?? '', $limit);
}

function savora_update_location(mysqli $conn, string $sql, string $types, array $values): void
{
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    if ($stmt->affected_rows < 1) {
        $stmt->close();
        throw new RuntimeException('Profile location could not be saved.');
    }
    $stmt->close();
}

function savora_sync_customer_checkout_gps(mysqli $conn, int $userId, array $resolved, float $latitude, float $longitude): void
{
    $line1 = savora_location_text($resolved['addressLine1'] ?? $resolved['address'] ?? '', 200);
    $city = savora_location_text($resolved['city'] ?? '', 100);
    if ($line1 === '') $line1 = savora_location_text($resolved['address'] ?? '', 200);
    if ($city === '') $city = 'Current location';

    $lookup = $conn->prepare('SELECT id FROM customer_addresses WHERE customer_user_id=? ORDER BY is_default DESC,updated_at DESC,id DESC LIMIT 1 FOR UPDATE');
    $lookup->bind_param('i', $userId);
    $lookup->execute();
    $existing = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    if ($existing) {
        $addressId = (int) $existing['id'];
        $update = $conn->prepare('UPDATE customer_addresses SET address_line1=?,city=?,latitude=?,longitude=?,version=version+1 WHERE id=? AND customer_user_id=?');
        $update->bind_param('ssddii', $line1, $city, $latitude, $longitude, $addressId, $userId);
        $update->execute();
        $affected = $update->affected_rows;
        $update->close();
        if ($affected !== 1) throw new RuntimeException('Customer delivery address could not be synchronized.');
        return;
    }

    $owner = $conn->prepare("SELECT u.full_name,COALESCE(NULLIF(p.phone,''),u.phone,'') AS phone FROM users u LEFT JOIN customer_profiles p ON p.user_id=u.id WHERE u.id=? AND u.role='customer' AND u.status='active' LIMIT 1 FOR UPDATE");
    $owner->bind_param('i', $userId);
    $owner->execute();
    $profile = $owner->get_result()->fetch_assoc();
    $owner->close();
    if (!$profile) throw new RuntimeException('Customer ownership could not be verified.');

    $publicId = 'gps-' . bin2hex(random_bytes(12));
    $label = 'Current location';
    $recipient = savora_location_text($profile['full_name'] ?? '', 120);
    $phone = savora_location_text($profile['phone'] ?? '', 40);
    $insert = $conn->prepare('INSERT INTO customer_addresses(customer_user_id,public_id,label,recipient_name,phone,address_line1,city,latitude,longitude,is_default,version) VALUES(?,?,?,?,?,?,?,?,?,1,1)');
    $insert->bind_param('issssssdd', $userId, $publicId, $label, $recipient, $phone, $line1, $city, $latitude, $longitude);
    $insert->execute();
    $insert->close();
}

function savora_save_gps_location(mysqli $conn, string $role, int $userId, array $resolved, float $latitude, float $longitude): array
{
    $coordinates = savora_validate_coordinates($latitude, $longitude);
    $address = savora_location_text($resolved['address'] ?? '', 500);
    if ($address === '') {
        throw new InvalidArgumentException('A readable address is required.');
    }
    if ($role === 'customer') {
        savora_update_location($conn, 'UPDATE customer_profiles SET address=?, latitude=?, longitude=?, location_method=\'gps\', location_updated_at=NOW() WHERE user_id=?', 'sddi', [$address, $coordinates['latitude'], $coordinates['longitude'], $userId]);
        savora_sync_customer_checkout_gps($conn, $userId, $resolved, $coordinates['latitude'], $coordinates['longitude']);
    } elseif ($role === 'driver') {
        savora_update_location($conn, 'UPDATE driver_profiles SET address=?, latitude=?, longitude=?, location_method=\'gps\', location_updated_at=NOW() WHERE user_id=?', 'sddi', [$address, $coordinates['latitude'], $coordinates['longitude'], $userId]);
    } elseif ($role === 'restaurant') {
        savora_update_location($conn, 'UPDATE restaurants SET address=?, address_line1=?, address_line2=?, city=?, state=?, postal_code=?, country=?, latitude=?, longitude=?, location_method=\'gps\', location_updated_at=NOW() WHERE owner_user_id=?', 'sssssssddi', [
            $address,
            savora_location_text($resolved['addressLine1'] ?? $address, 150),
            savora_location_text($resolved['addressLine2'] ?? '', 150),
            savora_location_text($resolved['city'] ?? '', 100),
            savora_location_text($resolved['state'] ?? '', 100),
            savora_location_text($resolved['postalCode'] ?? '', 30),
            savora_location_text($resolved['country'] ?? '', 100),
            $coordinates['latitude'],
            $coordinates['longitude'],
            $userId,
        ]);
    } else {
        throw new InvalidArgumentException('Location is not available for this role.');
    }
    return savora_profile_location($conn, $role, $userId);
}

function savora_save_manual_location(mysqli $conn, string $role, int $userId, array $payload): array
{
    if ($role === 'restaurant') {
        $line1 = savora_location_payload_text($payload, 'addressLine1', 150);
        $line2 = savora_location_payload_text($payload, 'addressLine2', 150);
        $city = savora_location_payload_text($payload, 'city', 100);
        $state = savora_location_payload_text($payload, 'state', 100);
        $postalCode = savora_location_payload_text($payload, 'postalCode', 30);
        $country = savora_location_payload_text($payload, 'country', 100);
        $address = savora_location_payload_text($payload, 'address', 500);
        $address = $address !== '' ? $address : trim(implode(', ', array_filter([$line1, $line2, $city, $state, $postalCode, $country])));
        if ($address === '') {
            throw new InvalidArgumentException('Enter a restaurant address.');
        }
        savora_update_location($conn, 'UPDATE restaurants SET address=?, address_line1=?, address_line2=?, city=?, state=?, postal_code=?, country=?, latitude=NULL, longitude=NULL, location_method=\'manual\', location_updated_at=NOW() WHERE owner_user_id=?', 'sssssssi', [$address, $line1, $line2, $city, $state, $postalCode, $country, $userId]);
    } elseif ($role === 'customer' || $role === 'driver') {
        $address = savora_location_payload_text($payload, 'address', 500);
        if ($address === '') {
            throw new InvalidArgumentException('Enter an address.');
        }
        $table = $role === 'customer' ? 'customer_profiles' : 'driver_profiles';
        $key = $role === 'customer' ? 'user_id' : 'user_id';
        savora_update_location($conn, "UPDATE {$table} SET address=?, latitude=NULL, longitude=NULL, location_method='manual', location_updated_at=NOW() WHERE {$key}=?", 'si', [$address, $userId]);
    } else {
        throw new InvalidArgumentException('Location is not available for this role.');
    }
    return savora_profile_location($conn, $role, $userId);
}
