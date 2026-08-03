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
