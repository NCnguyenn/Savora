# SePay QR Payment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task with review checkpoints.

**Goal:** Deliver a customer-owned SePay payment page that shows a real VND VietQR, supports the existing demo confirmation path, observes webhook-confirmed payments, renders a server-authoritative receipt, and leaves the order `pending` while setting only the payment to `paid`.

**Architecture:** Keep all payment truth on the server. A small SePay checkout service reads the Customer-owned order/payment record, validates and formats the integer VND amount, loads public bank-transfer configuration, and builds the official SePay VietQR URL. A narrow authenticated GET endpoint exposes only the current checkout snapshot. The page renders safe initial state and a focused JavaScript controller polls the endpoint, calls the existing demo endpoint when allowed, and swaps the pending panel for a receipt after the server reports `paid`.

**Tech Stack:** PHP 8+, MySQLi, existing Savora session/CSRF/idempotency helpers, vanilla JavaScript (UMD/CommonJS-testable), CSS, Node `node:test`, PHP integration tests using the existing `savora_test` database and CGI harness pattern.

## Global Constraints

- Treat the current server order/payment amount as VND, rounded to a positive integer for VietQR. Do not perform a broad catalogue/database currency migration in this change.
- Never accept an amount, account, payment state, or paid timestamp from the browser.
- Never expose `SEPAY_WEBHOOK_API_KEY`; only public recipient details may reach the page.
- Both webhook and demo confirmation continue through `payment_confirm_transaction()`.
- The receipt acknowledgement performs navigation only. It must not call an order transition endpoint.
- Successful SePay confirmation changes `payments.status` to `paid`; `orders.status` remains `pending` until the Restaurant confirms it.
- Use `apply_patch` for source edits and preserve unrelated working-tree changes.

---

### Task 1: Add a Customer-scoped SePay checkout read model and VietQR builder

**Files:**

- Create: `lib/services/sepay_checkout_service.php`
- Modify: `lib/repositories/payment_repository.php`
- Modify: `lib/services/payment_confirmation_service.php`
- Create: `tests/sepay_checkout_service_test.php`
- Modify: `tests/payment_confirmation_service_test.php`

**Step 1: Write the failing service test**

Create `tests/sepay_checkout_service_test.php` using `tests/support/test_database.php` and `savora_apply_migrations()`. Insert two Customers, one Restaurant, and fixtures for a pending SePay order, a paid SePay order, and a non-SePay order. Assert:

```php
$snapshot = sepay_checkout_snapshot($conn, $ownerId, $reference);
sepay_checkout_expect($snapshot['referenceCode'] === $reference, 'Reference must be server-owned.');
sepay_checkout_expect($snapshot['amountVnd'] === 125000, 'Stored amount must become integer VND.');
sepay_checkout_expect($snapshot['paymentStatus'] === 'pending', 'Pending payment must remain pending.');
sepay_checkout_expect($snapshot['orderStatus'] === 'pending', 'Reading payment must not change the order.');
sepay_checkout_expect(sepay_checkout_snapshot($conn, $otherCustomerId, $reference) === [], 'Another Customer must not read the payment.');
sepay_checkout_expect(sepay_checkout_snapshot($conn, $ownerId, $cashReference) === [], 'Non-SePay orders must be rejected.');
```

Also test URL generation and missing configuration:

```php
$url = sepay_checkout_vietqr_url([
    'bank' => 'MB',
    'account' => '0123456789',
    'accountName' => 'NGUYEN VAN A',
], 125000, 'SVR-ABC 123');
sepay_checkout_expect($url === 'https://vietqr.app/img?acc=0123456789&bank=MB&amount=125000&des=SVR-ABC%20123&template=compact', 'VietQR URL must use encoded server values.');
sepay_checkout_expect(sepay_checkout_vietqr_url(['bank' => '', 'account' => '', 'accountName' => ''], 125000, 'SVR-X') === null, 'Missing recipient config must not produce a QR.');
```

Run:

```powershell
php tests/sepay_checkout_service_test.php
```

Expected: FAIL because `sepay_checkout_service.php` and its functions do not exist.

**Step 2: Add the dedicated repository query**

Add this interface to `lib/repositories/payment_repository.php`:

```php
function payment_repository_customer_checkout(mysqli $conn, int $customerUserId, string $referenceCode): array
```

It must execute one bounded prepared query joining `payments` and `orders`, scoped by both `o.customer_user_id=?` and `o.reference_code=?`, selecting only:

