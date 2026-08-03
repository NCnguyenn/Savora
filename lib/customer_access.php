<?php
declare(strict_types=1);

require_once __DIR__ . '/session_security.php';

function customer_allowed_return_paths(): array
{
    return [
        'customer_dashboard.php', 'product_detail.php', 'customer_cart.php',
        'customer_checkout.php', 'customer_history.php', 'customer_favorites.php',
        'customer_profile.php', 'customer_wallet.php'
    ];
}

function customer_safe_return_to(mixed $candidate): string
{
    $value = is_string($candidate) ? trim($candidate) : '';
    if ($value === '' || preg_match('/[\r\n]/', $value) === 1) return '';
    $parts = parse_url($value);
    if ($parts === false || isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass'])) return '';
    $path = ltrim((string) ($parts['path'] ?? ''), '/');
    if (!in_array($path, customer_allowed_return_paths(), true)) return '';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    return $path . $query;
}

function customer_login_url(string $returnTo = '', string $notice = ''): string
{
    $safe = customer_safe_return_to($returnTo);
    $query = [];
    if ($safe !== '') $query['return_to'] = $safe;
    if (trim($notice) !== '') $query['notice'] = trim($notice);
    return 'login.php' . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
}

function customer_redirect_to_login(string $returnTo, string $notice = 'Please sign in to continue.'): never
{
    savora_start_session();
    $_SESSION['auth_notice'] = $notice;
    header('Location: ' . customer_login_url($returnTo));
    exit();
}
