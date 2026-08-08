# SePay QR Payment and Receipt Design

## Goal

Complete the customer SePay flow with a real VietQR payment page and a controlled demo-payment fallback. Both paths must confirm the same pending SePay payment through the existing server-authoritative confirmation service, show a receipt after payment, and leave the order `pending` for the Restaurant to confirm.

## Scope

- Add a customer-owned `seapay_checkout.php` page for a pending SePay order.
- Generate a real VietQR image from server configuration and the order reference.
- Provide a demo-only payment button when `SAVORA_DEMO_MODE` is enabled.
- Poll a narrow authenticated payment-status read endpoint while the page is visible.
- Replace the QR panel with a server-authoritative receipt once payment is `paid`.
- Route the receipt acknowledgement to Customer order history without changing the order state.

## Currency Decision

The SePay flow treats existing stored order totals as integer VND values. The QR amount is the server order total, rounded and validated as a positive VND integer. The QR and receipt display VND. A broad catalogue/database conversion is outside this change; after menu prices are updated, the QR uses the new totals automatically.

## Customer Flow

1. Customer selects `SePay Gateway` at checkout and places a server-authoritative order.
2. Checkout routes to `seapay_checkout.php?order=SVR-...`.
3. The page verifies that the logged-in Customer owns the order, that its payment method is `seapay`, and that the payment is pending or paid.
4. While pending, the page displays the exact VND amount, bank account details, required transfer content `SVR-...`, and a VietQR image.
5. In explicit demo mode only, the page also displays **Simulate successful payment**. It calls the existing CSRF-protected, idempotent `api/payment_demo.php` endpoint.
6. A real bank transfer reaches `api/webhook_seapay.php`; the webhook and demo endpoint both use the shared payment confirmation service.
7. The page polls the payment-status endpoint every three seconds while visible. On `paid`, it replaces the QR panel with a receipt.
8. **OK, view my order** navigates to `customer_history.php`. The payment remains `paid`; the order remains `pending` until the Restaurant confirms it.

## Components

### Server payment page

`seapay_checkout.php` renders only after ownership and SePay payment checks. It obtains bank details from `config/local.php` or supported environment configuration, never exposes the SePay webhook API key, and reports a configuration error instead of rendering a fabricated QR.

The QR source uses the official SePay VietQR image structure:

`https://vietqr.app/img?acc={account}&bank={bank}&amount={vnd}&des={reference}&template=compact`

All query values are URL-encoded. The page also renders the same transfer data as accessible text so the Customer can verify the recipient, amount, and reference before paying.

### Payment status read endpoint

Add an authenticated Customer-only read endpoint that accepts an order reference, verifies ownership and SePay method, and returns only the fields needed by the page: reference code, payment method, VND amount, payment status, paid timestamp, and order status. It must not mutate order or payment state.

### Receipt state

The QR page JavaScript transitions from a pending view to a receipt only from data returned by the authenticated status endpoint. The receipt includes the reference code, amount, SePay method, `paid` state, and paid timestamp. The acknowledgement button performs navigation only; it does not call an order transition endpoint.

### Demo payment

Reuse `api/payment_demo.php` and its existing CSRF, ownership, idempotency, and demo-mode guard. The page hides the simulation control unless server-rendered demo mode is enabled. A successful simulation is observed through the same status polling as a real webhook.

## Security and Failure Handling

- QR page and status endpoint deny unauthenticated or non-owner access.
- The page rejects non-SePay orders and terminally invalid payment states.
- QR configuration requires a bank identifier, account number, and account-holder name; missing values produce a bounded setup message.
- The SePay webhook remains the authority for real transfers and requires its existing API-key authentication, exact reference, exact VND amount, and unique provider transaction.
- Polling stops when the page is hidden, the payment is paid, or the page is left. Failures retain the visible pending QR and show a retryable status message.
- No frontend code may mark a payment paid or auto-confirm the order.

## Verification

Automated coverage will verify:

1. the QR page and status endpoint enforce Customer ownership and SePay method;
2. QR URLs contain encoded account, bank, integer VND amount, and `SVR-...` reference;
3. missing bank configuration cannot render a QR;
4. demo simulation remains available only in demo mode and uses the existing confirmation path;
5. paid status renders a receipt and the acknowledgement does not transition the order;
6. webhook-confirmed payments and simulated payments produce the same visible paid receipt state.

Browser verification will cover both the pending QR view and the paid receipt after a demo confirmation. A real transfer can then be verified with the configured webhook without modifying the client flow.
