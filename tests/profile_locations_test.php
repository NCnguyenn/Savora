<?php
declare(strict_types=1);

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
    fwrite(STDERR, "BLOCKED: profile location integration tests require savora_test\n");
    exit(2);
}

require_once __DIR__ . '/../lib/profile_locations.php';
require_once __DIR__ . '/support/test_database.php';

function profile_location_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$conn = null;
try {
    $conn = savora_test_database();
    $conn->begin_transaction();
    $suffix = bin2hex(random_bytes(5));
    $password = password_hash('test', PASSWORD_DEFAULT);

    $username = "location-customer-{$suffix}";
    $email = "{$username}@test.invalid";
    $role = 'customer';
    $name = 'Location Customer';
    $phone = '+10000000001';
    $user = $conn->prepare("INSERT INTO users(username,password,role,full_name,email,phone,status) VALUES(?,?,?,?,?,?,'active')");
    $user->bind_param('ssssss', $username, $password, $role, $name, $email, $phone);
    $user->execute();
    $customerId = (int) $user->insert_id;
    $user->close();
    $profile = $conn->prepare('INSERT INTO customer_profiles(user_id,email,phone,address,wallet_balance,version) VALUES(?,?,?,NULL,0,1)');
    $profile->bind_param('iss', $customerId, $email, $phone);
    $profile->execute();
    $profile->close();
    $publicId = "location-address-{$suffix}";
    $label = 'Home';
    $oldAddress = 'Old delivery address';
    $city = 'Old City';
    $latitude = 1.0;
    $longitude = 2.0;
    $address = $conn->prepare('INSERT INTO customer_addresses(customer_user_id,public_id,label,recipient_name,phone,address_line1,city,latitude,longitude,is_default,version) VALUES(?,?,?,?,?,?,?,?,?,1,1)');
    $address->bind_param('issssssdd', $customerId, $publicId, $label, $name, $phone, $oldAddress, $city, $latitude, $longitude);
    $address->execute();
    $address->close();

    $resolved = ['address' => '100 Server Street, Bangkok', 'addressLine1' => '100 Server Street', 'city' => 'Bangkok'];
    $saved = savora_save_gps_location($conn, 'customer', $customerId, $resolved, 13.7563, 100.5018);
    profile_location_expect($saved['locationMethod'] === 'gps', 'Customer GPS location should be authoritative.');
    $storedAddress = $conn->query("SELECT address_line1,city,latitude,longitude,version FROM customer_addresses WHERE customer_user_id={$customerId} AND is_default=1")->fetch_assoc();
    profile_location_expect(($storedAddress['address_line1'] ?? '') === '100 Server Street', 'Customer GPS must update the checkout address.');
    profile_location_expect(($storedAddress['city'] ?? '') === 'Bangkok', 'Customer GPS must update the checkout city.');
    profile_location_expect(abs((float) ($storedAddress['latitude'] ?? 0) - 13.7563) < 0.0000001, 'Customer checkout latitude must match GPS.');
    profile_location_expect((int) ($storedAddress['version'] ?? 0) === 2, 'Customer GPS must version the checkout address.');

    $manual = savora_save_manual_location($conn, 'customer', $customerId, ['address' => 'Manual display location']);
    profile_location_expect($manual['locationMethod'] === 'manual' && $manual['latitude'] === null, 'Manual customer location must clear stale profile coordinates.');
    $checkoutAddress = $conn->query("SELECT address_line1,latitude,longitude FROM customer_addresses WHERE customer_user_id={$customerId} AND is_default=1")->fetch_assoc();
    profile_location_expect(($checkoutAddress['address_line1'] ?? '') === '100 Server Street', 'Manual display location must not forge checkout coordinates.');

    echo "PASS: Profile locations and customer checkout coordinates stay authoritative\n";
    $conn->rollback();
} catch (Throwable $exception) {
    if ($conn instanceof mysqli) $conn->rollback();
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} finally {
    if ($conn instanceof mysqli) $conn->close();
}
