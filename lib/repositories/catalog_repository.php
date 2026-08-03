<?php
declare(strict_types=1);

function catalog_repository_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
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

function catalog_repository_one(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    return catalog_repository_rows($conn, $sql, $types, $params)[0] ?? [];
}

function catalog_repository_list_value(mixed $value): array
{
    if (is_array($value)) {
        return array_values(array_filter(array_map(static fn (mixed $entry): string => trim((string) $entry), $value), static fn (string $entry): bool => $entry !== ''));
    }
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return catalog_repository_list_value($decoded);
    }
    return array_values(array_filter(array_map('trim', preg_split('/\s*\|\s*|\s*,\s*/', $raw) ?: []), static fn (string $entry): bool => $entry !== ''));
}

function catalog_repository_options(mysqli $conn, int $menuItemId, bool $customerVisible): array
{
    $groups = catalog_repository_rows(
        $conn,
        'SELECT id,name,selection_type,minimum_choices,maximum_choices,sort_order,version
         FROM menu_option_groups WHERE menu_item_id=? ORDER BY sort_order,id',
        'i',
        [$menuItemId]
    );
    $result = [];
    foreach ($groups as $group) {
        $choicesSql = 'SELECT public_id,name,price_delta,available,sort_order,version
                       FROM menu_option_choices WHERE option_group_id=?';
        if ($customerVisible) {
            $choicesSql .= ' AND available=1';
        }
        $choicesSql .= ' ORDER BY sort_order,id';
        $choices = catalog_repository_rows($conn, $choicesSql, 'i', [(int) $group['id']]);
        $result[] = [
            'id' => (int) $group['id'],
            'name' => (string) $group['name'],
            'selectionType' => (string) $group['selection_type'],
            'minimumChoices' => (int) $group['minimum_choices'],
            'maximumChoices' => (int) $group['maximum_choices'],
            'sortOrder' => (int) $group['sort_order'],
            'version' => (int) $group['version'],
            'optionChoices' => array_map(static fn (array $choice): array => [
                'publicId' => (string) $choice['public_id'],
                'name' => (string) $choice['name'],
                'priceDelta' => (float) $choice['price_delta'],
                'available' => (bool) $choice['available'],
                'sortOrder' => (int) $choice['sort_order'],
                'version' => (int) $choice['version'],
            ], $choices),
        ];
    }
    return $result;
}

