<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/environment.php';
require_once __DIR__ . '/lib/services/sepay_checkout_service.php';

$customer_page_scripts = ['js/sepay_checkout.js'];
include 'components/customer_header.php';

$referenceCode = strtoupper(trim((string) ($_GET['order'] ?? '')));
$snapshot = preg_match('/^SVR-[A-Z0-9-]+$/', $referenceCode) === 1
    ? sepay_checkout_snapshot($conn, (int) ($_SESSION['user_id'] ?? 0), $referenceCode)
    : [];
$demoMode = savora_demo_mode();
$recipient = $snapshot === [] ? [] : sepay_checkout_config();
$qrUrl = $snapshot !== [] && $snapshot['paymentStatus'] === 'pending'
    ? sepay_checkout_vietqr_url($recipient, (int) $snapshot['amountVnd'], (string) $snapshot['referenceCode'])
    : null;
$amountLabel = $snapshot === [] ? '' : number_format((int) $snapshot['amountVnd'], 0, ',', '.') . ' ₫';
$isPaid = $snapshot !== [] && $snapshot['paymentStatus'] === 'paid';
$hasRecipient = $qrUrl !== null;
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<main class="container seapay-page">
    <header class="seapay-title-row">
        <div class="page-title-block">
            <p class="eyebrow">Thanh toán an toàn</p>
            <h1>Thanh toán qua SePay</h1>
            <p>Quét VietQR hoặc dùng chế độ giả lập để hoàn tất phần thanh toán của đơn hàng.</p>
        </div>
        <ol class="checkout-steps" aria-label="Tiến trình thanh toán">
            <li class="is-complete"><span><i class="fa-solid fa-check" aria-hidden="true"></i></span>Giỏ hàng</li>
            <li class="is-complete"><span><i class="fa-solid fa-check" aria-hidden="true"></i></span>Giao hàng</li>
            <li class="is-current" aria-current="step"><span>3</span>Thanh toán</li>
        </ol>
    </header>

    <?php if ($snapshot === []): ?>
        <section class="surface-card seapay-not-found" aria-labelledby="seapay-not-found-title">
            <span class="seapay-state-icon" aria-hidden="true"><i class="fa-solid fa-receipt"></i></span>
            <h2 id="seapay-not-found-title">Không tìm thấy thanh toán SePay</h2>
            <p>Đơn hàng không tồn tại, không thuộc tài khoản này hoặc không sử dụng SePay.</p>
            <a class="primary-action" href="customer_history.php">Xem đơn hàng của tôi</a>
        </section>
    <?php else: ?>
        <div class="seapay-shell">
            <section class="surface-card seapay-payment-card" data-seapay-pending<?php echo $isPaid ? ' hidden' : ''; ?> aria-labelledby="seapay-pending-title">
                <div class="seapay-card-heading">
                    <div>
                        <p class="eyebrow">Đang chờ thanh toán</p>
                        <h2 id="seapay-pending-title">Quét mã bằng ứng dụng ngân hàng</h2>
                    </div>
                    <span class="status-chip status-pending">Chờ SePay xác nhận</span>
                </div>

                <div class="seapay-payment-layout">
                    <div class="seapay-qr-column">
                        <?php if ($hasRecipient): ?>
                            <div class="seapay-qr-frame">
                                <img data-seapay-qr src="<?php echo htmlspecialchars((string) $qrUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Mã VietQR thanh toán đơn <?php echo htmlspecialchars((string) $snapshot['referenceCode'], ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <p class="seapay-qr-help"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i>Số tiền và nội dung chuyển khoản được tạo từ đơn hàng trên máy chủ.</p>
                        <?php else: ?>
                            <div class="seapay-configuration-error" role="alert">
                                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                                <div><strong>Chưa cấu hình tài khoản nhận tiền</strong><p>Vui lòng cấu hình ngân hàng, số tài khoản và tên người nhận để hiển thị VietQR thật.</p></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="seapay-transfer-details">
                        <h3>Thông tin chuyển khoản</h3>
                        <dl>
                            <div class="seapay-copy-row"><dt>Số tiền</dt><dd data-seapay-amount><?php echo htmlspecialchars($amountLabel, ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <div class="seapay-copy-row"><dt>Nội dung</dt><dd data-seapay-reference><?php echo htmlspecialchars((string) $snapshot['referenceCode'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <?php if ($hasRecipient): ?>
                                <div class="seapay-copy-row"><dt>Ngân hàng</dt><dd><?php echo htmlspecialchars((string) $recipient['bank'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                <div class="seapay-copy-row"><dt>Số tài khoản</dt><dd><?php echo htmlspecialchars((string) $recipient['account'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                <div class="seapay-copy-row"><dt>Người nhận</dt><dd><?php echo htmlspecialchars((string) $recipient['accountName'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <?php endif; ?>
                        </dl>

                        <div class="seapay-actions">
                            <?php if ($demoMode): ?>
                                <button class="primary-action" type="button" data-demo-seapay-confirm>
                                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>Thanh toán giả lập
                                </button>
                            <?php endif; ?>
                            <a class="secondary-action" href="customer_history.php">Thanh toán sau</a>
                        </div>
                        <p class="seapay-waiting-state" data-seapay-status role="status" aria-live="polite">
                            <span aria-hidden="true"></span><?php echo $hasRecipient ? 'Đang chờ SePay webhook xác nhận giao dịch…' : 'VietQR chưa sẵn sàng. Bạn vẫn có thể dùng thanh toán giả lập khi chế độ demo được bật.'; ?>
                        </p>
                    </div>
                </div>
            </section>

            <section class="surface-card seapay-receipt" data-seapay-receipt<?php echo $isPaid ? '' : ' hidden'; ?> aria-labelledby="seapay-receipt-title">
                <span class="seapay-receipt-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                <p class="eyebrow">Biên lai thanh toán</p>
                <h2 id="seapay-receipt-title">Thanh toán thành công</h2>
                <p>SePay đã xác nhận khoản thanh toán. Nhà hàng sẽ xác nhận đơn hàng ở bước tiếp theo.</p>
                <dl class="seapay-receipt-list">
                    <div><dt>Mã đơn hàng</dt><dd data-seapay-receipt-reference><?php echo htmlspecialchars((string) $snapshot['referenceCode'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
                    <div><dt>Số tiền</dt><dd data-seapay-receipt-amount><?php echo htmlspecialchars($amountLabel, ENT_QUOTES, 'UTF-8'); ?></dd></div>
                    <div><dt>Phương thức</dt><dd data-seapay-receipt-method>SePay</dd></div>
                    <div><dt>Thanh toán</dt><dd data-seapay-receipt-payment><?php echo $isPaid ? 'Đã thanh toán' : 'Đang chờ'; ?></dd></div>
                    <div><dt>Thời gian</dt><dd data-seapay-receipt-paid-at><?php echo htmlspecialchars((string) ($snapshot['paidAt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                    <div><dt>Đơn hàng</dt><dd data-seapay-receipt-order><?php echo $snapshot['orderStatus'] === 'pending' ? 'Chờ nhà hàng xác nhận' : htmlspecialchars((string) $snapshot['orderStatus'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
                </dl>
                <button class="primary-action seapay-receipt-action" type="button" data-seapay-receipt-ok>OK, xem đơn hàng</button>
            </section>
        </div>

        <script>
        window.SavoraSePayCheckout = {
            referenceCode: <?php echo json_encode((string) $snapshot['referenceCode'], $jsonFlags); ?>,
            initialSnapshot: <?php echo json_encode($snapshot, $jsonFlags); ?>,
            demoMode: <?php echo $demoMode ? 'true' : 'false'; ?>
        };
        document.addEventListener('DOMContentLoaded', () => {
            if (window.SavoraSePay) window.SavoraSePay.init(window.SavoraSePayCheckout);
        });
        </script>
    <?php endif; ?>
</main>

<?php include 'components/customer_footer.php'; ?>
