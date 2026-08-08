<?php
// Fake SePay checkout page for screenshot purposes
$reference = 'SVR-DEMO1234';
$amount = 50000;
$bankBin = 'MB'; 
$accountNo = '0366564953';
$accountName = urlencode('NGUYEN CHI NGUYEN');
$addInfo = urlencode($reference);

$qrUrl = "https://img.vietqr.io/image/{$bankBin}-{$accountNo}-compact2.png?amount={$amount}&addInfo={$addInfo}&accountName={$accountName}";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Savora - Payment</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f8; margin: 0; padding: 20px; }
        .container { max-width: 500px; margin: 40px auto; text-align: center; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        h1 { margin-bottom: 8px; font-size: 24px; color: #333; }
        p { color: #666; margin-bottom: 24px; }
        .box { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 24px; }
        .box p { margin-bottom: 8px; font-size: 1.1em; color: #333; }
        .amount { margin-bottom: 0 !important; font-size: 1.8em !important; color: #e53935 !important; font-weight: bold; }
        img { width: 100%; max-width: 300px; border-radius: 8px; margin-bottom: 24px; border: 1px solid #ddd; }
        .btn-group { display: flex; gap: 12px; justify-content: center; }
        button { background: #2e7d32; color: white; border: none; padding: 12px 20px; border-radius: 6px; font-size: 16px; cursor: pointer; }
        a { text-decoration: none; background: #e0e0e0; color: #333; padding: 12px 20px; border-radius: 6px; font-size: 16px; }
        .footer { margin-top: 24px; font-size: 0.9em; color: #888; }
    </style>
</head>
<body>
    <div class="container">
        <h1>SePay Payment Gateway</h1>
        <p>Please scan the QR code below with your banking app to complete your payment.</p>
        
        <div class="box">
            <p>Order Reference: <strong><?= htmlspecialchars($reference) ?></strong></p>
            <p class="amount"><?= number_format($amount) ?> VND</p>
        </div>

        <img src="<?= htmlspecialchars($qrUrl) ?>" alt="VietQR">
        
        <div class="btn-group">
            <button>I have paid, check status</button>
            <a href="#">Cancel & Return</a>
        </div>
        
        <p class="footer">Waiting for SePay webhook confirmation...</p>
    </div>
</body>
</html>
