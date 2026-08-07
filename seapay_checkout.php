<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/services/payment_confirmation_service.php';

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

$stmt = $conn->prepare("SELECT o.id, o.reference_code, o.total, o.payment_method, p.status AS payment_status
                        FROM orders o
                        JOIN payments p ON p.order_id = o.id
                        WHERE o.reference_code = ? AND o.customer_user_id = ?
                        LIMIT 1");
$stmt->bind_param('si', $reference, $actor['userId']);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($order === null || (string) $order['payment_method'] !== 'seapay') {
    header('Location: customer_history.php');
    exit;
}
if ((string) $order['payment_status'] === 'paid') {
    header('Location: customer_history.php?order=' . rawurlencode((string) $order['reference_code']) . '&paid=1');
    exit;
}

$amount = (float) $order['total'];
$sepayConfig = payment_confirmation_seapay_config();
$qrUrl = payment_confirmation_vietqr_url($sepayConfig, $amount, (string) $order['reference_code']);
$hasBankConfig = $qrUrl !== null;
$demoMode = savora_demo_mode();

include 'components/customer_header.php';
?>

<main class="container">
    <div style="max-width: 500px; margin: 40px auto; text-align: center; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
        <h1 style="margin-bottom: 8px;">SePay Payment Gateway</h1>
        <p style="color: #666; margin-bottom: 24px;"><?= $hasBankConfig ? 'Scan the QR code below with your banking app to complete your payment.' : 'Bank transfer details are not configured for this environment.' ?></p>

        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 24px;">
            <p style="margin-bottom: 8px; font-size: 1.1em;">Order Reference: <strong><?= htmlspecialchars((string) $order['reference_code'], ENT_QUOTES, 'UTF-8') ?></strong></p>
            <p style="margin-bottom: 0; font-size: 1.4em; color: #e53935; font-weight: bold;">$<?= number_format($amount, 2) ?></p>
        </div>

        <?php if ($hasBankConfig): ?>
            <img src="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') ?>" alt="VietQR payment code" style="width: 100%; max-width: 300px; border-radius: 8px; margin-bottom: 24px; border: 1px solid #ddd;">
        <?php endif; ?>

        <?php if ($demoMode): ?>
            <button type="button" class="primary-action" data-demo-seapay-confirm>
                Simulate successful payment
            </button>
            <p data-demo-seapay-status role="status" aria-live="polite"></p>
        <?php endif; ?>

        <div style="display: flex; gap: 12px; justify-content: center; margin-top: 16px;">
            <a href="customer_history.php" class="secondary-action">Cancel &amp; Return</a>
        </div>

        <?php if ($hasBankConfig): ?>
            <p style="margin-top: 24px; font-size: 0.9em; color: #888;">Waiting for SePay webhook confirmation...</p>
        <?php endif; ?>
    </div>
</main>

<?php if ($demoMode): ?>
<script>
(() => {
    const button = document.querySelector('[data-demo-seapay-confirm]');
    const status = document.querySelector('[data-demo-seapay-status]');
    const referenceCode = <?= json_encode((string) $order['reference_code'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    if (!button || !status) return;
    button.addEventListener('click', async () => {
        button.disabled = true;
        status.textContent = 'Confirming payment…';
        try {
            await SavoraApi.post('api/payment_demo.php', { action: 'simulate_success', payload: { referenceCode } }, SavoraApi.intentKey(`customer-seapay-payment-${referenceCode}`));
            SavoraApi.clearIntentKey(`customer-seapay-payment-${referenceCode}`);
            status.textContent = 'Payment confirmed. Redirecting…';
            window.location.assign(`customer_history.php?order=${encodeURIComponent(referenceCode)}&paid=1`);
        } catch (error) {
            button.disabled = false;
            status.textContent = error.message || 'Payment could not be confirmed.';
        }
    });
})();
</script>
<?php endif; ?>

<?php if ($hasBankConfig): ?>
<script>
(() => {
    const statusUrl = <?= json_encode('seapay_checkout.php?order=' . rawurlencode((string) $order['reference_code']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const checkProviderStatus = () => {
        window.setTimeout(async () => {
            try {
                const response = await fetch(statusUrl, { credentials: 'same-origin' });
                if (response.redirected && response.url.includes('customer_history.php')) {
                    window.location.replace(response.url);
                    return;
                }
            } catch (_) {
                // A later check can recover from a transient network failure.
            }
            checkProviderStatus();
        }, 5000);
    };
    checkProviderStatus();
})();
</script>
<?php endif; ?>

<?php include 'components/customer_footer.php'; ?>
