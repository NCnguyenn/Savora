<?php
declare(strict_types=1);

$admin_page_title = 'System Overview';
require_once __DIR__ . '/components/admin_header.php';
$overview = admin_page_data($conn, 'overview');
$metrics = $overview['metrics'];
$trendMax = max(array_map(static fn(array $row): float => (float) $row['revenue'], $overview['trend']) ?: [1]);
$statusTotal = array_sum(array_map(static fn(array $row): int => (int) $row['total'], $overview['status_distribution'])) ?: 1;
?>
<main class="admin-main" id="admin-main" tabindex="-1">
    <header class="admin-page-heading">
        <div><p class="admin-eyebrow"><?= admin_escape(strtoupper(date('l, F j'))) ?></p><h1>System Overview</h1><p>Monitor service health, partner approvals, live orders and high-priority work.</p></div>
        <div class="admin-page-heading__actions">
            <span class="admin-live-indicator"><i aria-hidden="true"></i> Live · updated now</span>
            <a class="admin-button admin-button--ghost" href="admin_analytics.php"><i class="fa-solid fa-chart-line" aria-hidden="true"></i> Open analytics</a>
        </div>
    </header>

    <section class="admin-kpi-grid" aria-label="Platform summary">
        <article class="admin-kpi-card"><span class="admin-kpi-card__icon"><i class="fa-solid fa-dollar-sign" aria-hidden="true"></i></span><div class="admin-kpi-card__label">Gross order value <span class="admin-trend">30 days</span></div><strong>$<?= admin_escape(number_format((float) ($metrics['gross_order_value'] ?? 0), 2)) ?></strong><small>Across <?= admin_escape((int) ($metrics['total_orders'] ?? 0)) ?> orders</small></article>
        <article class="admin-kpi-card"><span class="admin-kpi-card__icon admin-kpi-card__icon--blue"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i></span><div class="admin-kpi-card__label">Active orders <span class="admin-trend admin-trend--steady">Live</span></div><strong><?= admin_escape((int) ($metrics['active_orders'] ?? 0)) ?></strong><small>All fulfillment stages</small></article>
        <article class="admin-kpi-card"><span class="admin-kpi-card__icon admin-kpi-card__icon--sage"><i class="fa-solid fa-coins" aria-hidden="true"></i></span><div class="admin-kpi-card__label">Platform revenue <span class="admin-trend">30 days</span></div><strong>$<?= admin_escape(number_format((float) ($metrics['platform_revenue'] ?? 0), 2)) ?></strong><small>Derived from immutable ledger fees</small></article>
        <article class="admin-kpi-card admin-kpi-card--dark"><span class="admin-kpi-card__icon"><i class="fa-solid fa-user-clock" aria-hidden="true"></i></span><div class="admin-kpi-card__label">Pending approvals <span class="admin-trend admin-trend--light">Action</span></div><strong><?= admin_escape((int) ($metrics['pending_approvals'] ?? 0)) ?></strong><small>Restaurant and Driver applications</small></article>
    </section>

    <section class="admin-dashboard-grid admin-dashboard-grid--operations">
        <article class="admin-card admin-card--flush admin-card--wide">
            <header class="admin-card__header admin-card__header--padded"><div><span class="admin-eyebrow">OPERATIONS</span><h2>Live Operations</h2></div><a class="admin-text-link" href="admin_orders.php">View all orders <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a></header>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Order</th><th>Customer</th><th>Restaurant</th><th>Status</th><th>Driver</th><th>Total</th></tr></thead><tbody>
            <?php if (!$overview['live_orders']): ?><tr><td colspan="6"><div class="admin-empty-state"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><strong>No live orders</strong><span>The operation queue is clear.</span></div></td></tr><?php endif; ?>
            <?php foreach ($overview['live_orders'] as $order): ?><tr><td><a class="admin-reference" href="admin_orders.php?id=<?= admin_escape($order['id']) ?>"><?= admin_escape($order['reference_code']) ?></a><small class="admin-cell-note"><?= admin_escape(date('H:i', strtotime((string) $order['placed_at']))) ?></small></td><td><?= admin_escape($order['customer_name']) ?></td><td><?= admin_escape($order['restaurant_name']) ?></td><td><span class="admin-status admin-status--<?= admin_escape($order['status']) ?>"><i aria-hidden="true"></i><?= admin_escape(ucwords(str_replace('_', ' ', (string) $order['status']))) ?></span></td><td><?= admin_escape($order['driver_name'] ?: 'Searching') ?></td><td><strong>$<?= admin_escape(number_format((float) $order['total'], 2)) ?></strong></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </article>

        <article class="admin-card admin-card--flush">
            <header class="admin-card__header admin-card__header--padded"><div><span class="admin-eyebrow">PARTNER ONBOARDING</span><h2>Approval Queue</h2></div><span class="admin-count-badge"><?= admin_escape(count($overview['approval_queue'])) ?></span></header>
            <div class="admin-queue-list">
            <?php foreach ($overview['approval_queue'] as $application): ?>
                <a class="admin-queue-item" href="admin_<?= admin_escape($application['application_type'] === 'restaurant' ? 'restaurants' : 'drivers') ?>.php?id=<?= admin_escape($application['id']) ?>">
                    <span class="admin-queue-item__icon"><i class="fa-solid <?= $application['application_type'] === 'restaurant' ? 'fa-store' : 'fa-id-card' ?>" aria-hidden="true"></i></span>
                    <span><strong><?= admin_escape($application['applicant_name']) ?></strong><small><?= admin_escape($application['reference_code']) ?> · <?= admin_escape($application['city']) ?></small></span>
                    <span class="admin-risk admin-risk--<?= admin_escape($application['risk_level']) ?>"><?= admin_escape(ucfirst((string) $application['risk_level'])) ?></span>
                </a>
            <?php endforeach; ?>
            </div>
            <div class="admin-card__footer-links"><a href="admin_restaurants.php">Restaurants</a><a href="admin_drivers.php">Drivers</a></div>
        </article>
    </section>

    <section class="admin-dashboard-grid admin-dashboard-grid--charts">
        <article class="admin-card">
            <header class="admin-card__header"><div><span class="admin-eyebrow">FINANCE SIGNAL</span><h2>Revenue Trend</h2></div><span class="admin-legend"><i></i> Daily GOV</span></header>
            <div class="admin-bar-chart" role="img" aria-label="Revenue Trend for the last seven days">
                <?php foreach ($overview['trend'] as $point): $height = max(8, ((float) $point['revenue'] / $trendMax) * 100); ?><span class="admin-bar-chart__item"><span class="admin-bar-chart__bar" style="--height: <?= admin_escape(number_format($height, 2, '.', '')) ?>%"><b>$<?= admin_escape(number_format((float) $point['revenue'], 0)) ?></b></span><small><?= admin_escape($point['label']) ?></small></span><?php endforeach; ?>
            </div>
        </article>

        <article class="admin-card">
            <header class="admin-card__header"><div><span class="admin-eyebrow">FULFILLMENT</span><h2>Order Status Distribution</h2></div></header>
            <div class="admin-donut-layout" role="img" aria-label="Order Status Distribution for the last thirty days">
                <div class="admin-donut"><strong><?= admin_escape($statusTotal) ?></strong><span>Orders</span></div>
                <div class="admin-chart-list"><?php foreach ($overview['status_distribution'] as $index => $status): ?><div><i class="admin-chart-dot admin-chart-dot--<?= admin_escape(($index % 4) + 1) ?>"></i><span><?= admin_escape(ucwords(str_replace('_', ' ', (string) $status['status']))) ?></span><strong><?= admin_escape(round(((int) $status['total'] / $statusTotal) * 100)) ?>%</strong></div><?php endforeach; ?></div>
            </div>
        </article>

        <article class="admin-card admin-alert-card">
            <header class="admin-card__header"><div><span class="admin-eyebrow">RISK & SLA</span><h2>High Priority Alerts</h2></div><a class="admin-text-link" href="admin_cases.php">Open cases</a></header>
            <?php if (!$overview['alerts']): ?><div class="admin-empty-state"><i class="fa-solid fa-shield" aria-hidden="true"></i><strong>No urgent alerts</strong><span>All SLAs are within target.</span></div><?php endif; ?>
            <?php foreach ($overview['alerts'] as $alert): ?><a class="admin-alert-row" href="admin_cases.php?id=<?= admin_escape($alert['id']) ?>"><span class="admin-alert-row__icon"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span><span><strong><?= admin_escape($alert['subject']) ?></strong><small><?= admin_escape($alert['reference_code']) ?> · <?= admin_escape(ucfirst((string) $alert['status'])) ?></small></span><time><?= admin_escape($alert['sla_due_at'] ? date('H:i', strtotime((string) $alert['sla_due_at'])) : 'No SLA') ?></time></a><?php endforeach; ?>
        </article>
    </section>

    <section class="admin-card admin-card--flush">
        <header class="admin-card__header admin-card__header--padded"><div><span class="admin-eyebrow">CONTROLLED INTERVENTIONS</span><h2>Recent Admin Activity</h2></div><a class="admin-text-link" href="admin_settings.php#audit-log">View immutable audit log</a></header>
        <div class="admin-activity-strip"><?php foreach ($overview['activity'] as $activity): ?><article><span class="admin-avatar admin-avatar--soft"><?= admin_escape(strtoupper(substr((string) $activity['actor_name'], 0, 1))) ?></span><div><strong><?= admin_escape(ucwords(str_replace('_', ' ', (string) $activity['action']))) ?></strong><p><?= admin_escape($activity['reason'] ?: $activity['entity_type']) ?></p><small><?= admin_escape($activity['reference_id']) ?> · <?= admin_escape(date('M j, H:i', strtotime((string) $activity['created_at']))) ?></small></div></article><?php endforeach; ?></div>
    </section>
</main>
<?php require_once __DIR__ . '/components/admin_footer.php'; ?>
