<?php
declare(strict_types=1);

function savora_environment(): string
{
    $environment = strtolower(trim((string) (getenv('SAVORA_ENV') ?: 'development')));
    if (!in_array($environment, ['development', 'test', 'production'], true)) throw new RuntimeException('Invalid SAVORA_ENV.');
    return $environment;
}

function savora_demo_mode(): bool
{
    return getenv('SAVORA_DEMO_MODE') === '1' && savora_environment() !== 'production';
}

function savora_require_production_database_config(array $config): void
{
    if (savora_environment() !== 'production') return;
    foreach (['host', 'user', 'name'] as $key) if (trim((string) ($config[$key] ?? '')) === '') throw new RuntimeException('Production database configuration is incomplete.');
    if ((string) ($config['password'] ?? '') === '') throw new RuntimeException('Production database credentials are required.');
    if ((string) (getenv('SAVORA_APP_SECRET') ?: '') === '') throw new RuntimeException('SAVORA_APP_SECRET is required in production.');
}