```text
reference_code, order_status, payment_method, payment_amount,
payment_status, paid_at, provider_reference
```

Return `[]` when no owned record exists.

**Step 3: Implement the SePay checkout service**

Create `lib/services/sepay_checkout_service.php` with these exact public interfaces:

```php
function sepay_checkout_config(?string $localPath = null): array
function sepay_checkout_amount_vnd(mixed $amount): int
function sepay_checkout_vietqr_url(array $config, int $amountVnd, string $referenceCode): ?string
function sepay_checkout_snapshot(mysqli $conn, int $customerUserId, string $referenceCode): array
```

Implementation rules:

- `sepay_checkout_config()` reads environment values first, then `config/local.php`, and maps `SEPAY_BANK_BIN`, `SEPAY_BANK_ACCOUNT`, and `SEPAY_ACCOUNT_NAME` to `bank`, `account`, and `accountName`.
- `sepay_checkout_amount_vnd()` rejects non-numeric, non-finite, zero, or negative values with `InvalidArgumentException`; otherwise it returns `(int) round((float) $amount)`.
- `sepay_checkout_vietqr_url()` returns `null` unless bank, account, account name, positive amount, and a reference matching `^SVR-[A-Z0-9-]+$` are present. Build the official URL with `http_build_query(..., '', '&', PHP_QUERY_RFC3986)` and the keys `acc`, `bank`, `amount`, `des`, `template`.
- `sepay_checkout_snapshot()` returns `[]` for non-owned and non-SePay orders. Otherwise return:

```php
[
    'referenceCode' => (string) $row['reference_code'],
    'paymentMethod' => 'seapay',
    'amountVnd' => sepay_checkout_amount_vnd($row['payment_amount']),
    'paymentStatus' => (string) $row['payment_status'],
    'paidAt' => $row['paid_at'] === null ? null : (string) $row['paid_at'],
    'orderStatus' => (string) $row['order_status'],
]
```

Only `pending` and `paid` payment states are valid for this presentation flow; invalid states return `[]`.

**Step 4: Remove presentation helpers from the confirmation service**

Delete `payment_confirmation_seapay_config()` and `payment_confirmation_vietqr_url()` from `lib/services/payment_confirmation_service.php`. The confirmation service must remain responsible only for confirming payments. Update any test references to use the new SePay checkout service.

**Step 5: Run the service tests**

Run:

```powershell
php tests/sepay_checkout_service_test.php
php tests/payment_confirmation_service_test.php
```

Expected: both print `PASS`; the confirmation test must still prove exact amount matching, idempotent webhook retries, demo ownership, and no order-state transition.

**Step 6: Commit**

```powershell
git add lib/services/sepay_checkout_service.php lib/repositories/payment_repository.php lib/services/payment_confirmation_service.php tests/sepay_checkout_service_test.php tests/payment_confirmation_service_test.php
git commit -m "feat: add SePay checkout read model"
```

---

### Task 2: Add the authenticated payment-status endpoint

**Files:**

- Create: `api/payment_status.php`
- Create: `tests/payment_status_endpoint_test.php`
- Create: `tests/sepay_checkout_contract.test.js`

**Step 1: Write the failing endpoint integration test**

Follow the PHP-CGI/session fixture pattern in `tests/catalog_api_endpoint_test.php`. Create a real Customer session and owned SePay fixture, then assert:

```php
$owned = payment_status_request('GET', 'order=' . rawurlencode($reference), $sessionId, $sessionPath);
payment_status_expect($owned['status'] === 200, 'Owner must read payment status.');
payment_status_expect($owned['body']['data']['amountVnd'] === 125000, 'Endpoint must return integer VND.');

$other = payment_status_request('GET', 'order=' . rawurlencode($reference), $otherSessionId, $otherSessionPath);
payment_status_expect($other['status'] === 404, 'Another Customer must not discover the payment.');

$method = payment_status_request('POST', 'order=' . rawurlencode($reference), $sessionId, $sessionPath);
payment_status_expect($method['status'] === 405, 'Only GET is allowed.');
```

Also cover unauthenticated `401`, missing/invalid reference `422`, and a non-SePay fixture `404`.

Run:

```powershell
php tests/payment_status_endpoint_test.php
```

Expected: FAIL because the endpoint does not exist.

**Step 2: Add a static security contract test**

