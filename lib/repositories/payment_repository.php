<?php
declare(strict_types=1);

function payment_repository_status(string $paymentMethod): string
{
    return $paymentMethod === 'wallet' ? 'paid' : 'pending';
}

function payment_repository_insert(mysqli $conn, int $orderId, string $method, float $amount, string $status): void
{
    $statement = $conn->prepare('INSERT INTO payments(order_id,method,amount,status,paid_at) VALUES(?,?,?,?,IF(?=\'paid\',NOW(),NULL))');
    $statement->bind_param('isdss', $orderId, $method, $amount, $status, $status);
    $statement->execute();
    $statement->close();
}

function payment_repository_insert_wallet_debit(mysqli $conn, int $customerUserId, int $orderId, float $amount): void
{
    $statement = $conn->prepare("INSERT INTO wallet_transactions(customer_user_id,order_id,type,amount,description) VALUES(?,?,'debit',?,'Savora checkout payment')");
    $statement->bind_param('iid', $customerUserId, $orderId, $amount);
    $statement->execute();
    $statement->close();
}

function payment_repository_debit_wallet(mysqli $conn, int $customerUserId, float $amount): bool
{
    $lock = $conn->prepare('SELECT wallet_balance FROM customer_profiles WHERE user_id=? FOR UPDATE');
    $lock->bind_param('i', $customerUserId);
    $lock->execute();
    $balance = $lock->get_result()->fetch_assoc();
    $lock->close();
    if ($balance === null || (float) $balance['wallet_balance'] < $amount) return false;
    $debit = $conn->prepare('UPDATE customer_profiles SET wallet_balance=wallet_balance-? WHERE user_id=? AND wallet_balance>=?');
    $debit->bind_param('did', $amount, $customerUserId, $amount);
    $debit->execute();
    $affected = $debit->affected_rows === 1;
    $debit->close();
    return $affected;
}

function payment_repository_target_by_reference(
    mysqli $conn,
    string $referenceCode,
    ?int $customerUserId = null,
    bool $forUpdate = false
): array {
    $sql = "SELECT p.id AS payment_id,p.order_id,p.method,p.amount,p.status,p.provider_reference,p.version,
                   o.reference_code,o.customer_user_id
            FROM payments p JOIN orders o ON o.id=p.order_id
            WHERE o.reference_code=?";
    if ($customerUserId !== null) $sql .= ' AND o.customer_user_id=?';
    $sql .= ' LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';

    $statement = $conn->prepare($sql);
    if ($customerUserId === null) {
        $statement->bind_param('s', $referenceCode);
    } else {
        $statement->bind_param('si', $referenceCode, $customerUserId);
    }
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    return is_array($row) ? $row : [];
}

function payment_repository_customer_checkout(mysqli $conn, int $customerUserId, string $referenceCode): array
{
    $statement = $conn->prepare(
        'SELECT o.reference_code,o.status AS order_status,p.method AS payment_method,
                p.amount AS payment_amount,p.status AS payment_status,p.paid_at,p.provider_reference
         FROM payments p
         JOIN orders o ON o.id=p.order_id
         WHERE o.customer_user_id=? AND o.reference_code=?
         LIMIT 1'
    );
    $statement->bind_param('is', $customerUserId, $referenceCode);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    return is_array($row) ? $row : [];
}

function payment_repository_by_provider_reference(mysqli $conn, string $providerReference, bool $forUpdate = false): array
{
    $sql = "SELECT p.id AS payment_id,p.order_id,p.method,p.amount,p.status,p.provider_reference,p.version,
                   o.reference_code,o.customer_user_id
            FROM payments p JOIN orders o ON o.id=p.order_id
            WHERE p.provider_reference=? LIMIT 1";
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $statement = $conn->prepare($sql);
    $statement->bind_param('s', $providerReference);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    return is_array($row) ? $row : [];
}

function payment_repository_mark_paid(mysqli $conn, int $paymentId, int $expectedVersion, string $providerReference): bool
{
    $statement = $conn->prepare("UPDATE payments SET status='paid',provider_reference=?,paid_at=NOW(),version=version+1 WHERE id=? AND version=? AND status='pending' AND (provider_reference IS NULL OR provider_reference='')");
    $statement->bind_param('sii', $providerReference, $paymentId, $expectedVersion);
    $statement->execute();
    $ok = $statement->affected_rows === 1;
    $statement->close();
    return $ok;
}
