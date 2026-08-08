<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/environment.php';

function environment_test_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function environment_test_restore(string $name, string|false $value): void
{
    putenv($value === false ? $name : $name . '=' . $value);
}

$previousEnvironment = getenv('SAVORA_ENV');
$previousDemoMode = getenv('SAVORA_DEMO_MODE');
$trueConfigPath = tempnam(sys_get_temp_dir(), 'savora-demo-true-');
$falseConfigPath = tempnam(sys_get_temp_dir(), 'savora-demo-false-');
if ($trueConfigPath === false || $falseConfigPath === false) {
    throw new RuntimeException('Unable to create temporary local config fixtures.');
}

try {
    file_put_contents($trueConfigPath, "<?php\nreturn ['SAVORA_DEMO_MODE' => true];\n");
    file_put_contents($falseConfigPath, "<?php\nreturn ['SAVORA_DEMO_MODE' => false];\n");

    putenv('SAVORA_ENV=development');
    putenv('SAVORA_DEMO_MODE');
    environment_test_expect(
        savora_demo_mode($trueConfigPath) === true,
        'Laptop-local demo mode must work in development.'
    );

    putenv('SAVORA_DEMO_MODE=0');
    environment_test_expect(
        savora_demo_mode($trueConfigPath) === false,
        'An explicit disabled environment value must override local demo mode.'
    );

    putenv('SAVORA_DEMO_MODE=1');
    environment_test_expect(
        savora_demo_mode($falseConfigPath) === true,
        'An explicit enabled environment value must enable demo mode outside production.'
    );

    putenv('SAVORA_ENV=production');
    environment_test_expect(
        savora_demo_mode($trueConfigPath) === false,
        'Production must disable demo mode even when the environment enables it.'
    );
    putenv('SAVORA_DEMO_MODE');
    environment_test_expect(
        savora_demo_mode($trueConfigPath) === false,
        'Production must disable demo mode even when local config enables it.'
    );
} finally {
    environment_test_restore('SAVORA_ENV', $previousEnvironment);
    environment_test_restore('SAVORA_DEMO_MODE', $previousDemoMode);
    @unlink($trueConfigPath);
    @unlink($falseConfigPath);
}

echo "PASS: demo mode honors local config, explicit overrides, and production safety\n";
