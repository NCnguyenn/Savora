<?php
declare(strict_types=1);

function review_repository_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $statement = $conn->prepare($sql);
    if ($types !== '') $statement->bind_param($types, ...$params);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function review_repository_one(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    return review_repository_rows($conn, $sql, $types, $params)[0] ?? [];
}

function review_repository_customer_order(mysqli $conn, int $customerUserId, string $referenceCode, bool $forUpdate = false): array
{
    $sql = "SELECT o.id,o.reference_code,o.customer_user_id,o.restaurant_id,o.status,r.name AS restaurant_name
            FROM orders o JOIN restaurants r ON r.id=o.restaurant_id
            WHERE o.customer_user_id=? AND o.reference_code=? LIMIT 1";
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return review_repository_one($conn, $sql, 'is', [$customerUserId, $referenceCode]);
}

function review_repository_by_order(mysqli $conn, int $orderId, bool $forUpdate = false): array
{
    $sql = 'SELECT id,public_id,order_id,customer_user_id,restaurant_id,rating,comment,reply_text,reply_status,replied_at,version,created_at FROM restaurant_reviews WHERE order_id=? LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return review_repository_one($conn, $sql, 'i', [$orderId]);
}

function review_repository_for_owner(mysqli $conn, int $ownerUserId, string $publicId, bool $forUpdate = false): array
{
    $sql = 'SELECT rr.id,rr.public_id,rr.order_id,rr.customer_user_id,rr.restaurant_id,rr.rating,rr.comment,rr.reply_text,rr.reply_status,rr.replied_at,rr.version,rr.created_at
            FROM restaurant_reviews rr JOIN restaurants r ON r.id=rr.restaurant_id
            WHERE r.owner_user_id=? AND rr.public_id=? LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return review_repository_one($conn, $sql, 'is', [$ownerUserId, $publicId]);
}

function review_repository_items(mysqli $conn, int $orderId): array
{
    return review_repository_rows($conn, 'SELECT item_name,quantity FROM order_items WHERE order_id=? ORDER BY id', 'i', [$orderId]);
}

function review_repository_map(mysqli $conn, array $row): array
{
    return [
        'publicId' => (string) $row['public_id'], 'orderReference' => (string) $row['reference_code'],
        'customerName' => (string) $row['customer_name'], 'restaurantName' => (string) $row['restaurant_name'],
        'rating' => (int) $row['rating'], 'comment' => (string) $row['comment'],
        'replyText' => (string) ($row['reply_text'] ?? ''), 'replyStatus' => (string) $row['reply_status'],
        'repliedAt' => $row['replied_at'] === null ? null : (string) $row['replied_at'],
        'version' => (int) $row['version'], 'createdAt' => (string) $row['created_at'],
        'items' => array_map(static fn (array $item): array => ['name' => (string) $item['item_name'], 'quantity' => (int) $item['quantity']], review_repository_items($conn, (int) $row['order_id'])),
    ];
}

function review_repository_for_customer(mysqli $conn, int $customerUserId): array
{
    $rows = review_repository_rows(
        $conn,
        'SELECT rr.*,o.reference_code,u.full_name AS customer_name,r.name AS restaurant_name
         FROM restaurant_reviews rr JOIN orders o ON o.id=rr.order_id JOIN users u ON u.id=rr.customer_user_id JOIN restaurants r ON r.id=rr.restaurant_id
         WHERE rr.customer_user_id=? ORDER BY rr.created_at DESC,rr.id DESC',
        'i', [$customerUserId]
    );
    return array_map(fn (array $row): array => review_repository_map($conn, $row), $rows);
}

function review_repository_for_restaurant(mysqli $conn, int $ownerUserId): array
{
    $rows = review_repository_rows(
        $conn,
        'SELECT rr.*,o.reference_code,u.full_name AS customer_name,r.name AS restaurant_name
         FROM restaurant_reviews rr JOIN orders o ON o.id=rr.order_id JOIN users u ON u.id=rr.customer_user_id JOIN restaurants r ON r.id=rr.restaurant_id
         WHERE r.owner_user_id=? ORDER BY rr.created_at DESC,rr.id DESC',
        'i', [$ownerUserId]
    );
    return array_map(fn (array $row): array => review_repository_map($conn, $row), $rows);
}
