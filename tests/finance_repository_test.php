<?php
declare(strict_types=1);

if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') { fwrite(STDERR, "BLOCKED: finance repository integration tests require savora_test\n"); exit(2); }
require_once __DIR__ . '/../lib/repositories/finance_repository.php';
require_once __DIR__ . '/support/test_database.php';
function finance_read_expect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$conn = null;
try {
    $conn = savora_test_database();
    $restaurantId = (int) ($conn->query("SELECT id FROM restaurants WHERE demo_key='lotus-kitchen' LIMIT 1")->fetch_assoc()['id'] ?? 0);
    finance_read_expect($restaurantId > 0, 'Seed restaurant is required.');
    $report = finance_repository_report($conn, $restaurantId, ['from' => '2026-01-01', 'to' => '2026-12-31']);
    finance_read_expect(($report['kpis']['grossSales'] ?? 0) > 0, 'Server finance report must read seeded ledger sales.');
    finance_read_expect(count($report['transactions'] ?? []) > 0, 'Server finance report must return ledger transactions.');
    finance_read_expect(count($report['payouts'] ?? []) > 0, 'Server finance report must return restaurant payouts.');
    $invoice = array_values(array_filter($report['documents'], static fn (array $document): bool => str_starts_with((string) $document['id'], 'INV-')))[0] ?? [];
    finance_read_expect($invoice !== [], 'Server finance report must expose an invoice document.');
    finance_read_expect(finance_repository_document($conn, $restaurantId, (string) $invoice['id'], $report['filters'])['id'] === $invoice['id'], 'Invoice print document must remain restaurant-scoped.');
    echo "PASS: restaurant finance repository reads scoped ledger, payouts, and documents\n";
} catch (Throwable $exception) { fwrite(STDERR, $exception->getMessage() . "\n"); exit(1); }
finally { if ($conn instanceof mysqli) $conn->close(); }
