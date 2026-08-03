<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/session_security.php';
require_once __DIR__ . '/lib/admin_security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/services/rate_limit_service.php';
savora_start_session();
$csrf = admin_issue_csrf_token();
$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!admin_verify_csrf((string) ($_POST['_csrf'] ?? ''))) $message = 'Your secure form expired. Refresh and try again.';
    elseif (!rate_limit_consume($conn, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 'forgot_password', 5, 900)) $message = 'Too many recovery attempts. Please try again later.';
    else $message = 'If an active account matches those details, password recovery instructions will be sent through the configured channel.';
}
$authPageTitle = 'Forgot password'; $authNavHref = 'login.php'; $authNavLabel = 'Sign in';
require __DIR__ . '/components/auth_header.php';
?>
<section class="auth-panel auth-panel--padded" aria-labelledby="forgot-title">
<h1 class="auth-heading" id="forgot-title">Forgot your password?</h1><p class="auth-lead">Enter your username or email. The response is always the same to protect account privacy.</p>
<?php if ($message !== ''): ?><div class="auth-notice" role="status"><?= auth_escape($message) ?></div><?php endif; ?>
<form method="post" data-auth-form data-auth-mode="login" novalidate><input type="hidden" name="_csrf" value="<?= auth_escape($csrf) ?>"><div class="auth-summary auth-summary--error" data-form-summary tabindex="-1" hidden></div><div class="auth-field"><label for="recovery-identity">Username or email</label><input class="auth-control" id="recovery-identity" name="identity" autocomplete="username" required maxlength="190"></div><div class="auth-actions"><button class="auth-button" type="submit" data-submit-label data-loading-label="Submitting...">Continue</button><a href="login.php">Return to sign in</a></div></form>
</section>
<?php require __DIR__ . '/components/auth_footer.php'; ?>
