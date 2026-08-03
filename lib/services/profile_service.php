<?php
declare(strict_types=1);

require_once __DIR__ . '/../idempotency.php';
require_once __DIR__ . '/../repositories/profile_repository.php';
require_once __DIR__ . '/../repositories/registration_repository.php';

function profile_error(int $status, string $message, array $errors = []): array
{
    $result = ['ok' => false, 'status' => $status, 'message' => $message];
    if ($errors !== []) $result['errors'] = $errors;
    return $result;
}

function profile_success(array $data, string $message = 'Profile operation completed.'): array
{
    return ['ok' => true, 'status' => 200, 'message' => $message, 'data' => $data];
}

function profile_text(mixed $value, int $maximum, string $field, bool $required = false): string
{
    $text = trim((string) $value);
    if (($required && $text === '') || mb_strlen($text) > $maximum) {
        throw new InvalidArgumentException("{$field} is invalid.");
    }
    return $text;
}

function profile_public_id(mixed $value, string $field = 'Public identifier'): string
{
    $id = trim((string) $value);
    if (!preg_match('/^[A-Za-z0-9_-]{1,60}$/', $id)) {
        throw new InvalidArgumentException("{$field} is invalid.");
    }
    return $id;
}

function profile_for_user(mysqli $conn, int $userId): array
{
    $snapshot = profile_repository_snapshot($conn, $userId);
    return $snapshot === [] ? profile_error(404, 'Customer profile was not found.') : profile_success($snapshot);
}

function profile_update_customer_mutation(mysqli $conn, int $userId, array $input, int $expectedVersion): array
{
    try {
        $fullName = profile_text($input['fullName'] ?? '', 120, 'Full name', true);
        $email = profile_text($input['email'] ?? '', 190, 'Email address', true);
        $phone = profile_text($input['phone'] ?? '', 40, 'Phone number');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return profile_error(422, 'Email address is invalid.');
        $current = profile_repository_customer($conn, $userId, true);
        if ($current === [] || (string) $current['status'] !== 'active') return profile_error(403, 'Customer ownership could not be verified.');
        $version = (int) $current['profile_version'];
        if ($version === 0) {
            if ($expectedVersion !== 0) return profile_error(409, 'Customer profile version is stale.');
            $insert = $conn->prepare('INSERT INTO customer_profiles(user_id,email,phone,address,wallet_balance,version) VALUES(?,?,?,NULL,0,1)');
            $insert->bind_param('iss', $userId, $email, $phone);
            $insert->execute();
            $insert->close();
            $nextVersion = 1;
        } else {
            if ($version !== $expectedVersion) return profile_error(409, 'Customer profile version is stale.');
            $update = $conn->prepare('UPDATE customer_profiles SET email=?,phone=?,version=version+1 WHERE user_id=? AND version=?');
            $update->bind_param('ssii', $email, $phone, $userId, $expectedVersion);
            $update->execute();
            $affected = $update->affected_rows;
            $update->close();
            if ($affected !== 1) return profile_error(409, 'Customer profile changed. Refresh before retrying.');
            $nextVersion = $expectedVersion + 1;
        }
        $user = $conn->prepare("UPDATE users SET full_name=?,version=version+1 WHERE id=? AND role='customer' AND status='active'");
        $user->bind_param('si', $fullName, $userId);
        $user->execute();
        $user->close();
        return profile_success(['fullName' => $fullName, 'email' => $email, 'phone' => $phone, 'version' => $nextVersion], 'Customer profile saved.');
    } catch (InvalidArgumentException $exception) {
        return profile_error(422, $exception->getMessage());
    }
}

