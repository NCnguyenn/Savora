<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/platform_schema.php';

return static function (mysqli $conn): void {
    platform_migrate($conn);
};