Create `tests/sepay_checkout_contract.test.js` and assert that `api/payment_status.php`:

```js
assert.match(endpoint, /savora_request_actor\(\$conn,\s*\['customer'\]\)/);
assert.match(endpoint, /sepay_checkout_snapshot/);
assert.doesNotMatch(endpoint, /UPDATE|INSERT|DELETE/i);
assert.doesNotMatch(endpoint, /SEPAY_WEBHOOK_API_KEY/);
```

Assert that `seapay_checkout.php` no longer includes `auth.php`, contains no committed bank account/name, and has no client call to an order transition endpoint.

**Step 3: Implement the endpoint**

Create `api/payment_status.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/request_security.php';
require_once __DIR__ . '/../lib/services/sepay_checkout_service.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    savora_error(405, 'Method not allowed.');
}
$actor = savora_request_actor($conn, ['customer']);
$referenceCode = strtoupper(trim((string) ($_GET['order'] ?? '')));
if (!preg_match('/^SVR-[A-Z0-9-]+$/', $referenceCode)) {
    savora_error(422, 'A valid order reference is required.');
}
$snapshot = sepay_checkout_snapshot($conn, (int) $actor['userId'], $referenceCode);
if ($snapshot === []) savora_error(404, 'SePay payment was not found.');
savora_json(['ok' => true, 'data' => $snapshot]);
```

The endpoint deliberately exposes no recipient configuration and performs no write.

**Step 4: Run tests**

Run:

```powershell
php tests/payment_status_endpoint_test.php
node --test tests/sepay_checkout_contract.test.js
```

Expected: both PASS.

**Step 5: Commit**

```powershell
git add api/payment_status.php tests/payment_status_endpoint_test.php tests/sepay_checkout_contract.test.js
git commit -m "feat: expose Customer SePay payment status"
```

---

### Task 3: Build the pending-payment controller and paid receipt state

**Files:**

- Create: `js/seapay_checkout.js`
- Create: `tests/sepay_checkout_client.test.js`

**Step 1: Write failing client unit tests**

Use Node `node:test` with injected fake API, document, clock, and navigation dependencies. The exported module must be CommonJS-testable. Cover:

```js
assert.equal(SePay.formatVnd(125000), '125.000 ₫');

const receipt = SePay.receiptModel({
  referenceCode: 'SVR-ABC-123',
  amountVnd: 125000,
  paymentMethod: 'seapay',
  paymentStatus: 'paid',
  paidAt: '2026-08-08 12:34:56',
  orderStatus: 'pending'
});
assert.equal(receipt.paymentLabel, 'Đã thanh toán');
assert.equal(receipt.orderLabel, 'Chờ nhà hàng xác nhận');
```

Test controller behavior with fakes:

- polling calls `api/payment_status.php?order=...` every 3 seconds only while visible;
- polling stops permanently when `paymentStatus === 'paid'`;
- demo click calls `api/payment_demo.php` with action `simulate_success`, then immediately refreshes status;
- transient GET failure keeps the pending panel and exposes a retryable message;
- receipt OK navigates to `customer_history.php?order=...` and never posts to `api/orders.php`.

Run:

```powershell
node --test tests/sepay_checkout_client.test.js
```

Expected: FAIL because the module does not exist.

**Step 2: Implement pure formatting and receipt mapping**

Create a UMD module exposing:

```js
formatVnd(value)
receiptModel(snapshot)
createController(dependencies)
init(config)
```

Use:

```js
const vnd = new Intl.NumberFormat('vi-VN', {
  style: 'currency',
  currency: 'VND',
  maximumFractionDigits: 0
});
```

`receiptModel()` must reject any state other than server-returned `paid` and map `orderStatus === 'pending'` to `Chờ nhà hàng xác nhận`.

**Step 3: Implement the polling controller**

The controller must:

- build the status URL using `encodeURIComponent(referenceCode)`;
- make one immediate status request, then schedule at `3000` ms;
- pause timers on `document.visibilitychange` while hidden and refresh immediately when visible again;
- abort/ignore overlapping requests;
- stop when paid or destroyed;
- update existing DOM nodes using `textContent`, `hidden`, and attributes only—no `innerHTML`;
- let the existing `SavoraApi.post()` supply CSRF and idempotency headers for demo mode;
- clear the demo intent key after success, but do not clear it on uncertain network failure;
- after demo confirmation, read the status endpoint and render the same receipt used by webhook confirmation.

