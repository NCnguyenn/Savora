<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/environment.php';
savora_demo_mode();
header('Location: customer_dashboard.php');
exit();
