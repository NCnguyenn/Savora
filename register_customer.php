<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/session_security.php';
require_once __DIR__ . '/lib/admin_security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/services/registration_service.php';
require_once __DIR__ . '/lib/services/rate_limit_service.php';
savora_start_session();
$csrf = admin_issue_csrf_token();
$error = '';
$values = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf((string) ($_POST['_csrf'] ?? ''))) {
        $error = 'Your secure form expired. Refresh and try again.';
    } elseif (!rate_limit_consume($conn, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 'customer_registration_page', 8, 900)) {
        $error = 'Too many registration attempts. Please try again later.';
    } else {
        $result = registration_register_customer($conn, $_POST);
        if ($result['ok'] ?? false) {
            $_SESSION['registration_result'] = ['kind' => 'customer_active', 'title' => 'Your account is ready', 'message' => 'Sign in to start ordering with Savora.', 'referenceCode' => ''];
            header('Location: registration_result.php'); exit;
        }
        $error = (string) ($result['message'] ?? 'Registration could not be completed.');
    }
}
$v = static fn(string $key): string => htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$authPageTitle = 'Create a Customer account'; $authNavHref = 'login.php'; $authNavLabel = 'Sign in';
require __DIR__ . '/components/auth_header.php';
?>
<section class="auth-panel auth-panel--padded" aria-labelledby="customer-title">
<h1 class="auth-heading" id="customer-title">Create a Customer account</h1>
<p class="auth-lead">Sign up to order your favorites and get them delivered.</p>
<form method="post" data-auth-form novalidate>
<input type="hidden" name="_csrf" value="<?= auth_escape($csrf) ?>">
<div class="auth-summary auth-summary--error" data-form-summary role="alert" tabindex="-1" <?= $error === '' ? 'hidden' : '' ?>><?= auth_escape($error) ?></div>
<div class="auth-form-grid">
<div class="auth-field"><label for="customer-name">Full name</label><input class="auth-control" id="customer-name" name="fullName" value="<?= $v('fullName') ?>" autocomplete="name" required maxlength="120"></div>
<div class="auth-field"><label for="customer-username">Username</label><input class="auth-control" id="customer-username" name="username" value="<?= $v('username') ?>" autocomplete="username" required minlength="3" maxlength="50"></div>
<div class="auth-field"><label for="customer-email">Email</label><input class="auth-control" id="customer-email" name="email" type="email" value="<?= $v('email') ?>" autocomplete="email" required maxlength="190"></div>
<div class="auth-field"><label for="customer-phone">Phone number</label><input class="auth-control" id="customer-phone" name="phone" type="tel" value="<?= $v('phone') ?>" autocomplete="tel" required maxlength="40"></div>
<div class="auth-field"><label for="customer-password">Password</label><div class="auth-control-wrap"><input class="auth-control" id="customer-password" name="password" type="password" autocomplete="new-password" minlength="10" required><button class="auth-password-toggle" type="button" data-password-toggle aria-label="Show password" aria-pressed="false"><i class="fa-regular fa-eye"></i></button></div><div class="auth-strength" data-password-strength data-strength="weak"></div></div>
<div class="auth-field"><label for="customer-confirmation">Confirm password</label><div class="auth-control-wrap"><input class="auth-control" id="customer-confirmation" name="passwordConfirmation" type="password" data-password-confirmation autocomplete="new-password" minlength="10" required><button class="auth-password-toggle" type="button" data-password-toggle aria-label="Show password" aria-pressed="false"><i class="fa-regular fa-eye"></i></button></div></div>
<div class="auth-field auth-field--full"><label for="customer-address">Delivery address</label><input class="auth-control" id="customer-address" name="deliveryAddress" value="<?= $v('deliveryAddress') ?>" autocomplete="street-address" required maxlength="500"></div>
<div class="auth-field auth-field--full"><label for="customer-notes">Default delivery notes <span class="auth-help">(optional)</span></label><textarea class="auth-control" id="customer-notes" name="defaultDeliveryNotes" maxlength="500"><?= $v('defaultDeliveryNotes') ?></textarea></div>
<label class="auth-check auth-field--full"><input type="checkbox" name="acceptedTerms" value="1" required <?= isset($values['acceptedTerms']) ? 'checked' : '' ?>><span>I agree to the Terms of Service and Privacy Policy.</span></label>
</div>
<div class="auth-actions"><button class="auth-button" type="submit" data-submit-label data-loading-label="Creating account...">Create account</button><div class="auth-notice"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Your account is ready to use immediately after registration.</div></div>
</form>
</section>
<?php require __DIR__ . '/components/auth_footer.php'; ?>
