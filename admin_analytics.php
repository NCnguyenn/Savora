<?php
declare(strict_types=1);

$admin_page_title = 'Analytics & Reports';
require_once __DIR__ . '/components/admin_header.php';
$filters = [
    'from' => $_GET['from'] ?? null,
    'to' => $_GET['to'] ?? null,
    'service_area' => $_GET['service_area'] ?? '',
    'payment_method' => $_GET['payment_method'] ?? '',
    'order_type' => $_GET['order_type'] ?? '',
];
$analytics = admin_page_data($conn, 'analytics', $filters);
$kpis = $analytics['kpis'];
$revenueMax = max(array_map(static fn(array $row): float => (float) $row['revenue'], $analytics['trend']) ?: [1]);
$funnelMax = max(array_map(static fn(array $row): int => (int) $row['total'], $analytics['funnel']) ?: [1]);
$hourMax = max(array_map(static fn(array $row): int => (int) $row['total'], $analytics['hourly']) ?: [1]);
?>
<main class="admin-main" id="admin-main" tabindex="-1">
    <header class="admin-page-heading">
        <div><p class="admin-eyebrow">PLATFORM PERFORMANCE</p><h1>Analytics &amp; Reports</h1><p>Explore operational, financial and partner performance from authoritative order data.</p></div>
        <div class="admin-page-heading__actions"><a class="admin-button admin-button--ghost" href="api/admin_export.php?type=analytics&amp;from=<?= admin_escape($analytics['from']) ?>&amp;to=<?= admin_escape($analytics['to']) ?>"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> Export CSV</a><button class="admin-button admin-button--primary" type="button" data-admin-print><i class="fa-solid fa-file-pdf" aria-hidden="true"></i> Print server report</button></div>
    </header>

    <form class="admin-filter-bar" method="get" data-admin-filter aria-label="Analytics filters">
        <fieldset class="admin-date-range"><legend>Date range</legend><label><span>From</span><input type="date" name="from" value="<?= admin_escape($analytics['from']) ?>"></label><label><span>To</span><input type="date" name="to" value="<?= admin_escape($analytics['to']) ?>"></label></fieldset>
        <label><span class="sr-only">Service area</span><select name="service_area"><option value="">All service areas</option><option>Central District</option><option>North District</option></select></label>
        <label><span class="sr-only">Payment method</span><select name="payment_method"><option value="">All payment methods</option><option value="card">Card</option><option value="wallet">Wallet</option><option value="cash">Cash</option></select></label>
        <label><span class="sr-only">Order type</span><select name="order_type"><option value="">All order types</option><option value="delivery">Delivery</option><option value="pickup">Pickup</option></select></label>
        <button class="admin-button admin-button--primary" type="submit">Apply filters</button>
    </form>

    <section class="admin-kpi-grid" aria-label="Analytics summary">
        <article class="admin-kpi-card"><span class="admin-kpi-card__icon"><i class="fa-solid fa-dollar-sign" aria-hidden="true"></i></span><div class="admin-kpi-card__label">Gross Order Value <span class="admin-trend">Selected range</span></div><strong>$<?= admin_escape(number_format((float) ($kpis['gross_order_value'] ?? 0), 2)) ?></strong><small>Customer checkout totals</small></article>
        <article class="admin-kpi-card"><span class="admin-kpi-card__icon admin-kpi-card__icon--blue"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span><div class="admin-kpi-card__label">Orders <span class="admin-trend admin-trend--steady">Volume</span></div><strong><?= admin_escape((int) ($kpis['orders'] ?? 0)) ?></strong><small>Placed in the selected period</small></article>
        <article class="admin-kpi-card"><span class="admin-kpi-card__icon admin-kpi-card__icon--sage"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span><div class="admin-kpi-card__label">Completion Rate <span class="admin-trend">Health</span></div><strong><?= admin_escape(number_format((float) ($kpis['completion_rate'] ?? 0), 1)) ?>%</strong><small>Orders delivered successfully</small></article>
        <article class="admin-kpi-card admin-kpi-card--dark"><span class="admin-kpi-card__icon"><i class="fa-solid fa-stopwatch" aria-hidden="true"></i></span><div class="admin-kpi-card__label">Average Delivery Time <span class="admin-trend admin-trend--light">Minutes</span></div><strong><?= admin_escape((int) ($kpis['average_delivery_minutes'] ?? 0)) ?> min</strong><small>Placed to delivered</small></article>
    </section>

    <section class="admin-analytics-grid admin-analytics-grid--primary">
        <article class="admin-card admin-card--wide"><header class="admin-card__header"><div><span class="admin-eyebrow">GROWTH</span><h2>Order &amp; Revenue Trend</h2></div><span class="admin-legend"><i></i> Revenue</span></header><div class="admin-line-chart" role="img" aria-label="Order and Revenue Trend over the selected date range"><?php foreach ($analytics['trend'] as $point): $height = max(8, ((float) $point['revenue'] / $revenueMax) * 100); ?><span class="admin-line-chart__point" style="--height: <?= admin_escape(number_format($height, 2, '.', '')) ?>%"><b>$<?= admin_escape(number_format((float) $point['revenue'], 0)) ?></b><i></i><small><?= admin_escape($point['label']) ?></small></span><?php endforeach; ?></div></article>
        <article class="admin-card"><header class="admin-card__header"><div><span class="admin-eyebrow">CONVERSION</span><h2>Order Completion Funnel</h2></div></header><div class="admin-funnel" role="img" aria-label="Order Completion Funnel by current order status"><?php foreach ($analytics['funnel'] as $step): $width = max(24, ((int) $step['total'] / $funnelMax) * 100); ?><div><span><b><?= admin_escape(ucwords(str_replace('_', ' ', (string) $step['status']))) ?></b><em><?= admin_escape($step['total']) ?></em></span><i style="--width: <?= admin_escape(number_format($width, 2, '.', '')) ?>%"></i></div><?php endforeach; ?></div></article>
    </section>

    <section class="admin-analytics-grid admin-analytics-grid--secondary">
        <article class="admin-card"><header class="admin-card__header"><div><span class="admin-eyebrow">EXCEPTIONS</span><h2>Cancellation Reasons</h2></div></header><div class="admin-reason-chart" role="img" aria-label="Cancellation Reasons ranked by frequency"><?php if (!$analytics['cancellations']): ?><div class="admin-empty-state"><strong>No cancellations</strong><span>There are no cancelled orders in this range.</span></div><?php endif; ?><?php foreach ($analytics['cancellations'] as $reason): ?><div><span><?= admin_escape($reason['reason']) ?></span><strong><?= admin_escape($reason['total']) ?></strong><i style="--width: <?= admin_escape(min(100, (int) $reason['total'] * 18)) ?>%"></i></div><?php endforeach; ?></div></article>
        <article class="admin-card"><header class="admin-card__header"><div><span class="admin-eyebrow">FULFILLMENT</span><h2>Order Health</h2></div></header><div class="admin-health-ring" role="img" aria-label="Order Health based on completion and cancellation rates"><div class="admin-donut"><strong><?= admin_escape(number_format((float) ($kpis['completion_rate'] ?? 0), 0)) ?>%</strong><span>Healthy</span></div><ul><li><i class="admin-chart-dot admin-chart-dot--1"></i>Completed <strong><?= admin_escape(number_format((float) ($kpis['completion_rate'] ?? 0), 1)) ?>%</strong></li><li><i class="admin-chart-dot admin-chart-dot--3"></i>Open <strong><?= admin_escape(max(0, 100 - (float) ($kpis['completion_rate'] ?? 0))) ?>%</strong></li></ul></div></article>
        <article class="admin-card"><header class="admin-card__header"><div><span class="admin-eyebrow">CAPACITY</span><h2>Hourly Demand</h2></div></header><div class="admin-hour-chart" role="img" aria-label="Hourly Demand by order placement hour"><?php foreach ($analytics['hourly'] as $hour): $height = max(7, ((int) $hour['total'] / $hourMax) * 100); ?><span style="--height: <?= admin_escape(number_format($height, 2, '.', '')) ?>%"><i></i><small><?= admin_escape(str_pad((string) $hour['hour'], 2, '0', STR_PAD_LEFT)) ?></small></span><?php endforeach; ?></div></article>
    </section>

    <section class="admin-analytics-grid admin-analytics-grid--tables">
        <article class="admin-card admin-card--flush"><header class="admin-card__header admin-card__header--padded"><div><span class="admin-eyebrow">PARTNERS</span><h2>Top Restaurants</h2></div><a class="admin-text-link" href="admin_restaurants.php">View all</a></header><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Restaurant</th><th>Orders</th><th>Revenue</th><th>Rating</th><th>Cancellation</th></tr></thead><tbody><?php foreach ($analytics['restaurants'] as $restaurant): ?><tr><td><strong><?= admin_escape($restaurant['name']) ?></strong></td><td><?= admin_escape($restaurant['orders']) ?></td><td>$<?= admin_escape(number_format((float) $restaurant['revenue'], 2)) ?></td><td><i class="fa-solid fa-star admin-star" aria-hidden="true"></i> <?= admin_escape($restaurant['rating']) ?></td><td><?= admin_escape($restaurant['cancellation_rate']) ?>%</td></tr><?php endforeach; ?></tbody></table></div></article>
        <article class="admin-card admin-card--flush"><header class="admin-card__header admin-card__header--padded"><div><span class="admin-eyebrow">DELIVERY NETWORK</span><h2>Driver Efficiency</h2></div><a class="admin-text-link" href="admin_drivers.php">View all</a></header><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Driver</th><th>Deliveries</th><th>Acceptance</th><th>Completion</th><th>Rating</th></tr></thead><tbody><?php foreach ($analytics['drivers'] as $driver): ?><tr><td><strong><?= admin_escape($driver['full_name']) ?></strong></td><td><?= admin_escape($driver['deliveries']) ?></td><td><?= admin_escape($driver['acceptance_rate']) ?>%</td><td><?= admin_escape($driver['completion_rate']) ?>%</td><td><i class="fa-solid fa-star admin-star" aria-hidden="true"></i> <?= admin_escape($driver['rating']) ?></td></tr><?php endforeach; ?></tbody></table></div></article>
        <article class="admin-card admin-retention-card"><header class="admin-card__header"><div><span class="admin-eyebrow">CUSTOMERS</span><h2>Customer Retention</h2></div></header><div class="admin-retention-stat"><strong><?= admin_escape(number_format((float) ($analytics['retention']['repeat_rate'] ?? 0), 1)) ?>%</strong><span>repeat customer rate</span></div><dl><div><dt>Active customers</dt><dd><?= admin_escape((int) ($analytics['retention']['active_customers'] ?? 0)) ?></dd></div><div><dt>Repeat customers</dt><dd><?= admin_escape((int) ($analytics['retention']['repeat_customers'] ?? 0)) ?></dd></div></dl></article>
    </section>
</main>
<?php require_once __DIR__ . '/components/admin_footer.php'; ?>
