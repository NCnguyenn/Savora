<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/session_security.php';
savora_start_session();
$authPageTitle = 'Create your account';
$authPageClass = 'auth-role-page';
$authNavHref = 'login.php';
$authNavLabel = 'Sign in';
require __DIR__ . '/components/auth_header.php';
?>
<section class="auth-panel auth-panel--padded" aria-labelledby="role-title">
    <h1 class="auth-heading" id="role-title">How will you use Savora?</h1>
    <p class="auth-lead">Choose an account type. You can always return to sign in if you already have an account.</p>
    <div class="auth-role-grid">
        <a class="auth-role-card" href="register_customer.php">
            <span class="auth-role-icon" aria-hidden="true"><i class="fa-solid fa-bag-shopping"></i></span>
            <h2>Customer</h2><p>Order meals, save delivery details, and track every delivery.</p><strong>Create a Customer account</strong>
        </a>
        <a class="auth-role-card" href="register_restaurant.php">
            <span class="auth-role-icon" aria-hidden="true"><i class="fa-solid fa-store"></i></span>
            <h2>Restaurant</h2><p>Publish your menu and reach more customers after Admin approval.</p><strong>Register your restaurant</strong>
        </a>
        <a class="auth-role-card" href="register_driver.php">
            <span class="auth-role-icon" aria-hidden="true"><i class="fa-solid fa-motorcycle"></i></span>
            <h2>Driver</h2><p>Join the delivery network and receive offers after Admin approval.</p><strong>Apply as a Driver</strong>
        </a>
    </div>
</section>
<?php require __DIR__ . '/components/auth_footer.php'; ?>
