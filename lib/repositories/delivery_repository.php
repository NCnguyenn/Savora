<?php
declare(strict_types=1);

function delivery_repository_one(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $statement = $conn->prepare($sql);
    if ($types !== '') $statement->bind_param($types, ...$params);
    $statement->execute(); $row = $statement->get_result()->fetch_assoc() ?: []; $statement->close();
    return $row;
}
function delivery_repository_delivery(mysqli $conn, int $deliveryId, bool $forUpdate = false): array
{
    $sql = 'SELECT d.id,d.order_id,d.driver_user_id,d.status,d.earning,d.accepted_at,d.delivered_at,d.version,d.proof_required,d.superseded_at,d.superseded_by_delivery_id,d.failed_at,d.failure_reason,
                   o.reference_code,o.customer_user_id,o.status AS order_status,o.payment_method,o.delivery_address,o.delivery_note,o.version AS order_version
            FROM deliveries d JOIN orders o ON o.id=d.order_id WHERE d.id=? LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return delivery_repository_one($conn, $sql, 'i', [$deliveryId]);
}

function delivery_repository_driver_profile(mysqli $conn, int $driverUserId, bool $forUpdate = false): array
{
    $sql = 'SELECT dp.user_id,dp.availability_status,dp.eligibility_status,dp.version,u.status AS user_status
            FROM driver_profiles dp JOIN users u ON u.id=dp.user_id WHERE dp.user_id=? LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return delivery_repository_one($conn, $sql, 'i', [$driverUserId]);
}

function delivery_repository_location(mysqli $conn, int $driverUserId, bool $forUpdate = false): array
{
    $sql = 'SELECT driver_user_id,latitude,longitude,accuracy_meters,recorded_at,version FROM driver_locations WHERE driver_user_id=? LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return delivery_repository_one($conn, $sql, 'i', [$driverUserId]);
}

function delivery_repository_set_location(mysqli $conn, int $driverUserId, float $latitude, float $longitude, ?float $accuracy, string $recordedAt, int $expectedVersion): bool
{
    if ($expectedVersion < 1) {
        $statement = $conn->prepare('INSERT INTO driver_locations(driver_user_id,latitude,longitude,accuracy_meters,recorded_at,version) VALUES(?,?,?,?,?,1)
            ON DUPLICATE KEY UPDATE latitude=VALUES(latitude),longitude=VALUES(longitude),accuracy_meters=VALUES(accuracy_meters),recorded_at=VALUES(recorded_at),version=version+1');
        $statement->bind_param('iddds', $driverUserId, $latitude, $longitude, $accuracy, $recordedAt); $statement->execute(); $ok = $statement->affected_rows >= 1; $statement->close(); return $ok;
    }
    $statement = $conn->prepare('UPDATE driver_locations SET latitude=?,longitude=?,accuracy_meters=?,recorded_at=?,version=version+1 WHERE driver_user_id=? AND version=?');
    $statement->bind_param('dddsii', $latitude, $longitude, $accuracy, $recordedAt, $driverUserId, $expectedVersion); $statement->execute(); $ok = $statement->affected_rows === 1; $statement->close();
    return $ok;
}

function delivery_repository_update_status(mysqli $conn, int $deliveryId, string $status, int $expectedVersion, bool $setAccepted = false, bool $setDelivered = false, ?string $failureReason = null): bool
{
    $sql = 'UPDATE deliveries SET status=?,version=version+1';
    if ($setAccepted) $sql .= ',accepted_at=COALESCE(accepted_at,NOW())';
    if ($setDelivered) $sql .= ',delivered_at=NOW()';
    if ($failureReason !== null) $sql .= ',failed_at=NOW(),failure_reason=?';
    $sql .= ' WHERE id=? AND version=?';
    $statement = $conn->prepare($sql);
    if ($failureReason !== null) $statement->bind_param('ssii', $status, $failureReason, $deliveryId, $expectedVersion);
    else $statement->bind_param('sii', $status, $deliveryId, $expectedVersion);
    $statement->execute(); $ok = $statement->affected_rows === 1; $statement->close(); return $ok;
}

function delivery_repository_add_milestone(mysqli $conn, int $deliveryId, string $status, int $actorUserId, string $note = ''): int
{
    $statement = $conn->prepare('INSERT INTO delivery_milestones(delivery_id,status,actor_user_id,note) VALUES(?,?,?,?)');
    $statement->bind_param('isis', $deliveryId, $status, $actorUserId, $note); $statement->execute(); $id = (int) $statement->insert_id; $statement->close(); return $id;
}

function delivery_repository_add_evidence(mysqli $conn, int $deliveryId, int $uploaderId, array $evidence): int
{
    $statement = $conn->prepare('INSERT INTO delivery_evidence(delivery_id,evidence_type,stored_path,mime_type,size_bytes,sha256,captured_at,uploaded_by) VALUES(?,?,?,?,?,?,?,?)');
    $type = (string) $evidence['type']; $path = (string) $evidence['storedPath']; $mime = (string) $evidence['mimeType']; $size = (int) $evidence['sizeBytes']; $sha = (string) $evidence['sha256']; $capturedAt = ($evidence['capturedAt'] ?? null) === null ? null : (string) $evidence['capturedAt'];
    $statement->bind_param('isssissi', $deliveryId, $type, $path, $mime, $size, $sha, $capturedAt, $uploaderId); $statement->execute(); $id = (int) $statement->insert_id; $statement->close(); return $id;
}
