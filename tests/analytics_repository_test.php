<?php
declare(strict_types=1);

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
    fwrite(STDERR, "BLOCKED: analytics integration tests require savora_test\n");
    exit(2);
}

require_once __DIR__ . '/../lib/repositories/analytics_repository.php';
require_once __DIR__ . '/../lib/services/export_service.php';
require_once __DIR__ . '/support/test_database.php';

function analytics_expect(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$conn = null;
try {
    $conn = savora_test_database();
    $filters = analytics_normalize_filters(['from' => '2026-08-01', 'to' => '2026-08-02', 'restaurantId' => 1]);
    $data = analytics_repository_report($conn, $filters);
    analytics_expect(($data['filters']['restaurantId'] ?? 0) === 1, 'Restaurant filter must be preserved.');
    analytics_expect(isset($data['kpis']['gmv'], $data['kpis']['netRevenue'], $data['durationMinutes']), 'Analytics must expose server-defined KPIs.');
    $csv = export_csv_string(['Order', 'Amount'], [['=SUM(A1)', '10.00'], ['normal', '2.00']]);
    analytics_expect(str_contains($csv, "'=SUM(A1)"), 'CSV export must protect formula injection.');
    echo "PASS: analytics definitions and CSV export hold\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} finally {
    if ($conn instanceof mysqli) $conn->close();
}
