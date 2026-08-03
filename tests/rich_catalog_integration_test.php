<?php
declare(strict_types=1);

if (getenv('SAVORA_ENV') !== 'development' || getenv('SAVORA_DB_NAME') !== 'savora_db') {
    fwrite(STDERR, "BLOCKED: rich catalog integration test requires development savora_db\n");
    exit(2);
}

require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/repositories/catalog_repository.php';
require_once __DIR__ . '/../lib/services/catalog_service.php';

$conn = savora_database_connect();
try {
    $items = catalog_for_customer($conn, []);
    if (count($items) !== 48) {
        throw new RuntimeException('Customer catalog must return 48 active seeded items.');
    }
    $restaurants = [];
    foreach ($items as $item) {
        $restaurant = $item['restaurant'];
        $restaurantKey = (string) $restaurant['publicId'];
        if ($restaurantKey === '' || !preg_match('/^demo-[a-z0-9-]+$/', $restaurantKey)) {
            throw new RuntimeException('Customer catalog returned an invalid restaurant public ID.');
        }
        if (!preg_match('#^assets/images/brands/[a-z0-9-]+\.svg$#', (string) $restaurant['logoPath'])) {
            throw new RuntimeException('Customer catalog returned an invalid restaurant logo path.');
        }
        $restaurants[$restaurantKey] ??= ['food' => 0, 'drink' => 0];
        $itemType = (string) ($item['itemType'] ?? '');
        if (!isset($restaurants[$restaurantKey][$itemType])) {
            throw new RuntimeException('Customer catalog returned an invalid item type.');
        }
        $restaurants[$restaurantKey][$itemType]++;
        foreach (['description', 'imagePath', 'category', 'itemType', 'prepTimeMinutes', 'calories', 'ingredients'] as $field) {
            if ($item[$field] === '' || $item[$field] === null || $item[$field] === []) {
                throw new RuntimeException("Customer catalog item is missing {$field}.");
            }
        }
        if (!preg_match('#^assets/images/catalog/demo-[a-z0-9-]+\.jpg$#', (string) $item['imagePath'])) {
            throw new RuntimeException('Customer catalog returned an unsafe or non-local image path.');
        }
    }
    if (count($restaurants) !== 6) {
        throw new RuntimeException('Customer catalog must expose six restaurants.');
    }
    foreach ($restaurants as $counts) {
        if ($counts['food'] !== 6 || $counts['drink'] !== 2) {
            throw new RuntimeException('Every seeded restaurant must expose six food items and two drinks.');
        }
    }
    echo "PASS: customer catalog returns 48 rich items across six restaurants with food and drinks\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} finally {
    $conn->close();
}
