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
        'deliveryDetails' => '',
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
            'sql' => 'SELECT address, delivery_details, latitude, longitude, location_method, location_updated_at FROM customer_profiles WHERE user_id=? LIMIT 1',
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
    $location['deliveryDetails'] = savora_location_text($row['delivery_details'] ?? '', 300);
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

function savora_delivery_details(mixed $value): string
{
    if (!is_string($value)) throw new InvalidArgumentException('Delivery details must be text.');
    $value = trim($value);
    if (function_exists('mb_strlen') ? mb_strlen($value) > 300 : strlen($value) > 300) {
        throw new InvalidArgumentException('Delivery details must be 300 characters or fewer.');
    }
    return $value;
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

function savora_sync_customer_checkout_location(
    mysqli $conn,
    int $userId,
    array $resolved,
    ?float $latitude,
    ?float $longitude,
    string $deliveryDetails
): void
{
    $line1 = savora_location_text($resolved['addressLine1'] ?? $resolved['address'] ?? '', 200);
    $line2 = savora_location_text($resolved['addressLine2'] ?? '', 200);
    $city = savora_location_text($resolved['city'] ?? '', 100);
    if ($line1 === '') $line1 = savora_location_text($resolved['address'] ?? '', 200);
    if ($city === '' && $latitude !== null && $longitude !== null) $city = 'Current location';

    $lookup = $conn->prepare('SELECT id FROM customer_addresses WHERE customer_user_id=? ORDER BY is_default DESC,updated_at DESC,id DESC LIMIT 1 FOR UPDATE');
    $lookup->bind_param('i', $userId);
    $lookup->execute();
    $existing = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    if ($existing) {
        $addressId = (int) $existing['id'];
        $update = $latitude !== null && $longitude !== null
            ? $conn->prepare('UPDATE customer_addresses SET address_line1=?,address_line2=?,city=?,latitude=?,longitude=?,delivery_details=?,version=version+1 WHERE id=? AND customer_user_id=?')
            : $conn->prepare('UPDATE customer_addresses SET address_line1=?,address_line2=?,city=?,latitude=NULL,longitude=NULL,delivery_details=?,version=version+1 WHERE id=? AND customer_user_id=?');
        if ($latitude !== null && $longitude !== null) {
            $update->bind_param('sssddsii', $line1, $line2, $city, $latitude, $longitude, $deliveryDetails, $addressId, $userId);
        } else {
            $update->bind_param('ssssii', $line1, $line2, $city, $deliveryDetails, $addressId, $userId);
        }
        $update->execute();
        if ($update->errno !== 0) {
            $message = $update->error;
            $update->close();
            throw new RuntimeException('Customer delivery address could not be synchronized: ' . $message);
        }
        $update->close();
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
    $insert = $latitude !== null && $longitude !== null
        ? $conn->prepare('INSERT INTO customer_addresses(customer_user_id,public_id,label,recipient_name,phone,address_line1,address_line2,city,latitude,longitude,delivery_details,is_default,version) VALUES(?,?,?,?,?,?,?,?,?,?,?,1,1)')
        : $conn->prepare('INSERT INTO customer_addresses(customer_user_id,public_id,label,recipient_name,phone,address_line1,address_line2,city,latitude,longitude,delivery_details,is_default,version) VALUES(?,?,?,?,?,?,?,?,NULL,NULL,?,1,1)');
    if ($latitude !== null && $longitude !== null) {
        $insert->bind_param('isssssssdds', $userId, $publicId, $label, $recipient, $phone, $line1, $line2, $city, $latitude, $longitude, $deliveryDetails);
    } else {
        $insert->bind_param('issssssss', $userId, $publicId, $label, $recipient, $phone, $line1, $line2, $city, $deliveryDetails);
    }
    $insert->execute();
    if ($insert->errno !== 0) {
        $message = $insert->error;
        $insert->close();
        throw new RuntimeException('Customer delivery address could not be synchronized: ' . $message);
    }
    $insert->close();
}

function savora_save_gps_location(mysqli $conn, string $role, int $userId, array $resolved, float $latitude, float $longitude, string $deliveryDetails = ''): array
{
    $coordinates = savora_validate_coordinates($latitude, $longitude);
    $address = savora_location_text($resolved['address'] ?? '', 500);
    if ($address === '') {
        throw new InvalidArgumentException('A readable address is required.');
    }
    if ($role === 'customer') {
        $deliveryDetails = savora_delivery_details($deliveryDetails);
        savora_update_location($conn, 'UPDATE customer_profiles SET address=?, delivery_details=?, latitude=?, longitude=?, location_method=\'gps\', location_updated_at=NOW() WHERE user_id=?', 'ssddi', [$address, $deliveryDetails, $coordinates['latitude'], $coordinates['longitude'], $userId]);
        savora_sync_customer_checkout_location($conn, $userId, $resolved, $coordinates['latitude'], $coordinates['longitude'], $deliveryDetails);
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
        if ($role === 'customer') {
            $deliveryDetails = savora_delivery_details($payload['deliveryDetails'] ?? '');
            savora_update_location($conn, "UPDATE customer_profiles SET address=?, delivery_details=?, latitude=NULL, longitude=NULL, location_method='manual', location_updated_at=NOW() WHERE user_id=?", 'ssi', [$address, $deliveryDetails, $userId]);
            savora_sync_customer_checkout_location($conn, $userId, ['address' => $address, 'addressLine1' => $address], null, null, $deliveryDetails);
        } else {
            savora_update_location($conn, "UPDATE driver_profiles SET address=?, latitude=NULL, longitude=NULL, location_method='manual', location_updated_at=NOW() WHERE user_id=?", 'si', [$address, $userId]);
        }
    } else {
        throw new InvalidArgumentException('Location is not available for this role.');
    }
    return savora_profile_location($conn, $role, $userId);
}