**Step 4: Run client tests**

Run:

```powershell
node --test tests/sepay_checkout_client.test.js
```

Expected: PASS for formatting, polling lifecycle, demo reuse, webhook-observed paid state, and navigation-only acknowledgement.

**Step 5: Commit**

```powershell
git add js/seapay_checkout.js tests/sepay_checkout_client.test.js
git commit -m "feat: add SePay payment page controller"
```

---

### Task 4: Replace the broken SePay page with the real QR and receipt UI

**Files:**

- Modify: `seapay_checkout.php`
- Modify: `components/customer_header.php`
- Modify: `css/customer_style.css`
- Modify: `tests/customer_markup.test.js`
- Modify: `tests/sepay_checkout_contract.test.js`

**Step 1: Extend the failing markup contract**

Add assertions for stable DOM hooks:

```js
for (const hook of [
  'data-seapay-pending',
  'data-seapay-qr',
  'data-seapay-amount',
  'data-seapay-reference',
  'data-seapay-status',
  'data-seapay-receipt',
  'data-seapay-receipt-ok'
]) assert.match(page, new RegExp(hook));
assert.match(page, /js\/seapay_checkout\.js/);
assert.doesNotMatch(page, /style="/);
assert.doesNotMatch(page, /include.*auth\.php/);
```

Run:

```powershell
node --test tests/sepay_checkout_contract.test.js tests/customer_markup.test.js
```

Expected: FAIL against the current inline-style/redirect implementation.

**Step 2: Rewrite `seapay_checkout.php`**

Before including the Customer header:

- load `db.php`, `lib/environment.php`, `lib/session_security.php`, and `lib/services/sepay_checkout_service.php`;
- start and validate the Customer session using the existing session helpers;
- preserve `seapay_checkout.php?order=...` as the login return target;
- validate the `SVR-...` reference and load the owned snapshot;
- set `$customer_page_scripts = ['js/seapay_checkout.js'];`;
- load public recipient config and build the QR only for a pending payment;
- set the page title mapping in `components/customer_header.php` to `Pay with SePay | Savora`.

Do not redirect an already-paid payment to history. Render its receipt immediately.

Render three bounded states:

1. **Not found/invalid:** generic message and a link to order history; no order or recipient details.
2. **Pending:** exact VND amount, recipient name, bank, account number, required transfer content, VietQR image, waiting status, cancel link, and demo button only when `savora_demo_mode()` is true.
3. **Paid:** receipt with reference, VND amount, `SePay`, `Đã thanh toán`, paid timestamp, `Đơn hàng: Chờ nhà hàng xác nhận`, and **OK, xem đơn hàng**.

Pass only safe bootstrap data to JavaScript:

```php
window.SavoraSePayCheckout = {
    referenceCode: <?= json_encode($snapshot['referenceCode'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    initialSnapshot: <?= json_encode($snapshot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    demoMode: <?= $demoMode ? 'true' : 'false' ?>
};
```

Never bootstrap the webhook API key.

**Step 3: Add responsive page styling**

Add scoped classes to `css/customer_style.css` for:

```text
.seapay-page
.seapay-shell
.seapay-payment-card
.seapay-qr-frame
.seapay-transfer-details
.seapay-copy-row
.seapay-waiting-state
.seapay-receipt
.seapay-receipt-mark
.seapay-receipt-list
.seapay-configuration-error
```

Use existing Savora variables, cards, button styles, focus states, and breakpoints. At 480px the QR stays within the viewport, transfer values wrap, and action buttons become full-width. Add a visually bounded configuration error when bank, account, or account name is missing; do not show a broken/fabricated image.

**Step 4: Wire the page controller**

Initialize after the footer-loaded client scripts are available:

```js
document.addEventListener('DOMContentLoaded', () => {
  window.SavoraSePay.init(window.SavoraSePayCheckout);
});
```

The QR `<img>` source must come exclusively from the server-generated official URL. The paid receipt must be populated/confirmed by the status snapshot even when the initial page request already sees `paid`.

**Step 5: Run markup and client tests**

Run:

```powershell
node --test tests/sepay_checkout_contract.test.js tests/sepay_checkout_client.test.js tests/customer_markup.test.js
```

Expected: PASS with no inline styles, no `auth.php` include, no committed recipient identity, and no client-side order transition.

**Step 6: Commit**

