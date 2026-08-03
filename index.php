<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/session_security.php';
require_once __DIR__ . '/lib/environment.php';
savora_start_session();
require_once __DIR__ . '/db.php';
$error = is_string($_SESSION['error'] ?? null) ? $_SESSION['error'] : '';
$notice = is_string($_SESSION['auth_notice'] ?? null) ? $_SESSION['auth_notice'] : '';
$loginUsername = is_string($_SESSION['login_username'] ?? null) ? $_SESSION['login_username'] : '';
unset($_SESSION['error'], $_SESSION['auth_notice'], $_SESSION['login_username']);
$authPageTitle = 'Sign in'; $authPageClass = 'auth-login-page'; $authNavHref = 'register.php'; $authNavLabel = 'Create account';
require __DIR__ . '/components/auth_header.php';
?>
<section class="auth-panel auth-grid" aria-labelledby="login-title">
    <div class="auth-brand-panel"><h1>Good food, closer than ever.</h1><p>Fresh meals from local favorites, delivered to your door.</p></div>
    <div class="auth-form-panel">
        <h1 class="auth-heading" id="login-title">Welcome back</h1><p class="auth-lead">Sign in to continue to Savora.</p>
        <?php if ($notice !== ''): ?><div class="auth-notice" role="status"><?= auth_escape($notice) ?></div><?php endif; ?>
        <form action="auth.php" method="post" data-auth-form data-auth-mode="login" novalidate>
            <div class="auth-summary auth-summary--error" data-form-summary role="alert" tabindex="-1" <?= $error === '' ? 'hidden' : '' ?>><?= auth_escape($error) ?></div>
            <div class="auth-field"><label for="login-username">Username</label><div class="auth-control-wrap"><i class="fa-regular fa-user" aria-hidden="true"></i><input class="auth-control" id="login-username" name="username" value="<?= auth_escape($loginUsername) ?>" autocomplete="username" placeholder="Enter your username" required maxlength="50"></div></div>
            <div class="auth-field" style="margin-top:1rem"><label for="login-password">Password</label><div class="auth-control-wrap"><i class="fa-solid fa-lock" aria-hidden="true"></i><input class="auth-control" id="login-password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required><button class="auth-password-toggle" type="button" data-password-toggle aria-label="Show password" aria-pressed="false"><i class="fa-regular fa-eye"></i></button></div></div>
            <div class="auth-link-row"><a href="register.php">Create an account</a><a href="forgot_password.php">Forgot password?</a></div>
            <div class="auth-actions"><button class="auth-button" type="submit" data-submit-label data-loading-label="Signing in...">Sign in</button></div>
        </form>
        <?php if (savora_demo_mode()): ?>
        <div class="auth-notice" style="margin-top:1.25rem"><strong>Demo accounts</strong><p>customer · restaurant · driver · admin</p><small>Password for demo accounts: <strong>123456</strong></small></div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/components/auth_footer.php'; ?>
