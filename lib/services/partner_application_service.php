<?php
declare(strict_types=1);

require_once __DIR__ . '/../repositories/partner_application_repository.php';
require_once __DIR__ . '/../repositories/registration_repository.php';
require_once __DIR__ . '/media_service.php';

function partner_result(bool $ok, int $status, string $message, array $data = []): array
{
    $result = ['ok' => $ok, 'status' => $status, 'message' => $message];
    if ($data !== []) $result['data'] = $data;
    return $result;
}

function partner_text(mixed $value, int $max, string $field, bool $required = true): string
{
    $text = trim((string) $value);
    if (($required && $text === '') || mb_strlen($text) > $max) throw new InvalidArgumentException("{$field} is invalid.");
    return $text;
}

function partner_normalize_identifier(string $value): string
{
    return mb_strtolower(trim($value));
}

function partner_application_submit_payload(string $type, array $input): array
{
    if (!in_array($type, ['driver', 'restaurant'], true)) throw new InvalidArgumentException('Application type is invalid.');
    $username = partner_normalize_identifier(partner_text($input['username'] ?? '', 50, 'Username'));
    if (!preg_match('/^[a-z0-9_-]{3,50}$/', $username)) throw new InvalidArgumentException('Username may contain letters, numbers, underscores, and hyphens only.');
    $email = partner_normalize_identifier(partner_text($input['email'] ?? ($input['ownerEmail'] ?? ''), 190, 'Email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Email is invalid.');
    $password = (string) ($input['password'] ?? '');
    if (strlen($password) < 10) throw new InvalidArgumentException('Password must contain at least 10 characters.');
    if (!hash_equals($password, (string) ($input['passwordConfirmation'] ?? ''))) throw new InvalidArgumentException('Password confirmation does not match.');
    if (!filter_var($input['acceptedTerms'] ?? false, FILTER_VALIDATE_BOOL)) throw new InvalidArgumentException('You must accept the partner terms.');

    $data = [
        'reference' => strtoupper(($type === 'driver' ? 'DA-' : 'RA-') . date('Ymd') . '-' . bin2hex(random_bytes(4))),
        'username' => $username,
        'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
    ];
    if ($type === 'driver') {
        $serviceArea = partner_text($input['serviceArea'] ?? ($input['operatingArea'] ?? ''), 160, 'Operating area');
        $data += [
            'fullName' => partner_text($input['fullName'] ?? '', 120, 'Full name'),
            'email' => $email,
            'phone' => partner_text($input['phone'] ?? '', 40, 'Phone number'),
            'city' => partner_text($input['city'] ?? $serviceArea, 100, 'City'),
            'vehicleType' => partner_text($input['vehicleType'] ?? '', 80, 'Vehicle type'),
            'vehicleModel' => partner_text($input['vehicleModel'] ?? '', 100, 'Vehicle model'),
            'licensePlate' => partner_text($input['licensePlate'] ?? '', 40, 'License plate'),
            'serviceArea' => $serviceArea,
            'vehicleColor' => partner_text($input['vehicleColor'] ?? '', 80, 'Vehicle color', false),
        ];
    } else {
        $opensAt = partner_text($input['opensAt'] ?? '', 5, 'Opening time');
        $closesAt = partner_text($input['closesAt'] ?? '', 5, 'Closing time');
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $opensAt) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $closesAt) || $opensAt === $closesAt) {
            throw new InvalidArgumentException('Opening and closing times are invalid.');
        }
        $data += [
            'ownerName' => partner_text($input['ownerName'] ?? ($input['fullName'] ?? ''), 120, 'Owner name'),
            'ownerEmail' => $email,
            'ownerPhone' => partner_text($input['phone'] ?? '', 40, 'Phone number'),
            'restaurantName' => partner_text($input['restaurantName'] ?? '', 160, 'Restaurant name'),
            'description' => partner_text($input['description'] ?? '', 1000, 'Restaurant description', false),
            'cuisine' => partner_text($input['cuisine'] ?? '', 100, 'Cuisine type'),
            'city' => partner_text($input['city'] ?? '', 100, 'City'),
            'address' => partner_text($input['address'] ?? '', 500, 'Address'),
            'restaurantPhone' => partner_text($input['restaurantPhone'] ?? '', 40, 'Restaurant phone number'),
            'opensAt' => $opensAt,
            'closesAt' => $closesAt,
        ];
    }
    return $data;
}

