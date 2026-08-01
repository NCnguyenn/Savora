<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/admin_security.php';
require_once __DIR__ . '/../lib/idempotency.php';
require_once __DIR__ . '/../lib/request_security.php';

function platform_rows(mysqli $conn, string $sql, int $id): array
{
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$actor = savora_request_actor($conn, ['customer', 'restaurant', 'driver', 'admin']);
$userId = $actor['userId'];
$role = $actor['role'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if ($role === 'customer') {
        $orders = platform_rows($conn, 'SELECT o.*,r.name restaurant_name FROM orders o JOIN restaurants r ON r.id=o.restaurant_id WHERE o.customer_user_id=? ORDER BY o.placed_at DESC', $userId);
    } elseif ($role === 'restaurant') {
        $orders = platform_rows($conn, 'SELECT o.*,u.full_name customer_name FROM orders o JOIN restaurants r ON r.id=o.restaurant_id JOIN users u ON u.id=o.customer_user_id WHERE r.owner_user_id=? ORDER BY o.placed_at DESC', $userId);
    } elseif ($role === 'driver') {
        $orders = platform_rows($conn, 'SELECT o.*,r.name restaurant_name,u.full_name customer_name,v.status delivery_status FROM deliveries v JOIN orders o ON o.id=v.order_id JOIN restaurants r ON r.id=o.restaurant_id JOIN users u ON u.id=o.customer_user_id WHERE v.driver_user_id=? ORDER BY o.placed_at DESC', $userId);
    } else {
        $orders = [];
    }
    savora_json(['ok' => true, 'csrfToken' => admin_csrf_token(), 'role' => $role, 'orders' => $orders, 'serverTime' => date(DATE_ATOM)]);
}

try {
    savora_require_csrf(['X-CSRF-Token' => (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')]);
} catch (InvalidArgumentException) {
    savora_error(403, 'Secure session expired.');
}
try {
    $key = savora_require_idempotency_key(['Idempotency-Key' => (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')]);
} catch (InvalidArgumentException) {
    savora_error(422, 'Idempotency key required.');
}
try {
    $body = savora_read_json();
} catch (JsonException) {
    savora_error(400, 'Invalid JSON.');
}
$command = (string) ($body['command'] ?? '');
$payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
$requestHash = savora_idempotency_hash($command, $payload);

savora_idempotency_lock($conn, $userId, $key);
try {
    $storedResponse = savora_idempotency_find($conn, $userId, $key, $command, $requestHash);
} catch (SavoraIdempotencyConflict) {
    savora_idempotency_unlock($conn, $userId, $key);
    savora_error(409, 'Idempotency key was already used for a different request.');
} catch (JsonException) {
    savora_idempotency_unlock($conn, $userId, $key);
    savora_error(500, 'Stored response is invalid.');
}
if ($storedResponse !== null) {
    savora_idempotency_unlock($conn, $userId, $key);
    savora_json($storedResponse);
}

$conn->begin_transaction();
try {
    $result = [];
    if ($command === 'place_order' && $role === 'customer') {
        $reference = mb_substr((string) ($payload['id'] ?? ''), 0, 40);
        $address = mb_substr(trim((string) ($payload['address'] ?? '')), 0, 500);
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $payment = in_array(($payload['paymentMethod'] ?? ''), ['wallet', 'cash', 'card'], true) ? (string) $payload['paymentMethod'] : 'cash';
        if (!preg_match('/^SVR-[A-Za-z0-9-]{3,35}$/', $reference) || $address === '' || !$items || count($items) > 50) throw new RuntimeException('Order reference, address or items are invalid.');

        $menuLookup = $conn->prepare("SELECT m.id,m.name,m.price,m.restaurant_id,r.status,r.accepting_orders FROM menu_items m JOIN restaurants r ON r.id=m.restaurant_id WHERE m.public_id=? AND m.is_available=1 FOR UPDATE");
        $authoritativeItems = [];
        $restaurantId = 0;
        $subtotal = 0.0;
        foreach ($items as $item) {
            $publicId = mb_substr((string) ($item['id'] ?? ''), 0, 60);
            $quantity = max(1, min(20, (int) ($item['quantity'] ?? 0)));
            $menuLookup->bind_param('s', $publicId);
            $menuLookup->execute();
            $menuItem = $menuLookup->get_result()->fetch_assoc();
            if (!$menuItem || $menuItem['status'] !== 'active' || (int) $menuItem['accepting_orders'] !== 1) throw new RuntimeException('A selected menu item is unavailable.');
            if ($restaurantId !== 0 && $restaurantId !== (int) $menuItem['restaurant_id']) throw new RuntimeException('One order can only contain items from one Restaurant.');
            $restaurantId = (int) $menuItem['restaurant_id'];
            $unitPrice = round((float) $menuItem['price'], 2);
            $subtotal += $unitPrice * $quantity;
            $authoritativeItems[] = ['name' => (string) $menuItem['name'], 'quantity' => $quantity, 'price' => $unitPrice, 'options' => mb_substr(json_encode($item['options'] ?? [], JSON_UNESCAPED_UNICODE), 0, 500)];
        }
        $menuLookup->close();
        $feeRow = $conn->query("SELECT setting_value FROM platform_settings WHERE setting_key='delivery_fee_flat' LIMIT 1 FOR UPDATE")->fetch_assoc();
        $deliveryFee = round(max(0, (float) ($feeRow['setting_value'] ?? 2)), 2);
        $subtotal = round($subtotal, 2);
        $total = round($subtotal + $deliveryFee, 2);

        if ($payment === 'wallet') {
            $wallet = $conn->prepare('SELECT wallet_balance FROM customer_profiles WHERE user_id=? FOR UPDATE');
            $wallet->bind_param('i', $userId);
            $wallet->execute();
            $balance = $wallet->get_result()->fetch_assoc();
            $wallet->close();
            if (!$balance || (float) $balance['wallet_balance'] < $total) throw new RuntimeException('Insufficient Savora Pay balance.');
            $debit = $conn->prepare('UPDATE customer_profiles SET wallet_balance=wallet_balance-? WHERE user_id=? AND wallet_balance>=?');
            $debit->bind_param('did', $total, $userId, $total);
            $debit->execute();
            if ($debit->affected_rows !== 1) throw new RuntimeException('Wallet balance changed. Please retry.');
            $debit->close();
        }

        $note = mb_substr((string) ($payload['deliveryNote'] ?? ''), 0, 300);
        $insert = $conn->prepare("INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,delivery_fee,total,delivery_address,delivery_note) VALUES(?,?,?,'pending',?,?,?,?,?,?)");
        $insert->bind_param('siisdddss', $reference, $userId, $restaurantId, $payment, $subtotal, $deliveryFee, $total, $address, $note);
        $insert->execute();
        $orderId = $insert->insert_id;
        $insert->close();
        $itemStmt = $conn->prepare('INSERT INTO order_items(order_id,item_name,quantity,unit_price,options_text) VALUES(?,?,?,?,?)');
        foreach ($authoritativeItems as $item) {
            $itemStmt->bind_param('isids', $orderId, $item['name'], $item['quantity'], $item['price'], $item['options']);
            $itemStmt->execute();
        }
        $itemStmt->close();
        $history = $conn->prepare("INSERT INTO order_status_history(order_id,status,actor_role,actor_user_id) VALUES(?,'pending','customer',?)");
        $history->bind_param('ii', $orderId, $userId);
        $history->execute();
        $history->close();
        $paymentStatus = $payment === 'wallet' ? 'paid' : 'pending';
        $paymentStmt = $conn->prepare('INSERT INTO payments(order_id,method,amount,status,paid_at) VALUES(?,?,?,?,IF(?="paid",NOW(),NULL))');
        $paymentStmt->bind_param('isdss', $orderId, $payment, $total, $paymentStatus, $paymentStatus);
        $paymentStmt->execute();
        $paymentStmt->close();
        if ($payment === 'wallet') {
            $walletEntry = $conn->prepare("INSERT INTO wallet_transactions(customer_user_id,order_id,type,amount,description) VALUES(?,?,'debit',?,'Savora order payment')");
            $walletEntry->bind_param('iid', $userId, $orderId, $total);
            $walletEntry->execute();
            $walletEntry->close();
        }
        $result = ['orderId' => $orderId, 'referenceCode' => $reference, 'status' => 'pending', 'subtotal' => $subtotal, 'deliveryFee' => $deliveryFee, 'total' => $total, 'paymentStatus' => $paymentStatus];
    } elseif ($command === 'restaurant_sync_menu' && $role === 'restaurant') {
        $publicId = mb_substr(trim((string) ($payload['id'] ?? '')), 0, 60);
        $name = mb_substr(trim((string) ($payload['name'] ?? '')), 0, 160);
        $price = round((float) ($payload['price'] ?? 0), 2);
        $available = (($payload['status'] ?? 'published') === 'published' && ($payload['available'] ?? true) !== false) ? 1 : 0;
        if (!preg_match('/^[A-Za-z0-9_-]{1,60}$/', $publicId) || $name === '' || $price <= 0) throw new RuntimeException('Menu item id, name and price are required.');
        $restaurant = $conn->prepare("SELECT id FROM restaurants WHERE owner_user_id=? AND status='active' FOR UPDATE");
        $restaurant->bind_param('i', $userId); $restaurant->execute(); $restaurantRow = $restaurant->get_result()->fetch_assoc(); $restaurant->close();
        if (!$restaurantRow) throw new RuntimeException('Active Restaurant profile not found.');
        $existingMenu = $conn->prepare('SELECT restaurant_id,version FROM menu_items WHERE public_id=? FOR UPDATE');
        $existingMenu->bind_param('s', $publicId); $existingMenu->execute(); $existingItem = $existingMenu->get_result()->fetch_assoc(); $existingMenu->close();
        if ($existingItem && (int) $existingItem['restaurant_id'] !== (int) $restaurantRow['id']) throw new RuntimeException('Menu item identifier belongs to another Restaurant.');
        $menu = $conn->prepare('INSERT INTO menu_items(public_id,restaurant_id,name,price,is_available) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),price=VALUES(price),is_available=VALUES(is_available),version=version+1');
        $menu->bind_param('sisdi', $publicId, $restaurantRow['id'], $name, $price, $available); $menu->execute(); $menu->close();
        $result = ['publicId' => $publicId, 'available' => (bool) $available];
    } elseif ($command === 'restaurant_order_status' && $role === 'restaurant') {
        $reference = mb_substr((string) ($payload['reference_code'] ?? ''), 0, 40);
        $target = ['confirmed' => 'accepted', 'preparing' => 'preparing', 'ready_for_pickup' => 'ready_for_pickup', 'cancelled' => 'cancelled'][(string) ($payload['status'] ?? '')] ?? '';
        $stmt = $conn->prepare('SELECT o.id,o.status,o.version FROM orders o JOIN restaurants r ON r.id=o.restaurant_id WHERE o.reference_code=? AND r.owner_user_id=? FOR UPDATE');
        $stmt->bind_param('si', $reference, $userId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $allowed = ['pending' => ['accepted', 'cancelled'], 'accepted' => ['preparing', 'cancelled'], 'preparing' => ['ready_for_pickup']];
        if (!$order || !in_array($target, $allowed[$order['status']] ?? [], true)) throw new RuntimeException('Restaurant transition is not allowed.');
        $update = $conn->prepare('UPDATE orders SET status=?,version=version+1 WHERE id=? AND version=?');
        $update->bind_param('sii', $target, $order['id'], $order['version']);
        $update->execute();
        if ($update->affected_rows !== 1) throw new RuntimeException('Order changed. Refresh before retrying.');
        $update->close();
        $history = $conn->prepare("INSERT INTO order_status_history(order_id,status,actor_role,actor_user_id) VALUES(?,?,'restaurant',?)");
        $history->bind_param('isi', $order['id'], $target, $userId);
        $history->execute();
        $history->close();
        if ($target === 'ready_for_pickup') {
            $dispatch = $conn->prepare("INSERT INTO delivery_dispatches(order_id,status,attempt_count) VALUES(?,'searching_driver',0) ON DUPLICATE KEY UPDATE status=IF(assigned_driver_user_id IS NULL,'searching_driver',status),version=version+1");
            $dispatch->bind_param('i', $order['id']);
            $dispatch->execute();
            $dispatch->close();
        }
        $result = ['referenceCode' => $reference, 'status' => $target];
    } elseif ($command === 'driver_accept_order' && $role === 'driver') {
        $reference = mb_substr((string) ($payload['reference_code'] ?? ''), 0, 40);
        $driver = $conn->prepare("SELECT d.eligibility_status FROM driver_profiles d JOIN users u ON u.id=d.user_id WHERE d.user_id=? AND u.status='active' FOR UPDATE");
        $driver->bind_param('i', $userId); $driver->execute(); $driverRow = $driver->get_result()->fetch_assoc(); $driver->close();
        if (!$driverRow || $driverRow['eligibility_status'] !== 'eligible') throw new RuntimeException('Driver is not eligible for dispatch.');
        $active = $conn->prepare("SELECT id FROM deliveries WHERE driver_user_id=? AND status IN ('assigned','arrived','picked_up') LIMIT 1 FOR UPDATE");
        $active->bind_param('i', $userId); $active->execute(); $hasActive = $active->get_result()->fetch_assoc(); $active->close();
        if ($hasActive) throw new RuntimeException('Driver already has an active delivery.');
        $orderLookup = $conn->prepare("SELECT o.id,o.version FROM orders o JOIN delivery_dispatches d ON d.order_id=o.id WHERE o.reference_code=? AND o.status='ready_for_pickup' AND d.status IN ('searching_driver','offer_sent') FOR UPDATE");
        $orderLookup->bind_param('s', $reference); $orderLookup->execute(); $acceptedOrder = $orderLookup->get_result()->fetch_assoc(); $orderLookup->close();
        if (!$acceptedOrder) throw new RuntimeException('Delivery offer is no longer available.');
        $dispatch = $conn->prepare("UPDATE delivery_dispatches SET status='assigned',assigned_driver_user_id=?,attempt_count=attempt_count+1,version=version+1 WHERE order_id=? AND status IN ('searching_driver','offer_sent')");
        $dispatch->bind_param('ii', $userId, $acceptedOrder['id']); $dispatch->execute();
        if ($dispatch->affected_rows !== 1) throw new RuntimeException('Another Driver accepted this offer.');
        $dispatch->close();
        $delivery = $conn->prepare("INSERT INTO deliveries(order_id,driver_user_id,status,accepted_at) VALUES(?,?,'assigned',NOW())");
        $delivery->bind_param('ii', $acceptedOrder['id'], $userId); $delivery->execute(); $delivery->close();
        $orderUpdate = $conn->prepare("UPDATE orders SET status='assigned',version=version+1 WHERE id=? AND version=?");
        $orderUpdate->bind_param('ii', $acceptedOrder['id'], $acceptedOrder['version']); $orderUpdate->execute();
        if ($orderUpdate->affected_rows !== 1) throw new RuntimeException('Order changed. Refresh before retrying.');
        $orderUpdate->close();
        $history = $conn->prepare("INSERT INTO order_status_history(order_id,status,actor_role,actor_user_id) VALUES(?,'assigned','driver',?)");
        $history->bind_param('ii', $acceptedOrder['id'], $userId); $history->execute(); $history->close();
        $result = ['referenceCode' => $reference, 'status' => 'assigned'];
    } elseif ($command === 'driver_milestone' && $role === 'driver') {
        $reference = mb_substr((string) ($payload['reference_code'] ?? ''), 0, 40);
        $milestone = (string) ($payload['milestone'] ?? '');
        $stmt = $conn->prepare('SELECT v.id delivery_id,v.status delivery_status,v.version delivery_version,o.id order_id,o.status order_status,o.payment_method FROM deliveries v JOIN orders o ON o.id=v.order_id WHERE o.reference_code=? AND v.driver_user_id=? FOR UPDATE');
        $stmt->bind_param('si', $reference, $userId);
        $stmt->execute();
        $deliveryRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$deliveryRow) throw new RuntimeException('Assigned delivery not found.');
        $allowed = ['assigned' => 'arrived', 'arrived' => 'picked_up', 'picked_up' => 'delivered'];
        if (($allowed[$deliveryRow['delivery_status']] ?? '') !== $milestone) throw new RuntimeException('Driver milestone must follow the active delivery sequence.');
        $orderStatus = ['arrived' => 'assigned', 'picked_up' => 'picked_up', 'delivered' => 'delivered'][$milestone];
        $update = $conn->prepare('UPDATE deliveries SET status=?,delivered_at=IF(?="delivered",NOW(),delivered_at),version=version+1 WHERE id=? AND version=?');
        $update->bind_param('ssii', $milestone, $milestone, $deliveryRow['delivery_id'], $deliveryRow['delivery_version']);
        $update->execute();
        if ($update->affected_rows !== 1) throw new RuntimeException('Delivery changed. Refresh before retrying.');
        $update->close();
        $orderUpdate = $conn->prepare('UPDATE orders SET status=?,version=version+1 WHERE id=?');
        $orderUpdate->bind_param('si', $orderStatus, $deliveryRow['order_id']);
        $orderUpdate->execute();
        $orderUpdate->close();
        $event = $conn->prepare('INSERT INTO delivery_milestones(delivery_id,status,actor_user_id) VALUES(?,?,?)');
        $event->bind_param('isi', $deliveryRow['delivery_id'], $milestone, $userId);
        $event->execute();
        $event->close();
        $history = $conn->prepare("INSERT INTO order_status_history(order_id,status,actor_role,actor_user_id) VALUES(?,?,'driver',?)");
        $history->bind_param('isi', $deliveryRow['order_id'], $orderStatus, $userId);
        $history->execute();
        $history->close();
        if ($milestone === 'delivered' && $deliveryRow['payment_method'] === 'cash') {
            $cash = $conn->prepare("UPDATE payments SET status='paid',paid_at=NOW(),version=version+1 WHERE order_id=? AND status='pending'");
            $cash->bind_param('i', $deliveryRow['order_id']);
            $cash->execute();
            $cash->close();
        }
        $result = ['referenceCode' => $reference, 'status' => $milestone];
    } else {
        throw new RuntimeException('Command is not allowed for this role.');
    }

    $response = ['ok' => true, 'message' => 'Platform state synchronized.', 'data' => $result];
    savora_idempotency_store($conn, $userId, $key, $command, $requestHash, $response);
    $conn->commit();
    savora_idempotency_unlock($conn, $userId, $key);
    savora_json($response);
} catch (JsonException) {
    $conn->rollback();
    savora_idempotency_unlock($conn, $userId, $key);
    savora_error(500, 'Response serialization failed.');
} catch (Throwable $error) {
    $conn->rollback();
    savora_idempotency_unlock($conn, $userId, $key);
    savora_error(422, $error->getMessage());
}
