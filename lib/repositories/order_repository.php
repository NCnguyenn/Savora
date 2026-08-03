<?php
declare(strict_types=1);

function order_repository_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $statement = $conn->prepare($sql);
    if ($types !== '') $statement->bind_param($types, ...$params);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function order_repository_one(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    return order_repository_rows($conn, $sql, $types, $params)[0] ?? [];
}

function order_repository_quote_for_customer(mysqli $conn, int $customerUserId, string $quotePublicId, bool $forUpdate = false): array
{
    $sql = 'SELECT q.*,r.name AS restaurant_name,r.status AS restaurant_status,r.accepting_orders,
                   a.address_line1,a.address_line2,a.city,a.region,a.postal_code
            FROM checkout_quotes q
            JOIN restaurants r ON r.id=q.restaurant_id
            JOIN customer_addresses a ON a.id=q.address_id AND a.customer_user_id=q.customer_user_id
            WHERE q.customer_user_id=? AND q.public_id=? LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return order_repository_one($conn, $sql, 'is', [$customerUserId, $quotePublicId]);
}

function order_repository_menu_available(mysqli $conn, string $publicId, bool $forUpdate = false): array
{
    $sql = "SELECT m.id,m.public_id,m.price,m.is_available,r.status AS restaurant_status,r.accepting_orders
            FROM menu_items m JOIN restaurants r ON r.id=m.restaurant_id WHERE m.public_id=? LIMIT 1";
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return order_repository_one($conn, $sql, 's', [$publicId]);
}

function order_repository_insert_order(
    mysqli $conn,
    string $referenceCode,
    int $customerUserId,
    int $restaurantId,
    string $paymentMethod,
    float $subtotal,
    float $discount,
    float $deliveryFee,
    float $total,
    string $deliveryAddress,
    string $deliveryNote,
    int $quoteId,
    ?int $promotionId,
    ?int $feeRuleId
): int {
    $statement = $conn->prepare(
        "INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,discount_amount,delivery_fee,total,delivery_address,delivery_note,quote_id,promotion_id,fee_rule_id)
         VALUES(?,?,?,'pending',?,?,?,?,?,?,?,?,?,?)"
    );
    $statement->bind_param('siisddddssiii', $referenceCode, $customerUserId, $restaurantId, $paymentMethod, $subtotal, $discount, $deliveryFee, $total, $deliveryAddress, $deliveryNote, $quoteId, $promotionId, $feeRuleId);
    $statement->execute();
    $id = (int) $statement->insert_id;
    $statement->close();
    return $id;
}

