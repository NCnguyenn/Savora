<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$actor = savora_session_actor($conn);
if ($actor === null || $actor['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}

$reference = trim((string) ($_GET['order'] ?? ''));
if ($reference === '') {
    header('Location: customer_history.php');
    exit;
}

$stmt = $conn->prepare("SELECT o.id, o.reference_code, o.total, p.status as payment_status
                        FROM orders o
                        JOIN payments p ON p.order_id = o.id
                        WHERE o.reference_code = ? AND o.customer_user_id = ?
                        LIMIT 1");
$stmt->bind_param('si', $reference, $actor['userId']);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($order === null) {
    header('Location: customer_history.php');
    exit;
}

// If already paid (webhook processed it), redirect to history
if ($order['payment_status'] === 'paid') {
    header('Location: customer_history.php?paid=1');
    exit;
}

// Bank Info for Demo
$bankBin = 'MB'; // MB Bank
$accountNo = '0366564953';
$accountName = urlencode('NGUYEN CHI NGUYEN');
$amount = (float) $order['total'];
$addInfo = urlencode($order['reference_code']);

$qrUrl = "https://img.vietqr.io/image/{$bankBin}-{$accountNo}-compact2.png?amount={$amount}&addInfo={$addInfo}&accountName={$accountName}";

include 'components/customer_header.php';
?>

<main class="container">
    <div style="max-width: 500px; margin: 40px auto; text-align: center; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
        <h1 style="margin-bottom: 8px;">SePay Payment Gateway</h1>
        <p style="color: #666; margin-bottom: 24px;">Please scan the QR code below with your banking app to complete your payment.</p>

        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 24px;">
            <p style="margin-bottom: 8px; font-size: 1.1em;">Order Reference: <strong><?= htmlspecialchars($order['reference_code']) ?></strong></p>
            <p style="margin-bottom: 0; font-size: 1.4em; color: #e53935; font-weight: bold;">$<?= number_format($amount, 2) ?></p>
        </div>

        <img src="<?= htmlspecialchars($qrUrl) ?>" alt="VietQR" style="width: 100%; max-width: 300px; border-radius: 8px; margin-bottom: 24px; border: 1px solid #ddd;">

        <div style="display: flex; gap: 12px; justify-content: center;">
            <button onclick="window.location.reload();" class="primary-action">
                <i class="fa-solid fa-rotate-right"></i> I have paid, check status
            </button>
            <a href="customer_history.php" class="secondary-action">Cancel & Return</a>
        </div>

        <p style="margin-top: 24px; font-size: 0.9em; color: #888;">
            Waiting for SePay webhook confirmation...
        </p>
    </div>
</main>

<script>
    // Simple auto-polling every 5 seconds
    setInterval(() => {
        fetch('seapay_checkout.php?order=<?= urlencode($reference) ?>')
            .then(res => {
                if (res.redirected && res.url.includes('customer_history.php')) {
                    window.location.replace('customer_history.php?paid=1');
                }
            });
    }, 5000);
</script>

<?php include 'components/customer_footer.php'; ?>
