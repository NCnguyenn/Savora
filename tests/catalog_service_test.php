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
    $itemBId = $conn->insert_id;
    $item->close();

    $hiddenItem = $prefix . '-item-hidden';
    $hiddenName = 'Catalog Fixture Hidden';
    $hiddenPrice = 7.25;
    $hidden = $conn->prepare('INSERT INTO menu_items(public_id,restaurant_id,name,price,is_available,version) VALUES(?,?,?,?,0,1)');
    $hidden->bind_param('sisd', $hiddenItem, $restaurantA, $hiddenName, $hiddenPrice);
    $hidden->execute();
    $hidden->close();

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
    $foreignState = $conn->query("SELECT name,price,version FROM menu_items WHERE id={$itemBId}")->fetch_assoc();
    catalog_expect(
        $foreignState !== null && $foreignState['name'] === $itemBName && (float) $foreignState['price'] === $priceB && (int) $foreignState['version'] === 1,
        'A cross-Restaurant rejection must leave the foreign item unchanged.'
    );

    $stale = catalog_save_item($conn, $ownerA, [
        'publicId' => $itemA,
        'name' => $itemAName,
        'price' => $priceA,
        'available' => true,
    ], 0);
    catalog_expect(($stale['ok'] ?? true) === false && ($stale['status'] ?? 0) === 409, 'Stale menu versions must be rejected.');
    $itemState = $conn->query("SELECT name,price,is_available,version FROM menu_items WHERE id={$itemAId}")->fetch_assoc();
    catalog_expect(
        $itemState !== null && $itemState['name'] === $itemAName && (float) $itemState['price'] === $priceA && (int) $itemState['is_available'] === 1 && (int) $itemState['version'] === 1,
        'A stale item rejection must leave the owned item unchanged.'
    );

    $weekly = $conn->prepare("INSERT INTO restaurant_weekly_hours(restaurant_id,weekday,opens_at,closes_at,is_closed) VALUES(?,1,'09:00:00','17:00:00',0)");
    $weekly->bind_param('i', $restaurantA);
    $weekly->execute();
    $weekly->close();
    $staleOperations = catalog_save_operations($conn, $ownerA, [
        'acceptingOrders' => false,
        'weeklyHours' => [['weekday' => 1, 'opensAt' => '10:00:00', 'closesAt' => '18:00:00', 'isClosed' => false]],
        'specialHours' => [],
    ], 0);
    catalog_expect(($staleOperations['ok'] ?? true) === false && ($staleOperations['status'] ?? 0) === 409, 'Stale operations versions must be rejected.');
    $operationsState = $conn->query("SELECT r.accepting_orders,r.version,h.opens_at,h.closes_at FROM restaurants r JOIN restaurant_weekly_hours h ON h.restaurant_id=r.id AND h.weekday=1 WHERE r.id={$restaurantA}")->fetch_assoc();
    catalog_expect(
        $operationsState !== null && (int) $operationsState['accepting_orders'] === 1 && (int) $operationsState['version'] === 1 && $operationsState['opens_at'] === '09:00:00' && $operationsState['closes_at'] === '17:00:00',
        'A stale operations rejection must not replace hours or Restaurant state.'
    );

    $staleProfile = catalog_save_profile($conn, $ownerA, ['name' => 'Stale profile overwrite'], 0);
    catalog_expect(($staleProfile['ok'] ?? true) === false && ($staleProfile['status'] ?? 0) === 409, 'Stale profile versions must be rejected.');
    $profileState = $conn->query("SELECT name,version FROM restaurants WHERE id={$restaurantA}")->fetch_assoc();
    catalog_expect($profileState !== null && $profileState['name'] === $restaurantAName && (int) $profileState['version'] === 1, 'A stale profile rejection must leave the Restaurant unchanged.');

    $conn->query("UPDATE restaurants SET status='suspended' WHERE id={$restaurantB}");

    $customer = catalog_for_customer($conn, ['q' => 'Catalog Fixture']);
    catalog_expect(count($customer) === 1, 'Customer reads must exclude unavailable items and items owned by inactive Restaurants.');
    $entry = $customer[0];
    catalog_expect(
        isset($entry['restaurant']['id'], $entry['restaurant']['operationalAvailable'], $entry['publicId'], $entry['basePrice'], $entry['version'], $entry['available'], $entry['optionGroups'][0]['optionChoices'][0]['publicId']),
        'Customer catalog entries must include Restaurant availability, versioned item fields, and deterministic options.'
    );
    catalog_expect($entry['publicId'] === $itemA && $entry['optionGroups'][0]['optionChoices'][0]['publicId'] === $choiceId, 'Customer options must belong to the matching menu item.');

    $restaurantCatalog = catalog_for_restaurant($conn, $ownerA);
    catalog_expect(count($restaurantCatalog['items'] ?? []) === 2 && $restaurantCatalog['items'][0]['publicId'] === $itemA, 'Restaurant reads must resolve the owner to only its own catalog: ' . json_encode($restaurantCatalog, JSON_THROW_ON_ERROR));
} finally {
    if ($conn instanceof mysqli && $ownerA !== null && $ownerB !== null) {
        $deleteGroups = $conn->prepare('DELETE g FROM menu_option_groups g JOIN menu_items m ON m.id=g.menu_item_id WHERE m.public_id IN (?,?,?)');
        $itemA ??= '';
        $itemB ??= '';
        $hiddenItem ??= '';
        $deleteGroups->bind_param('sss', $itemA, $itemB, $hiddenItem);
        $deleteGroups->execute();
        $deleteGroups->close();
        $deleteItems = $conn->prepare('DELETE FROM menu_items WHERE public_id IN (?,?,?)');
        $deleteItems->bind_param('sss', $itemA, $itemB, $hiddenItem);
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
