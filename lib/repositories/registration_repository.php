<?php
declare(strict_types=1);

function registration_repository_claim(mysqli $conn, string $type, string $value, string $ownerKind, int $ownerId): void
{
    $statement = $conn->prepare('INSERT INTO identity_claims(identifier_type,normalized_value,owner_kind,owner_id) VALUES(?,?,?,?)');
    $statement->bind_param('sssi', $type, $value, $ownerKind, $ownerId);
    $statement->execute();
    $statement->close();
}

function registration_repository_transfer_claims(mysqli $conn, string $fromKind, int $fromId, string $toKind, int $toId): void
{
    $statement = $conn->prepare('UPDATE identity_claims SET owner_kind=?,owner_id=? WHERE owner_kind=? AND owner_id=?');
    $statement->bind_param('sisi', $toKind, $toId, $fromKind, $fromId);
    $statement->execute();
    $statement->close();
}

function registration_repository_release_claims(mysqli $conn, string $ownerKind, int $ownerId): void
{
    $statement = $conn->prepare('DELETE FROM identity_claims WHERE owner_kind=? AND owner_id=?');
    $statement->bind_param('si', $ownerKind, $ownerId);
    $statement->execute();
    $statement->close();
}

function registration_repository_create_user(mysqli $conn, array $data): int
{
    $role = (string) $data['role'];
    $status = (string) $data['status'];
    $statement = $conn->prepare('INSERT INTO users(username,password,role,full_name,email,phone,status) VALUES(?,?,?,?,?,?,?)');
    $statement->bind_param('sssssss', $data['username'], $data['passwordHash'], $role, $data['fullName'], $data['email'], $data['phone'], $status);
    $statement->execute();
    $id = (int) $statement->insert_id;
    $statement->close();
    return $id;
}

function registration_repository_create_customer_profile(mysqli $conn, int $userId, array $data): void
{
    $statement = $conn->prepare(
        'INSERT INTO customer_profiles(user_id,email,phone,address,default_delivery_notes) VALUES(?,?,?,?,?)'
    );
    $statement->bind_param('issss', $userId, $data['email'], $data['phone'], $data['address'], $data['notes']);
    $statement->execute();
    $statement->close();
}
