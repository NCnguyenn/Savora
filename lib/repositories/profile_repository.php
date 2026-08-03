<?php
declare(strict_types=1);

function profile_repository_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $statement = $conn->prepare($sql);
    if ($types !== '') {
        $statement->bind_param($types, ...$params);
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function profile_repository_one(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    return profile_repository_rows($conn, $sql, $types, $params)[0] ?? [];
}

function profile_repository_customer(mysqli $conn, int $userId, bool $forUpdate = false): array
{
    $sql = "SELECT u.id AS user_id,u.full_name,u.status,
                   p.email,p.phone,p.wallet_balance,COALESCE(p.version,0) AS profile_version
            FROM users u LEFT JOIN customer_profiles p ON p.user_id=u.id
            WHERE u.id=? AND u.role='customer' LIMIT 1";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    return profile_repository_one($conn, $sql, 'i', [$userId]);
}

function profile_repository_addresses(mysqli $conn, int $userId): array
{
    return profile_repository_rows(
        $conn,
        'SELECT public_id,label,recipient_name,phone,address_line1,address_line2,city,region,postal_code,latitude,longitude,is_default,version
         FROM customer_addresses WHERE customer_user_id=? ORDER BY is_default DESC,updated_at DESC,id DESC',
        'i',
        [$userId]
    );
}

function profile_repository_address(mysqli $conn, int $userId, string $publicId, bool $forUpdate = false): array
{
    $sql = 'SELECT id,customer_user_id,public_id,is_default,version FROM customer_addresses WHERE customer_user_id=? AND public_id=? LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    return profile_repository_one($conn, $sql, 'is', [$userId, $publicId]);
}

function profile_repository_favorites(mysqli $conn, int $userId): array
{
    return profile_repository_rows(
        $conn,
        'SELECT favorite_type,entity_public_id,created_at FROM customer_favorites WHERE customer_user_id=? ORDER BY created_at DESC,id DESC',
        'i',
        [$userId]
    );
}

function profile_repository_wallet_transactions(mysqli $conn, int $userId): array
{
    return profile_repository_rows(
        $conn,
        'SELECT id,type,amount,description,created_at FROM wallet_transactions WHERE customer_user_id=? ORDER BY created_at DESC,id DESC LIMIT 50',
        'i',
        [$userId]
    );
}

function profile_repository_favorite_entity_exists(mysqli $conn, string $type, string $publicId): bool
{
    if ($type === 'product') {
        return profile_repository_one(
            $conn,
            "SELECT m.id FROM menu_items m JOIN restaurants r ON r.id=m.restaurant_id WHERE m.public_id=? AND m.is_available=1 AND r.status='active' LIMIT 1",
            's',
            [$publicId]
        ) !== [];
    }
    if (!ctype_digit($publicId) || (int) $publicId <= 0) {
        return false;
    }
    return profile_repository_one($conn, "SELECT id FROM restaurants WHERE id=? AND status='active' LIMIT 1", 'i', [(int) $publicId]) !== [];
}

function profile_repository_snapshot(mysqli $conn, int $userId): array
{
    $profile = profile_repository_customer($conn, $userId);
    if ($profile === [] || (string) ($profile['status'] ?? '') !== 'active') {
        return [];
    }
    return [
        'profile' => [
            'fullName' => (string) $profile['full_name'],
            'email' => (string) ($profile['email'] ?? ''),
            'phone' => (string) ($profile['phone'] ?? ''),
            'walletBalance' => (float) ($profile['wallet_balance'] ?? 0),
            'version' => (int) $profile['profile_version'],
        ],
        'wallet' => [
            'balance' => (float) ($profile['wallet_balance'] ?? 0),
            'transactions' => array_map(static fn (array $row): array => [
                'id' => (string) $row['id'], 'kind' => (string) $row['type'] === 'credit' ? 'credit' : 'debit',
                'amount' => (float) $row['amount'], 'label' => (string) $row['description'], 'createdAt' => (string) $row['created_at'],
            ], profile_repository_wallet_transactions($conn, $userId)),
        ],
        'addresses' => array_map(static fn (array $row): array => [
            'publicId' => (string) $row['public_id'], 'label' => (string) $row['label'],
            'recipientName' => (string) $row['recipient_name'], 'phone' => (string) $row['phone'],
            'addressLine1' => (string) $row['address_line1'], 'addressLine2' => (string) ($row['address_line2'] ?? ''),
            'city' => (string) $row['city'], 'region' => (string) ($row['region'] ?? ''), 'postalCode' => (string) ($row['postal_code'] ?? ''),
            'latitude' => (float) $row['latitude'], 'longitude' => (float) $row['longitude'],
            'isDefault' => (bool) $row['is_default'], 'version' => (int) $row['version'],
        ], profile_repository_addresses($conn, $userId)),
        'favorites' => array_map(static fn (array $row): array => [
            'type' => (string) $row['favorite_type'], 'publicId' => (string) $row['entity_public_id'],
        ], profile_repository_favorites($conn, $userId)),
    ];
}

function profile_repository_driver(mysqli $conn, int $userId, bool $forUpdate = false): array
{
    $sql = "SELECT u.id AS user_id,u.full_name,u.email,u.phone,u.status,u.role,
                   dp.city,dp.vehicle_type,dp.vehicle_model,dp.license_plate,dp.vehicle_color,dp.service_area,
                   dp.eligibility_status,dp.availability_status,dp.rating,dp.acceptance_rate,dp.completion_rate,dp.cod_balance,
                   dp.preferences_json,dp.version
            FROM users u JOIN driver_profiles dp ON dp.user_id=u.id
            WHERE u.id=? AND u.role='driver' LIMIT 1";
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return profile_repository_one($conn, $sql, 'i', [$userId]);
}

function profile_repository_driver_documents(mysqli $conn, int $userId): array
{
    return profile_repository_rows($conn, 'SELECT document_type,verification_status,expires_at,reviewer_note,version FROM driver_documents WHERE driver_user_id=? ORDER BY document_type ASC', 'i', [$userId]);
}

function profile_repository_driver_change(mysqli $conn, int $userId, string $publicId, bool $forUpdate = false): array
{
    $sql = 'SELECT id,public_id,driver_user_id,change_type,requested_json,status,version FROM driver_change_requests WHERE driver_user_id=? AND public_id=? LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return profile_repository_one($conn, $sql, 'is', [$userId, $publicId]);
}
