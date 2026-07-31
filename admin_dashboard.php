<?php
declare(strict_types=1);

$admin_page_title = 'System Overview';
require_once __DIR__ . '/components/admin_header.php';

$overview = admin_page_data($conn, 'overview');
$accounts = $overview['accounts'];
$roleCounts = ['customer' => 0, 'restaurant' => 0, 'driver' => 0];
foreach ($accounts as $account) {
    if (array_key_exists($account['role'], $roleCounts) && $account['status'] === 'active') {
        $roleCounts[$account['role']]++;
    }
}
$recentAccounts = array_slice($accounts, 0, 5);
?>
<main class="admin-main" id="admin-main" tabindex="-1">
    <header class="admin-page-heading">
        <div>
            <p class="admin-eyebrow">FRIDAY, JULY 31</p>
            <h1>System Overview</h1>
            <p>Monitor platform health, approvals and live operations from one place.</p>
        </div>
        <div class="admin-page-heading__actions">
            <a class="admin-button admin-button--ghost" href="admin_analytics.php"><i class="fa-solid fa-chart-line" aria-hidden="true"></i> View analytics</a>
            <a class="admin-button admin-button--primary" href="admin_orders.php"><i class="fa-solid fa-bolt" aria-hidden="true"></i> Live operations</a>
        </div>
    </header>

    <section class="admin-kpi-grid" aria-label="Platform summary">
        <article class="admin-kpi-card">
            <span class="admin-kpi-card__icon admin-kpi-card__icon--sage"><i class="fa-solid fa-users" aria-hidden="true"></i></span>
            <div class="admin-kpi-card__label">Active customers <span class="admin-trend admin-trend--up">+8.2%</span></div>
            <strong><?= admin_escape($roleCounts['customer']) ?></strong>
            <small>Verified customer accounts</small>
        </article>
        <article class="admin-kpi-card">
            <span class="admin-kpi-card__icon admin-kpi-card__icon--coral"><i class="fa-solid fa-store" aria-hidden="true"></i></span>
            <div class="admin-kpi-card__label">Live restaurants <span class="admin-trend admin-trend--up">+3.1%</span></div>
            <strong><?= admin_escape($roleCounts['restaurant']) ?></strong>
            <small>Approved storefronts</small>
        </article>
        <article class="admin-kpi-card">
            <span class="admin-kpi-card__icon admin-kpi-card__icon--blue"><i class="fa-solid fa-motorcycle" aria-hidden="true"></i></span>
            <div class="admin-kpi-card__label">Online drivers <span class="admin-trend admin-trend--steady">Live</span></div>
            <strong><?= admin_escape($roleCounts['driver']) ?></strong>
            <small>Available delivery partners</small>
        </article>
        <article class="admin-kpi-card admin-kpi-card--dark">
            <span class="admin-kpi-card__icon"><i class="fa-solid fa-shield-heart" aria-hidden="true"></i></span>
            <div class="admin-kpi-card__label">Platform health <span class="admin-trend admin-trend--light">Stable</span></div>
            <strong>99.9%</strong>
            <small>All core services operational</small>
        </article>
    </section>

    <section class="admin-overview-grid">
        <article class="admin-card admin-card--span-two">
            <header class="admin-card__header">
                <div><span class="admin-eyebrow">LIVE ACTIVITY</span><h2>Order volume</h2></div>
                <select class="admin-select" aria-label="Order volume period"><option>Last 7 days</option><option>Last 30 days</option></select>
            </header>
            <div class="admin-chart-placeholder" role="img" aria-label="Order volume trending upward during the week">
                <span style="--bar: 42%"></span><span style="--bar: 58%"></span><span style="--bar: 48%"></span>
                <span style="--bar: 72%"></span><span style="--bar: 64%"></span><span style="--bar: 88%"></span><span style="--bar: 76%"></span>
            </div>
            <div class="admin-chart-labels"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>
        </article>

        <article class="admin-card admin-priority-card">
            <header class="admin-card__header"><div><span class="admin-eyebrow">ACTION CENTER</span><h2>Needs attention</h2></div><span class="admin-count-badge">4</span></header>
            <a class="admin-task-row" href="admin_restaurants.php?status=pending"><span class="admin-task-row__icon"><i class="fa-solid fa-store" aria-hidden="true"></i></span><span><strong>Restaurant approvals</strong><small>2 applications waiting</small></span><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a>
            <a class="admin-task-row" href="admin_drivers.php?status=pending"><span class="admin-task-row__icon"><i class="fa-solid fa-id-card" aria-hidden="true"></i></span><span><strong>Driver verification</strong><small>1 document review</small></span><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a>
            <a class="admin-task-row" href="admin_cases.php?priority=urgent"><span class="admin-task-row__icon admin-task-row__icon--coral"><i class="fa-solid fa-life-ring" aria-hidden="true"></i></span><span><strong>Urgent case</strong><small>Response due in 12 min</small></span><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a>
        </article>
    </section>

    <section class="admin-card">
        <header class="admin-card__header">
            <div><span class="admin-eyebrow">RECENT ACTIVITY</span><h2>New platform accounts</h2></div>
            <a class="admin-text-link" href="admin_accounts.php">Manage all <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
        </header>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead><tr><th>Account</th><th>Role</th><th>Joined</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($recentAccounts as $account): ?>
                    <tr>
                        <td><div class="admin-person"><span class="admin-avatar admin-avatar--soft"><?= admin_escape(strtoupper(substr((string) $account['full_name'], 0, 1))) ?></span><span><strong><?= admin_escape($account['full_name']) ?></strong><small><?= admin_escape($account['email']) ?></small></span></div></td>
                        <td><span class="admin-role-label"><?= admin_escape(ucfirst((string) $account['role'])) ?></span></td>
                        <td><?= admin_escape(date('M j, Y', strtotime((string) $account['created_at']))) ?></td>
                        <td><span class="admin-status admin-status--<?= admin_escape($account['status']) ?>"><i aria-hidden="true"></i><?= admin_escape(ucfirst((string) $account['status'])) ?></span></td>
                        <td><a class="admin-row-action" href="admin_accounts.php?id=<?= admin_escape($account['id']) ?>" aria-label="View <?= admin_escape($account['full_name']) ?>"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/components/admin_footer.php'; ?>
