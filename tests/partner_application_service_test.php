<?php
declare(strict_types=1);

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
    fwrite(STDERR, "BLOCKED: partner application integration tests require savora_test\n");
    exit(2);
}

require_once __DIR__ . '/../lib/services/partner_application_service.php';
require_once __DIR__ . '/support/test_database.php';

function partner_expect(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$conn = null;
$prefix = 'auth-partner-' . bin2hex(random_bytes(5));

try {
    $conn = savora_test_database();
    $driverUsername = $prefix . '-driver';
    $driverEmail = $prefix . '-driver@example.test';
    $driver = partner_submit_application($conn, 'driver', [
        'fullName' => 'Pending Driver',
        'username' => $driverUsername,
        'email' => $driverEmail,
        'phone' => '+1 555 010 3300',
        'password' => 'Strong-Driver-123!',
        'passwordConfirmation' => 'Strong-Driver-123!',
        'serviceArea' => 'Central City',
        'city' => 'Central City',
        'vehicleType' => 'Motorcycle',
        'vehicleModel' => 'Honda Wave',
        'licensePlate' => 'TEST-3300',
        'vehicleColor' => 'Black',
        'acceptedTerms' => true,
    ]);
    partner_expect(($driver['ok'] ?? false) === true && ($driver['status'] ?? 0) === 201, 'Driver application without legal documents must be accepted.');
    $driverId = (int) ($driver['data']['applicationId'] ?? 0);
    partner_expect($driverId > 0 && ($driver['data']['status'] ?? '') === 'pending', 'Driver application must remain pending.');

    $row = partner_application_one($conn, 'SELECT status,vehicle_model,license_plate,vehicle_color,service_area FROM driver_applications WHERE id=?', 'i', [$driverId]);
    partner_expect(($row['status'] ?? '') === 'pending', 'Driver status must be pending.');
    partner_expect(($row['vehicle_model'] ?? '') === 'Honda Wave' && ($row['license_plate'] ?? '') === 'TEST-3300', 'Driver vehicle details must be stored.');
    partner_expect(($row['vehicle_color'] ?? '') === 'Black' && ($row['service_area'] ?? '') === 'Central City', 'Driver area and optional color must be stored.');

    $claim = $conn->prepare('SELECT identifier_type,normalized_value,owner_kind FROM identity_claims WHERE owner_kind=? AND owner_id=? ORDER BY identifier_type');
    $ownerKind = 'driver_application';
    $claim->bind_param('si', $ownerKind, $driverId);
    $claim->execute();
    $claims = $claim->get_result()->fetch_all(MYSQLI_ASSOC);
    $claim->close();
    partner_expect(count($claims) === 2, 'Pending Driver must reserve username and email claims.');

    $user = partner_application_one($conn, 'SELECT id FROM users WHERE username=? OR email=? LIMIT 1', 'ss', [$driverUsername, $driverEmail]);
    partner_expect($user === [], 'Submitting a Driver application must not create a user account.');

    $restaurantUsername = $prefix . '-restaurant';
    $restaurantEmail = $prefix . '-restaurant@example.test';
    $restaurant = partner_submit_application($conn, 'restaurant', [
        'ownerName' => 'Restaurant Owner',
        'username' => $restaurantUsername,
        'email' => $restaurantEmail,
        'phone' => '+1 555 010 4400',
        'password' => 'Strong-Restaurant-123!',
        'passwordConfirmation' => 'Strong-Restaurant-123!',
        'restaurantName' => 'Onboarding Kitchen',
        'description' => 'Fresh meals prepared daily.',
        'cuisine' => 'Vietnamese',
        'address' => '44 Test Street',
        'city' => 'Central City',
        'restaurantPhone' => '+1 555 010 4401',
        'opensAt' => '09:00',
        'closesAt' => '22:00',
        'acceptedTerms' => true,
    ]);
    partner_expect(($restaurant['ok'] ?? false) === true && ($restaurant['status'] ?? 0) === 201, 'Restaurant application without legal documents must be accepted.');
    $restaurantId = (int) ($restaurant['data']['applicationId'] ?? 0);
    $restaurantRow = partner_application_one($conn, 'SELECT status,description,restaurant_phone,opens_at,closes_at FROM restaurant_applications WHERE id=?', 'i', [$restaurantId]);
    partner_expect(($restaurantRow['status'] ?? '') === 'pending', 'Restaurant status must be pending.');
    partner_expect(($restaurantRow['description'] ?? '') === 'Fresh meals prepared daily.', 'Restaurant description must be stored.');
    partner_expect(($restaurantRow['restaurant_phone'] ?? '') === '+1 555 010 4401', 'Restaurant phone must be stored.');
    partner_expect(str_starts_with((string) ($restaurantRow['opens_at'] ?? ''), '09:00') && str_starts_with((string) ($restaurantRow['closes_at'] ?? ''), '22:00'), 'Restaurant hours must be stored.');

    $mismatch = partner_submit_application($conn, 'driver', [
        'fullName' => 'Mismatch Driver', 'username' => $prefix . '-mismatch', 'email' => $prefix . '-mismatch@example.test',
        'phone' => '+1 555 010 5500', 'password' => 'Strong-Driver-123!', 'passwordConfirmation' => 'Different-Password-123!',
        'serviceArea' => 'Central City', 'city' => 'Central City', 'vehicleType' => 'Motorcycle', 'vehicleModel' => 'Wave',
        'licensePlate' => 'TEST-5500', 'acceptedTerms' => true,
    ]);
    partner_expect(($mismatch['ok'] ?? true) === false && ($mismatch['status'] ?? 0) === 422, 'Password mismatch must be rejected.');

    echo "PASS: partner applications reserve identities, persist approved fields, and require no legal documents\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} finally {
    if ($conn instanceof mysqli) {
        $pattern = $prefix . '%';
        foreach (['driver_application', 'restaurant_application'] as $ownerKind) {
            $statement = $conn->prepare('DELETE FROM identity_claims WHERE owner_kind=? AND owner_id IN (SELECT id FROM ' . ($ownerKind === 'driver_application' ? 'driver_applications' : 'restaurant_applications') . ' WHERE username LIKE ?)');
            $statement->bind_param('ss', $ownerKind, $pattern);
            $statement->execute();
            $statement->close();
        }
        foreach (['DELETE FROM driver_applications WHERE username LIKE ?', 'DELETE FROM restaurant_applications WHERE username LIKE ?'] as $sql) {
            $statement = $conn->prepare($sql);
            $statement->bind_param('s', $pattern);
            $statement->execute();
            $statement->close();
        }
        $conn->close();
    }
}