function partner_submit_application(mysqli $conn, string $type, array $input, array $files = []): array
{
    $storedLogoPath = '';
    try {
        $data = partner_application_submit_payload($type, $input);
        $email = $type === 'driver' ? $data['email'] : $data['ownerEmail'];
        $ownerKind = $type . '_application';
        $conn->begin_transaction();
        $id = partner_application_create($conn, $type, $data);
        registration_repository_claim($conn, 'username', $data['username'], $ownerKind, $id);
        registration_repository_claim($conn, 'email', $email, $ownerKind, $id);

        $logo = [];
        if ($type === 'restaurant' && is_array($files['logo'] ?? null)) {
            $logo = media_store_restaurant_logo($conn, $files['logo'], $ownerKind, $id);
            if ($logo !== []) {
                $stored = partner_application_one($conn, 'SELECT stored_path FROM media_assets WHERE id=?', 'i', [(int) $logo['id']]);
                $storedLogoPath = (string) ($stored['stored_path'] ?? '');
            }
        }
        $conn->commit();
        $response = ['applicationId' => $id, 'referenceCode' => $data['reference'], 'status' => 'pending', 'role' => $type];
        if ($logo !== []) $response['logo'] = $logo;
        return partner_result(true, 201, 'Application submitted. An Admin must approve it before you can sign in.', $response);
    } catch (Throwable $exception) {
        try { $conn->rollback(); } catch (Throwable) {}
        if ($storedLogoPath !== '') {
            try { media_delete_file($storedLogoPath); } catch (Throwable) {}
        }
        if ($exception instanceof mysqli_sql_exception && (int) $exception->getCode() === 1062) {
            return partner_result(false, 409, 'Username or email is already in use.');
        }
        if ($exception instanceof InvalidArgumentException) return partner_result(false, 422, $exception->getMessage());
        return partner_result(false, 500, 'The application could not be submitted. Please try again.');
    }
}

function partner_add_document_metadata(mysqli $conn, int $applicationId, string $documentType, string $storedPath, string $mimeType, int $sizeBytes, string $sha256): array
{
    try {
        if ($applicationId <= 0 || !preg_match('/^[A-Za-z0-9_-]{2,80}$/', $documentType) || preg_match('#(^|[\\/])\.\.([\\/]|$)#', $storedPath) || str_starts_with($storedPath, '/') || preg_match('/^[A-Za-z]:/i', $storedPath)) throw new InvalidArgumentException('Document metadata is invalid.');
        if (!in_array(strtolower($mimeType), ['application/pdf', 'image/jpeg', 'image/png'], true) || $sizeBytes <= 0 || $sizeBytes > 10 * 1024 * 1024 || !preg_match('/^[a-f0-9]{64}$/i', $sha256)) throw new InvalidArgumentException('Document type, size or hash is invalid.');
        $application = partner_application_one($conn, 'SELECT "driver" AS type,status FROM driver_applications WHERE id=? UNION ALL SELECT "restaurant" AS type,status FROM restaurant_applications WHERE id=?', 'ii', [$applicationId, $applicationId]);
        if ($application === [] || !in_array((string) $application['status'], ['pending', 'changes_requested'], true)) throw new RuntimeException('Application is not accepting documents.');
        $conn->begin_transaction();
        partner_application_add_document($conn, (string) $application['type'], $applicationId, ['documentType' => $documentType, 'storedPath' => $storedPath, 'mimeType' => strtolower($mimeType), 'fileSize' => $sizeBytes, 'sha256' => strtolower($sha256)]);
        $conn->commit();
        return partner_result(true, 201, 'Document metadata added.', ['applicationId' => $applicationId, 'documentType' => $documentType, 'sha256' => strtolower($sha256)]);
    } catch (Throwable $exception) {
        try { $conn->rollback(); } catch (Throwable) {}
        return partner_result(false, $exception instanceof InvalidArgumentException ? 422 : 409, $exception->getMessage());
    }
}
