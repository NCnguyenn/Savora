<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/services/rate_limit_service.php';

$token = mb_substr(trim((string) ($_REQUEST['token'] ?? '')), 0, 128);
$message = ''; $success = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!rate_limit_consume($conn, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 'password_reset', 5, 900)) $message = 'Too many recovery attempts. Please try again later.';
    $password = (string) ($_POST['password'] ?? ''); $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    if ($message !== '') { /* Keep the generic rate-limit response. */ }
    elseif (strlen($password) < 10 || $password !== $confirmation) $message = 'Use at least 10 characters and make sure both passwords match.';
    else {
        $conn->begin_transaction();
        try {
            $hash = hash('sha256', $token);
            $lookup = $conn->prepare('SELECT id,user_id FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE');
            $lookup->bind_param('s', $hash); $lookup->execute(); $reset = $lookup->get_result()->fetch_assoc(); $lookup->close();
            if (!$reset) throw new RuntimeException('This recovery link is invalid or has expired.');
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $update = $conn->prepare('UPDATE users SET password=?,session_version=session_version+1,version=version+1 WHERE id=?');
            $update->bind_param('si', $passwordHash, $reset['user_id']); $update->execute(); $update->close();
            $consume = $conn->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE id=?');
            $consume->bind_param('i', $reset['id']); $consume->execute(); $consume->close();
            $conn->commit(); $success = true; $message = 'Your password has been updated. You can now sign in.';
        } catch (Throwable $error) { $conn->rollback(); $message = $error->getMessage(); }
    }
}
$authPageTitle = 'Reset password'; $authNavHref = 'login.php'; $authNavLabel = 'Sign in';
require __DIR__ . '/components/auth_header.php';
?>
<section class="auth-panel auth-panel--padded" aria-labelledby="reset-title"><h1 class="auth-heading" id="reset-title">Create a new password</h1><p class="auth-lead">Recovery links expire after 30 minutes and can only be used once.</p>
<?php if ($message !== ''): ?><div class="auth-notice" role="status"><?= auth_escape($message) ?></div><?php endif; ?>
<?php if ($success): ?><div class="auth-actions"><a class="auth-button" href="login.php">Return to sign in</a></div><?php else: ?>
<form method="post" data-auth-form novalidate><input type="hidden" name="token" value="<?= auth_escape($token) ?>"><div class="auth-summary auth-summary--error" data-form-summary role="alert" tabindex="-1" hidden></div><div class="auth-form-grid">
<div class="auth-field"><label for="reset-password">New password</label><div class="auth-control-wrap"><input class="auth-control" id="reset-password" name="password" type="password" minlength="10" autocomplete="new-password" required><button class="auth-password-toggle" type="button" data-password-toggle aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div><div class="auth-strength" data-password-strength data-strength="weak"></div></div>
<div class="auth-field"><label for="reset-confirmation">Confirm password</label><div class="auth-control-wrap"><input class="auth-control" id="reset-confirmation" name="password_confirmation" type="password" data-password-confirmation minlength="10" autocomplete="new-password" required><button class="auth-password-toggle" type="button" data-password-toggle aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div></div></div><div class="auth-actions"><button class="auth-button" type="submit" data-submit-label data-loading-label="Updating password...">Update password</button></div></form>
<?php endif; ?></section>
<?php require __DIR__ . '/components/auth_footer.php'; ?>
