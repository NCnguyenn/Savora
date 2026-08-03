<?php
declare(strict_types=1);

require_once __DIR__ . '/../idempotency.php';
require_once __DIR__ . '/audit_service.php';
require_once __DIR__ . '/../repositories/registration_repository.php';
require_once __DIR__ . '/../admin_security.php';

function admin_provision_result(bool $ok, int $status, string $message, array $data = [], array $errors = []): array
{
    $result = ['ok' => $ok, 'status' => $status, 'message' => $message, 'referenceId' => admin_reference_id()];
    if ($data !== []) $result['data'] = $data;
    if ($errors !== []) $result['errors'] = $errors;
    return $result;
}

function admin_provision_account(mysqli $conn, array $payload, int $actorId, string $idempotencyKey): array
{
    $authorization = $conn->prepare("SELECT u.status,ap.privilege_level FROM users u JOIN admin_profiles ap ON ap.user_id=u.id WHERE u.id=? AND u.role='admin' LIMIT 1");
    $authorization->bind_param('i', $actorId); $authorization->execute(); $actor = $authorization->get_result()->fetch_assoc(); $authorization->close();
    if (!$actor || $actor['status'] !== 'active' || $actor['privilege_level'] !== 'super_admin') return admin_provision_result(false, 403, 'Only an active Super Admin can create Admin accounts.');

    $fullName = trim((string) ($payload['full_name'] ?? ''));
    $username = mb_strtolower(trim((string) ($payload['username'] ?? '')));
    $email = mb_strtolower(trim((string) ($payload['email'] ?? '')));
    $phone = trim((string) ($payload['phone'] ?? ''));
    $password = (string) ($payload['password'] ?? '');
    $confirmation = (string) ($payload['password_confirmation'] ?? '');
    $privilege = (string) ($payload['privilege_level'] ?? '');
    if ($fullName === '' || mb_strlen($fullName) > 120 || !preg_match('/^[a-z0-9_-]{3,50}$/', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '' || mb_strlen($phone) > 40 || strlen($password) < 10 || !hash_equals($password, $confirmation) || !in_array($privilege, ['admin', 'super_admin'], true)) {
        return admin_provision_result(false, 422, 'Review the Admin account details and try again.', [], ['form' => 'All fields, matching 10-character passwords, and a valid privilege are required.']);
    }

    $hash = savora_idempotency_hash('create_admin_account', $payload);
    $conn->begin_transaction();
    try {
        $stored = savora_idempotency_find($conn, $actorId, $idempotencyKey, 'create_admin_account', $hash);
        if ($stored !== null) { $conn->commit(); return $stored; }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $userId = registration_repository_create_user($conn, ['username' => $username, 'passwordHash' => $passwordHash, 'role' => 'admin', 'fullName' => $fullName, 'email' => $email, 'phone' => $phone, 'status' => 'active']);
        $profile = $conn->prepare('INSERT INTO admin_profiles(user_id,privilege_level,created_by) VALUES(?,?,?)');
        $profile->bind_param('isi', $userId, $privilege, $actorId); $profile->execute(); $profile->close();
        registration_repository_claim($conn, 'username', $username, 'user', $userId);
        registration_repository_claim($conn, 'email', $email, 'user', $userId);
        $referenceId = admin_reference_id();
        audit_append($conn, $actorId, 'create_admin_account', 'user', $userId, null, ['role' => 'admin', 'status' => 'active', 'privilege_level' => $privilege], 'Internal Admin account provisioned', $referenceId);
        $response = ['ok' => true, 'status' => 201, 'message' => 'Admin account created and activated.', 'data' => ['user_id' => $userId, 'role' => 'admin', 'status' => 'active', 'privilege_level' => $privilege], 'referenceId' => $referenceId];
        savora_idempotency_store($conn, $actorId, $idempotencyKey, 'create_admin_account', $hash, $response);
        $conn->commit();
        return $response;
    } catch (mysqli_sql_exception $exception) {
        $conn->rollback();
        if ((int) $exception->getCode() === 1062) return admin_provision_result(false, 409, 'Username or email is already in use.');
        return admin_provision_result(false, 500, 'Admin account could not be created.');
    } catch (Throwable) {
        $conn->rollback();
        return admin_provision_result(false, 500, 'Admin account could not be created.');
    }
}