function catalog_repository_map_item(mysqli $conn, array $row, bool $customerVisible): array
{
    return [
        'id' => (int) $row['menu_item_id'],
        'publicId' => (string) $row['public_id'],
        'name' => (string) $row['item_name'],
        'basePrice' => (float) $row['base_price'],
        'version' => (int) $row['item_version'],
        'available' => (bool) $row['item_available'],
        'description' => (string) ($row['item_description'] ?? ''),
        'imagePath' => (string) ($row['image_path'] ?? ''),
        'category' => (string) ($row['item_category'] ?? ''),
        'itemType' => in_array((string) ($row['item_type'] ?? 'food'), ['food', 'drink'], true) ? (string) $row['item_type'] : 'food',
        'sortOrder' => (int) ($row['sort_order'] ?? 0),
        'prepTimeMinutes' => $row['prep_time_minutes'] === null ? null : (int) $row['prep_time_minutes'],
        'calories' => $row['calories'] === null ? null : (int) $row['calories'],
        'dietaryTags' => catalog_repository_list_value($row['dietary_tags'] ?? null),
        'allergens' => catalog_repository_list_value($row['allergens'] ?? null),
        'ingredients' => catalog_repository_list_value($row['ingredients'] ?? null),
        'restaurant' => [
            'id' => (int) $row['restaurant_id'],
            'publicId' => (string) ($row['restaurant_public_id'] ?? ''),
            'name' => (string) $row['restaurant_name'],
            'cuisine' => (string) ($row['cuisine'] ?? ''),
            'description' => (string) ($row['restaurant_description'] ?? ''),
            'slogan' => (string) ($row['restaurant_slogan'] ?? ''),
            'heroImage' => (string) ($row['restaurant_hero_image'] ?? ''),
            'logoPath' => (string) ($row['restaurant_logo_path'] ?? ''),
            'rating' => (float) ($row['restaurant_rating'] ?? 0),
            'address' => (string) ($row['address'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'phone' => (string) ($row['restaurant_phone'] ?? ''),
            'latitude' => $row['latitude'] === null ? null : (float) $row['latitude'],
            'longitude' => $row['longitude'] === null ? null : (float) $row['longitude'],
            'operationalAvailable' => (bool) $row['operational_available'],
        ],
        'optionGroups' => catalog_repository_options($conn, (int) $row['menu_item_id'], $customerVisible),
    ];
}

function catalog_repository_customer_items(mysqli $conn, array $filters): array
{
    $q = trim((string) ($filters['q'] ?? ''));
    $restaurant = trim((string) ($filters['restaurant'] ?? ''));
    $rows = catalog_repository_rows(
        $conn,
        "SELECT m.id AS menu_item_id,m.public_id,m.name AS item_name,m.price AS base_price,m.version AS item_version,m.is_available AS item_available,
                m.description AS item_description,m.image_path,m.category AS item_category,m.item_type,m.prep_time_minutes,m.calories,m.dietary_tags,m.allergens,m.ingredients,m.sort_order,
                r.id AS restaurant_id,r.public_id AS restaurant_public_id,r.name AS restaurant_name,r.cuisine,r.description AS restaurant_description,r.slogan AS restaurant_slogan,r.hero_image AS restaurant_hero_image,r.logo_path AS restaurant_logo_path,r.rating AS restaurant_rating,r.address,r.city,r.phone AS restaurant_phone,r.latitude,r.longitude,
                (r.accepting_orders=1) AS operational_available
         FROM menu_items m JOIN restaurants r ON r.id=m.restaurant_id
         WHERE r.status='active' AND m.is_available=1
           AND (?='' OR m.name LIKE CONCAT('%',?,'%') OR r.name LIKE CONCAT('%',?,'%'))
           AND (?='' OR r.name LIKE CONCAT('%',?,'%'))
         ORDER BY r.name,m.sort_order,m.name,m.id",
        'sssss',
        [$q, $q, $q, $restaurant, $restaurant]
    );
    return array_map(fn (array $row): array => catalog_repository_map_item($conn, $row, true), $rows);
}

function catalog_repository_customer_storefront(mysqli $conn, string $publicId): array
{
    return catalog_repository_one(
        $conn,
        "SELECT id,public_id,name,cuisine,description,slogan,hero_image,logo_path,rating,address,city,phone,accepting_orders,latitude,longitude
         FROM restaurants
         WHERE public_id=? AND status='active' LIMIT 1",
        's',
        [$publicId]
    );
}

function catalog_repository_customer_storefront_items(mysqli $conn, int $restaurantId): array
{
    $rows = catalog_repository_rows(
        $conn,
        "SELECT m.id AS menu_item_id,m.public_id,m.name AS item_name,m.price AS base_price,m.version AS item_version,m.is_available AS item_available,
                m.description AS item_description,m.image_path,m.category AS item_category,m.item_type,m.prep_time_minutes,m.calories,m.dietary_tags,m.allergens,m.ingredients,m.sort_order,
                r.id AS restaurant_id,r.public_id AS restaurant_public_id,r.name AS restaurant_name,r.cuisine,r.description AS restaurant_description,r.slogan AS restaurant_slogan,r.hero_image AS restaurant_hero_image,r.logo_path AS restaurant_logo_path,r.rating AS restaurant_rating,r.address,r.city,r.phone AS restaurant_phone,r.latitude,r.longitude,
                (r.accepting_orders=1) AS operational_available
         FROM menu_items m JOIN restaurants r ON r.id=m.restaurant_id
         WHERE r.id=? AND r.status='active' AND m.is_available=1
         ORDER BY m.item_type,m.sort_order,m.name,m.id",
        'i',
        [$restaurantId]
    );
    return array_map(fn (array $row): array => catalog_repository_map_item($conn, $row, true), $rows);
}

function catalog_repository_active_promotions(mysqli $conn, int $restaurantId, DateTimeImmutable $at): array
{
    $timestamp = $at->format('Y-m-d H:i:s');
    $id = (string) $restaurantId;
    $scoped = 'restaurant:' . $restaurantId;
    return catalog_repository_rows(
        $conn,
        "SELECT code,discount_type,discount_value,maximum_discount,minimum_order,ends_at
         FROM promotions
         WHERE status='active' AND starts_at<=? AND ends_at>=?
           AND (scope='all_restaurants' OR scope='all' OR scope=? OR scope=?)
         ORDER BY ends_at,code",
        'ssss',
        [$timestamp, $timestamp, $id, $scoped]
    );
}

function catalog_repository_restaurant(mysqli $conn, int $ownerUserId): array
{
    return catalog_repository_one(
        $conn,
        "SELECT r.id,r.owner_user_id,r.name,r.cuisine,r.description,r.hero_image,r.rating,r.address,r.city,r.phone,r.status,r.accepting_orders,r.latitude,r.longitude,r.version
         FROM restaurants r JOIN users u ON u.id=r.owner_user_id
         WHERE r.owner_user_id=? AND u.role='restaurant' AND u.status='active' LIMIT 1",
        'i',
        [$ownerUserId]
    );
}

function catalog_repository_restaurant_items(mysqli $conn, int $ownerUserId): array
{
    $rows = catalog_repository_rows(
        $conn,
        "SELECT m.id AS menu_item_id,m.public_id,m.name AS item_name,m.price AS base_price,m.version AS item_version,m.is_available AS item_available,
                m.description AS item_description,m.image_path,m.category AS item_category,m.item_type,m.prep_time_minutes,m.calories,m.dietary_tags,m.allergens,m.ingredients,m.sort_order,
                r.id AS restaurant_id,r.public_id AS restaurant_public_id,r.name AS restaurant_name,r.cuisine,r.description AS restaurant_description,r.slogan AS restaurant_slogan,r.hero_image AS restaurant_hero_image,r.logo_path AS restaurant_logo_path,r.rating AS restaurant_rating,r.address,r.city,r.phone AS restaurant_phone,r.latitude,r.longitude,
                (r.accepting_orders=1) AS operational_available
         FROM menu_items m JOIN restaurants r ON r.id=m.restaurant_id JOIN users u ON u.id=r.owner_user_id
         WHERE r.owner_user_id=? AND u.role='restaurant' AND u.status='active'
         ORDER BY m.sort_order,m.name,m.id",
        'i',
        [$ownerUserId]
    );
    return array_map(fn (array $row): array => catalog_repository_map_item($conn, $row, false), $rows);
}

function catalog_repository_item_by_public_id(mysqli $conn, string $publicId, bool $forUpdate = false): array
{
    $sql = "SELECT m.id AS menu_item_id,m.public_id,m.name AS item_name,m.price AS base_price,m.version AS item_version,m.is_available AS item_available,
                   m.description AS item_description,m.image_path,m.category AS item_category,m.item_type,m.prep_time_minutes,m.calories,m.dietary_tags,m.allergens,m.ingredients,m.sort_order,
                   r.id AS restaurant_id,r.owner_user_id,r.public_id AS restaurant_public_id,r.name AS restaurant_name,r.cuisine,r.description AS restaurant_description,r.slogan AS restaurant_slogan,r.hero_image AS restaurant_hero_image,r.logo_path AS restaurant_logo_path,r.rating AS restaurant_rating,r.address,r.city,r.phone AS restaurant_phone,r.latitude,r.longitude,
                   (r.accepting_orders=1) AS operational_available
            FROM menu_items m JOIN restaurants r ON r.id=m.restaurant_id WHERE m.public_id=? LIMIT 1";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    return catalog_repository_one($conn, $sql, 's', [$publicId]);
}

function catalog_repository_weekly_hours(mysqli $conn, int $restaurantId): array
{
    return catalog_repository_rows($conn, 'SELECT weekday,opens_at,closes_at,is_closed,version FROM restaurant_weekly_hours WHERE restaurant_id=? ORDER BY weekday', 'i', [$restaurantId]);
}

function catalog_repository_special_hours(mysqli $conn, int $restaurantId): array
{
    return catalog_repository_rows($conn, 'SELECT special_date,opens_at,closes_at,is_closed,note,version FROM restaurant_special_hours WHERE restaurant_id=? ORDER BY special_date', 'i', [$restaurantId]);
}
