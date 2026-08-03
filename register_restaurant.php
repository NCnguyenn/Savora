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
    else {
        $result = partner_submit_application($conn, 'restaurant', $_POST, ['logo' => $_FILES['logo'] ?? []]);
        if ($result['ok'] ?? false) { $_SESSION['registration_result'] = ['kind' => 'partner_pending', 'title' => 'Application submitted', 'message' => 'Your Restaurant application is waiting for Admin approval.', 'referenceCode' => (string) $result['data']['referenceCode']]; header('Location: registration_result.php'); exit; }
        $error = (string) ($result['message'] ?? 'Application could not be submitted.');
    }
}
$v = static fn(string $key): string => htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$authPageTitle = 'Register your restaurant'; $authNavHref = 'login.php'; $authNavLabel = 'Sign in';
require __DIR__ . '/components/auth_header.php';
?>
<section class="auth-panel auth-panel--padded" aria-labelledby="restaurant-title">
<h1 class="auth-heading" id="restaurant-title">Register your restaurant</h1><p class="auth-lead">Bring your restaurant closer to more customers. No legal documents are required for this demo.</p>
<form method="post" enctype="multipart/form-data" data-auth-form novalidate>
<input type="hidden" name="_csrf" value="<?= auth_escape($csrf) ?>">
<div class="auth-summary auth-summary--error" data-form-summary role="alert" tabindex="-1" <?= $error === '' ? 'hidden' : '' ?>><?= auth_escape($error) ?></div>
<div class="auth-form-grid">
<div><h2 class="auth-section-title"><i class="fa-regular fa-user"></i> Account information</h2><div class="auth-form-grid">
<div class="auth-field"><label for="restaurant-owner">Owner's full name</label><input class="auth-control" id="restaurant-owner" name="ownerName" value="<?= $v('ownerName') ?>" required maxlength="120"></div>
<div class="auth-field"><label for="restaurant-username">Username</label><input class="auth-control" id="restaurant-username" name="username" value="<?= $v('username') ?>" autocomplete="username" required maxlength="50"></div>
<div class="auth-field"><label for="restaurant-email">Email</label><input class="auth-control" id="restaurant-email" name="email" type="email" value="<?= $v('email') ?>" required maxlength="190"></div>
<div class="auth-field"><label for="restaurant-owner-phone">Phone number</label><input class="auth-control" id="restaurant-owner-phone" name="phone" type="tel" value="<?= $v('phone') ?>" required maxlength="40"></div>
<div class="auth-field"><label for="restaurant-password">Password</label><div class="auth-control-wrap"><input class="auth-control" id="restaurant-password" name="password" type="password" minlength="10" required><button type="button" class="auth-password-toggle" data-password-toggle aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div><div class="auth-strength" data-password-strength data-strength="weak"></div></div>
<div class="auth-field"><label for="restaurant-confirmation">Confirm password</label><div class="auth-control-wrap"><input class="auth-control" id="restaurant-confirmation" name="passwordConfirmation" type="password" data-password-confirmation minlength="10" required><button type="button" class="auth-password-toggle" data-password-toggle aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div></div>
</div></div>
<div><h2 class="auth-section-title"><i class="fa-solid fa-store"></i> Restaurant information</h2><div class="auth-form-grid">
<div class="auth-field"><label for="restaurant-name">Restaurant name</label><input class="auth-control" id="restaurant-name" name="restaurantName" value="<?= $v('restaurantName') ?>" required maxlength="160"></div>
<div class="auth-field"><label for="restaurant-cuisine">Cuisine type</label><input class="auth-control" id="restaurant-cuisine" name="cuisine" value="<?= $v('cuisine') ?>" required maxlength="100"></div>
<div class="auth-field auth-field--full"><label for="restaurant-description">Restaurant description <span class="auth-help">(optional)</span></label><textarea class="auth-control" id="restaurant-description" name="description" maxlength="1000"><?= $v('description') ?></textarea></div>
<div class="auth-field"><label for="restaurant-address">Address</label><input class="auth-control" id="restaurant-address" name="address" value="<?= $v('address') ?>" required maxlength="500"></div>
<div class="auth-field"><label for="restaurant-city">City</label><input class="auth-control" id="restaurant-city" name="city" value="<?= $v('city') ?>" required maxlength="100"></div>
<div class="auth-field"><label for="restaurant-phone">Restaurant phone number</label><input class="auth-control" id="restaurant-phone" name="restaurantPhone" type="tel" value="<?= $v('restaurantPhone') ?>" required maxlength="40"></div>
<div class="auth-field"><label for="restaurant-logo">Profile image or logo <span class="auth-help">(optional)</span></label><input class="auth-control" id="restaurant-logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp"></div>
<div class="auth-field"><label for="restaurant-opens">Opening time</label><input class="auth-control" id="restaurant-opens" name="opensAt" type="time" value="<?= $v('opensAt') ?>" required></div>
<div class="auth-field"><label for="restaurant-closes">Closing time</label><input class="auth-control" id="restaurant-closes" name="closesAt" type="time" value="<?= $v('closesAt') ?>" required></div>
<div class="auth-file-preview auth-field--full" data-logo-preview hidden></div>
</div></div>
<label class="auth-check auth-field--full"><input type="checkbox" name="acceptedTerms" value="1" required <?= isset($values['acceptedTerms']) ? 'checked' : '' ?>><span>I agree to the Restaurant Partner Terms.</span></label>
</div>
<div class="auth-actions"><button class="auth-button" type="submit" data-submit-label data-loading-label="Submitting application...">Submit registration</button><div class="auth-notice"><i class="fa-regular fa-clock"></i> After registration: Pending Admin approval.</div></div>
</form></section>
<?php require __DIR__ . '/components/auth_footer.php'; ?>
