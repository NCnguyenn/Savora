<?php
declare(strict_types=1);

require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/services/catalog_service.php';

function storefront_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$conn = savora_test_database();
$suffix = bin2hex(random_bytes(5));
$userIds = [];
$restaurantIds = [];
$promotionIds = [];
$activeCode = "STORE10-{$suffix}";
try {
    $user = $conn->prepare("INSERT INTO users(username,password,role,full_name,status) VALUES(?,?,'restaurant',?,'active')");
    $password = password_hash('storefront-test', PASSWORD_DEFAULT);
    foreach (['a', 'b', 'suspended'] as $key) {
        $username = "storefront-{$key}-{$suffix}";
        $ownerName = "Storefront {$key} Owner";
        $user->bind_param('sss', $username, $password, $ownerName);
        $user->execute();
        $userIds[$key] = (int) $conn->insert_id;
    }
    $user->close();

    $restaurant = $conn->prepare("INSERT INTO restaurants(owner_user_id,public_id,name,cuisine,status,accepting_orders) VALUES(?,?,?,?,?,1)");
    foreach ([['a', 'Storefront A', 'active'], ['b', 'Storefront B', 'active'], ['suspended', 'Storefront Suspended', 'suspended']] as [$key, $name, $status]) {
        $restaurantPublicId = "storefront-{$key}-{$suffix}";
        $cuisine = 'Test Cuisine';
        $restaurant->bind_param('issss', $userIds[$key], $restaurantPublicId, $name, $cuisine, $status);
        $restaurant->execute();
        $restaurantIds[$key] = (int) $conn->insert_id;
    }
    $restaurant->close();

    $item = $conn->prepare('INSERT INTO menu_items(public_id,restaurant_id,name,price,item_type,is_available) VALUES(?,?,?,?,?,?)');
    foreach ([['visible', $restaurantIds['a'], 1], ['hidden', $restaurantIds['a'], 0], ['foreign', $restaurantIds['b'], 1]] as [$key, $restaurantId, $available]) {
        $itemPublicId = "storefront-item-{$key}-{$suffix}";
        $itemName = "Storefront {$key} item";
        $price = 10.0;
        $itemType = 'food';
        $item->bind_param('sisdsi', $itemPublicId, $restaurantId, $itemName, $price, $itemType, $available);
        $item->execute();
    }
    $item->close();

    $hour = $conn->prepare("INSERT INTO restaurant_weekly_hours(restaurant_id,weekday,opens_at,closes_at,is_closed) VALUES(?,1,'09:00:00','21:00:00',0)");
    $hour->bind_param('i', $restaurantIds['a']);
    $hour->execute();
    $hour->close();

    $promotion = $conn->prepare('INSERT INTO promotions(code,audience,discount_type,discount_value,minimum_order,budget,starts_at,ends_at,status,scope) VALUES(?,?,?,10,0,1000,?,?,?,?)');
    foreach ([
        [$activeCode, '2026-08-01 00:00:00', '2026-08-31 23:59:59', 'active', 'restaurant:' . $restaurantIds['a']],
        ["EXPIRED10-{$suffix}", '2026-07-01 00:00:00', '2026-07-31 23:59:59', 'active', 'restaurant:' . $restaurantIds['a']],
        ["OTHER10-{$suffix}", '2026-08-01 00:00:00', '2026-08-31 23:59:59', 'active', 'restaurant:' . $restaurantIds['b']],
    ] as [$code, $starts, $ends, $status, $scope]) {
        $audience = 'all_customers';
        $discountType = 'percentage';
        $promotion->bind_param('sssssss', $code, $audience, $discountType, $starts, $ends, $status, $scope);
        $promotion->execute();
        $promotionIds[] = (int) $conn->insert_id;
    }
    $promotion->close();

    $result = catalog_storefront_for_customer($conn, "storefront-a-{$suffix}", new DateTimeImmutable('2026-08-03 12:00:00'));
    storefront_expect(($result['ok'] ?? false) === true, 'Active restaurant storefront must resolve.');
    storefront_expect(count($result['data']['items'] ?? []) === 1, 'Only available selected-restaurant items may be returned.');
    storefront_expect(count($result['data']['weeklyHours'] ?? []) === 1, 'Selected restaurant hours must be returned.');
    $promotionCodes = array_column($result['data']['promotions'] ?? [], 'code');
    storefront_expect(in_array($activeCode, $promotionCodes, true), 'The selected restaurant promotion must be returned.');
    storefront_expect(!in_array("EXPIRED10-{$suffix}", $promotionCodes, true), 'Expired promotions must not be returned.');
    storefront_expect(!in_array("OTHER10-{$suffix}", $promotionCodes, true), 'Other-restaurant promotions must not be returned.');
    storefront_expect((catalog_storefront_for_customer($conn, 'bad id')['status'] ?? 0) === 422, 'Invalid IDs must return 422.');
    storefront_expect((catalog_storefront_for_customer($conn, "storefront-suspended-{$suffix}")['status'] ?? 0) === 404, 'Inactive restaurants must return 404.');
    echo "PASS: customer storefront read boundary is scoped and promotion-safe\n";
} finally {
    foreach (array_reverse($promotionIds) as $id) {
        $conn->query('DELETE FROM promotions WHERE id=' . (int) $id);
    }
    foreach (array_reverse($restaurantIds) as $id) {
        $conn->query('DELETE FROM restaurant_weekly_hours WHERE restaurant_id=' . (int) $id);
        $conn->query('DELETE FROM menu_items WHERE restaurant_id=' . (int) $id);
        $conn->query('DELETE FROM restaurants WHERE id=' . (int) $id);
    }
    foreach (array_reverse($userIds) as $id) {
        $conn->query('DELETE FROM users WHERE id=' . (int) $id);
    }
    $conn->close();
}
