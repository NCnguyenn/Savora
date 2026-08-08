<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/admin_repository.php';

function analytics_normalize_filters(array $filters): array
{
    $today = new DateTimeImmutable('today');
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($filters['from'] ?? $today->modify('-30 days')->format('Y-m-d')));
    $to = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($filters['to'] ?? $today->format('Y-m-d')));
    if (!$from || $from->format('Y-m-d') !== (string) ($filters['from'] ?? $from->format('Y-m-d'))) $from = $today->modify('-30 days');
    if (!$to || $to->format('Y-m-d') !== (string) ($filters['to'] ?? $to->format('Y-m-d'))) $to = $today;
    if ($from > $to) [$from, $to] = [$to, $from];

    $paymentMethod = in_array((string) ($filters['orderType'] ?? ''), ['cash', 'card', 'wallet'], true) ? (string) $filters['orderType'] : '';
    $status = in_array((string) ($filters['status'] ?? ''), ['pending', 'accepted', 'preparing', 'ready_for_pickup', 'assigned', 'picked_up', 'in_transit', 'delivered', 'completed', 'cancelled', 'refunded'], true) ? (string) $filters['status'] : '';
    return [
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
        'restaurantId' => max(0, (int) ($filters['restaurantId'] ?? 0)),
        'driverId' => max(0, (int) ($filters['driverId'] ?? 0)),
        'orderType' => $paymentMethod,
        'status' => $status,
    ];
}

function analytics_repository_where(array $filters, string $alias = 'o'): array
{
    $where = ["DATE({$alias}.placed_at) BETWEEN ? AND ?"];
    $types = 'ss';
    $params = [$filters['from'], $filters['to']];
    if ((int) $filters['restaurantId'] > 0) { $where[] = "{$alias}.restaurant_id = ?"; $types .= 'i'; $params[] = (int) $filters['restaurantId']; }
    if ((int) $filters['driverId'] > 0) { $where[] = 'd.driver_user_id = ?'; $types .= 'i'; $params[] = (int) $filters['driverId']; }
    if ($filters['orderType'] !== '') { $where[] = "{$alias}.payment_method = ?"; $types .= 's'; $params[] = $filters['orderType']; }
    if ($filters['status'] !== '') { $where[] = "{$alias}.status = ?"; $types .= 's'; $params[] = $filters['status']; }
    return ['sql' => implode(' AND ', $where), 'types' => $types, 'params' => $params];
}

function analytics_repository_report(mysqli $conn, array $rawFilters): array
{
    $filters = analytics_normalize_filters($rawFilters);
    $where = analytics_repository_where($filters);
    $join = ' LEFT JOIN deliveries d ON d.order_id = o.id AND d.superseded_at IS NULL';
    $kpis = admin_one($conn, "SELECT COUNT(*) AS orders,
        COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled','refunded') THEN o.total ELSE 0 END), 0) AS gmv,
        COALESCE(SUM(CASE WHEN o.status IN ('delivered','completed') THEN o.total ELSE 0 END), 0) AS netRevenue,
        COALESCE(SUM(CASE WHEN o.status = 'cancelled' THEN o.total ELSE 0 END), 0) AS cancelledValue,
        ROUND(100 * SUM(o.status IN ('delivered','completed')) / NULLIF(COUNT(*), 0), 1) AS completionRate
        FROM orders o{$join} WHERE {$where['sql']}", $where['types'], $where['params']);

    $statusRows = admin_rows($conn, "SELECT o.status, COUNT(*) AS total FROM orders o{$join} WHERE {$where['sql']} GROUP BY o.status ORDER BY total DESC", $where['types'], $where['params']);
    $trend = admin_rows($conn, "SELECT DATE(o.placed_at) AS day, COUNT(*) AS orders,
        COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled','refunded') THEN o.total ELSE 0 END), 0) AS gmv
        FROM orders o{$join} WHERE {$where['sql']} GROUP BY DATE(o.placed_at) ORDER BY day", $where['types'], $where['params']);
    $duration = admin_one($conn, "SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, COALESCE(d.accepted_at, o.placed_at), d.delivered_at)), 0) AS minutes
        FROM orders o{$join} WHERE {$where['sql']} AND o.status IN ('delivered','completed') AND d.delivered_at IS NOT NULL", $where['types'], $where['params']);
    $rows = admin_rows($conn, "SELECT o.id, o.reference_code, o.status, o.payment_method, o.total, o.placed_at,
        r.name AS restaurant_name, u.full_name AS customer_name
        FROM orders o JOIN restaurants r ON r.id=o.restaurant_id JOIN users u ON u.id=o.customer_user_id{$join}
        WHERE {$where['sql']} ORDER BY o.placed_at DESC LIMIT 1000", $where['types'], $where['params']);

    return [
        'filters' => $filters,
        'kpis' => [
            'orders' => (int) ($kpis['orders'] ?? 0),
            'gmv' => (float) ($kpis['gmv'] ?? 0),
            'netRevenue' => (float) ($kpis['netRevenue'] ?? 0),
            'cancelledValue' => (float) ($kpis['cancelledValue'] ?? 0),
            'completionRate' => (float) ($kpis['completionRate'] ?? 0),
        ],
        'durationMinutes' => (int) ($duration['minutes'] ?? 0),
        'status' => $statusRows,
        'trend' => $trend,
        'rows' => $rows,
    ];
}
