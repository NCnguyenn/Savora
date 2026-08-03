<?php
declare(strict_types=1);

function pricing_repository_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $statement = $conn->prepare($sql);
    if ($types !== '') $statement->bind_param($types, ...$params);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function pricing_repository_one(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    return pricing_repository_rows($conn, $sql, $types, $params)[0] ?? [];
}

function pricing_repository_address_for_customer(mysqli $conn, int $customerUserId, string $publicId, bool $forUpdate = false): array
{
    $sql = 'SELECT a.id,a.public_id,a.customer_user_id,a.city,a.region,a.latitude,a.longitude,a.version
            FROM customer_addresses a JOIN users u ON u.id=a.customer_user_id
            WHERE a.customer_user_id=? AND a.public_id=? AND u.role=\'customer\' AND u.status=\'active\' LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return pricing_repository_one($conn, $sql, 'is', [$customerUserId, $publicId]);
}

function pricing_repository_menu_item(mysqli $conn, string $publicId, bool $forUpdate = false): array
{
    $sql = 'SELECT m.id,m.public_id,m.name,m.price,m.restaurant_id,m.is_available,m.version,
                   r.name AS restaurant_name,r.city AS restaurant_city,r.status AS restaurant_status,r.accepting_orders
            FROM menu_items m JOIN restaurants r ON r.id=m.restaurant_id
            WHERE m.public_id=? LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return pricing_repository_one($conn, $sql, 's', [$publicId]);
}

function pricing_repository_option_rows(mysqli $conn, int $menuItemId, bool $forUpdate = false): array
{
    $sql = 'SELECT g.id AS group_id,g.name AS group_name,g.selection_type,g.minimum_choices,g.maximum_choices,
                   c.public_id AS choice_public_id,c.name AS choice_name,c.price_delta,c.available
            FROM menu_option_groups g LEFT JOIN menu_option_choices c ON c.option_group_id=g.id
            WHERE g.menu_item_id=? ORDER BY g.sort_order,g.id,c.sort_order,c.id';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return pricing_repository_rows($conn, $sql, 'i', [$menuItemId]);
}

function pricing_repository_service_area(mysqli $conn, string $city): array
{
    return pricing_repository_one($conn, "SELECT id,name,city,radius_km,status,minimum_order FROM service_areas WHERE status='active' AND city=? LIMIT 1", 's', [$city]);
}

function pricing_repository_setting(mysqli $conn, string $key): ?string
{
    $row = pricing_repository_one($conn, 'SELECT setting_value FROM platform_settings WHERE setting_key=? LIMIT 1', 's', [$key]);
    return $row === [] ? null : (string) $row['setting_value'];
}

function pricing_repository_fee_rule(mysqli $conn): array
{
    return pricing_repository_one(
        $conn,
        "SELECT id,amount,unit FROM fee_rules WHERE status='active' AND rule_type IN ('delivery_fee','delivery') AND effective_at<=NOW() ORDER BY effective_at DESC,id DESC LIMIT 1"
    );
}

function pricing_repository_promotion(mysqli $conn, string $code): array
{
    return pricing_repository_one($conn, 'SELECT id,code,audience,discount_type,discount_value,maximum_discount,minimum_order,usage_cap,budget,used_amount,starts_at,ends_at,status,scope FROM promotions WHERE code=? LIMIT 1', 's', [$code]);
}

function pricing_repository_promotion_usage(mysqli $conn, int $promotionId): int
{
    $row = pricing_repository_one($conn, 'SELECT COUNT(*) AS total FROM promotion_redemptions WHERE promotion_id=?', 'i', [$promotionId]);
    return (int) ($row['total'] ?? 0);
}

function pricing_repository_customer_has_delivered_order(mysqli $conn, int $customerUserId): bool
{
    return pricing_repository_one($conn, "SELECT id FROM orders WHERE customer_user_id=? AND status='delivered' LIMIT 1", 'i', [$customerUserId]) !== [];
}

function pricing_repository_insert_quote(
    mysqli $conn,
    string $publicId,
    int $customerUserId,
    int $restaurantId,
    int $addressId,
    string $cartHash,
    string $itemsJson,
    float $subtotal,
    float $discount,
    float $deliveryFee,
    float $total,
    string $currency,
    ?string $promotionCode,
    ?int $promotionId,
    ?int $feeRuleId,
    string $expiresAt
): void {
    $statement = $conn->prepare(
        'INSERT INTO checkout_quotes(public_id,customer_user_id,restaurant_id,address_id,cart_hash,items_json,subtotal,discount_amount,delivery_fee,total,currency,promotion_code,promotion_id,fee_rule_id,expires_at,version)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)'
    );
    $statement->bind_param('siiissddddssiis', $publicId, $customerUserId, $restaurantId, $addressId, $cartHash, $itemsJson, $subtotal, $discount, $deliveryFee, $total, $currency, $promotionCode, $promotionId, $feeRuleId, $expiresAt);
    $statement->execute();
    $statement->close();
}