function order_repository_insert_items(mysqli $conn, int $orderId, array $items): void
{
    $statement = $conn->prepare('INSERT INTO order_items(order_id,item_public_id,item_name,quantity,unit_price,options_text) VALUES(?,?,?,?,?,?)');
    foreach ($items as $item) {
        $itemPublicId = trim((string) ($item['itemPublicId'] ?? ''));
        $name = (string) ($item['name'] ?? 'Saved menu item');
        $quantity = (int) ($item['quantity'] ?? 0);
        $unitPrice = (float) ($item['unitPrice'] ?? 0);
        $options = json_encode($item['options'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $statement->bind_param('issids', $orderId, $itemPublicId, $name, $quantity, $unitPrice, $options);
        $statement->execute();
    }
    $statement->close();
}

function order_repository_insert_history(mysqli $conn, int $orderId, int $customerUserId): void
{
    $statement = $conn->prepare("INSERT INTO order_status_history(order_id,status,actor_role,actor_user_id) VALUES(?,'pending','customer',?)");
    $statement->bind_param('ii', $orderId, $customerUserId);
    $statement->execute();
    $statement->close();
}

function order_repository_one_order(mysqli $conn, int $orderId): array
{
    return order_repository_one($conn, 'SELECT id,reference_code,status,payment_method,subtotal,discount_amount,delivery_fee,total,version FROM orders WHERE id=? LIMIT 1', 'i', [$orderId]);
}

function order_repository_mark_quote_consumed(mysqli $conn, int $quoteId, int $version): bool
{
    $statement = $conn->prepare('UPDATE checkout_quotes SET consumed_at=NOW(),version=version+1 WHERE id=? AND version=? AND consumed_at IS NULL');
    $statement->bind_param('ii', $quoteId, $version);
    $statement->execute();
    $affected = $statement->affected_rows === 1;
    $statement->close();
    return $affected;
}

function order_repository_order_base(mysqli $conn, string $where, string $types = '', array $params = []): array
{
    $sql = 'SELECT o.id,o.reference_code,o.customer_user_id,o.restaurant_id,o.status,o.payment_method,
                   o.subtotal,o.discount_amount,o.delivery_fee,o.total,o.delivery_address,o.delivery_note,
                   o.placed_at,o.updated_at,o.version,r.name AS restaurant_name,
                   p.method AS payment_method_confirmed,p.amount AS payment_amount,p.status AS payment_status,p.paid_at,
                   d.id AS delivery_id,d.driver_user_id,d.status AS delivery_status,d.earning,
                   d.accepted_at,d.delivered_at,d.version AS delivery_version,
                   dd.id AS dispatch_id,dd.status AS dispatch_status,dd.version AS dispatch_version,
                   u.full_name AS customer_name,cp.phone AS customer_phone,
                   ca.latitude AS customer_latitude,ca.longitude AS customer_longitude,
                   dl.latitude AS driver_latitude,dl.longitude AS driver_longitude,dl.accuracy_meters AS driver_accuracy_meters,dl.recorded_at AS driver_recorded_at,dl.version AS driver_location_version,
                   r.address AS restaurant_address,r.city AS restaurant_city,r.phone AS restaurant_phone
            FROM orders o
            JOIN restaurants r ON r.id=o.restaurant_id
            JOIN users u ON u.id=o.customer_user_id
            LEFT JOIN customer_profiles cp ON cp.user_id=o.customer_user_id
            LEFT JOIN payments p ON p.order_id=o.id
            LEFT JOIN deliveries d ON d.order_id=o.id
            LEFT JOIN delivery_dispatches dd ON dd.order_id=o.id
            LEFT JOIN customer_addresses ca ON ca.customer_user_id=o.customer_user_id AND ca.is_default=1
            LEFT JOIN driver_locations dl ON dl.driver_user_id=d.driver_user_id
            WHERE ' . $where;
    return order_repository_rows($conn, $sql, $types, $params);
}

function order_repository_count(mysqli $conn, string $where, string $types = '', array $params = []): int
{
    $rows = order_repository_rows($conn, 'SELECT COUNT(*) AS total FROM orders o JOIN restaurants r ON r.id=o.restaurant_id LEFT JOIN deliveries d ON d.order_id=o.id WHERE ' . $where, $types, $params);
    return (int) ($rows[0]['total'] ?? 0);
}

function order_repository_items_for_order(mysqli $conn, int $orderId): array
{
    $rows = order_repository_rows($conn, 'SELECT item_public_id,item_name,quantity,unit_price,options_text FROM order_items WHERE order_id=? ORDER BY id ASC', 'i', [$orderId]);
    return array_map(static function (array $row): array {
        $options = [];
        if ((string) ($row['options_text'] ?? '') !== '') {
            $decoded = json_decode((string) $row['options_text'], true);
            if (is_array($decoded)) $options = $decoded;
        }
        $itemPublicId = (string) ($row['item_public_id'] ?? '');
        $options = array_map(static fn (array $option): array => [
            'id' => (string) ($option['publicId'] ?? $option['id'] ?? ''),
            'label' => (string) ($option['name'] ?? $option['label'] ?? ''),
            'price' => (float) ($option['priceDelta'] ?? $option['price'] ?? 0),
        ], array_values(array_filter($options, static fn (mixed $option): bool => is_array($option))));
        return [
            'id' => $itemPublicId,
            'itemPublicId' => $itemPublicId,
            'name' => (string) $row['item_name'],
            'quantity' => (int) $row['quantity'],
            'unitPrice' => (float) $row['unit_price'],
            'options' => $options,
        ];
    }, $rows);
}

function order_repository_history_for_order(mysqli $conn, int $orderId): array
{
    $rows = order_repository_rows($conn, 'SELECT status,actor_role,actor_user_id,reason,created_at FROM order_status_history WHERE order_id=? ORDER BY id ASC', 'i', [$orderId]);
    return array_map(static fn (array $row): array => [
        'status' => (string) $row['status'],
        'actorRole' => (string) $row['actor_role'],
        'actorUserId' => $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
        'reason' => (string) ($row['reason'] ?? ''),
        'createdAt' => (string) $row['created_at'],
    ], $rows);
}

function order_repository_milestones_for_delivery(mysqli $conn, ?int $deliveryId): array
{
    if ($deliveryId === null || $deliveryId <= 0) return [];
    $rows = order_repository_rows($conn, 'SELECT status,actor_user_id,created_at FROM delivery_milestones WHERE delivery_id=? ORDER BY id ASC', 'i', [$deliveryId]);
    return array_map(static fn (array $row): array => [
        'status' => (string) $row['status'],
        'actorUserId' => (int) $row['actor_user_id'],
        'createdAt' => (string) $row['created_at'],
    ], $rows);
}

function order_repository_map_order(mysqli $conn, array $row): array
{
    $orderId = (int) $row['id'];
    $deliveryId = $row['delivery_id'] === null ? null : (int) $row['delivery_id'];
    $assignment = $deliveryId === null ? null : [
        'deliveryId' => $deliveryId,
        'driverUserId' => (int) $row['driver_user_id'],
        'status' => (string) $row['delivery_status'],
        'earning' => (float) $row['earning'],
        'acceptedAt' => $row['accepted_at'] === null ? null : (string) $row['accepted_at'],
        'deliveredAt' => $row['delivered_at'] === null ? null : (string) $row['delivered_at'],
        'version' => (int) $row['delivery_version'],
        'location' => $row['driver_latitude'] === null ? null : [
            'latitude' => (float) $row['driver_latitude'],
            'longitude' => (float) $row['driver_longitude'],
            'accuracyMeters' => $row['driver_accuracy_meters'] === null ? null : (float) $row['driver_accuracy_meters'],
            'recordedAt' => (string) $row['driver_recorded_at'],
            'version' => (int) $row['driver_location_version'],
        ],
        'milestones' => order_repository_milestones_for_delivery($conn, $deliveryId),
    ];
    return [
        'id' => (string) $row['reference_code'],
        'internalId' => $orderId,
        'referenceCode' => (string) $row['reference_code'],
        'customerUserId' => (int) $row['customer_user_id'],
        'restaurantId' => (int) $row['restaurant_id'],
        'restaurantName' => (string) $row['restaurant_name'],
        'restaurant' => [
            'id' => (int) $row['restaurant_id'], 'name' => (string) $row['restaurant_name'],
            'address' => (string) ($row['restaurant_address'] ?? ''), 'city' => (string) ($row['restaurant_city'] ?? ''),
            'phone' => (string) ($row['restaurant_phone'] ?? ''),
        ],
        'customer' => [
            'userId' => (int) $row['customer_user_id'], 'fullName' => (string) ($row['customer_name'] ?? ''),
            'phone' => (string) ($row['customer_phone'] ?? ''),
        ],
        'status' => (string) $row['status'],
        'paymentMethod' => (string) $row['payment_method'],
        'subtotal' => (float) $row['subtotal'],
        'discount' => (float) $row['discount_amount'],
        'deliveryFee' => (float) $row['delivery_fee'],
        'total' => (float) $row['total'],
        'address' => (string) ($row['delivery_address'] ?? ''),
        'deliveryLocation' => $row['customer_latitude'] === null ? null : [
            'latitude' => (float) $row['customer_latitude'],
            'longitude' => (float) $row['customer_longitude'],
        ],
        'deliveryNote' => (string) ($row['delivery_note'] ?? ''),
        'createdAt' => (string) $row['placed_at'],
        'updatedAt' => (string) $row['updated_at'],
        'version' => (int) $row['version'],
        'items' => order_repository_items_for_order($conn, $orderId),
        'payment' => [
            'method' => (string) ($row['payment_method_confirmed'] ?? $row['payment_method']),
            'amount' => $row['payment_amount'] === null ? (float) $row['total'] : (float) $row['payment_amount'],
            'status' => (string) ($row['payment_status'] ?? 'pending'),
            'paidAt' => $row['paid_at'] === null ? null : (string) $row['paid_at'],
        ],
        'statusHistory' => order_repository_history_for_order($conn, $orderId),
        'assignment' => $assignment,
        'dispatch' => $row['dispatch_id'] === null ? null : [
            'dispatchId' => (int) $row['dispatch_id'],
            'status' => (string) $row['dispatch_status'],
            'version' => (int) $row['dispatch_version'],
        ],
    ];
}

function order_repository_scoped(mysqli $conn, string $scope, int $userId, array $filters): array
{
    $where = '1=1';
    $types = '';
    $params = [];
    if ($scope === 'customer') {
        $where .= ' AND o.customer_user_id=?'; $types .= 'i'; $params[] = $userId;
    } elseif ($scope === 'restaurant') {
        $where .= ' AND r.owner_user_id=?'; $types .= 'i'; $params[] = $userId;
    } elseif ($scope === 'driver') {
        $where .= ' AND d.driver_user_id=?'; $types .= 'i'; $params[] = $userId;
    }
    $status = trim((string) ($filters['status'] ?? ''));
    if ($status !== '') {
        $allowed = ['pending','confirmed','preparing','ready_for_pickup','assigned','picked_up','on_the_way','delivered','completed','cancelled','refunded'];
        if (!in_array($status, $allowed, true)) throw new InvalidArgumentException('Invalid order status filter.');
        $where .= ' AND o.status=?'; $types .= 's'; $params[] = $status;
    }
    $from = trim((string) ($filters['from'] ?? ''));
    if ($from !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) throw new InvalidArgumentException('Invalid order start date.');
        $where .= ' AND o.placed_at>=?'; $types .= 's'; $params[] = $from . ' 00:00:00';
    }
    $to = trim((string) ($filters['to'] ?? ''));
    if ($to !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) throw new InvalidArgumentException('Invalid order end date.');
        $where .= ' AND o.placed_at<?'; $types .= 's'; $params[] = date('Y-m-d H:i:s', strtotime($to . ' +1 day'));
    }
    $page = max(1, min(1000, (int) ($filters['page'] ?? 1)));
    $pageSize = max(1, min(50, (int) ($filters['pageSize'] ?? 20)));
    $total = order_repository_count($conn, $where, $types, $params);
    $offset = ($page - 1) * $pageSize;
    $listTypes = $types . 'ii';
    $listParams = [...$params, $pageSize, $offset];
    $rows = order_repository_order_base($conn, $where . ' ORDER BY o.placed_at DESC,o.id DESC LIMIT ? OFFSET ?', $listTypes, $listParams);
    return [
        'orders' => array_map(static fn (array $row): array => order_repository_map_order($conn, $row), $rows),
        'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total, 'pages' => $total === 0 ? 0 : (int) ceil($total / $pageSize)],
    ];
}

