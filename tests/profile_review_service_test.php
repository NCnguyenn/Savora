<?php
declare(strict_types=1);

require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/services/profile_service.php';
require_once __DIR__ . '/../lib/services/review_service.php';

function profile_review_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function profile_review_schema_blocker(mysqli $conn): ?string
{
    $database = savora_test_selected_database($conn);
    $tables = $conn->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ('schema_migrations','customer_profiles','customer_addresses','customer_favorites','restaurant_reviews','orders','order_items','restaurants','menu_items')"
    );
    $tables->bind_param('s', $database);
    $tables->execute();
    $present = array_column($tables->get_result()->fetch_all(MYSQLI_ASSOC), 'TABLE_NAME');
    $tables->close();
    if (count($present) !== 9) {
        return 'savora_test is missing the profile/review prerequisite tables.';
    }
    $migration = $conn->prepare('SELECT 1 FROM schema_migrations WHERE version=? LIMIT 1');
    $version = '004a_profiles_reviews';
    $migration->bind_param('s', $version);
    $migration->execute();
    $applied = $migration->get_result()->fetch_assoc();
    $migration->close();
    return $applied ? null : 'savora_test has not recorded migration 004a_profiles_reviews.';
}

$conn = null;
$customerA = null;
$customerB = null;
$ownerA = null;
$ownerB = null;
$restaurantA = null;
$restaurantB = null;
$orderDelivered = null;
$orderPending = null;
$prefix = 'task8a-profile-review-' . bin2hex(random_bytes(6));

