<?php
declare(strict_types=1);

function payment_method_error(string $paymentMethod): ?array
{
    if (!in_array($paymentMethod, ['cash', 'wallet', 'card', 'seapay'], true)) return ['status' => 422, 'message' => 'Payment method is invalid.'];
    if ($paymentMethod === 'card') return ['status' => 422, 'message' => 'Card payments are unavailable until a payment provider is configured.'];
    return null;
}
