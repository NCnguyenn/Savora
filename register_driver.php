<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/session_security.php';
require_once __DIR__ . '/lib/admin_security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/services/partner_application_service.php';
require_once __DIR__ . '/lib/services/rate_limit_service.php';
savora_start_session();
$csrf = admin_issue_csrf_token(); $error = ''; $values = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf((string) ($_POST['_csrf'] ?? ''))) $error = 'Your secure form expired. Refresh and try again.';
    elseif (!rate_limit_consume($conn, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 'partner_registration_page', 6, 900)) $error = 'Too many application attempts. Please try again later.';
    else { $result = partner_submit_application($conn, 'driver', $_POST, []); if ($result['ok'] ?? false) { $_SESSION['registration_result'] = ['kind' => 'partner_pending', 'title' => 'Application submitted', 'message' => 'Your Driver application is waiting for Admin approval.', 'referenceCode' => (string) $result['data']['referenceCode']]; header('Location: registration_result.php'); exit; } $error = (string) ($result['message'] ?? 'Application could not be submitted.'); }
}
$v = static fn(string $key): string => htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$authPageTitle = 'Register as a Driver'; $authNavHref = 'index.php'; $authNavLabel = 'Sign in';
require __DIR__ . '/components/auth_header.php';
?>
<section class="auth-panel auth-panel--padded" aria-labelledby="driver-title"><h1 class="auth-heading" id="driver-title">Register as a Driver</h1><p class="auth-lead">Become a Savora delivery partner. No legal documents are required for this demo.</p>
<form method="post" data-auth-form novalidate><input type="hidden" name="_csrf" value="<?= auth_escape($csrf) ?>"><div class="auth-summary auth-summary--error" data-form-summary role="alert" tabindex="-1" <?= $error === '' ? 'hidden' : '' ?>><?= auth_escape($error) ?></div>
<div class="auth-form-grid"><div><h2 class="auth-section-title"><i class="fa-regular fa-user"></i> Personal information</h2><div class="auth-form-grid">
<div class="auth-field"><label for="driver-name">Full name</label><input class="auth-control" id="driver-name" name="fullName" value="<?= $v('fullName') ?>" required maxlength="120"></div>
<div class="auth-field"><label for="driver-username">Username</label><input class="auth-control" id="driver-username" name="username" value="<?= $v('username') ?>" required maxlength="50"></div>
<div class="auth-field"><label for="driver-email">Email</label><input class="auth-control" id="driver-email" name="email" type="email" value="<?= $v('email') ?>" required maxlength="190"></div>
<div class="auth-field"><label for="driver-phone">Phone number</label><input class="auth-control" id="driver-phone" name="phone" type="tel" value="<?= $v('phone') ?>" required maxlength="40"></div>
<div class="auth-field"><label for="driver-password">Password</label><div class="auth-control-wrap"><input class="auth-control" id="driver-password" name="password" type="password" minlength="10" required><button type="button" class="auth-password-toggle" data-password-toggle aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div><div class="auth-strength" data-password-strength data-strength="weak"></div></div>
<div class="auth-field"><label for="driver-confirmation">Confirm password</label><div class="auth-control-wrap"><input class="auth-control" id="driver-confirmation" name="passwordConfirmation" type="password" data-password-confirmation minlength="10" required><button type="button" class="auth-password-toggle" data-password-toggle aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div></div>
<div class="auth-field"><label for="driver-city">City</label><input class="auth-control" id="driver-city" name="city" value="<?= $v('city') ?>" required maxlength="100"></div>
<div class="auth-field"><label for="driver-area">Operating area</label><input class="auth-control" id="driver-area" name="serviceArea" value="<?= $v('serviceArea') ?>" required maxlength="160"></div>
</div></div><div><h2 class="auth-section-title"><i class="fa-solid fa-motorcycle"></i> Vehicle information</h2><div class="auth-form-grid">
<div class="auth-field auth-field--full"><label for="driver-type">Vehicle type</label><select class="auth-control" id="driver-type" name="vehicleType" required><option value="">Select a vehicle type</option><?php foreach (['Motorcycle','Bicycle','Car','Van'] as $option): ?><option value="<?= auth_escape($option) ?>" <?= ($values['vehicleType'] ?? '') === $option ? 'selected' : '' ?>><?= auth_escape($option) ?></option><?php endforeach; ?></select></div>
<div class="auth-field auth-field--full"><label for="driver-model">Vehicle name or model</label><input class="auth-control" id="driver-model" name="vehicleModel" value="<?= $v('vehicleModel') ?>" required maxlength="100"></div>
<div class="auth-field"><label for="driver-plate">License plate</label><input class="auth-control" id="driver-plate" name="licensePlate" value="<?= $v('licensePlate') ?>" required maxlength="40"></div>
<div class="auth-field"><label for="driver-color">Vehicle color <span class="auth-help">(optional)</span></label><input class="auth-control" id="driver-color" name="vehicleColor" value="<?= $v('vehicleColor') ?>" maxlength="80"></div>
</div></div><label class="auth-check auth-field--full"><input type="checkbox" name="acceptedTerms" value="1" required <?= isset($values['acceptedTerms']) ? 'checked' : '' ?>><span>I agree to the Driver Partner Terms.</span></label></div>
<div class="auth-actions"><button class="auth-button" type="submit" data-submit-label data-loading-label="Submitting application...">Submit registration</button><div class="auth-notice"><i class="fa-regular fa-clock"></i> After registration: Pending Admin approval.</div></div></form></section>
<?php require __DIR__ . '/components/auth_footer.php'; ?>
