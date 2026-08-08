<?php
require_once __DIR__ . '/../lib/session_security.php';
require_once __DIR__ . '/../lib/customer_access.php';
require_once __DIR__ . '/../db.php';
savora_start_session();
$current_page = basename($_SERVER['PHP_SELF']);
$public_customer_pages = ['customer_dashboard.php', 'customer_restaurant.php', 'product_detail.php', 'customer_cart.php'];
$customer_session = savora_validate_session($conn, $_SESSION, session_id(), 'customer');
$customer_is_authenticated = ($customer_session['ok'] ?? false) === true;
if (!$customer_is_authenticated && !in_array($current_page, $public_customer_pages, true)) {
    $returnTarget = $current_page;
    $queryString = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
    if ($queryString !== '') $returnTarget .= '?' . $queryString;
    customer_redirect_to_login($returnTarget);
}
if ($customer_is_authenticated) {
    if (!savora_session_has_csrf_token($_SESSION)) {
        header('Location: index.php');
        exit();
    }
}
$full_name = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'Customer';
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'customer';
$customer_link = static function (string $route) use ($customer_is_authenticated, $public_customer_pages): string {
    return $customer_is_authenticated || in_array($route, $public_customer_pages, true)
        ? $route
        : customer_login_url($route);
};
$page_titles = [
    'customer_dashboard.php' => 'Discover | Savora',
    'customer_restaurant.php' => 'Restaurant | Savora',
    'product_detail.php' => 'Dish details | Savora',
    'customer_cart.php' => 'Your cart | Savora',
    'customer_checkout.php' => 'Checkout | Savora',
    'customer_history.php' => 'Your orders | Savora',
    'customer_favorites.php' => 'Favorites | Savora',
    'customer_profile.php' => 'Your profile | Savora',
    'customer_wallet.php' => 'Savora Pay | Savora'
];
$document_title = $page_titles[$current_page] ?? 'Savora | Local food delivery';
$initial = strtoupper(substr($username, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($document_title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/customer_style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <?php foreach (($customer_page_styles ?? []) as $style): ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars((string) $style, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo time(); ?>">
    <?php endforeach; ?>
</head>
<body>
    <script>window.SavoraCustomerAuthenticated = <?php echo $customer_is_authenticated ? 'true' : 'false'; ?>;</script>
    <nav class="customer-header" aria-label="Customer navigation">
        <a class="brand" href="<?= htmlspecialchars($customer_link('customer_dashboard.php'), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Savora home">
            <i class="fa-solid fa-utensils" aria-hidden="true"></i><span>Savora</span>
        </a>
        <button class="mobile-menu-toggle" type="button" aria-expanded="false" aria-controls="customer-mobile-menu" aria-label="Open navigation menu">
            <i class="fa-solid fa-bars" aria-hidden="true"></i>
        </button>
        <div id="customer-mobile-menu" class="customer-nav" data-open="false">
            <?php foreach ([
                'customer_dashboard.php' => ['Discover', 'fa-compass'],
                'customer_wallet.php' => ['Wallet', 'fa-wallet'],
                'customer_profile.php' => ['Profile', 'fa-user']
            ] as $route => [$label, $icon]): ?>
                <a href="<?php echo htmlspecialchars($customer_link($route), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $current_page === $route ? ' aria-current="page"' : ''; ?>><i class="fa-solid <?php echo $icon; ?>" aria-hidden="true"></i><span><?php echo $label; ?></span></a>
            <?php endforeach; ?>
            <?php if ($customer_is_authenticated): ?><a href="logout.php" class="logout-link mobile-logout"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i><span>Log out</span></a><?php endif; ?>
        </div>
        <div class="customer-actions">
            <?php if ($customer_is_authenticated): ?>
                <a href="<?php echo htmlspecialchars($customer_link('customer_history.php'), ENT_QUOTES, 'UTF-8'); ?>" class="icon-button" aria-label="Your orders" title="Orders">
                    <i class="fa-solid fa-bag-shopping" aria-hidden="true"></i>
                </a>
                <a href="<?php echo htmlspecialchars($customer_link('customer_favorites.php'), ENT_QUOTES, 'UTF-8'); ?>" class="icon-button" aria-label="Favorites" title="Favorites">
                    <i class="fa-solid fa-heart" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
            <button id="open-cart-btn" class="cart-btn" type="button" aria-label="Open cart" aria-controls="cart-overlay">
                <i class="fa-solid fa-cart-shopping" aria-hidden="true"></i><span id="cart-count" class="cart-badge" hidden>0</span>
            </button>
            <?php if ($customer_is_authenticated): ?><div class="user-dropdown">
                <button class="user-avatar" type="button" aria-label="Open account menu" aria-expanded="false" aria-controls="userDropdown" data-avatar><?php echo htmlspecialchars($initial); ?></button>
                <div class="dropdown-menu" id="userDropdown" hidden>
                    <a href="customer_profile.php"><i class="fa-solid fa-user-gear" aria-hidden="true"></i>My Profile</a>
                    <a href="logout.php" class="logout-link"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>Log out</a>
                </div>
            </div><?php else: ?><a class="customer-sign-in" href="login.php">Sign in</a><?php endif; ?>
        </div>
    </nav>
