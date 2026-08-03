<?php
declare(strict_types=1);

if (!function_exists('auth_escape')) {
    function auth_escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
$authPageTitle = isset($authPageTitle) ? (string) $authPageTitle : 'Savora';
$authPageClass = isset($authPageClass) ? (string) $authPageClass : '';
$authNavHref = isset($authNavHref) ? (string) $authNavHref : 'index.php';
$authNavLabel = isset($authNavLabel) ? (string) $authNavLabel : 'Sign in';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= auth_escape($authPageTitle) ?> | Savora</title>
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="css/auth.css">
</head>
<body class="auth-body <?= auth_escape($authPageClass) ?>">
<a class="auth-skip-link" href="#main-content">Skip to main content</a>
<header class="auth-site-header">
    <a class="auth-logo" href="index.php" aria-label="Savora home">
        <span class="auth-logo-mark" aria-hidden="true"><i class="fa-solid fa-utensils"></i></span>
        <span>Savora</span>
    </a>
    <nav aria-label="Authentication">
        <a class="auth-header-link" href="<?= auth_escape($authNavHref) ?>"><?= auth_escape($authNavLabel) ?></a>
    </nav>
</header>
<main id="main-content" class="auth-main">
