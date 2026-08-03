<?php
declare(strict_types=1);

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') { fwrite(STDERR, "BLOCKED: driver profile integration tests require savora_test\n"); exit(2); }
require_once __DIR__ . '/../lib/services/profile_service.php';
require_once __DIR__ . '/support/test_database.php';

function driver_profile_expect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$prefix = 'task17a-driver-' . bin2hex(random_bytes(5)); $conn = null; $driverId = 0;
try {
    $conn = savora_test_database();
    $password = password_hash('test', PASSWORD_DEFAULT); $username = $prefix . '-user'; $name = 'Profile Driver'; $email = $prefix . '@test.invalid'; $phone = '+10000000000'; $role = 'driver';
    $user = $conn->prepare("INSERT INTO users(username,password,role,full_name,email,phone,status) VALUES(?,?,?,?,?,?, 'active')");
    $user->bind_param('ssssss', $username, $password, $role, $name, $email, $phone); $user->execute(); $driverId = (int) $user->insert_id; $user->close();
    $profile = $conn->prepare("INSERT INTO driver_profiles(user_id,city,vehicle_type,vehicle_model,license_plate,service_area,eligibility_status,availability_status,version) VALUES(?, 'Test City','Motorcycle','Test Model','OLD-1','Central','eligible','offline',1)");
    $profile->bind_param('i', $driverId); $profile->execute(); $profile->close();

    $contact = profile_update_driver_contact($conn, $driverId, ['fullName' => 'Updated Driver', 'phone' => '+10000000001', 'email' => $email], 1);
    driver_profile_expect(($contact['ok'] ?? false) === true, 'Driver should update owned contact data.');
    $identity = profile_update_driver_contact($conn, $driverId, ['eligibilityStatus' => 'eligible'], 2);
    driver_profile_expect(($identity['status'] ?? 0) === 422, 'Driver must not self-approve eligibility.');
    $vehicle = profile_update_driver_vehicle_request($conn, $driverId, ['licensePlate' => 'NEW-123'], 2);
    driver_profile_expect(($vehicle['data']['reviewStatus'] ?? '') === 'pending', 'Vehicle change must require review.');
    $preferences = profile_update_driver_preferences($conn, $driverId, ['newOffers' => false, 'soundAlerts' => true], 2);
    driver_profile_expect(($preferences['ok'] ?? false) === true, 'Driver preferences should be versioned on the server.');
    $snapshot = profile_for_driver($conn, $driverId);
    driver_profile_expect(($snapshot['data']['profile']['fullName'] ?? '') === 'Updated Driver', 'Driver snapshot must come from MySQL.');
    driver_profile_expect(($snapshot['data']['preferences']['newOffers'] ?? true) === false, 'Server preference must be returned.');
    echo "PASS: Driver profile, vehicle review, and preference authority hold\n";
} catch (Throwable $exception) { fwrite(STDERR, $exception->getMessage() . "\n"); exit(1); }
finally {
    if ($conn instanceof mysqli) {
        if ($driverId > 0) { $stmt = $conn->prepare('DELETE FROM driver_change_requests WHERE driver_user_id=?'); $stmt->bind_param('i', $driverId); $stmt->execute(); $stmt->close(); $stmt = $conn->prepare('DELETE FROM driver_profiles WHERE user_id=?'); $stmt->bind_param('i', $driverId); $stmt->execute(); $stmt->close(); $stmt = $conn->prepare('DELETE FROM users WHERE id=?'); $stmt->bind_param('i', $driverId); $stmt->execute(); $stmt->close(); }
        $conn->close();
    }
}
