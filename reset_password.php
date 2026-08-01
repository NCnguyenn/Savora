<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$token = mb_substr(trim((string) ($_REQUEST['token'] ?? '')), 0, 128);
$message = '';
$success = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    if (strlen($password) < 10 || $password !== $confirmation) {
        $message = 'Use at least 10 characters and make sure both passwords match.';
    } else {
        $conn->begin_transaction();
        try {
            $hash = hash('sha256', $token);
            $lookup = $conn->prepare('SELECT id, user_id FROM password_reset_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() FOR UPDATE');
            $lookup->bind_param('s', $hash);
            $lookup->execute();
            $reset = $lookup->get_result()->fetch_assoc();
            $lookup->close();
            if (!$reset) throw new RuntimeException('This recovery link is invalid or has expired.');
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $update = $conn->prepare('UPDATE users SET password = ?, session_version = session_version + 1, version = version + 1 WHERE id = ?');
            $update->bind_param('si', $passwordHash, $reset['user_id']);
            $update->execute();
            $update->close();
            $consume = $conn->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?');
            $consume->bind_param('i', $reset['id']);
            $consume->execute();
            $consume->close();
            $conn->commit();
            $success = true;
            $message = 'Your password has been updated. You can now sign in.';
        } catch (Throwable $error) {
            $conn->rollback();
            $message = $error->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password | Savora</title>
    <style>
        :root{--forest:#073B2B;--forest-dark:#04291E;--coral:#EF634B;--ivory:#FBF9F3;--sage:#E8EDDF;--text:#1C2923;--muted:#657169;--border:#DFE4DA;--focus:#1B75D0}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--ivory);font:16px/1.5 system-ui,sans-serif;color:var(--text)}main{width:min(92vw,480px);padding:2rem;border:1px solid var(--border);border-radius:20px;background:white;box-shadow:0 18px 50px #073b2b1a}h1{margin:.25rem 0;color:var(--forest)}p{color:var(--muted)}label{display:grid;gap:.4rem;margin:1rem 0;font-weight:700}input{padding:.85rem 1rem;border:1px solid var(--border);border-radius:10px;font:inherit}input:focus{outline:3px solid var(--focus);outline-offset:2px}button,a{display:inline-block;padding:.85rem 1.1rem;border:0;border-radius:10px;background:var(--coral);color:white;font-weight:800;text-decoration:none;cursor:pointer}button:hover{background:#d9513c}.notice{padding:.8rem 1rem;border-radius:10px;background:var(--sage);color:var(--forest-dark)}
    </style>
</head>
<body><main><strong>Savora Security</strong><h1>Create a new password</h1><p>Recovery links expire after 30 minutes and can only be used once.</p><?php if($message):?><p class="notice" role="status"><?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></p><?php endif;?><?php if($success):?><a href="index.php">Return to sign in</a><?php else:?><form method="post"><input type="hidden" name="token" value="<?=htmlspecialchars($token,ENT_QUOTES,'UTF-8')?>"><label>New password<input type="password" name="password" minlength="10" autocomplete="new-password" required></label><label>Confirm password<input type="password" name="password_confirmation" minlength="10" autocomplete="new-password" required></label><button type="submit">Update password</button></form><?php endif;?></main></body></html>