function order_repository_admin(mysqli $conn, int $orderId): array
{
    if ($orderId <= 0) return [];
    $rows = order_repository_order_base($conn, 'o.id=? LIMIT 1', 'i', [$orderId]);
    return $rows === [] ? [] : order_repository_map_order($conn, $rows[0]);
}

function order_repository_transition_target(mysqli $conn, string $referenceCode, bool $forUpdate = false): array
{
    $sql = 'SELECT o.id,o.reference_code,o.customer_user_id,o.restaurant_id,o.status,o.payment_method,o.version,
                   r.owner_user_id,d.id AS delivery_id,d.driver_user_id,d.status AS delivery_status,d.version AS delivery_version
            FROM orders o JOIN restaurants r ON r.id=o.restaurant_id
            LEFT JOIN deliveries d ON d.order_id=o.id
            WHERE o.reference_code=? LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    return order_repository_one($conn, $sql, 's', [$referenceCode]);
}

function order_repository_set_status(mysqli $conn, int $orderId, string $nextStatus, int $expectedVersion): bool
{
    $statement = $conn->prepare('UPDATE orders SET status=?,version=version+1 WHERE id=? AND version=?');
    $statement->bind_param('sii', $nextStatus, $orderId, $expectedVersion);
    $statement->execute();
    $affected = $statement->affected_rows === 1;
    $statement->close();
    return $affected;
}

function order_repository_insert_history_event(mysqli $conn, int $orderId, string $status, string $actorRole, int $actorUserId, string $reason): void
{
    $statement = $conn->prepare('INSERT INTO order_status_history(order_id,status,actor_role,actor_user_id,reason) VALUES(?,?,?,?,?)');
    $statement->bind_param('issis', $orderId, $status, $actorRole, $actorUserId, $reason);
    $statement->execute();
    $statement->close();
}

function order_repository_create_dispatch(mysqli $conn, int $orderId): void
{
    $statement = $conn->prepare("INSERT INTO delivery_dispatches(order_id,status,attempt_count) VALUES(?,'searching_driver',0) ON DUPLICATE KEY UPDATE status=IF(assigned_driver_user_id IS NULL,'searching_driver',status),version=version+1");
    $statement->bind_param('i', $orderId);
    $statement->execute();
    $statement->close();
}
