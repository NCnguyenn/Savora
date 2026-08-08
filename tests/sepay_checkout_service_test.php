<?php
declare(strict_types=1);

require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/migrations.php';

$servicePath = __DIR__ . '/../lib/services/sepay_checkout_service.php';
if (!is_file($servicePath)) {
    throw new RuntimeException('SePay checkout service is missing.');
}
require_once $servicePath;

function sepay_checkout_test_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function sepay_checkout_test_user(mysqli $conn, string $username, string $role, string $name): int
{
    $password = password_hash('sepay-checkout-test', PASSWORD_DEFAULT);
    $statement = $conn->prepare("INSERT INTO users(username,password,role,full_name,status) VALUES(?,?,?,?,'active')");
    $statement->bind_param('ssss', $username, $password, $role, $name);
    $statement->execute();
    $id = (int) $statement->insert_id;
    $statement->close();
    return $id;
}

function sepay_checkout_test_order(
    mysqli $conn,
    string $reference,
    int $customerId,
    int $restaurantId,
    string $method,
    float $amount,
    string $paymentStatus = 'pending'
): int {
    $statement = $conn->prepare(
        "INSERT INTO orders(reference_code,customer_user_id,restaurant_id,status,payment_method,subtotal,total)
         VALUES(?,?,?,'pending',?,?,?)"
    );
    $statement->bind_param('siisdd', $reference, $customerId, $restaurantId, $method, $amount, $amount);
    $statement->execute();
    $orderId = (int) $statement->insert_id;
    $statement->close();

    $payment = $conn->prepare(
        "INSERT INTO payments(order_id,method,amount,status,paid_at)
         VALUES(?,?,?,?,IF(?='paid',NOW(),NULL))"
    );
    $payment->bind_param('isdss', $orderId, $method, $amount, $paymentStatus, $paymentStatus);
    $payment->execute();
    $payment->close();
    return $orderId;
}

$conn = null;
$userIds = [];
$orderIds = [];
$restaurantId = 0;
$suffix = strtoupper(bin2hex(random_bytes(5)));
$pendingReference = 'SVR-' . $suffix . '-PENDING';
$paidReference = 'SVR-' . $suffix . '-PAID';
$cashReference = 'SVR-' . $suffix . '-CASH';
$previousBank = getenv('SEPAY_BANK_BIN');
$previousAccount = getenv('SEPAY_BANK_ACCOUNT');
$previousName = getenv('SEPAY_ACCOUNT_NAME');
$configPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'savora-sepay-config-' . strtolower($suffix) . '.php';

