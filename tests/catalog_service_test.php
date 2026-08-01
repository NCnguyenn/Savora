<?php
declare(strict_types=1);

require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/services/catalog_service.php';

function catalog_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function catalog_schema_blocker(mysqli $conn): ?string
{
    $database = savora_test_selected_database($conn);
    $tables = $conn->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ('schema_migrations','restaurant_weekly_hours','restaurant_special_hours','menu_option_groups','menu_option_choices')"
    );
    $tables->bind_param('s', $database);
    $tables->execute();
    $present = array_column($tables->get_result()->fetch_all(MYSQLI_ASSOC), 'TABLE_NAME');
    $tables->close();
    if (count($present) !== 5) {
        return 'savora_test is missing the catalog migration tables.';
    }
    $migration = $conn->prepare('SELECT 1 FROM schema_migrations WHERE version=? LIMIT 1');
    $version = '004_catalog_contract';
    $migration->bind_param('s', $version);
    $migration->execute();
    $applied = $migration->get_result()->fetch_assoc();
    $migration->close();
    return $applied ? null : 'savora_test has not recorded migration 004_catalog_contract.';
}

$conn = null;
$ownerA = null;
$ownerB = null;
$prefix = 'task7-catalog-' . bin2hex(random_bytes(6));
try {
    $conn = savora_test_database();
    $blocker = catalog_schema_blocker($conn);
    if ($blocker !== null) {
        echo "BLOCKED: {$blocker}\n";
        return;
    }

    $password = password_hash('catalog-test', PASSWORD_DEFAULT);
    $owner = $conn->prepare("INSERT INTO users(username,password,role,full_name,status) VALUES(?,?,'restaurant',?,'active')");
    $usernameA = $prefix . '-a';
    $nameA = 'Catalog Owner A';
    $owner->bind_param('sss', $usernameA, $password, $nameA);
    $owner->execute();
    $ownerA = $conn->insert_id;
    $usernameB = $prefix . '-b';
    $nameB = 'Catalog Owner B';
    $owner->bind_param('sss', $usernameB, $password, $nameB);
    $owner->execute();
    $ownerB = $conn->insert_id;
    $owner->close();

    $restaurant = $conn->prepare("INSERT INTO restaurants(owner_user_id,name,status,accepting_orders) VALUES(?,?,'active',1)");
    $restaurantAName = 'Catalog Fixture A';
    $restaurant->bind_param('is', $ownerA, $restaurantAName);
    $restaurant->execute();
    $restaurantA = $conn->insert_id;
    $restaurantBName = 'Catalog Fixture B';
    $restaurant->bind_param('is', $ownerB, $restaurantBName);
    $restaurant->execute();
    $restaurantB = $conn->insert_id;
    $restaurant->close();

    $item = $conn->prepare('INSERT INTO menu_items(public_id,restaurant_id,name,price,is_available,version) VALUES(?,?,?,?,1,1)');
    $itemA = $prefix . '-item-a';
    $itemAName = 'Catalog Fixture Bowl';
    $priceA = 8.50;
    $item->bind_param('sisd', $itemA, $restaurantA, $itemAName, $priceA);
    $item->execute();
    $itemAId = $conn->insert_id;
    $itemB = $prefix . '-item-b';
    $itemBName = 'Catalog Fixture B Item';
    $priceB = 9.50;
    $item->bind_param('sisd', $itemB, $restaurantB, $itemBName, $priceB);
    $item->execute();
    $item->close();

    $group = $conn->prepare("INSERT INTO menu_option_groups(menu_item_id,name,selection_type,minimum_choices,maximum_choices,sort_order) VALUES(?,'Add-ons','multiple',0,2,1)");
    $group->bind_param('i', $itemAId);
    $group->execute();
    $groupId = $conn->insert_id;
    $group->close();
    $choice = $conn->prepare("INSERT INTO menu_option_choices(option_group_id,public_id,name,price_delta,available,sort_order) VALUES(?,?,?, ?,1,1)");
    $choiceId = $prefix . '-choice';
    $choiceName = 'Extra sauce';
    $delta = 0.75;
    $choice->bind_param('issd', $groupId, $choiceId, $choiceName, $delta);
    $choice->execute();
    $choice->close();

    $denied = catalog_save_item($conn, $ownerA, [
        'publicId' => $itemB,
        'name' => 'Forbidden update',
        'price' => 4.50,
        'available' => true,
    ], 1);
    catalog_expect(($denied['ok'] ?? true) === false && ($denied['status'] ?? 0) === 403, 'Cross-Restaurant menu update must be denied.');

    $stale = catalog_save_item($conn, $ownerA, [
        'publicId' => $itemA,
        'name' => $itemAName,
        'price' => $priceA,
        'available' => true,
    ], 0);
    catalog_expect(($stale['ok'] ?? true) === false && ($stale['status'] ?? 0) === 409, 'Stale menu versions must be rejected.');

    $customer = catalog_for_customer($conn, ['q' => 'Fixture Bowl']);
    catalog_expect(count($customer) === 1, 'Customer reads must include the active matching item only.');
    $entry = $customer[0];
    catalog_expect(
        isset($entry['restaurant']['id'], $entry['restaurant']['operationalAvailable'], $entry['publicId'], $entry['basePrice'], $entry['version'], $entry['available'], $entry['optionGroups'][0]['optionChoices'][0]['publicId']),
        'Customer catalog entries must include Restaurant availability, versioned item fields, and deterministic options.'
    );
    catalog_expect($entry['publicId'] === $itemA && $entry['optionGroups'][0]['optionChoices'][0]['publicId'] === $choiceId, 'Customer options must belong to the matching menu item.');

    $restaurantCatalog = catalog_for_restaurant($conn, $ownerA);
    catalog_expect(count($restaurantCatalog['items'] ?? []) === 1 && $restaurantCatalog['items'][0]['publicId'] === $itemA, 'Restaurant reads must resolve the owner to only its own catalog.');
} finally {
    if ($conn instanceof mysqli && $ownerA !== null && $ownerB !== null) {
        $deleteGroups = $conn->prepare('DELETE g FROM menu_option_groups g JOIN menu_items m ON m.id=g.menu_item_id WHERE m.public_id IN (?,?)');
        $itemA ??= '';
        $itemB ??= '';
        $deleteGroups->bind_param('ss', $itemA, $itemB);
        $deleteGroups->execute();
        $deleteGroups->close();
        $deleteItems = $conn->prepare('DELETE FROM menu_items WHERE public_id IN (?,?)');
        $deleteItems->bind_param('ss', $itemA, $itemB);
        $deleteItems->execute();
        $deleteItems->close();
        $deleteRestaurants = $conn->prepare('DELETE FROM restaurants WHERE owner_user_id IN (?,?)');
        $deleteRestaurants->bind_param('ii', $ownerA, $ownerB);
        $deleteRestaurants->execute();
        $deleteRestaurants->close();
        $deleteOwners = $conn->prepare('DELETE FROM users WHERE id IN (?,?)');
        $deleteOwners->bind_param('ii', $ownerA, $ownerB);
        $deleteOwners->execute();
        $deleteOwners->close();
    }
    if ($conn instanceof mysqli) {
        $conn->close();
    }
}

echo "PASS: catalog reads are customer-safe and Restaurant writes are owner/version controlled\n";
