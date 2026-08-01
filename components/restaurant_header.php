<?php
require_once __DIR__ . '/../lib/session_security.php';
require_once __DIR__ . '/../db.php';
savora_start_session();
$restaurant_session = savora_validate_session($conn, $_SESSION, session_id(), 'restaurant');
if (!$restaurant_session['ok']) {
    savora_end_session();
    header('Location: index.php');
    exit();
}

$restaurant_current_page = basename($_SERVER['PHP_SELF'] ?? 'restaurant_dashboard.php');
$restaurant_routes = [
    'restaurant_dashboard.php' => ['Overview', 'fa-house'],
    'restaurant_orders.php' => ['Live Orders', 'fa-bag-shopping'],
    'restaurant_order_history.php' => ['Order History', 'fa-clock-rotate-left'],
    'restaurant_menu.php' => ['Menu', 'fa-utensils'],
    'restaurant_profile.php' => ['Storefront', 'fa-store'],
    'restaurant_finance.php' => ['Finance', 'fa-circle-dollar-to-slot'],
    'restaurant_analytics.php' => ['Analytics', 'fa-chart-column'],
    'restaurant_reviews.php' => ['Reviews', 'fa-star']
];
$restaurant_titles = [
    'restaurant_dashboard.php' => 'Restaurant Overview | Savora',
    'restaurant_orders.php' => 'Live Orders | Savora',
    'restaurant_order_history.php' => 'Order History | Savora',
    'restaurant_menu.php' => 'Menu | Savora',
    'restaurant_profile.php' => 'Storefront | Savora',
    'restaurant_finance.php' => 'Finance | Savora',
    'restaurant_analytics.php' => 'Analytics | Savora',
    'restaurant_reviews.php' => 'Reviews | Savora'
];
$restaurant_document_title = $restaurant_titles[$restaurant_current_page] ?? 'Restaurant Portal | Savora';
$restaurant_owner_name = htmlspecialchars((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Restaurant owner'), ENT_QUOTES, 'UTF-8');
$restaurant_initial = htmlspecialchars(strtoupper(substr((string) ($_SESSION['username'] ?? 'S'), 0, 1)), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($restaurant_document_title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="css/restaurant_style.css">
</head>
<body class="restaurant-body">
    <a class="skip-link" href="#restaurant-main">Skip to main content</a>
    <div class="restaurant-shell">
        <aside class="restaurant-sidebar" aria-label="Restaurant navigation">
            <a class="restaurant-brand" href="restaurant_dashboard.php"><i class="fa-solid fa-utensils" aria-hidden="true"></i><span>Savora<small>Restaurant Portal</small></span></a>
            <nav class="restaurant-primary-nav" aria-label="Restaurant sections">
                <?php foreach ($restaurant_routes as $route => [$label, $icon]): ?>
                    <a href="<?php echo $route; ?>"<?php echo $restaurant_current_page === $route ? ' aria-current="page"' : ''; ?>><i class="fa-solid <?php echo $icon; ?>" aria-hidden="true"></i><span><?php echo $label; ?></span></a>
                <?php endforeach; ?>
            </nav>
            <div class="restaurant-sidebar-bottom">
                <a class="restaurant-support-link" href="restaurant_profile.php"><i class="fa-solid fa-headset" aria-hidden="true"></i><span>Need help?<small>Contact support</small></span></a>
                <a class="restaurant-logout-link" href="logout.php"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>Log out</a>
            </div>
        </aside>
        <header class="restaurant-topbar">
            <button class="restaurant-mobile-menu-button" type="button" aria-expanded="false" aria-controls="restaurant-mobile-navigation" aria-label="Open navigation menu"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
            <form class="restaurant-search" action="restaurant_orders.php" method="get" role="search">
                <label class="sr-only" for="restaurant-search">Search orders, customers, or menu items</label>
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input id="restaurant-search" name="q" type="search" placeholder="Search orders, customers, items…">
                <kbd aria-hidden="true">Ctrl K</kbd>
            </form>
            <div class="restaurant-topbar-actions">
                <button class="restaurant-icon-button" type="button" aria-label="View notifications"><i class="fa-regular fa-bell" aria-hidden="true"></i><span class="notification-badge" aria-label="3 notifications">3</span></button>
                <button class="restaurant-accepting-button" type="button" data-accepting-orders aria-pressed="true"><span aria-hidden="true"></span>Accepting orders<i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>
                <div class="restaurant-owner-menu">
                    <button class="restaurant-owner-button" type="button" data-owner-menu aria-expanded="false" aria-controls="restaurant-owner-popover"><span class="restaurant-avatar"><?php echo $restaurant_initial; ?></span><span><strong data-restaurant-name>Savora Kitchen</strong><small><?php echo $restaurant_owner_name; ?></small></span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>
                    <div id="restaurant-owner-popover" class="restaurant-owner-popover" hidden><a href="restaurant_profile.php">Store profile</a><a href="logout.php">Log out</a></div>
                </div>
            </div>
        </header>
        <section id="restaurant-mobile-navigation" class="restaurant-mobile-dialog" role="dialog" aria-modal="true" aria-labelledby="restaurant-mobile-navigation-title" hidden>
            <div class="restaurant-dialog-scrim" data-close-dialog="restaurant-mobile-navigation"></div>
            <div class="restaurant-mobile-panel" role="document">
                <header><h2 id="restaurant-mobile-navigation-title">Restaurant navigation</h2><button type="button" class="restaurant-icon-button" aria-label="Close navigation menu" data-close-dialog="restaurant-mobile-navigation"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
                <nav aria-label="Restaurant sections">
                    <?php foreach ($restaurant_routes as $route => [$label, $icon]): ?>
                        <a href="<?php echo $route; ?>"<?php echo $restaurant_current_page === $route ? ' aria-current="page"' : ''; ?>><i class="fa-solid <?php echo $icon; ?>" aria-hidden="true"></i><?php echo $label; ?></a>
                    <?php endforeach; ?>
                    <a href="logout.php"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>Log out</a>
                </nav>
            </div>
        </section>
