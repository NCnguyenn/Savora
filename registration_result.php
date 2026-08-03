<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/session_security.php';
savora_start_session();
$registrationResult = $_SESSION['registration_result'] ?? null;
if (!is_array($registrationResult)) { header('Location: register.php'); exit; }
unset($_SESSION['registration_result']);
$authPageTitle = (string) ($registrationResult['title'] ?? 'Registration complete'); $authNavHref = 'index.php'; $authNavLabel = 'Sign in';
require __DIR__ . '/components/auth_header.php';
?>
<section class="auth-panel auth-panel--padded" aria-labelledby="result-title">
<div class="auth-role-icon" aria-hidden="true"><i class="fa-solid <?= ($registrationResult['kind'] ?? '') === 'customer_active' ? 'fa-circle-check' : 'fa-clock' ?>"></i></div>
<h1 class="auth-heading" id="result-title"><?= auth_escape($registrationResult['title'] ?? 'Registration complete') ?></h1>
<p class="auth-lead"><?= auth_escape($registrationResult['message'] ?? '') ?></p>
<?php if (($registrationResult['referenceCode'] ?? '') !== ''): ?><div class="auth-notice">Reference: <strong><?= auth_escape($registrationResult['referenceCode']) ?></strong>. Keep this reference for support.</div><?php endif; ?>
<div class="auth-actions auth-actions--inline"><a class="auth-button auth-button--secondary" href="register.php">Create another account</a><a class="auth-button" href="index.php">Return to sign in</a></div>
</section>
<?php require __DIR__ . '/components/auth_footer.php'; ?>
