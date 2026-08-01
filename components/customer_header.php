<?php
require_once __DIR__ . '/../lib/session_security.php';
require_once __DIR__ . '/../db.php';
savora_start_session();
$customer_session = savora_validate_session($conn, $_SESSION, session_id(), 'customer');
if (!$customer_session['ok']) {
    savora_end_session();
    header('Location: index.php');
    exit();
}
$full_name = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'Customer';
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'customer';
$current_page = basename($_SERVER['PHP_SELF']);
$page_titles = [
    'customer_dashboard.php' => 'Discover | Savora',
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
</head>
<body>
    <nav class="customer-header" aria-label="Customer navigation">
        <a class="brand" href="customer_dashboard.php" aria-label="Savora home">
            <i class="fa-solid fa-utensils" aria-hidden="true"></i><span>Savora</span>
        </a>
        <button class="mobile-menu-toggle" type="button" aria-expanded="false" aria-controls="customer-mobile-menu" aria-label="Open navigation menu">
            <i class="fa-solid fa-bars" aria-hidden="true"></i>
        </button>
        <div id="customer-mobile-menu" class="customer-nav" data-open="false">
            <?php foreach ([
                'customer_dashboard.php' => ['Discover', 'fa-compass'],
                'customer_history.php' => ['Orders', 'fa-bag-shopping'],
                'customer_favorites.php' => ['Favorites', 'fa-heart'],
                'customer_wallet.php' => ['Wallet', 'fa-wallet'],
                'customer_profile.php' => ['Profile', 'fa-user']
            ] as $route => [$label, $icon]): ?>
                <a href="<?php echo $route; ?>"<?php echo $current_page === $route ? ' aria-current="page"' : ''; ?>><i class="fa-solid <?php echo $icon; ?>" aria-hidden="true"></i><span><?php echo $label; ?></span></a>
            <?php endforeach; ?>
            <a href="logout.php" class="logout-link mobile-logout"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i><span>Log out</span></a>
        </div>
        <div class="customer-actions">
            <button id="open-cart-btn" class="cart-btn" type="button" aria-label="Open cart" aria-controls="cart-overlay">
                <i class="fa-solid fa-cart-shopping" aria-hidden="true"></i><span id="cart-count" class="cart-badge" hidden>0</span>
            </button>
            <div class="user-dropdown">
                <button class="user-avatar" type="button" aria-label="Open account menu" aria-expanded="false" aria-controls="userDropdown" data-avatar><?php echo htmlspecialchars($initial); ?></button>
                <div class="dropdown-menu" id="userDropdown" hidden>
                    <a href="customer_profile.php"><i class="fa-solid fa-user-gear" aria-hidden="true"></i>My Profile</a>
                    <a href="logout.php" class="logout-link"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>Log out</a>
                </div>
            </div>
        </div>
    </nav>
