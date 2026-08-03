<?php
declare(strict_types=1);

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
    fwrite(STDERR, "BLOCKED: Admin provisioning integration tests require savora_test\n");
    exit(2);
}
putenv('SAVORA_SEED_DEMO=1');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/services/admin_provisioning_service.php';

function admin_provision_expect(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$prefix = 'auth-admin-' . bin2hex(random_bytes(5));
$normalActorId = 0;
$createdUserId = 0;
$keys = [];

try {
    $super = $conn->query("SELECT u.id FROM users u JOIN admin_profiles ap ON ap.user_id=u.id WHERE u.role='admin' AND u.status='active' AND ap.privilege_level='super_admin' ORDER BY u.id LIMIT 1")->fetch_assoc();
    $superId = (int) ($super['id'] ?? 0);
    admin_provision_expect($superId > 0, 'An active Super Admin is required.');

    $hash = password_hash('Temporary-Admin-123!', PASSWORD_DEFAULT);
    $normalUsername = $prefix . '-actor';
    $normalEmail = $prefix . '-actor@example.test';
    $statement = $conn->prepare("INSERT INTO users(username,password,role,full_name,email,phone,status) VALUES(?,?,'admin','Normal Admin',?,'+1 555 010 9000','active')");
    $statement->bind_param('sss', $normalUsername, $hash, $normalEmail); $statement->execute(); $normalActorId = (int) $statement->insert_id; $statement->close();
    $statement = $conn->prepare("INSERT INTO admin_profiles(user_id,privilege_level,created_by) VALUES(?,'admin',?)");
    $statement->bind_param('ii', $normalActorId, $superId); $statement->execute(); $statement->close();

    $username = $prefix . '-operations';
    $email = $prefix . '-operations@example.test';
    $payload = [
        'full_name' => 'Operations Admin', 'username' => $username, 'email' => $email,
        'phone' => '+1 555 010 4400', 'password' => 'Strong-Admin-123!',
        'password_confirmation' => 'Strong-Admin-123!', 'privilege_level' => 'admin',
    ];
    $forbiddenKey = 'admin-forbidden-' . bin2hex(random_bytes(5)); $keys[] = $forbiddenKey;
    $forbidden = admin_provision_account($conn, $payload, $normalActorId, $forbiddenKey);
    admin_provision_expect(($forbidden['ok'] ?? true) === false && ($forbidden['status'] ?? 0) === 403, 'A normal Admin must not create Admin accounts.');

    $createKey = 'admin-create-' . bin2hex(random_bytes(5)); $keys[] = $createKey;
    $created = admin_provision_account($conn, $payload, $superId, $createKey);
    $retry = admin_provision_account($conn, $payload, $superId, $createKey);
    admin_provision_expect(($created['ok'] ?? false) === true && ($created['status'] ?? 0) === 201 && $created === $retry, 'Super Admin creation must succeed and replay exactly.');
    $createdUserId = (int) ($created['data']['user_id'] ?? 0);
    $user = $conn->prepare('SELECT role,status,password FROM users WHERE id=?'); $user->bind_param('i', $createdUserId); $user->execute(); $userRow = $user->get_result()->fetch_assoc(); $user->close();
    admin_provision_expect(($userRow['role'] ?? '') === 'admin' && ($userRow['status'] ?? '') === 'active' && password_verify('Strong-Admin-123!', (string) $userRow['password']), 'Provisioned Admin must be active with a secure password hash.');
    $profile = $conn->prepare('SELECT privilege_level,created_by FROM admin_profiles WHERE user_id=?'); $profile->bind_param('i', $createdUserId); $profile->execute(); $profileRow = $profile->get_result()->fetch_assoc(); $profile->close();
    admin_provision_expect(($profileRow['privilege_level'] ?? '') === 'admin' && (int) $profileRow['created_by'] === $superId, 'Admin privilege and creator must be recorded.');
    $claims = $conn->prepare("SELECT COUNT(*) AS total FROM identity_claims WHERE owner_kind='user' AND owner_id=?"); $claims->bind_param('i', $createdUserId); $claims->execute(); $claimCount = (int) $claims->get_result()->fetch_assoc()['total']; $claims->close();
    admin_provision_expect($claimCount === 2, 'Provisioned Admin must own username and email claims.');

    $invalidKey = 'admin-invalid-' . bin2hex(random_bytes(5)); $keys[] = $invalidKey;
    $invalid = admin_provision_account($conn, array_merge($payload, ['username' => $prefix . '-invalid', 'email' => $prefix . '-invalid@example.test', 'privilege_level' => 'owner']), $superId, $invalidKey);
    admin_provision_expect(($invalid['ok'] ?? true) === false && ($invalid['status'] ?? 0) === 422, 'Invalid privilege must be rejected.');
    $duplicateKey = 'admin-duplicate-' . bin2hex(random_bytes(5)); $keys[] = $duplicateKey;
    $duplicate = admin_provision_account($conn, $payload, $superId, $duplicateKey);
    admin_provision_expect(($duplicate['ok'] ?? true) === false && ($duplicate['status'] ?? 0) === 409, 'Duplicate identity must return conflict.');

    echo "PASS: only Super Admin provisions idempotent active Admin accounts with profiles and identity claims\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} finally {
    if ($keys !== []) { $placeholders = implode(',', array_fill(0, count($keys), '?')); $types = str_repeat('s', count($keys)); $statement = $conn->prepare("DELETE FROM idempotency_keys WHERE idempotency_key IN ({$placeholders})"); $statement->bind_param($types, ...$keys); $statement->execute(); $statement->close(); }
    foreach ([$createdUserId, $normalActorId] as $id) if ($id > 0) {
        $conn->query("DELETE FROM identity_claims WHERE owner_kind='user' AND owner_id={$id}");
        $conn->query("DELETE FROM admin_profiles WHERE user_id={$id}");
        $conn->query("DELETE FROM users WHERE id={$id}");
    }
    $conn->close();
}
