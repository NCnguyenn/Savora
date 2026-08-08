<?php
declare(strict_types=1);

require_once __DIR__ . '/../repositories/payment_repository.php';

function sepay_checkout_config(?string $localPath = null): array
{
    $localPath ??= __DIR__ . '/../../config/local.php';
    $local = [];
    if (is_file($localPath)) {
        try {
            $loaded = require $localPath;
            if (is_array($loaded)) $local = $loaded;
        } catch (Throwable) {
            $local = [];
        }
    }
    $value = static function (string $key) use ($local): string {
        $environmentValue = getenv($key);
        if ($environmentValue !== false && trim((string) $environmentValue) !== '') {
            return trim((string) $environmentValue);
        }
        return trim((string) ($local[$key] ?? ''));
    };
    return [
        'bank' => $value('SEPAY_BANK_BIN'),
        'account' => $value('SEPAY_BANK_ACCOUNT'),
        'accountName' => $value('SEPAY_ACCOUNT_NAME'),
    ];
}

function sepay_checkout_amount_vnd(mixed $amount): int
{
    if (!is_numeric($amount)) throw new InvalidArgumentException('A numeric payment amount is required.');
    $numeric = (float) $amount;
    if (!is_finite($numeric) || $numeric <= 0) throw new InvalidArgumentException('A positive payment amount is required.');
    $rounded = (int) round($numeric);
    if ($rounded <= 0) throw new InvalidArgumentException('A positive VND payment amount is required.');
    return $rounded;
}

function sepay_checkout_vietqr_url(array $config, int $amountVnd, string $referenceCode): ?string
{
    $bank = trim((string) ($config['bank'] ?? ''));
    $account = trim((string) ($config['account'] ?? ''));
    $accountName = trim((string) ($config['accountName'] ?? ''));
    $referenceCode = strtoupper(trim($referenceCode));
    if (
        $bank === ''
        || $account === ''
        || $accountName === ''
        || $amountVnd <= 0
        || preg_match('/^SVR-[A-Z0-9-]+$/', $referenceCode) !== 1
    ) {
        return null;
    }
    return 'https://vietqr.app/img?' . http_build_query([
        'acc' => $account,
        'bank' => $bank,
        'amount' => $amountVnd,
        'des' => $referenceCode,
        'template' => 'compact',
    ], '', '&', PHP_QUERY_RFC3986);
}

function sepay_checkout_snapshot(mysqli $conn, int $customerUserId, string $referenceCode): array
{
    $referenceCode = strtoupper(trim($referenceCode));
    if ($customerUserId <= 0 || preg_match('/^SVR-[A-Z0-9-]+$/', $referenceCode) !== 1) return [];
    $row = payment_repository_customer_checkout($conn, $customerUserId, $referenceCode);
    if (
        $row === []
        || (string) ($row['payment_method'] ?? '') !== 'seapay'
        || !in_array((string) ($row['payment_status'] ?? ''), ['pending', 'paid'], true)
    ) {
        return [];
    }
    try {
        $amountVnd = sepay_checkout_amount_vnd($row['payment_amount'] ?? null);
    } catch (InvalidArgumentException) {
        return [];
    }
    return [
        'referenceCode' => (string) $row['reference_code'],
        'paymentMethod' => 'seapay',
        'amountVnd' => $amountVnd,
        'paymentStatus' => (string) $row['payment_status'],
        'paidAt' => $row['paid_at'] === null ? null : (string) $row['paid_at'],
        'orderStatus' => (string) $row['order_status'],
    ];
}