try {
    $conn = savora_test_database();
    savora_apply_migrations($conn);

    $ownerId = sepay_checkout_test_user($conn, 'sepay-' . strtolower($suffix) . '-owner', 'customer', 'SePay Owner');
    $otherId = sepay_checkout_test_user($conn, 'sepay-' . strtolower($suffix) . '-other', 'customer', 'Other Customer');
    $restaurantOwnerId = sepay_checkout_test_user($conn, 'sepay-' . strtolower($suffix) . '-restaurant', 'restaurant', 'Restaurant Owner');
    $userIds = [$ownerId, $otherId, $restaurantOwnerId];

    $restaurant = $conn->prepare("INSERT INTO restaurants(owner_user_id,name,status) VALUES(?,'SePay Test Restaurant','active')");
    $restaurant->bind_param('i', $restaurantOwnerId);
    $restaurant->execute();
    $restaurantId = (int) $restaurant->insert_id;
    $restaurant->close();

    $orderIds[] = sepay_checkout_test_order($conn, $pendingReference, $ownerId, $restaurantId, 'seapay', 125000.40);
    $orderIds[] = sepay_checkout_test_order($conn, $paidReference, $ownerId, $restaurantId, 'seapay', 99000, 'paid');
    $orderIds[] = sepay_checkout_test_order($conn, $cashReference, $ownerId, $restaurantId, 'cash', 50000);

    $snapshot = sepay_checkout_snapshot($conn, $ownerId, $pendingReference);
    sepay_checkout_test_expect($snapshot['referenceCode'] === $pendingReference, 'Reference must be server-owned.');
    sepay_checkout_test_expect($snapshot['amountVnd'] === 125000, 'Stored amount must become integer VND.');
    sepay_checkout_test_expect($snapshot['paymentStatus'] === 'pending', 'Pending payment must remain pending.');
    sepay_checkout_test_expect($snapshot['orderStatus'] === 'pending', 'Reading payment must not change the order.');
    sepay_checkout_test_expect(sepay_checkout_snapshot($conn, $otherId, $pendingReference) === [], 'Another Customer must not read the payment.');
    sepay_checkout_test_expect(sepay_checkout_snapshot($conn, $ownerId, $cashReference) === [], 'Non-SePay orders must be rejected.');

    $paid = sepay_checkout_snapshot($conn, $ownerId, $paidReference);
    sepay_checkout_test_expect($paid['paymentStatus'] === 'paid' && $paid['paidAt'] !== null, 'Paid snapshot must include its server timestamp.');
    sepay_checkout_test_expect($paid['orderStatus'] === 'pending', 'Paid payment must not confirm the Restaurant order.');

    $url = sepay_checkout_vietqr_url([
        'bank' => 'MB',
        'account' => '0123456789',
        'accountName' => 'NGUYEN VAN A',
    ], 125000, 'SVR-ABC-123');
    sepay_checkout_test_expect(
        $url === 'https://vietqr.app/img?acc=0123456789&bank=MB&amount=125000&des=SVR-ABC-123&template=compact',
        'VietQR URL must use official encoded server values.'
    );
    sepay_checkout_test_expect(
        sepay_checkout_vietqr_url(['bank' => '', 'account' => '', 'accountName' => ''], 125000, 'SVR-X') === null,
        'Missing recipient config must not produce a QR.'
    );
    sepay_checkout_test_expect(
        sepay_checkout_vietqr_url(['bank' => 'MB', 'account' => '1', 'accountName' => 'A'], 0, 'SVR-X') === null,
        'A non-positive amount must not produce a QR.'
    );

    file_put_contents($configPath, "<?php return ['SEPAY_BANK_BIN'=>'LOCAL','SEPAY_BANK_ACCOUNT'=>'111','SEPAY_ACCOUNT_NAME'=>'Local Name'];");
    putenv('SEPAY_BANK_BIN=ENV');
    putenv('SEPAY_BANK_ACCOUNT=222');
    putenv('SEPAY_ACCOUNT_NAME=Env Name');
    $config = sepay_checkout_config($configPath);
    sepay_checkout_test_expect(
        $config === ['bank' => 'ENV', 'account' => '222', 'accountName' => 'Env Name'],
        'Environment recipient config must override local config.'
    );
} finally {
    putenv($previousBank === false ? 'SEPAY_BANK_BIN' : 'SEPAY_BANK_BIN=' . $previousBank);
    putenv($previousAccount === false ? 'SEPAY_BANK_ACCOUNT' : 'SEPAY_BANK_ACCOUNT=' . $previousAccount);
    putenv($previousName === false ? 'SEPAY_ACCOUNT_NAME' : 'SEPAY_ACCOUNT_NAME=' . $previousName);
    if (is_file($configPath)) unlink($configPath);
    if ($conn instanceof mysqli) {
        if ($orderIds !== []) {
            $orderList = implode(',', array_map('intval', $orderIds));
            $conn->query('DELETE FROM payments WHERE order_id IN (' . $orderList . ')');
            $conn->query('DELETE FROM orders WHERE id IN (' . $orderList . ')');
        }
        if ($restaurantId > 0) $conn->query('DELETE FROM restaurants WHERE id=' . $restaurantId);
        if ($userIds !== []) $conn->query('DELETE FROM users WHERE id IN (' . implode(',', array_map('intval', $userIds)) . ')');
        $conn->close();
    }
}

echo "PASS: Customer-scoped SePay snapshots and official integer-VND VietQR URLs are enforced\n";
