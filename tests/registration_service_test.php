<?php
declare(strict_types=1);

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
    fwrite(STDERR, "BLOCKED: registration service tests require savora_test\n");
    exit(2);
}

require_once __DIR__ . '/../lib/services/registration_service.php';
require_once __DIR__ . '/support/test_database.php';

function registration_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$conn = savora_test_database();
$suffix = bin2hex(random_bytes(6));
$username = 'customer-' . $suffix;
$email = $username . '@example.test';
$userId = 0;
try {
    $payload = [
        'fullName' => 'Onboarding Customer',
        'username' => strtoupper($username),
        'email' => strtoupper($email),
        'phone' => '+1 555 010 2200',
        'password' => 'Strong-Customer-123!',
        'passwordConfirmation' => 'Strong-Customer-123!',
        'deliveryAddress' => '220 Test Avenue, Central City',
        'defaultDeliveryNotes' => 'Leave at reception',
        'acceptedTerms' => '1',
    ];
    $created = registration_register_customer($conn, $payload);
    registration_expect(($created['ok'] ?? false) === true && ($created['status'] ?? 0) === 201, 'Customer registration must succeed.');
    registration_expect(($created['data']['role'] ?? '') === 'customer', 'Created role must be customer.');
    $userId = (int) ($created['data']['userId'] ?? 0);
    registration_expect($userId > 0, 'Created user identifier must be returned.');

    $user = $conn->query("SELECT username,email,password,role,status FROM users WHERE id={$userId}")->fetch_assoc();
    registration_expect($user !== null, 'Customer user row must exist.');
    registration_expect($user['username'] === $username && $user['email'] === $email, 'Username and email must be normalized.');
    registration_expect($user['role'] === 'customer' && $user['status'] === 'active', 'Customer must be active immediately.');
    registration_expect(password_verify('Strong-Customer-123!', (string) $user['password']), 'Password must be hashed and verifiable.');

    $profile = $conn->query("SELECT email,phone,address,default_delivery_notes FROM customer_profiles WHERE user_id={$userId}")->fetch_assoc();
    registration_expect($profile !== null, 'Customer profile must be created atomically.');
    registration_expect($profile['address'] === '220 Test Avenue, Central City' && $profile['default_delivery_notes'] === 'Leave at reception', 'Customer profile fields must persist.');
    $claims = (int) $conn->query("SELECT COUNT(*) AS total FROM identity_claims WHERE owner_kind='user' AND owner_id={$userId}")->fetch_assoc()['total'];
    registration_expect($claims === 2, 'Customer must own username and email claims.');

    $duplicate = registration_register_customer($conn, $payload);
    registration_expect(($duplicate['ok'] ?? true) === false && ($duplicate['status'] ?? 0) === 409, 'Duplicate identity must return 409.');
    $sameUsers = (int) $conn->query("SELECT COUNT(*) AS total FROM users WHERE username='{$username}'")->fetch_assoc()['total'];
    registration_expect($sameUsers === 1, 'Duplicate registration must not create another user.');

    $mismatch = $payload;
    $mismatch['username'] = 'mismatch-' . $suffix;
    $mismatch['email'] = 'mismatch-' . $suffix . '@example.test';
    $mismatch['passwordConfirmation'] = 'Different-Password-123!';
    $invalid = registration_register_customer($conn, $mismatch);
    registration_expect(($invalid['ok'] ?? true) === false && ($invalid['status'] ?? 0) === 422, 'Password mismatch must return 422.');
    $invalidCount = (int) $conn->query("SELECT COUNT(*) AS total FROM users WHERE username='mismatch-{$suffix}'")->fetch_assoc()['total'];
    registration_expect($invalidCount === 0, 'Invalid registration must not create partial data.');

    echo "PASS: Customer registration is atomic, normalized, active, and duplicate-safe\n";
} finally {
    if ($userId > 0) {
        $conn->query("DELETE FROM identity_claims WHERE owner_kind='user' AND owner_id={$userId}");
        $conn->query("DELETE FROM customer_profiles WHERE user_id={$userId}");
        $conn->query("DELETE FROM users WHERE id={$userId}");
    }
    $conn->close();
}