try {
    try {
        $conn = savora_test_database();
    } catch (Throwable $exception) {
        echo "BLOCKED: {$exception->getMessage()}\n";
        exit(2);
    }
    $blocker = profile_review_schema_blocker($conn);
    if ($blocker !== null) {
        echo "BLOCKED: {$blocker}\n";
        exit(2);
    }

    $password = password_hash('profile-review-test', PASSWORD_DEFAULT);
    $user = $conn->prepare("INSERT INTO users(username,password,role,full_name,status) VALUES(?,?,? ,?,'active')");
    $username = $prefix . '-customer-a'; $role = 'customer'; $name = 'Profile Customer A';
    $user->bind_param('ssss', $username, $password, $role, $name); $user->execute(); $customerA = $conn->insert_id;
    $username = $prefix . '-customer-b'; $name = 'Profile Customer B';
    $user->bind_param('ssss', $username, $password, $role, $name); $user->execute(); $customerB = $conn->insert_id;
    $username = $prefix . '-owner-a'; $role = 'restaurant'; $name = 'Profile Restaurant Owner A';
    $user->bind_param('ssss', $username, $password, $role, $name); $user->execute(); $ownerA = $conn->insert_id;
    $username = $prefix . '-owner-b'; $name = 'Profile Restaurant Owner B';
    $user->bind_param('ssss', $username, $password, $role, $name); $user->execute(); $ownerB = $conn->insert_id;
    $user->close();

    $profile = $conn->prepare('INSERT INTO customer_profiles(user_id,email,phone,address,wallet_balance,version) VALUES(?,?,?,NULL,0,1)');
    $email = $prefix . '-a@example.test'; $phone = '+1 555 0101';
    $profile->bind_param('iss', $customerA, $email, $phone); $profile->execute();
    $email = $prefix . '-b@example.test'; $phone = '+1 555 0102';
    $profile->bind_param('iss', $customerB, $email, $phone); $profile->execute();
    $profile->close();

    $restaurant = $conn->prepare("INSERT INTO restaurants(owner_user_id,public_id,name,status,accepting_orders) VALUES(?,?,?,'active',1)");
    $restaurantPublicId = $prefix . '-restaurant-a';
    $restaurantName = 'Profile Restaurant A';
    $restaurant->bind_param('iss', $ownerA, $restaurantPublicId, $restaurantName); $restaurant->execute(); $restaurantA = $conn->insert_id;
    $restaurantPublicId = $prefix . '-restaurant-b';
    $restaurantName = 'Profile Restaurant B';
    $restaurant->bind_param('iss', $ownerB, $restaurantPublicId, $restaurantName); $restaurant->execute(); $restaurantB = $conn->insert_id;
    $restaurant->close();

    $item = $conn->prepare('INSERT INTO menu_items(public_id,restaurant_id,name,price,is_available,version) VALUES(?,?,?,9.50,1,1)');
    $itemPublicId = $prefix . '-item'; $itemName = 'Profile Review Bowl';
    $item->bind_param('sis', $itemPublicId, $restaurantA, $itemName); $item->execute(); $item->close();

    $order = $conn->prepare("INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,delivery_fee,total,delivery_address) VALUES(?,?,?,'delivered','cash',9.50,2,11.50,'Test address')");
    $deliveredReference = 'SV-' . strtoupper(substr($prefix, -10)) . '-D';
    $order->bind_param('sii', $deliveredReference, $customerA, $restaurantA); $order->execute(); $orderDelivered = $conn->insert_id;
    $order->close();
    $order = $conn->prepare("INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,delivery_fee,total,delivery_address) VALUES(?,?,?,'pending','cash',9.50,2,11.50,'Test address')");
    $pendingReference = 'SV-' . strtoupper(substr($prefix, -10)) . '-P';
    $order->bind_param('sii', $pendingReference, $customerA, $restaurantA); $order->execute(); $orderPending = $conn->insert_id;
    $order->close();

    $orderItem = $conn->prepare('INSERT INTO order_items(order_id,item_name,quantity,unit_price,options_text) VALUES(?,?,1,9.50,?)');
    $options = '';
    $orderItem->bind_param('iss', $orderDelivered, $itemName, $options); $orderItem->execute(); $orderItem->close();

    $updated = profile_update_customer($conn, $customerA, [
        'fullName' => 'Profile Customer A Updated', 'email' => 'updated@example.test', 'phone' => '+1 555 0199',
    ], 1);
    profile_review_expect(($updated['ok'] ?? false) === true && ($updated['data']['version'] ?? 0) === 2, 'Customer profile update should be owned and versioned.');
    $stale = profile_update_customer($conn, $customerA, [
        'fullName' => 'Stale Profile', 'email' => 'stale@example.test', 'phone' => '+1 555 0100',
    ], 1);
    profile_review_expect(($stale['status'] ?? 0) === 409, 'Stale Customer profile versions must be rejected.');

    $addressInput = [
        'publicId' => 'home-' . substr($prefix, -8), 'label' => 'Home', 'recipientName' => 'Profile Customer A',
        'phone' => '+1 555 0199', 'addressLine1' => '1 Test Street', 'addressLine2' => '', 'city' => 'Test City',
        'region' => 'Test Region', 'postalCode' => '10000', 'latitude' => 13.7563, 'longitude' => 100.5018, 'isDefault' => true,
    ];
    $address = profile_save_address($conn, $customerA, $addressInput, 0);
    profile_review_expect(($address['ok'] ?? false) === true && ($address['data']['isDefault'] ?? false) === true, 'Customer address should be saved with required coordinates.');
    $foreignAddress = profile_save_address($conn, $customerB, $addressInput, 1);
    profile_review_expect(($foreignAddress['status'] ?? 0) === 409, 'A Customer must not update another Customer address.');

    $favorite = favorite_set($conn, $customerA, ['type' => 'restaurant', 'publicId' => (string) $restaurantA, 'active' => true]);
    profile_review_expect(($favorite['ok'] ?? false) === true, 'Customer restaurant favorite should be persisted server-side.');
    $snapshot = profile_for_user($conn, $customerA);
    profile_review_expect(($snapshot['ok'] ?? false) === true && count($snapshot['data']['addresses'] ?? []) === 1 && count($snapshot['data']['favorites'] ?? []) === 1, 'Customer snapshot should return owned addresses and favorites.');
    $foreignFavorite = favorite_set($conn, $customerB, ['type' => 'restaurant', 'publicId' => (string) $restaurantA, 'active' => false]);
    profile_review_expect(($foreignFavorite['ok'] ?? false) === true, 'Favorite mutations must be scoped to the calling Customer.');
    profile_review_expect(count(profile_repository_favorites($conn, $customerA)) === 1, 'A Customer favorite must not be removable by another Customer.');

    $review = review_create_for_order($conn, $customerA, $deliveredReference, 5, '<script>alert(1)</script>');
    profile_review_expect(($review['ok'] ?? false) === true, 'A delivered owned order should be reviewable.');
    $duplicate = review_create_for_order($conn, $customerA, $deliveredReference, 4, 'Second review');
    profile_review_expect(($duplicate['status'] ?? 0) === 409, 'One order may create only one Customer review.');
    $pending = review_create_for_order($conn, $customerA, $pendingReference, 5, 'Too early');
    profile_review_expect(($pending['status'] ?? 0) === 409, 'A pending order must not be reviewable.');
    $foreignReview = review_create_for_order($conn, $customerB, $deliveredReference, 5, 'Not my order');
    profile_review_expect(($foreignReview['status'] ?? 0) === 403, 'A Customer must not review another Customer order.');

    $reviewPublicId = (string) ($review['data']['publicId'] ?? '');
    $reply = review_reply_as_restaurant($conn, $ownerA, $reviewPublicId, 'Thank you for the feedback.', 1);
    profile_review_expect(($reply['ok'] ?? false) === true && ($reply['data']['version'] ?? 0) === 2, 'The owning Restaurant should reply with optimistic versioning.');
    $forbidden = review_reply_as_restaurant($conn, $ownerB, $reviewPublicId, 'Not my review', 1);
    profile_review_expect(($forbidden['status'] ?? 0) === 403, 'Only the owning Restaurant may reply.');
    $staleReply = review_reply_as_restaurant($conn, $ownerA, $reviewPublicId, 'Stale reply', 1);
    profile_review_expect(($staleReply['status'] ?? 0) === 409, 'A stale Restaurant review reply must be rejected.');
    $reviews = reviews_for_restaurant($conn, $ownerA);
    profile_review_expect(($reviews['ok'] ?? false) === true && ($reviews['data'][0]['comment'] ?? '') === '<script>alert(1)</script>', 'Review text must remain data for escaped rendering.');
} finally {
    if ($conn instanceof mysqli) {
        if ($orderDelivered !== null || $orderPending !== null) {
            $deleteReviews = $conn->prepare('DELETE rr FROM restaurant_reviews rr JOIN orders o ON o.id=rr.order_id WHERE o.reference_code LIKE ?');
            $orderPattern = 'SV-' . strtoupper(substr($prefix, -10)) . '%'; $deleteReviews->bind_param('s', $orderPattern); $deleteReviews->execute(); $deleteReviews->close();
            $deleteItems = $conn->prepare('DELETE oi FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.reference_code LIKE ?');
            $deleteItems->bind_param('s', $orderPattern); $deleteItems->execute(); $deleteItems->close();
            $deleteHistory = $conn->prepare('DELETE osh FROM order_status_history osh JOIN orders o ON o.id=osh.order_id WHERE o.reference_code LIKE ?');
            $deleteHistory->bind_param('s', $orderPattern); $deleteHistory->execute(); $deleteHistory->close();
            $deletePayments = $conn->prepare('DELETE p FROM payments p JOIN orders o ON o.id=p.order_id WHERE o.reference_code LIKE ?');
            $deletePayments->bind_param('s', $orderPattern); $deletePayments->execute(); $deletePayments->close();
            $deleteOrders = $conn->prepare('DELETE FROM orders WHERE reference_code LIKE ?');
            $deleteOrders->bind_param('s', $orderPattern); $deleteOrders->execute(); $deleteOrders->close();
        }
        if ($customerA !== null || $customerB !== null) {
            $deleteFavorites = $conn->prepare('DELETE FROM customer_favorites WHERE customer_user_id IN (?,?)');
            $customerA ??= 0; $customerB ??= 0; $deleteFavorites->bind_param('ii', $customerA, $customerB); $deleteFavorites->execute(); $deleteFavorites->close();
            $deleteAddresses = $conn->prepare('DELETE FROM customer_addresses WHERE customer_user_id IN (?,?)');
            $deleteAddresses->bind_param('ii', $customerA, $customerB); $deleteAddresses->execute(); $deleteAddresses->close();
            $deleteProfiles = $conn->prepare('DELETE FROM customer_profiles WHERE user_id IN (?,?)');
            $customerA ??= 0; $customerB ??= 0; $deleteProfiles->bind_param('ii', $customerA, $customerB); $deleteProfiles->execute(); $deleteProfiles->close();
        }
        if ($restaurantA !== null || $restaurantB !== null) {
            $deleteItems = $conn->prepare('DELETE FROM menu_items WHERE restaurant_id IN (?,?)');
            $restaurantA ??= 0; $restaurantB ??= 0; $deleteItems->bind_param('ii', $restaurantA, $restaurantB); $deleteItems->execute(); $deleteItems->close();
            $deleteRestaurants = $conn->prepare('DELETE FROM restaurants WHERE id IN (?,?)');
            $deleteRestaurants->bind_param('ii', $restaurantA, $restaurantB); $deleteRestaurants->execute(); $deleteRestaurants->close();
        }
        if ($customerA !== null || $customerB !== null || $ownerA !== null || $ownerB !== null) {
            $deleteUsers = $conn->prepare('DELETE FROM users WHERE id IN (?,?,?,?)');
            $customerA ??= 0; $customerB ??= 0; $ownerA ??= 0; $ownerB ??= 0;
            $deleteUsers->bind_param('iiii', $customerA, $customerB, $ownerA, $ownerB); $deleteUsers->execute(); $deleteUsers->close();
        }
        $conn->close();
    }
}

echo "PASS: profile, address, favorite, and review service ownership contracts hold\n";
