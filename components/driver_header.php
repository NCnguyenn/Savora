<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'driver') {
    header('Location: index.php');
    exit();
}

$driver_current_page = basename($_SERVER['PHP_SELF'] ?? 'driver_dashboard.php');
$driver_routes = [
    'driver_dashboard.php' => ['Overview', 'fa-house'],
    'driver_delivery.php' => ['Active Delivery', 'fa-motorcycle'],
    'driver_history.php' => ['History', 'fa-clock-rotate-left'],
    'driver_earnings.php' => ['Earnings', 'fa-wallet'],
    'driver_profile.php' => ['Profile', 'fa-user']
];
$driver_titles = [
    'driver_dashboard.php' => 'Driver Overview | Savora',
    'driver_delivery.php' => 'Active Delivery | Savora',
    'driver_history.php' => 'Delivery History | Savora',
    'driver_earnings.php' => 'Driver Earnings | Savora',
    'driver_profile.php' => 'Driver Profile | Savora'
];
$driver_document_title = $driver_titles[$driver_current_page] ?? 'Driver Portal | Savora';
$driver_name_raw = (string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Savora Driver');
$driver_name = htmlspecialchars($driver_name_raw, ENT_QUOTES, 'UTF-8');
$driver_initial = htmlspecialchars(strtoupper(substr((string) ($_SESSION['username'] ?? 'D'), 0, 1)), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($driver_document_title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="css/driver_style.css">
</head>
<body class="driver-body" data-driver-session-name="<?php echo $driver_name; ?>">
    <a class="skip-link" href="#driver-main">Skip to main content</a>
    <div class="driver-shell">
        <aside class="driver-sidebar" aria-label="Driver navigation">
            <a class="driver-brand" href="driver_dashboard.php" aria-label="Savora Rider overview">
                <i class="fa-solid fa-seedling" aria-hidden="true"></i>
                <span>Savora<small>Rider</small></span>
            </a>
            <nav class="driver-primary-nav" aria-label="Driver sections">
                <?php foreach ($driver_routes as $route => [$label, $icon]): ?>
                    <a href="<?php echo $route; ?>"<?php echo $driver_current_page === $route ? ' aria-current="page"' : ''; ?>>
                        <i class="fa-solid <?php echo $icon; ?>" aria-hidden="true"></i>
                        <span><?php echo $label; ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="driver-sidebar-bottom">
                <button class="driver-support-link" type="button" data-driver-support>
                    <i class="fa-solid fa-headset" aria-hidden="true"></i>
                    <span>Help &amp; Support</span>
                </button>
                <a class="driver-logout-link" href="logout.php">
                    <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                    <span>Sign out</span>
                </a>
            </div>
        </aside>
        <div class="driver-workspace">
            <header class="driver-topbar">
                <button class="driver-mobile-menu" type="button" aria-expanded="false" aria-controls="driver-mobile-navigation" aria-label="Open driver navigation">
                    <i class="fa-solid fa-bars" aria-hidden="true"></i>
                </button>
                <div class="driver-topbar-status">
                    <span class="driver-status-dot" aria-hidden="true"></span>
                    <span data-driver-topbar-status>Offline</span>
                </div>
                <div class="driver-account">
                    <span class="driver-avatar" aria-hidden="true"><?php echo $driver_initial; ?></span>
                    <span><strong><?php echo $driver_name; ?></strong><small>Delivery partner</small></span>
                </div>
            </header>
            <section id="driver-mobile-navigation" class="driver-mobile-panel" role="dialog" aria-modal="true" aria-labelledby="driver-mobile-navigation-title" hidden>
                <div class="driver-dialog-scrim" data-close-driver-dialog="driver-mobile-navigation"></div>
                <div class="driver-mobile-panel-content" role="document">
                    <header>
                        <h2 id="driver-mobile-navigation-title">Driver navigation</h2>
                        <button type="button" class="driver-icon-button" aria-label="Close driver navigation" data-close-driver-dialog="driver-mobile-navigation">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </header>
                    <nav aria-label="Driver sections">
                        <?php foreach ($driver_routes as $route => [$label, $icon]): ?>
                            <a href="<?php echo $route; ?>"<?php echo $driver_current_page === $route ? ' aria-current="page"' : ''; ?>>
                                <i class="fa-solid <?php echo $icon; ?>" aria-hidden="true"></i><?php echo $label; ?>
                            </a>
                        <?php endforeach; ?>
                        <a href="logout.php"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>Sign out</a>
                    </nav>
                </div>
            </section>