```powershell
git add seapay_checkout.php components/customer_header.php css/customer_style.css tests/customer_markup.test.js tests/sepay_checkout_contract.test.js
git commit -m "feat: deliver SePay QR and payment receipt"
```

---

### Task 5: Verify end-to-end state semantics and checkout handoff

**Files:**

- Modify: `tests/payment_confirmation_service_test.php`
- Modify: `tests/checkout_contract.test.js`
- Modify: `docs/superpowers/specs/2026-08-08-sepay-qr-payment-design.md` only if implementation evidence requires a factual clarification

**Step 1: Add the order/payment invariant regression**

After both webhook and demo confirmations, query both tables and assert:

```php
$state = payment_test_order_and_payment_state($conn, $reference);
payment_test_expect($state === [
    'orderStatus' => 'pending',
    'paymentStatus' => 'paid',
], 'Payment confirmation must not confirm the Restaurant order.');
```

Apply this assertion to one real webhook fixture and one demo fixture.

**Step 2: Lock the checkout redirect contract**

In `tests/checkout_contract.test.js`, assert that selecting `seapay` sends the user to:

```text
seapay_checkout.php?order=<server referenceCode>
```

and that the cart is cleared only after the server has placed the order. The redirect must never carry amount or paid state in the query string.

**Step 3: Run the focused regression set**

Run:

```powershell
php tests/sepay_checkout_service_test.php
php tests/payment_status_endpoint_test.php
php tests/payment_confirmation_service_test.php
php tests/sepay_webhook_test.php
node --test tests/sepay_checkout_contract.test.js tests/sepay_checkout_client.test.js tests/checkout_contract.test.js tests/customer_markup.test.js
```

Expected: all PASS.

**Step 4: Run broad automated regression**

Run all Node contract tests:

```powershell
node --test tests
```

Run all PHP tests sequentially and stop on the first failure:

```powershell
Get-ChildItem -LiteralPath tests -Filter '*test.php' | ForEach-Object { & php $_.FullName; if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE } }
```

Expected: PASS, or an explicitly documented environment-only `BLOCKED` result from a pre-existing test harness. No new SePay, checkout, order, or payment regression may remain.

**Step 5: Perform browser verification on the local app**

With Apache/MySQL running and `config/local.php` containing test recipient details plus `SAVORA_DEMO_MODE => true`:

1. Open `http://localhost:8085/Savora` and sign in as a Customer.
2. Add at least two distinct menu items and choose SePay at checkout.
3. Confirm the created order shows a real QR, exact integer VND amount, bank/account/recipient text, and the exact `SVR-...` transfer content.
4. Keep the page open and press **Simulate successful payment**.
5. Confirm the same page changes to the receipt without a manual reload.
6. Confirm the receipt says payment `paid` and order `pending / Chờ nhà hàng xác nhận`.
7. Press **OK, xem đơn hàng** and confirm history shows the pending order.
8. Repeat with a webhook event using the exact reference and amount; confirm it reaches the identical receipt state.
9. Verify missing bank configuration shows the bounded setup error and no QR image.

Capture the relevant HTTP/console evidence if any step fails; fix and rerun the focused tests before claiming completion.

**Step 6: Final commit and push**

```powershell
git add tests/payment_confirmation_service_test.php tests/checkout_contract.test.js docs/superpowers/specs/2026-08-08-sepay-qr-payment-design.md
git commit -m "test: verify SePay payment lifecycle"
git status --short --branch
git push origin main
```

Expected: clean `main`, pushed to `origin/main`, with the payment lifecycle verified and the Restaurant confirmation step still separate.

---

## Plan Self-Review

- Spec coverage: ownership, SePay-only access, official QR, public recipient configuration, VND amount, polling, real webhook, demo confirmation, paid receipt, navigation-only acknowledgement, and pending order semantics are each mapped to implementation and tests.
- Security coverage: session validation, scoped repository query, no write in status endpoint, no webhook secret exposure, exact server amount/reference, CSRF/idempotency reuse, and cross-Customer denial are explicit.
- Failure coverage: invalid reference, non-owner, wrong method, missing bank config, hidden tab, transient polling failure, invalid payment state, and duplicate demo/webhook confirmation are explicit.
- Type consistency: QR and client presentation use integer `amountVnd`; the database remains numeric and confirmation matching remains server-authoritative.
- No placeholder code, TODO markers, or deferred implementation decisions remain in this plan.
