<?php
declare(strict_types=1);

function sepay_webhook_api_key(?string $localPath = null): string
{
    $environmentKey = trim((string) getenv('SEPAY_WEBHOOK_API_KEY'));
    if ($environmentKey !== '' && !preg_match('/^(PASTE_YOUR_|YOUR_API_KEY|<.*>)/i', $environmentKey)) {
        return $environmentKey;
    }

    $localPath ??= __DIR__ . '/../../config/local.php';
    if (!is_file($localPath)) return '';
    try {
        $local = require $localPath;
    } catch (Throwable) {
        return '';
    }
    if (!is_array($local)) return '';
    $key = trim((string) ($local['SEPAY_WEBHOOK_API_KEY'] ?? ''));
    return preg_match('/^(PASTE_YOUR_|YOUR_API_KEY|<.*>)/i', $key) ? '' : $key;
}

function sepay_webhook_authorization_header(array $server): string
{
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        $header = trim((string) ($server[$key] ?? ''));
        if ($header !== '') return $header;
    }
    return '';
}

function sepay_webhook_is_authorized(array $server, string $expectedKey): bool
{
    $expectedKey = trim($expectedKey);
    if ($expectedKey === '') return false;
    $header = sepay_webhook_authorization_header($server);
    if (!preg_match('/^Apikey[ \t]+(.+)$/iD', $header, $matches)) return false;
    return hash_equals($expectedKey, trim((string) $matches[1]));
}

function sepay_webhook_amount_cents(mixed $value): int
{
    $amount = trim((string) $value);
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
        throw new InvalidArgumentException('Transfer amount is invalid.');
    }
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    if ((int) $whole > intdiv(PHP_INT_MAX, 100)) {
        throw new InvalidArgumentException('Transfer amount is too large.');
    }
    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
}

function sepay_webhook_parse_payload(array $payload): array
{
    $transactionId = trim((string) ($payload['id'] ?? ''));
    if ($transactionId === '' || strlen($transactionId) > 100) {
        throw new InvalidArgumentException('Provider transaction id is required.');
    }

    $transferType = strtolower(trim((string) ($payload['transferType'] ?? '')));
    if ($transferType !== 'in') {
        return ['state' => 'ignored', 'transactionId' => $transactionId];
    }

    $amountCents = sepay_webhook_amount_cents($payload['transferAmount'] ?? null);
    if ($amountCents <= 0) {
        throw new InvalidArgumentException('Transfer amount must be positive.');
    }

    $content = trim((string) ($payload['content'] ?? ''));
    if (!preg_match('/(?:^|[^A-Z0-9])(SVR-[A-Z0-9]+)(?:$|[^A-Z0-9])/i', $content, $matches)) {
        return ['state' => 'ignored', 'transactionId' => $transactionId];
    }

    return [
        'state' => 'process',
        'transactionId' => $transactionId,
        'referenceCode' => strtoupper((string) $matches[1]),
        'amountCents' => $amountCents,
    ];
}

function sepay_webhook_amount_matches(int $transferAmountCents, mixed $paymentAmount): bool
{
    if ($transferAmountCents <= 0) return false;
    try {
        return $transferAmountCents === sepay_webhook_amount_cents($paymentAmount);
    } catch (InvalidArgumentException) {
        return false;
    }
}
