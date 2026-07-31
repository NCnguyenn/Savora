<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/admin_security.php';
require_once __DIR__ . '/../lib/admin_repository.php';
require_once __DIR__ . '/../lib/admin_actions.php';

admin_require_role();

$adminRoutes = [
    'admin_dashboard.php' => ['label' => 'Overview', 'icon' => 'fa-chart-pie'],
    'admin_accounts.php' => ['label' => 'Accounts', 'icon' => 'fa-user-shield'],
    'admin_customers.php' => ['label' => 'Customers', 'icon' => 'fa-users'],
    'admin_restaurants.php' => ['label' => 'Restaurants', 'icon' => 'fa-store'],
    'admin_drivers.php' => ['label' => 'Drivers', 'icon' => 'fa-motorcycle'],
    'admin_orders.php' => ['label' => 'Orders', 'icon' => 'fa-receipt'],
    'admin_cases.php' => ['label' => 'Cases & Refunds', 'icon' => 'fa-life-ring'],
    'admin_finance.php' => ['label' => 'Finance', 'icon' => 'fa-wallet'],
    'admin_promotions.php' => ['label' => 'Promotions & Fees', 'icon' => 'fa-tags'],
    'admin_analytics.php' => ['label' => 'Analytics', 'icon' => 'fa-chart-line'],
    'admin_settings.php' => ['label' => 'Settings & Audit', 'icon' => 'fa-sliders'],
];

$adminCurrentRoute = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'admin_dashboard.php'));
$adminCurrentMeta = $adminRoutes[$adminCurrentRoute] ?? $adminRoutes['admin_dashboard.php'];
$adminPageTitle = $admin_page_title ?? $adminCurrentMeta['label'];
$adminUsername = (string) ($_SESSION['username'] ?? 'Administrator');
$adminInitial = strtoupper(substr($adminUsername, 0, 1));
$adminCsrfToken = admin_csrf_token();

function admin_render_navigation(array $routes, string $currentRoute, string $suffix = ''): void
{
    foreach ($routes as $route => $meta) {
        $active = $route === $currentRoute;
        ?>
        <a class="admin-nav__link<?= $active ? ' is-active' : '' ?>"
           href="<?= admin_escape($route) ?>"
           <?= $active ? 'aria-current="page"' : '' ?>>
            <i class="fa-solid <?= admin_escape($meta['icon']) ?>" aria-hidden="true"></i>
            <span><?= admin_escape($meta['label']) ?></span>
        </a>
        <?php
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="admin-csrf-token" content="<?= admin_escape($adminCsrfToken) ?>">
    <title><?= admin_escape($adminPageTitle) ?> · Savora Admin</title>
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="css/admin_style.css">
</head>
<body class="admin-body" data-admin-page="<?= admin_escape($adminCurrentRoute) ?>">
<a class="skip-link" href="#admin-main">Skip to main content</a>

<div class="admin-shell">
    <aside class="admin-sidebar" aria-label="Admin navigation">
        <a class="admin-brand" href="admin_dashboard.php" aria-label="Savora Admin home">
            <span class="admin-brand__mark"><i class="fa-solid fa-leaf" aria-hidden="true"></i></span>
            <span><strong>Savora</strong><small>CONTROL CENTER</small></span>
        </a>

        <nav class="admin-nav" aria-label="Admin navigation">
            <p class="admin-nav__eyebrow">PLATFORM</p>
            <?php admin_render_navigation($adminRoutes, $adminCurrentRoute); ?>
        </nav>

        <div class="admin-sidebar__footer">
            <div class="admin-support-card">
                <i class="fa-solid fa-headset" aria-hidden="true"></i>
                <div><strong>Need support?</strong><span>Operations handbook</span></div>
            </div>
            <a class="admin-nav__link" href="logout.php">
                <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>
                <span>Sign out</span>
            </a>
        </div>
    </aside>

    <div class="admin-stage">
        <header class="admin-topbar">
            <button class="admin-icon-button admin-menu-button" type="button"
                    data-admin-open="mobile-navigation" aria-label="Open navigation" aria-controls="admin-mobile-navigation">
                <i class="fa-solid fa-bars" aria-hidden="true"></i>
            </button>

            <form class="admin-global-search" action="admin_orders.php" method="get" role="search">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <label class="sr-only" for="admin-global-search">Global search</label>
                <input id="admin-global-search" name="q" type="search" aria-label="Global search"
                       placeholder="Search orders, people, restaurants…" autocomplete="off">
                <kbd>⌘ K</kbd>
            </form>

            <div class="admin-topbar__actions">
                <span class="admin-mode"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Admin Mode</span>
                <button class="admin-icon-button" type="button" data-admin-open="notifications"
                        aria-label="Open notifications">
                    <i class="fa-regular fa-bell" aria-hidden="true"></i><span class="admin-dot" aria-hidden="true"></span>
                </button>
                <button class="admin-profile-button" type="button" data-admin-open="profile" aria-label="Open administrator profile">
                    <span class="admin-avatar"><?= admin_escape($adminInitial) ?></span>
                    <span class="admin-profile-button__copy"><strong><?= admin_escape($adminUsername) ?></strong><small>Super Administrator</small></span>
                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                </button>
            </div>
        </header>

        <dialog class="admin-mobile-dialog" id="admin-mobile-navigation" role="dialog"
                aria-modal="true" aria-label="Mobile admin navigation" data-admin-mobile-navigation>
            <div class="admin-mobile-dialog__header">
                <span class="admin-brand"><span class="admin-brand__mark"><i class="fa-solid fa-leaf" aria-hidden="true"></i></span><strong>Savora</strong></span>
                <button class="admin-icon-button" type="button" data-admin-close aria-label="Close navigation">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
            <nav class="admin-nav" aria-label="Mobile admin navigation">
                <?php admin_render_navigation($adminRoutes, $adminCurrentRoute, '-mobile'); ?>
            </nav>
        </dialog>
