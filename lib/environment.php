<?php
declare(strict_types=1);

function savora_environment(): string
{
    $environment = strtolower(trim((string) (getenv('SAVORA_ENV') ?: 'development')));
    if (!in_array($environment, ['development', 'test', 'production'], true)) throw new RuntimeException('Invalid SAVORA_ENV.');
    return $environment;
}

function savora_demo_mode(?string $localPath = null): bool
{
    if (savora_environment() === 'production') return false;
    $override = getenv('SAVORA_DEMO_MODE');
    if ($override !== false && trim((string) $override) !== '') return trim((string) $override) === '1';
    $localPath ??= __DIR__ . '/../config/local.php';
    if (!is_file($localPath)) return false;
    $config = require $localPath;
    return is_array($config) && ($config['SAVORA_DEMO_MODE'] ?? false) === true;
}

function savora_require_production_database_config(array $config): void
{
    if (savora_environment() !== 'production') return;
    foreach (['host', 'user', 'name'] as $key) if (trim((string) ($config[$key] ?? '')) === '') throw new RuntimeException('Production database configuration is incomplete.');
    if ((string) ($config['password'] ?? '') === '') throw new RuntimeException('Production database credentials are required.');
    if ((string) (getenv('SAVORA_APP_SECRET') ?: '') === '') throw new RuntimeException('SAVORA_APP_SECRET is required in production.');
}
