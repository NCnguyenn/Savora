<?php
declare(strict_types=1);

function partner_application_one(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $statement = $conn->prepare($sql);
    if ($types !== '') $statement->bind_param($types, ...$params);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc() ?: [];
    $statement->close();
    return $row;
}

function partner_application_existing(mysqli $conn, string $type, string $username, string $email): array
{
    $table = $type === 'driver' ? 'driver_applications' : 'restaurant_applications';
    $emailColumn = $type === 'driver' ? 'email' : 'owner_email';
    return partner_application_one($conn, "SELECT id,status,version FROM {$table} WHERE username=? OR {$emailColumn}=? LIMIT 1", 'ss', [$username, $email]);
}

function partner_application_create(mysqli $conn, string $type, array $data): int
{
    if ($type === 'driver') {
        $statement = $conn->prepare(
            "INSERT INTO driver_applications(reference_code,username,password_hash,full_name,email,phone,city,vehicle_type,vehicle_model,license_plate,service_area,vehicle_color,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'pending')"
        );
        $statement->bind_param(
            'ssssssssssss',
            $data['reference'], $data['username'], $data['passwordHash'], $data['fullName'],
            $data['email'], $data['phone'], $data['city'], $data['vehicleType'],
            $data['vehicleModel'], $data['licensePlate'], $data['serviceArea'], $data['vehicleColor']
        );
    } else {
        $statement = $conn->prepare(
            "INSERT INTO restaurant_applications(reference_code,username,password_hash,owner_name,owner_email,owner_phone,restaurant_name,cuisine,city,address,description,restaurant_phone,opens_at,closes_at,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending')"
        );
        $statement->bind_param(
            'ssssssssssssss',
            $data['reference'], $data['username'], $data['passwordHash'], $data['ownerName'],
            $data['ownerEmail'], $data['ownerPhone'], $data['restaurantName'], $data['cuisine'],
            $data['city'], $data['address'], $data['description'], $data['restaurantPhone'],
            $data['opensAt'], $data['closesAt']
        );
    }
    $statement->execute();
    $id = (int) $statement->insert_id;
    $statement->close();
    return $id;
}

function partner_application_add_document(mysqli $conn, string $type, int $applicationId, array $data): int
{
    $table = $type === 'driver' ? 'driver_application_documents' : 'restaurant_application_documents';
    $statement = $conn->prepare("INSERT INTO {$table}(application_id,document_type,stored_path,mime_type,file_size,sha256,verification_status) VALUES(?,?,?,?,?,?,'pending') ON DUPLICATE KEY UPDATE stored_path=VALUES(stored_path),mime_type=VALUES(mime_type),file_size=VALUES(file_size),sha256=VALUES(sha256),verification_status='pending'");
    $statement->bind_param('isssis', $applicationId, $data['documentType'], $data['storedPath'], $data['mimeType'], $data['fileSize'], $data['sha256']);
    $statement->execute();
    $id = (int) ($statement->insert_id ?: (partner_application_one($conn, "SELECT id FROM {$table} WHERE application_id=? AND document_type=? LIMIT 1", 'is', [$applicationId, $data['documentType']])['id'] ?? 0));
    $statement->close();
    return $id;
}