function profile_save_address_mutation(mysqli $conn, int $userId, array $input, int $expectedVersion): array
{
    try {
        $publicId = profile_public_id($input['publicId'] ?? '', 'Address identifier');
        $label = profile_text($input['label'] ?? '', 80, 'Address label', true);
        $recipient = profile_text($input['recipientName'] ?? '', 120, 'Recipient name', true);
        $phone = profile_text($input['phone'] ?? '', 40, 'Address phone', true);
        $line1 = profile_text($input['addressLine1'] ?? '', 200, 'Address line 1', true);
        $line2 = profile_text($input['addressLine2'] ?? '', 200, 'Address line 2');
        $city = profile_text($input['city'] ?? '', 100, 'City', true);
        $region = profile_text($input['region'] ?? '', 100, 'Region');
        $postalCode = profile_text($input['postalCode'] ?? '', 30, 'Postal code');
        $latitude = filter_var($input['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($input['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return profile_error(422, 'Valid address coordinates are required.');
        }
        $owner = profile_repository_customer($conn, $userId, true);
        if ($owner === [] || (string) $owner['status'] !== 'active') return profile_error(403, 'Customer ownership could not be verified.');
        $existing = profile_repository_address($conn, $userId, $publicId, true);
        if ($existing === [] && $expectedVersion !== 0) return profile_error(409, 'Address version is stale.');
        if ($existing !== [] && (int) $existing['version'] !== $expectedVersion) return profile_error(409, 'Address version is stale.');
        $count = profile_repository_one($conn, 'SELECT COUNT(*) AS total FROM customer_addresses WHERE customer_user_id=?', 'i', [$userId]);
        $isDefault = ($input['isDefault'] ?? false) === true || (int) ($count['total'] ?? 0) === 0;
        if ($isDefault) {
            $clear = $conn->prepare('UPDATE customer_addresses SET is_default=0 WHERE customer_user_id=?');
            $clear->bind_param('i', $userId); $clear->execute(); $clear->close();
        }
        $defaultValue = $isDefault ? 1 : 0;
        if ($existing === []) {
            $save = $conn->prepare('INSERT INTO customer_addresses(customer_user_id,public_id,label,recipient_name,phone,address_line1,address_line2,city,region,postal_code,latitude,longitude,is_default,version) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,1)');
            $save->bind_param('isssssssssddi', $userId, $publicId, $label, $recipient, $phone, $line1, $line2, $city, $region, $postalCode, $latitude, $longitude, $defaultValue);
            $nextVersion = 1;
        } else {
            $addressId = (int) $existing['id'];
            $save = $conn->prepare('UPDATE customer_addresses SET label=?,recipient_name=?,phone=?,address_line1=?,address_line2=?,city=?,region=?,postal_code=?,latitude=?,longitude=?,is_default=?,version=version+1 WHERE id=? AND customer_user_id=? AND version=?');
            $save->bind_param('ssssssssddiiii', $label, $recipient, $phone, $line1, $line2, $city, $region, $postalCode, $latitude, $longitude, $defaultValue, $addressId, $userId, $expectedVersion);
            $nextVersion = $expectedVersion + 1;
        }
        $save->execute(); $affected = $save->affected_rows; $save->close();
        if ($affected !== 1) return profile_error(409, 'Address changed. Refresh before retrying.');
        return profile_success(['publicId' => $publicId, 'version' => $nextVersion, 'isDefault' => $isDefault], 'Address saved.');
    } catch (InvalidArgumentException $exception) {
        return profile_error(422, $exception->getMessage());
    }
}

function favorite_set_mutation(mysqli $conn, int $userId, array $input): array
{
    try {
        $type = trim((string) ($input['type'] ?? ''));
        $publicId = profile_public_id($input['publicId'] ?? '', 'Favorite identifier');
        if (!in_array($type, ['product', 'restaurant'], true)) return profile_error(422, 'Favorite type is invalid.');
        $owner = profile_repository_customer($conn, $userId, true);
        if ($owner === [] || (string) $owner['status'] !== 'active') return profile_error(403, 'Customer ownership could not be verified.');
        if (!profile_repository_favorite_entity_exists($conn, $type, $publicId)) return profile_error(404, 'Favorite target was not found.');
        $active = ($input['active'] ?? true) !== false;
        if ($active) {
            $save = $conn->prepare('INSERT IGNORE INTO customer_favorites(customer_user_id,favorite_type,entity_public_id) VALUES(?,?,?)');
        } else {
            $save = $conn->prepare('DELETE FROM customer_favorites WHERE customer_user_id=? AND favorite_type=? AND entity_public_id=?');
        }
        $save->bind_param('iss', $userId, $type, $publicId); $save->execute(); $save->close();
        return profile_success(['type' => $type, 'publicId' => $publicId, 'active' => $active], 'Favorite preference saved.');
    } catch (InvalidArgumentException $exception) {
        return profile_error(422, $exception->getMessage());
    }
}

function profile_transaction(mysqli $conn, callable $operation): array
{
    $conn->begin_transaction();
    try { $result = $operation(); $conn->commit(); return $result; }
    catch (Throwable $exception) { $conn->rollback(); return profile_error(500, 'Profile operation could not be completed.'); }
}

function profile_update_customer(mysqli $conn, int $userId, array $input, int $expectedVersion): array
{ return profile_transaction($conn, fn (): array => profile_update_customer_mutation($conn, $userId, $input, $expectedVersion)); }
function profile_save_address(mysqli $conn, int $userId, array $input, int $expectedVersion): array
{ return profile_transaction($conn, fn (): array => profile_save_address_mutation($conn, $userId, $input, $expectedVersion)); }
function favorite_set(mysqli $conn, int $userId, array $input): array
{ return profile_transaction($conn, fn (): array => favorite_set_mutation($conn, $userId, $input)); }

function profile_execute_action(mysqli $conn, int $userId, string $action, array $payload, int $expectedVersion, string $idempotencyKey): array
{
    $conn->begin_transaction();
    try {
        $result = match ($action) {
            'update_profile' => profile_update_customer_mutation($conn, $userId, $payload, $expectedVersion),
            'save_address' => profile_save_address_mutation($conn, $userId, $payload, $expectedVersion),
            'set_favorite' => favorite_set_mutation($conn, $userId, $payload),
            default => profile_error(422, 'Unsupported profile action.'),
        };
        savora_idempotency_store($conn, $userId, $idempotencyKey, $action, savora_idempotency_hash($action, $payload), $result);
        $conn->commit();
        return $result;
    } catch (Throwable $exception) {
        $conn->rollback();
        return profile_error(500, 'Profile operation could not be completed.');
    }
}

function profile_driver_preferences(array $row): array
{
    $defaults = ['newOffers' => true, 'soundAlerts' => true, 'cashOnDelivery' => true, 'avoidHighways' => false];
    $stored = json_decode((string) ($row['preferences_json'] ?? ''), true);
    if (is_array($stored)) foreach ($defaults as $key => $value) $defaults[$key] = ($stored[$key] ?? $value) === true;
    return $defaults;
}

function profile_for_driver(mysqli $conn, int $userId): array
{
    $driver = profile_repository_driver($conn, $userId);
    if ($driver === [] || (string) $driver['status'] !== 'active') return profile_error(404, 'Driver profile was not found.');
    $requests = profile_repository_rows($conn, "SELECT public_id,change_type,status,version,created_at FROM driver_change_requests WHERE driver_user_id=? ORDER BY created_at DESC,id DESC LIMIT 20", 'i', [$userId]);
    return profile_success([
        'profile' => [
            'id' => (int) $driver['user_id'], 'fullName' => (string) $driver['full_name'], 'email' => (string) ($driver['email'] ?? ''), 'phone' => (string) ($driver['phone'] ?? ''),
            'eligibilityStatus' => (string) $driver['eligibility_status'], 'availabilityStatus' => (string) $driver['availability_status'],
            'rating' => (float) $driver['rating'], 'acceptanceRate' => (float) $driver['acceptance_rate'], 'completionRate' => (float) $driver['completion_rate'], 'codBalance' => (float) $driver['cod_balance'], 'version' => (int) $driver['version'],
        ],
        'vehicle' => ['type' => (string) ($driver['vehicle_type'] ?? ''), 'model' => (string) ($driver['vehicle_model'] ?? ''), 'licensePlate' => (string) ($driver['license_plate'] ?? ''), 'color' => (string) ($driver['vehicle_color'] ?? ''), 'serviceArea' => (string) ($driver['service_area'] ?? '')],
        'documents' => array_map(static fn (array $row): array => ['type' => (string) $row['document_type'], 'status' => (string) $row['verification_status'], 'expiresAt' => $row['expires_at'] === null ? null : (string) $row['expires_at'], 'note' => (string) ($row['reviewer_note'] ?? ''), 'version' => (int) $row['version']], profile_repository_driver_documents($conn, $userId)),
        'preferences' => profile_driver_preferences($driver), 'changeRequests' => array_map(static fn (array $row): array => ['publicId' => (string) $row['public_id'], 'type' => (string) $row['change_type'], 'status' => (string) $row['status'], 'version' => (int) $row['version'], 'createdAt' => (string) $row['created_at']], $requests),
    ]);
}

function profile_driver_owner(mysqli $conn, int $userId, bool $forUpdate = true): array
{
    $driver = profile_repository_driver($conn, $userId, $forUpdate);
    if ($driver === [] || (string) $driver['status'] !== 'active') return profile_error(403, 'Driver ownership could not be verified.');
    return $driver;
}

function profile_update_driver_contact_mutation(mysqli $conn, int $userId, array $input, int $expectedVersion): array
{
    foreach (['eligibilityStatus', 'rating', 'acceptanceRate', 'completionRate', 'codBalance', 'availabilityStatus', 'documents', 'activeDelivery'] as $protected) if (array_key_exists($protected, $input)) return profile_error(422, 'Driver identity and operational fields are Admin-controlled.');
    $driver = profile_driver_owner($conn, $userId);
    if (isset($driver['status']) && isset($driver['ok'])) return $driver;
    $version = (int) $driver['version']; if ($version !== $expectedVersion) return profile_error(409, 'Driver profile version is stale.');
    try {
        $fullName = profile_text($input['fullName'] ?? $driver['full_name'], 120, 'Full name', true);
        $email = mb_strtolower(profile_text($input['email'] ?? ($driver['email'] ?? ''), 190, 'Email address', true));
        $phone = profile_text($input['phone'] ?? ($driver['phone'] ?? ''), 40, 'Phone number');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return profile_error(422, 'Email address is invalid.');
        registration_repository_replace_claim($conn, 'email', $email, 'user', $userId);
        $update = $conn->prepare('UPDATE driver_profiles SET version=version+1 WHERE user_id=? AND version=?'); $update->bind_param('ii', $userId, $expectedVersion); $update->execute(); $affected = $update->affected_rows; $update->close();
        if ($affected !== 1) return profile_error(409, 'Driver profile changed. Refresh before retrying.');
        $user = $conn->prepare("UPDATE users SET full_name=?,email=?,phone=?,version=version+1 WHERE id=? AND role='driver' AND status='active'"); $user->bind_param('sssi', $fullName, $email, $phone, $userId); $user->execute(); $user->close();
        return profile_success(['fullName' => $fullName, 'email' => $email, 'phone' => $phone, 'version' => $expectedVersion + 1], 'Driver contact saved.');
    } catch (InvalidArgumentException $exception) { return profile_error(422, $exception->getMessage()); }
    catch (mysqli_sql_exception $exception) { return profile_error((int) $exception->getCode() === 1062 ? 409 : 500, (int) $exception->getCode() === 1062 ? 'Email address is already in use.' : 'Driver contact could not be saved.'); }
}

function profile_update_driver_contact(mysqli $conn, int $userId, array $input, int $expectedVersion): array
{ return profile_transaction($conn, fn (): array => profile_update_driver_contact_mutation($conn, $userId, $input, $expectedVersion)); }

function profile_update_driver_vehicle_request_mutation(mysqli $conn, int $userId, array $input, int $expectedVersion): array
{
    $driver = profile_driver_owner($conn, $userId); if (isset($driver['ok'])) return $driver;
    if ((int) $driver['version'] !== $expectedVersion) return profile_error(409, 'Driver profile version is stale.');
    $allowed = ['vehicleType' => 'vehicleType', 'vehicleModel' => 'vehicleModel', 'licensePlate' => 'licensePlate', 'vehicleColor' => 'vehicleColor', 'serviceArea' => 'serviceArea']; $requested = [];
    foreach ($allowed as $field => $key) if (array_key_exists($field, $input)) $requested[$key] = profile_text($input[$field], 120, $field);
    if ($requested === []) return profile_error(422, 'At least one vehicle field is required.');
    $publicId = 'DCR-' . strtoupper(bin2hex(random_bytes(7))); $json = json_encode($requested, JSON_THROW_ON_ERROR);
    $insert = $conn->prepare("INSERT INTO driver_change_requests(public_id,driver_user_id,change_type,requested_json,status,version) VALUES(?,?, 'vehicle', ?, 'pending', 1)"); $insert->bind_param('sis', $publicId, $userId, $json); $insert->execute(); $insert->close();
    return profile_success(['publicId' => $publicId, 'reviewStatus' => 'pending', 'version' => $expectedVersion], 'Vehicle change submitted for review.');
}

function profile_update_driver_vehicle_request(mysqli $conn, int $userId, array $input, int $expectedVersion): array
{ return profile_transaction($conn, fn (): array => profile_update_driver_vehicle_request_mutation($conn, $userId, $input, $expectedVersion)); }

function profile_update_driver_preferences_mutation(mysqli $conn, int $userId, array $input, int $expectedVersion): array
{
    $driver = profile_driver_owner($conn, $userId); if (isset($driver['ok'])) return $driver;
    if ((int) $driver['version'] !== $expectedVersion) return profile_error(409, 'Driver profile version is stale.');
    $preferences = profile_driver_preferences($driver); foreach (array_keys($preferences) as $key) if (array_key_exists($key, $input)) $preferences[$key] = $input[$key] === true;
    $json = json_encode($preferences, JSON_THROW_ON_ERROR); $update = $conn->prepare('UPDATE driver_profiles SET preferences_json=?,version=version+1 WHERE user_id=? AND version=?'); $update->bind_param('sii', $json, $userId, $expectedVersion); $update->execute(); $affected = $update->affected_rows; $update->close();
    if ($affected !== 1) return profile_error(409, 'Driver preferences changed. Refresh before retrying.');
    return profile_success(['preferences' => $preferences, 'version' => $expectedVersion + 1], 'Driver preferences saved.');
}

function profile_update_driver_preferences(mysqli $conn, int $userId, array $input, int $expectedVersion): array
{ return profile_transaction($conn, fn (): array => profile_update_driver_preferences_mutation($conn, $userId, $input, $expectedVersion)); }

function profile_execute_driver_action(mysqli $conn, int $userId, string $action, array $payload, int $expectedVersion, string $idempotencyKey): array
{
    $conn->begin_transaction();
    try {
        $result = match ($action) {
            'update_driver_contact' => profile_update_driver_contact_mutation($conn, $userId, $payload, $expectedVersion),
            'request_driver_vehicle_change' => profile_update_driver_vehicle_request_mutation($conn, $userId, $payload, $expectedVersion),
            'update_driver_preferences' => profile_update_driver_preferences_mutation($conn, $userId, $payload, $expectedVersion),
            default => profile_error(422, 'Unsupported Driver profile action.'),
        };
        savora_idempotency_store($conn, $userId, $idempotencyKey, $action, savora_idempotency_hash($action, $payload), $result);
        $conn->commit(); return $result;
    } catch (Throwable $exception) { $conn->rollback(); return profile_error(500, 'Driver profile operation could not be completed.'); }
}
