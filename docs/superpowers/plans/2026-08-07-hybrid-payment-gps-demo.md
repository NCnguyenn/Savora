# Hybrid Payment and Simulated GPS Demo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a free, one-laptop Savora demo in which Customer payment, Restaurant preparation, Driver assignment, a 60-second simulated GPS route, Driver delivery, and Customer receipt confirmation form one observable server-authoritative workflow.

**Architecture:** Keep PHP/MySQL as the authority and use bounded two-second browser polling instead of WebSockets. Real SeaPay webhooks and a demo-only Customer simulator call the same payment confirmation service; simulated GPS progress is derived from server time so it continues across logout/login and separate browser sessions.

**Tech Stack:** PHP 8.2, MySQL 8/MariaDB through XAMPP port 3307, Vanilla JavaScript, local Leaflet 1.9.4, optional visible OpenStreetMap tiles, Node.js 24 built-in test runner.

## Global Constraints

- Run entirely on one Windows laptop using the existing XAMPP/PHP/MySQL application.
- Add no paid dependency, Node.js server, WebSocket server, scheduler, or external routing API.
- Keep SeaPay real webhook support optional; the primary demo must work without a public URL or bank transfer.
- Expose simulated payment and route-start commands only when `SAVORA_DEMO_MODE=1` and the environment is not production.
- Keep all writes authenticated, CSRF-protected, idempotent, ownership-scoped, version-checked, audited, and transactional.
- Derive route movement from server time with an exact duration of 60 seconds.
- Poll every 2 seconds only while the page is visible and an active order exists; back off to at most 15 seconds after failures.
- Load OpenStreetMap tiles only for an interactive visible viewport and retain attribution; never prefetch or cache tiles for offline use.
- Preserve the current manual/real Driver location commands and current role portals.
- Do not stage or modify unrelated untracked audit/debug files in the repository.

---

## File and Interface Map

| Area | Files | Responsibility |
| --- | --- | --- |
| Schema/domain | `database/migrations/021_hybrid_payment_gps_demo.php`, `lib/migrations.php`, `lib/domain/order_status.php` | Route table, final `completed` state, simple Restaurant transition |
| Payment authority | `lib/repositories/payment_repository.php`, `lib/services/payment_confirmation_service.php`, `api/webhook_seapay.php`, `api/payment_demo.php` | One shared confirmation path for provider and local demo events |
| Payment UI/gating | `seapay_checkout.php`, `customer_checkout.php`, `config/local.php.example`, `lib/repositories/order_repository.php`, `lib/services/order_transition_service.php` | Two payment timings, safe demo panel, unpaid-SeaPay Restaurant gate |
| Customer completion | `lib/services/customer_receipt_service.php`, `api/orders.php`, `lib/services/delivery_service.php` | `delivered -> completed` and atomic COD settlement |
| Route authority | `lib/repositories/demo_route_repository.php`, `lib/services/demo_route_service.php`, `api/tracking.php` | Start/read/finish a deterministic time-based route |
| Driver workflow | `api/dispatch.php`, `driver_dashboard.php`, `driver_delivery.php`, `js/driver_dashboard.js`, `js/driver_delivery.js` | Start a fresh demo shift, receive/accept an offer, pick up/start route, report delivery |
| Customer tracking | `customer_history.php`, `js/customer_tracking.js`, `css/customer_style.css` | Live status card, Leaflet/fallback route, receipt action |
| Restaurant refresh | `restaurant_orders.php`, `js/restaurant_orders.js` | Two-action preparation workflow and live refresh |
| Verification/docs | `tests/*`, `docs/HYBRID_PAYMENT_GPS_DEMO.md` | Contracts, integration coverage, four-role runbook |

---

### Task 1: Add the Route Schema and Final Order State

**Files:**
- Create: `database/migrations/021_hybrid_payment_gps_demo.php`
- Modify: `lib/migrations.php:4-28`
- Modify: `lib/domain/order_status.php:4-25`
- Modify: `tests/migration_registry.test.js`
- Modify: `tests/migration_integrity_test.php`
- Modify: `tests/order_transition_contract.test.js`

**Interfaces:**
- Produces table `delivery_demo_routes` with one row per delivery.
- Produces domain transition `customer: delivered -> completed`.
- Produces Restaurant transition `confirmed -> ready_for_pickup` while retaining `confirmed -> preparing` and `preparing -> ready_for_pickup`.

- [ ] **Step 1: Write failing registry and domain tests**

Add these assertions to the existing Node tests:

```js
test('hybrid payment GPS migration follows SeaPay hardening', () => {
  const registry = fs.readFileSync('lib/migrations.php', 'utf8');
  const sepay = registry.indexOf("'020_sepay_webhook_hardening'");
  const hybrid = registry.indexOf("'021_hybrid_payment_gps_demo'");
  assert.ok(hybrid > sepay);
  assert.match(registry, /database\/migrations\/021_hybrid_payment_gps_demo\.php/);
});

test('order domain includes customer completion and simple Restaurant ready action', () => {
  const source = fs.readFileSync('lib/domain/order_status.php', 'utf8');
  assert.match(source, /'completed'/);
  assert.match(source, /'customer'\s*=>\s*\[\s*'delivered'\s*=>\s*\['completed'\]/s);
  assert.match(source, /'confirmed'\s*=>\s*\['preparing',\s*'ready_for_pickup',\s*'cancelled'\]/);
});
```

- [ ] **Step 2: Run the tests and confirm RED**

Run:

```powershell
node --test tests/migration_registry.test.js tests/order_transition_contract.test.js
```

Expected: FAIL because migration `021_hybrid_payment_gps_demo` and `completed` are not registered in the domain.

- [ ] **Step 3: Implement migration 021 and domain transitions**

Create the migration with this final table contract:

```php
<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $sql = "CREATE TABLE IF NOT EXISTS delivery_demo_routes (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        delivery_id BIGINT NOT NULL,
        driver_user_id INT NOT NULL,
        start_latitude DECIMAL(10,7) NOT NULL,
        start_longitude DECIMAL(10,7) NOT NULL,
        end_latitude DECIMAL(10,7) NOT NULL,
        end_longitude DECIMAL(10,7) NOT NULL,
        started_at DATETIME NOT NULL,
        duration_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 60,
        status VARCHAR(20) NOT NULL DEFAULT 'running',
        completed_at DATETIME NULL,
        version INT NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_demo_route_delivery (delivery_id),
        KEY idx_demo_route_driver_status (driver_user_id,status),
        CONSTRAINT fk_demo_route_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
        CONSTRAINT fk_demo_route_driver FOREIGN KEY (driver_user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) throw new RuntimeException('Unable to create demo delivery routes: ' . $conn->error);
};
```

Register it after migration 020. Change the domain to:

```php
const SAVORA_ORDER_STATUSES = [
    'pending', 'confirmed', 'preparing', 'ready_for_pickup',
    'assigned', 'picked_up', 'delivered', 'completed', 'cancelled', 'refunded'
];

'customer' => [
    'delivered' => ['completed'],
],
'restaurant' => [
    'pending' => ['confirmed', 'cancelled'],
    'confirmed' => ['preparing', 'ready_for_pickup', 'cancelled'],
    'preparing' => ['ready_for_pickup', 'cancelled'],
],
```

- [ ] **Step 4: Extend migration integration verification**

After `savora_apply_migrations($conn)`, assert the exact schema:

```php
$routeTable = $conn->query("SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='delivery_demo_routes'")->fetch_assoc();
migration_expect((int) $routeTable['total'] === 1, 'Demo route table must exist.');
$deliveryIndex = $conn->query("SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='delivery_demo_routes' AND INDEX_NAME='uq_demo_route_delivery'")->fetch_assoc();
migration_expect($deliveryIndex !== null && (int) $deliveryIndex['NON_UNIQUE'] === 0, 'Each delivery must have at most one demo route.');
```

- [ ] **Step 5: Run focused tests and apply migrations to both databases**

Run:

```powershell
node --test tests/migration_registry.test.js tests/order_transition_contract.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' scripts/migrate.php
& 'D:\Xampp\php\php.exe' tests/migration_integrity_test.php
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
& 'D:\Xampp\php\php.exe' scripts/migrate.php
```

Expected: Node tests PASS; test and development migrations report 020/021 applied or no pending migrations; integrity test PASS.

- [ ] **Step 6: Commit Task 1**

```powershell
git add database/migrations/021_hybrid_payment_gps_demo.php lib/migrations.php lib/domain/order_status.php tests/migration_registry.test.js tests/migration_integrity_test.php tests/order_transition_contract.test.js
git commit -m "feat: add hybrid demo route schema"
```

---

### Task 2: Centralize SeaPay Confirmation and Add the Demo Simulator

**Files:**
- Create: `lib/services/payment_confirmation_service.php`
- Create: `api/payment_demo.php`
- Create: `tests/payment_confirmation_service_test.php`
- Create: `tests/environment_test.php`
- Modify: `lib/environment.php`
- Modify: `lib/repositories/payment_repository.php`
- Modify: `api/webhook_seapay.php`
- Modify: `tests/sepay_webhook_test.php`
- Modify: `tests/sepay_webhook_contract.test.js`

**Interfaces:**
- Produces `savora_demo_mode(?string $localPath = null): bool`; an explicit environment value overrides the ignored laptop-local config, and production always disables demo mode.
- Produces `payment_confirm_incoming(mysqli $conn, array $event, string $source): array`.
- Produces `payment_simulate_customer_success(mysqli $conn, int $customerUserId, string $referenceCode, string $idempotencyKey): array`.
- Both return `['ok'=>bool,'status'=>int,'message'=>string,'data'=>['referenceCode'=>string,'paymentStatus'=>string]]` on a processable order.

- [ ] **Step 1: Write failing payment service integration tests**

Create a transaction-scoped fixture with one Customer, Restaurant, SeaPay order, and pending payment, then assert:

```php
$event = [
    'state' => 'process',
    'transactionId' => 'SEPAY-TEST-' . bin2hex(random_bytes(4)),
    'referenceCode' => $reference,
    'amountCents' => 12550,
];
$confirmed = payment_confirm_incoming($conn, $event, 'seapay');
payment_test_expect(($confirmed['ok'] ?? false) === true, 'Exact incoming payment must succeed.');
payment_test_expect(($confirmed['data']['paymentStatus'] ?? '') === 'paid', 'Payment must become paid.');

$duplicate = payment_confirm_incoming($conn, $event, 'seapay');
payment_test_expect(($duplicate['ok'] ?? false) === true, 'Provider retry must be idempotent.');

$wrongEvent = $event;
$wrongEvent['transactionId'] = $event['transactionId'] . '-WRONG';
$wrongEvent['referenceCode'] = $wrongReference;
$wrongEvent['amountCents'] = 12549;
$wrong = payment_confirm_incoming($conn, $wrongEvent, 'seapay');
payment_test_expect(($wrong['status'] ?? 0) === 409, 'Wrong amount must remain pending/rejected.');
$wrongStatus = $conn->query("SELECT status FROM payments p JOIN orders o ON o.id=p.order_id WHERE o.reference_code='" . $conn->real_escape_string($wrongReference) . "'")->fetch_assoc();
payment_test_expect(($wrongStatus['status'] ?? '') === 'pending', 'Wrong amount must not change the pending payment.');

$demo = payment_simulate_customer_success($conn, $customerId, $secondReference, 'demo-pay-key-1');
payment_test_expect(($demo['data']['paymentStatus'] ?? '') === 'paid', 'Owned demo payment must use the same confirmation path.');
$forbidden = payment_simulate_customer_success($conn, $otherCustomerId, $thirdReference, 'demo-pay-key-2');
payment_test_expect(($forbidden['status'] ?? 0) === 404, 'Another Customer must not simulate this payment.');
```

Set `SAVORA_DEMO_MODE=1` only inside the test process and restore the previous value in `finally`.

In `tests/environment_test.php`, point `savora_demo_mode()` at temporary config files and assert local `true` works in development, explicit environment `0` overrides local `true`, explicit environment `1` enables demo, and production disables demo in all cases. Restore environment variables in `finally` so later tests are isolated.

- [ ] **Step 2: Run the integration test and confirm RED**

Run:

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DEMO_MODE='1'
& 'D:\Xampp\php\php.exe' tests/payment_confirmation_service_test.php
& 'D:\Xampp\php\php.exe' tests/environment_test.php
```

Expected: FAIL because `payment_confirmation_service.php`, both payment functions, and the local-config demo-mode behavior do not exist.

- [ ] **Step 3: Add focused payment repository operations**

Add these repository contracts using prepared statements:

```php
function payment_repository_target_by_reference(mysqli $conn, string $referenceCode, ?int $customerUserId = null, bool $forUpdate = false): array;
// SELECT p.id payment_id,p.order_id,p.method,p.amount,p.status,p.provider_reference,p.version,
//        o.reference_code,o.customer_user_id
// FROM payments p JOIN orders o ON o.id=p.order_id
// WHERE o.reference_code=? [AND o.customer_user_id=?] LIMIT 1 [FOR UPDATE]

function payment_repository_by_provider_reference(mysqli $conn, string $providerReference, bool $forUpdate = false): array;

function payment_repository_mark_paid(mysqli $conn, int $paymentId, int $expectedVersion, string $providerReference): bool
{
    $statement = $conn->prepare("UPDATE payments SET status='paid',provider_reference=?,paid_at=NOW(),version=version+1 WHERE id=? AND version=? AND status='pending' AND (provider_reference IS NULL OR provider_reference='')");
    $statement->bind_param('sii', $providerReference, $paymentId, $expectedVersion);
    $statement->execute();
    $ok = $statement->affected_rows === 1;
    $statement->close();
    return $ok;
}
```

- [ ] **Step 4: Make demo mode easy and safe to enable locally**

Update `savora_demo_mode()` so Apache does not need a machine-wide environment variable for this laptop demo:

```php
function savora_demo_mode(?string $localPath = null): bool
{
    if (savora_environment() === 'production') return false;
    $override = getenv('SAVORA_DEMO_MODE');
    if ($override !== false && trim((string) $override) !== '') return trim((string) $override) === '1';
    $localPath ??= __DIR__ . '/../config/local.php';
    if (!is_file($localPath)) return false;
    $config = require $localPath;
    return is_array($config) && ($config['SAVORA_DEMO_MODE'] ?? false) === true;
}
```

- [ ] **Step 5: Implement the shared payment service**

Use one private transaction body for provider and demo events; do not start a transaction in a public wrapper and then call another transaction-opening function. The critical behavior must be:

```php
function payment_confirm_transaction(
    mysqli $conn,
    array $event,
    string $source,
    ?int $customerScope = null,
    ?array $idempotency = null
): array
{
    if (($event['state'] ?? '') !== 'process') return payment_confirmation_result(true, 200, 'Payment event ignored.');
    $reference = trim((string) ($event['referenceCode'] ?? ''));
    $provider = trim((string) ($event['transactionId'] ?? ''));
    $conn->begin_transaction();
    try {
        if ($idempotency !== null) {
            $stored = savora_idempotency_find($conn, (int) $idempotency['actorId'], (string) $idempotency['key'], (string) $idempotency['action'], (string) $idempotency['requestHash']);
            if ($stored !== null) { $conn->commit(); return $stored; }
        }
        $target = payment_repository_target_by_reference($conn, $reference, $customerScope, true);
        if ($target === [] || (string) $target['method'] !== 'seapay') {
            $conn->commit();
            return payment_confirmation_result(false, 404, 'SeaPay order was not found.');
        }
        $amountCents = $source === 'demo'
            ? (int) round(((float) $target['amount']) * 100)
            : (int) ($event['amountCents'] ?? 0);
        $seen = payment_repository_by_provider_reference($conn, $provider, true);
        if ($seen !== []) {
            $same = (int) $seen['order_id'] === (int) $target['order_id'];
            $conn->commit();
            return payment_confirmation_result($same, $same ? 200 : 409, $same ? 'Payment already confirmed.' : 'Provider transaction is already bound.');
        }
        if ((string) $target['status'] !== 'pending' || !sepay_webhook_amount_matches($amountCents, $target['amount'])) {
            $conn->commit();
            return payment_confirmation_result(false, 409, 'Payment is not pending or the amount does not match.');
        }
        if (!payment_repository_mark_paid($conn, (int) $target['payment_id'], (int) $target['version'], $provider)) throw new RuntimeException('Payment changed.');
        notification_queue($conn, (int) $target['customer_user_id'], 'payment_confirmed', 'Payment confirmed', 'Payment for ' . $reference . ' was confirmed.', 'order', (int) $target['order_id']);
        audit_append($conn, (int) $target['customer_user_id'], 'payment_confirmed_' . $source, 'payment', (int) $target['payment_id'], ['status' => 'pending'], ['status' => 'paid', 'providerReference' => $provider], 'Payment confirmation received.', 'PAY-' . strtoupper(bin2hex(random_bytes(5))));
        $result = payment_confirmation_result(true, 200, 'Payment confirmed.', ['referenceCode' => $reference, 'paymentStatus' => 'paid']);
        if ($idempotency !== null) {
            savora_idempotency_store($conn, (int) $idempotency['actorId'], (string) $idempotency['key'], (string) $idempotency['action'], (string) $idempotency['requestHash'], $result);
        }
        $conn->commit();
        return $result;
    } catch (Throwable $exception) {
        $conn->rollback();
        error_log('Payment confirmation failed: ' . $exception->getMessage());
        return payment_confirmation_result(false, 500, 'Payment confirmation could not be completed.');
    }
}
```

`payment_confirm_incoming()` is a thin wrapper that calls this helper with `customerScope=null` and no idempotency descriptor.

`payment_simulate_customer_success()` must reject non-demo mode, create a deterministic provider reference, and call the same helper with `source='demo'`, the authenticated Customer id as `customerScope`, and an idempotency descriptor using action `simulate_seapay_payment`:

```php
$providerReference = 'DEMO-' . strtoupper(substr(hash('sha256', $customerUserId . '|' . $referenceCode . '|' . $idempotencyKey), 0, 24));
```

The helper derives exact cents only after locking the Customer-owned payment. Store/replay the demo response through the existing Savora idempotency table in that same transaction; the endpoint-level named lock serializes duplicate requests.

- [ ] **Step 6: Refactor the real webhook and add the demo endpoint**

Replace the webhook's inline payment transaction with:

```php
$response = payment_confirm_incoming($conn, $event, 'seapay');
$serviceStatus = (int) ($response['status'] ?? 500);
if ($serviceStatus >= 500) {
    sepay_webhook_reply(500, ['success' => false, 'message' => 'Payment confirmation could not be completed.']);
}
// Acknowledge valid, parsed provider events even when Savora safely ignores or
// rejects the business match, so SeaPay does not retry an unprocessable event.
sepay_webhook_reply(200, ['success' => true, 'message' => (string) ($response['message'] ?? 'Payment event acknowledged.')]);
```

Keep authentication and malformed-JSON failures outside this mapping so they retain their existing non-2xx responses. The Customer demo endpoint must return the service's real `status` (`200`, `404`, `409`, or `500`) because its caller needs actionable feedback.

Create `api/payment_demo.php` as Customer-only POST JSON:

```php
$actor = savora_request_actor($conn, ['customer']);
if (!savora_demo_mode()) savora_error(404, 'Demo payment is unavailable.');
// Require POST, CSRF, Idempotency-Key, and action simulate_success.
$response = payment_simulate_customer_success(
    $conn,
    (int) $actor['userId'],
    (string) ($payload['referenceCode'] ?? ''),
    $idempotencyKey
);
```

- [ ] **Step 7: Run payment tests**

Run:

```powershell
node --test tests/sepay_webhook_contract.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DEMO_MODE='1'
& 'D:\Xampp\php\php.exe' tests/sepay_webhook_test.php
& 'D:\Xampp\php\php.exe' tests/payment_confirmation_service_test.php
& 'D:\Xampp\php\php.exe' tests/environment_test.php
```

Expected: all tests PASS, including duplicate event and cross-Customer denial.

- [ ] **Step 8: Commit Task 2**

```powershell
git add lib/environment.php lib/repositories/payment_repository.php lib/services/payment_confirmation_service.php api/webhook_seapay.php api/payment_demo.php tests/environment_test.php tests/payment_confirmation_service_test.php tests/sepay_webhook_test.php tests/sepay_webhook_contract.test.js
git commit -m "feat: add shared SeaPay demo confirmation"
```

---

### Task 3: Add Payment Timing UI and Enforce the Restaurant Payment Gate

**Files:**
- Modify: `customer_checkout.php`
- Modify: `seapay_checkout.php`
- Modify: `config/local.php.example`
- Modify: `lib/repositories/order_repository.php`
- Modify: `lib/services/order_transition_service.php`
- Create: `tests/payment_gate_service_test.php`
- Modify: `tests/checkout_contract.test.js`
- Modify: `tests/customer_markup.test.js`
- Modify: `tests/order_transition_service_test.php`

**Interfaces:**
- Checkout presents `pay_now` (Wallet/SeaPay) and `pay_on_receipt` (cash) without changing the API's stored method values.
- Restaurant reads exclude `method='seapay' AND status<>'paid'`.
- Restaurant writes also reject an unpaid prepaid order even if the reference is crafted manually.

- [ ] **Step 1: Write failing payment timing and gate tests**

Add markup assertions:

```js
assert.match(checkout, /data-payment-timing="pay_now"/);
assert.match(checkout, /data-payment-timing="pay_on_receipt"/);
assert.match(checkout, /value="seapay"/);
assert.match(checkout, /value="cash"/);
assert.match(seapay, /data-demo-seapay-confirm/);
assert.doesNotMatch(seapay, /0366564953|NGUYEN CHI NGUYEN/);
```

In `payment_gate_service_test.php`, create three orders for one Restaurant: pending SeaPay, paid SeaPay, and pending COD. Assert the Restaurant query returns paid SeaPay and COD but not pending SeaPay. Call `order_transition()` with the pending SeaPay reference and assert status 409.

- [ ] **Step 2: Run focused tests and confirm RED**

```powershell
node --test tests/checkout_contract.test.js tests/customer_markup.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' tests/payment_gate_service_test.php
```

Expected: FAIL because timing hooks, safe demo panel, and server gate are absent.

- [ ] **Step 3: Group checkout methods without changing API values**

Render two labelled sections:

```html
<section class="payment-timing" data-payment-timing="pay_now">
  <h3>Pay now</h3>
  <!-- existing wallet and SeaPay radio controls -->
</section>
<section class="payment-timing" data-payment-timing="pay_on_receipt">
  <h3>Pay when you receive the order</h3>
  <!-- existing cash radio control -->
</section>
```

Keep the submit payload unchanged: `paymentMethod` remains `wallet`, `seapay`, or `cash`.

- [ ] **Step 4: Make the SeaPay page hybrid and configuration-safe**

Extend `config/local.php.example` with non-secret keys:

```php
'SAVORA_DEMO_MODE' => false,
'SEPAY_BANK_BIN' => '',
'SEPAY_BANK_ACCOUNT' => '',
'SEPAY_ACCOUNT_NAME' => '',
```

The committed example remains `false`. For the local demo only, set this key to `true` in ignored `config/local.php`; production still cannot enable it.

Read these values through a small configuration helper in `payment_confirmation_service.php`. Render a VietQR URL only when all three values are non-empty. In demo mode render:

```html
<button type="button" class="primary-action" data-demo-seapay-confirm>
    Simulate successful payment
</button>
<p data-demo-seapay-status role="status" aria-live="polite"></p>
```

The click handler posts `simulate_success` to `api/payment_demo.php`; on success it redirects to `customer_history.php?order=<reference>&paid=1`. Keep the existing five-second provider status check only when real bank configuration exists; implement it with recursive `setTimeout`, not `setInterval`.

- [ ] **Step 5: Enforce the gate in reads and writes**

In `order_repository_scoped()` add for Restaurant scope:

```php
$where .= " AND (o.payment_method='cash' OR p.status='paid')";
```

Update `order_repository_count()` to join `payments p`, matching the base read query. Extend `order_repository_transition_target()` to select `p.status AS payment_status` and join `payments p ON p.order_id=o.id`. Before Restaurant transitions, reject:

```php
if ($role === 'restaurant' && (string) $order['payment_method'] !== 'cash' && (string) ($order['payment_status'] ?? 'pending') !== 'paid') {
    return order_transition_finish($conn, $actorId, $idempotencyKey, $requestHash,
        order_transition_result(false, 409, 'Online payment must be confirmed before the Restaurant can process this order.'));
}
```

- [ ] **Step 6: Run focused and regression tests**

```powershell
node --test tests/checkout_contract.test.js tests/customer_markup.test.js tests/order_transition_contract.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' tests/payment_gate_service_test.php
& 'D:\Xampp\php\php.exe' tests/order_transition_service_test.php
& 'D:\Xampp\php\php.exe' tests/order_query_service_test.php
```

Expected: all tests PASS; Restaurant pagination totals match the filtered list.

- [ ] **Step 7: Commit Task 3**

```powershell
git add customer_checkout.php seapay_checkout.php config/local.php.example lib/repositories/order_repository.php lib/services/order_transition_service.php tests/payment_gate_service_test.php tests/checkout_contract.test.js tests/customer_markup.test.js tests/order_transition_service_test.php
git commit -m "feat: gate Restaurant orders on payment"
```

---

### Task 4: Make Customer Receipt Confirmation the Final Authority

**Files:**
- Create: `lib/services/customer_receipt_service.php`
- Create: `tests/customer_receipt_service_test.php`
- Modify: `api/orders.php`
- Modify: `lib/services/delivery_service.php:175-181`
- Modify: `tests/delivery_service_test.php`
- Modify: `tests/order_api_contract.test.js`

**Interfaces:**
- Produces `customer_confirm_receipt(mysqli $conn, int $customerUserId, string $referenceCode, int $expectedVersion, string $idempotencyKey): array`.
- `api/orders.php` accepts Customer action `confirm_received` separately from generic role transitions.
- Driver delivery changes the order to `delivered` but does not settle COD.

- [ ] **Step 1: Write failing Customer receipt integration tests**

Create prepaid and COD delivered orders. Assert:

```php
$cod = customer_confirm_receipt($conn, $customerId, $codReference, 3, 'receipt-cod-1');
receipt_expect(($cod['data']['status'] ?? '') === 'completed', 'Customer must complete delivered COD order.');
receipt_expect(($cod['data']['paymentStatus'] ?? '') === 'paid', 'Customer confirmation must settle COD.');

$prepaid = customer_confirm_receipt($conn, $customerId, $prepaidReference, 2, 'receipt-prepaid-1');
receipt_expect(($prepaid['data']['status'] ?? '') === 'completed', 'Customer must complete a paid SeaPay order.');

$foreign = customer_confirm_receipt($conn, $otherCustomerId, $codReference, 3, 'receipt-foreign-1');
receipt_expect(($foreign['status'] ?? 0) === 404, 'Customer must not confirm another order.');

$early = customer_confirm_receipt($conn, $customerId, $pickedUpReference, 2, 'receipt-early-1');
receipt_expect(($early['status'] ?? 0) === 409, 'Customer must not confirm before Driver delivery.');
```

Change `delivery_service_test.php` to expect cash payment `pending` immediately after `delivery_record_completion()`.

- [ ] **Step 2: Run tests and confirm RED**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' tests/customer_receipt_service_test.php
& 'D:\Xampp\php\php.exe' tests/delivery_service_test.php
```

Expected: receipt service is missing and current delivery test reveals COD is paid too early.

- [ ] **Step 3: Implement the receipt service transaction**

Use a prepared `FOR UPDATE` query joining orders, payments, Restaurant owner, and assigned Driver. The transaction body must:

```php
if ((int) $order['customer_user_id'] !== $customerUserId) {
    $conn->rollback();
    return customer_receipt_result(false, 404, 'Order was not found.');
}
if ((string) $order['status'] !== 'delivered') {
    $conn->rollback();
    return customer_receipt_result(false, 409, 'The Driver must deliver this order first.');
}
if ((int) $order['version'] !== $expectedVersion) {
    $conn->rollback();
    return customer_receipt_result(false, 409, 'Order changed. Refresh and try again.');
}
if ((string) $order['payment_method'] !== 'cash' && (string) $order['payment_status'] !== 'paid') {
    $conn->rollback();
    return customer_receipt_result(false, 409, 'Online payment has not been confirmed.');
}
if (!order_repository_set_status($conn, (int) $order['id'], 'completed', $expectedVersion)) {
    $conn->rollback();
    return customer_receipt_result(false, 409, 'Order changed. Refresh and try again.');
}
order_repository_insert_history_event($conn, (int) $order['id'], 'completed', 'customer', $customerUserId, 'Customer confirmed receipt.');
if ((string) $order['payment_method'] === 'cash') {
    $paid = $conn->prepare("UPDATE payments SET status='paid',paid_at=NOW(),version=version+1 WHERE order_id=? AND status='pending'");
    $paid->bind_param('i', $order['id']);
    $paid->execute();
    if ($paid->affected_rows !== 1) throw new RuntimeException('COD payment changed.');
    $paid->close();
}
```

Queue completion notifications for Restaurant owner and assigned Driver, append an audit row, store idempotency response, and commit.

- [ ] **Step 4: Route the Customer command in `api/orders.php`**

Parse action before dispatching:

```php
$action = trim((string) ($body['action'] ?? ''));
if ($action === 'confirm_received') {
    if ((string) $actor['role'] !== 'customer') savora_error(403, 'Only the Customer can confirm receipt.');
    $response = customer_confirm_receipt($conn, (int) $actor['userId'], (string) ($payload['referenceCode'] ?? ''), (int) ($payload['expectedVersion'] ?? 0), $idempotencyKey);
} elseif ($action === 'transition') {
    $response = order_transition(
        $conn,
        $actor,
        (string) ($payload['referenceCode'] ?? $payload['reference_code'] ?? ''),
        (string) ($payload['nextStatus'] ?? $payload['status'] ?? ''),
        (int) ($payload['expectedVersion'] ?? $payload['version'] ?? 0),
        $idempotencyKey,
        (string) ($payload['reason'] ?? '')
    );
} else {
    savora_error(422, 'Unsupported order action.');
}
```

Retain the endpoint's existing lock/unlock and response envelope behavior.

- [ ] **Step 5: Remove early COD settlement from Driver completion**

Delete only the `UPDATE payments SET status='paid'` branch from `delivery_transition()`. Keep delivery status, order status, notifications, evidence, and audit logic unchanged.

- [ ] **Step 6: Run receipt, delivery, and endpoint tests**

```powershell
node --test tests/order_api_contract.test.js tests/order_transition_contract.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' tests/customer_receipt_service_test.php
& 'D:\Xampp\php\php.exe' tests/delivery_service_test.php
& 'D:\Xampp\php\php.exe' tests/endpoint_compatibility_test.php
```

Expected: all PASS; COD remains pending at `delivered` and is paid at `completed`.

- [ ] **Step 7: Commit Task 4**

```powershell
git add lib/services/customer_receipt_service.php api/orders.php lib/services/delivery_service.php tests/customer_receipt_service_test.php tests/delivery_service_test.php tests/order_api_contract.test.js
git commit -m "feat: complete orders on Customer receipt"
```

---

### Task 5: Build the Server-timed Demo Route and Tracking API

**Files:**
- Create: `lib/repositories/demo_route_repository.php`
- Create: `lib/services/demo_route_service.php`
- Create: `api/tracking.php`
- Create: `tests/demo_route_service_test.php`
- Create: `tests/tracking_api_contract.test.js`
- Modify: `lib/repositories/order_repository.php:104-126`

**Interfaces:**
- Produces `demo_route_start(mysqli $conn, int $driverUserId, int $deliveryId, int $expectedDeliveryVersion, string $idempotencyKey): array`.
- Produces `demo_route_snapshot(mysqli $conn, array $actor, string $referenceCode): array`.
- Produces `demo_route_is_arrived(mysqli $conn, int $deliveryId, ?DateTimeImmutable $now = null): ?bool` for the Driver completion gate.
- Produces `demo_route_finish(mysqli $conn, int $deliveryId): void` for use inside the delivery transaction.
- Tracking payload uses `{referenceCode,orderStatus,orderVersion,payment,assignment,route}` with route points expressed as `{latitude:float,longitude:float}`.

- [ ] **Step 1: Write failing deterministic route tests**

Use a fixture with Restaurant and exact quote-address coordinates. Inject a clock callable into the pure calculator and assert:

```php
$half = demo_route_calculate_point([
    'start_latitude' => '10.0000000', 'start_longitude' => '106.0000000',
    'end_latitude' => '10.0100000', 'end_longitude' => '106.0200000',
    'started_at' => '2026-08-07 10:00:00', 'duration_seconds' => 60,
], new DateTimeImmutable('2026-08-07 10:00:30'));
route_expect(abs($half['progress'] - 0.5) < 0.0001, 'Thirty seconds must be 50 percent.');
route_expect($half['current']['latitude'] >= 10.0 && $half['current']['latitude'] <= 10.012, 'Latitude must remain near the route bounds.');

$ended = demo_route_calculate_point($route, new DateTimeImmutable('2026-08-07 10:01:30'));
route_expect($ended['progress'] === 1.0, 'Progress must clamp at one.');
route_expect($ended['current'] === ['latitude' => 10.01, 'longitude' => 106.02], 'Ended route must stop at Customer.');
```

Also assert start is rejected for production, another Driver, missing coordinates, stale version, and a second active route.

- [ ] **Step 2: Run tests and confirm RED**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DEMO_MODE='1'
& 'D:\Xampp\php\php.exe' tests/demo_route_service_test.php
node --test tests/tracking_api_contract.test.js
```

Expected: missing repository/service/endpoint failures.

- [ ] **Step 3: Implement route repository queries**

The start target query must lock the delivery and use the order's quoted address, not whichever address is currently default:

```sql
SELECT d.id AS delivery_id,d.driver_user_id,d.status AS delivery_status,d.version AS delivery_version,
       o.id AS order_id,o.reference_code,o.status AS order_status,o.version AS order_version,o.customer_user_id,
       r.owner_user_id,r.latitude AS start_latitude,r.longitude AS start_longitude,
       COALESCE(qa.latitude,da.latitude) AS end_latitude,
       COALESCE(qa.longitude,da.longitude) AS end_longitude
FROM deliveries d
JOIN orders o ON o.id=d.order_id
JOIN restaurants r ON r.id=o.restaurant_id
LEFT JOIN checkout_quotes q ON q.id=o.quote_id
LEFT JOIN customer_addresses qa ON qa.id=q.address_id AND qa.customer_user_id=o.customer_user_id
LEFT JOIN customer_addresses da ON da.customer_user_id=o.customer_user_id AND da.is_default=1
WHERE d.id=? LIMIT 1 FOR UPDATE
```

Repository operations must include route upsert/insert, route by delivery/reference, mark finished, and authorization target fields.

- [ ] **Step 4: Implement the pure point calculator**

Use clamped elapsed time and a deterministic curve that returns exact endpoints:

```php
function demo_route_calculate_point(array $route, DateTimeImmutable $now): array
{
    $startTime = new DateTimeImmutable((string) $route['started_at']);
    $elapsed = max(0, $now->getTimestamp() - $startTime->getTimestamp());
    $duration = max(1, (int) $route['duration_seconds']);
    $progress = min(1.0, $elapsed / $duration);
    $startLat = (float) $route['start_latitude'];
    $startLng = (float) $route['start_longitude'];
    $endLat = (float) $route['end_latitude'];
    $endLng = (float) $route['end_longitude'];
    $curve = sin(M_PI * $progress) * 0.0015;
    $current = $progress >= 1.0
        ? ['latitude' => $endLat, 'longitude' => $endLng]
        : [
            'latitude' => $startLat + (($endLat - $startLat) * $progress) + $curve,
            'longitude' => $startLng + (($endLng - $startLng) * $progress) - ($curve * 0.6),
        ];
    return ['progress' => $progress, 'current' => $current, 'arrived' => $progress >= 1.0];
}
```

- [ ] **Step 5: Implement route start, snapshot authorization, and finish**

`demo_route_start()` must check demo mode, Driver ownership, assigned status/version, and coordinates. In one transaction it records `arrived` and `picked_up` milestones, changes delivery/order to `picked_up`, inserts the 60-second route, places `driver_locations` at the Restaurant, queues a Customer notification, audits, and stores the idempotency response.

`demo_route_snapshot()` authorizes exactly:

```php
$allowed = match ((string) $actor['role']) {
    'customer' => (int) $row['customer_user_id'] === (int) $actor['userId'],
    'restaurant' => (int) $row['owner_user_id'] === (int) $actor['userId'],
    'driver' => (int) $row['driver_user_id'] === (int) $actor['userId'],
    'admin' => true,
    default => false,
};
```

Return 404 for unauthorized/missing references to avoid leaking order existence.

`demo_route_is_arrived()` reuses the same calculator and returns `null` when a real/non-demo delivery has no route. `demo_route_finish()` marks an arrived row `finished`, sets `completed_at=NOW()`, and places `driver_locations` at the stored destination. It does not commit; the caller owns the transaction.

- [ ] **Step 6: Create read-only tracking endpoint**

`api/tracking.php` accepts authenticated GET for all four roles and requires `order=<reference>`. It calls `demo_route_snapshot()` and returns the standard Savora JSON envelope. It has no POST branch and no state-changing GET behavior.

- [ ] **Step 7: Correct order read location to use the quoted address**

Change `order_repository_order_base()` to join the quote address first and fall back to default, matching the route target query. Map `deliveryLocation` from the resulting exact latitude/longitude fields.

- [ ] **Step 8: Run route/tracking/query tests**

```powershell
node --test tests/tracking_api_contract.test.js tests/order_api_contract.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DEMO_MODE='1'
& 'D:\Xampp\php\php.exe' tests/demo_route_service_test.php
& 'D:\Xampp\php\php.exe' tests/order_query_service_test.php
```

Expected: all PASS and the 30-second snapshot is deterministic.

- [ ] **Step 9: Commit Task 5**

```powershell
git add lib/repositories/demo_route_repository.php lib/services/demo_route_service.php api/tracking.php lib/repositories/order_repository.php tests/demo_route_service_test.php tests/tracking_api_contract.test.js
git commit -m "feat: add server-timed demo tracking"
```

---

### Task 6: Connect the Driver Demo Workflow

**Files:**
- Modify: `api/dispatch.php`
- Modify: `lib/services/delivery_service.php`
- Modify: `lib/services/dispatch_service.php`
- Modify: `lib/repositories/dispatch_repository.php`
- Modify: `lib/repositories/order_repository.php`
- Modify: `driver_dashboard.php`
- Modify: `driver_delivery.php`
- Modify: `js/driver_dashboard.js`
- Modify: `js/driver_delivery.js`
- Modify: `tests/dispatch_api_contract.test.js`
- Modify: `tests/driver_markup.test.js`
- Modify: `tests/delivery_service_test.php`
- Create: `tests/driver_demo_route.test.js`

**Interfaces:**
- `api/dispatch.php` command `demo_start_delivery` calls `demo_route_start()`.
- `api/dispatch.php` command `demo_start_shift` refreshes the authenticated Driver's saved profile coordinates and makes that Driver eligible for the five-minute candidate window.
- Existing `record_completion` rejects a running route before 100 percent, then marks an arrived route finished inside the same transaction.
- Driver UI maps assigned delivery to `demo_start_delivery`, picked-up delivery to `record_completion`.

- [ ] **Step 1: Write failing Driver contract tests**

```js
assert.match(dispatchApi, /'demo_start_delivery'/);
assert.match(dispatchApi, /'demo_start_shift'/);
assert.match(driverDashboard, /data-demo-start-shift/);
assert.match(driverController, /demo_start_delivery/);
assert.match(driverController, /Picked up - start delivery/);
assert.match(driverController, /Delivered to Customer/);
assert.match(driverPage, /data-demo-route-progress/);
assert.doesNotMatch(driverController, /watchPosition/);
```

Unit-test the action selector:

```js
assert.deepEqual(actions.primaryAction({ status: 'assigned' }, true), ['demo_start_delivery', 'Picked up - start delivery']);
assert.deepEqual(actions.primaryAction({ status: 'picked_up' }, true), ['record_completion', 'Delivered to Customer']);
```

Extend `delivery_service_test.php` with a demo route whose `started_at` is current and assert `delivery_record_completion()` returns 409 without changing delivery/order state. Move `started_at` to at least 61 seconds in the past, retry with a new idempotency key, and assert delivery/order become `delivered` and the route becomes `finished`.

- [ ] **Step 2: Run tests and confirm RED**

```powershell
node --test tests/dispatch_api_contract.test.js tests/driver_markup.test.js tests/driver_demo_route.test.js
```

Expected: missing demo command and labels.

- [ ] **Step 3: Add the dispatch command and finish hook**

In the API command match:

```php
'demo_start_delivery' => $role === 'driver'
    ? demo_route_start($conn, $actorId, (int) ($payload['deliveryId'] ?? 0), (int) ($payload['expectedVersion'] ?? 0), $idempotencyKey)
    : dispatch_result(false, 403, 'Only a Driver can start demo delivery.'),
```

Require `demo_route_service.php` from `lib/services/delivery_service.php`. Add `demo_route_is_arrived(mysqli $conn, int $deliveryId, ?DateTimeImmutable $now = null): ?bool`, returning `null` when no demo route exists. Before mutating a `picked_up -> delivered` transition, return status 409 when this function returns `false`. Inside the successful branch, call `demo_route_finish($conn, $deliveryId)` before committing.

- [ ] **Step 4: Add a stable demo-shift action and live offer refresh**

Extend `dispatch_repository_driver_profile()` to select `dp.latitude,dp.longitude`. Add this wrapper to `dispatch_service.php`:

```php
function driver_start_demo_shift(mysqli $conn, int $driverUserId, string $idempotencyKey): array
{
    if (!savora_demo_mode()) return dispatch_result(false, 404, 'Demo shift is unavailable.');
    $profile = dispatch_repository_driver_profile($conn, $driverUserId);
    $latitude = isset($profile['latitude']) ? (float) $profile['latitude'] : null;
    $longitude = isset($profile['longitude']) ? (float) $profile['longitude'] : null;
    if ($latitude === null || $longitude === null) return dispatch_result(false, 409, 'Save a Driver profile location before starting the demo shift.');
    return driver_set_availability($conn, $driverUserId, 'online', $latitude, $longitude, null, null, $idempotencyKey);
}
```

Passing `null` for `recordedAt` deliberately lets the existing repository use MySQL `NOW()`, keeping candidate freshness on the same clock as dispatch queries.

Route `demo_start_shift` in `api/dispatch.php`. Add a demo-only **Start demo shift** button to `driver_dashboard.php`; post the command and refresh the dashboard. After the shift starts, `js/driver_dashboard.js` recursively refreshes `api/dispatch.php` and assigned orders every two seconds while visible, with the same 15-second maximum backoff. This makes a new Restaurant offer appear without F5 and refreshes `driver_locations.recorded_at` without using the laptop's physical GPS.

- [ ] **Step 5: Expose proof requirements and simplify the Driver primary action in demo mode**

Select `d.proof_required` in `order_repository_order_base()` and map it as `assignment.proofRequired`. Carry it through `deliveryForOrder()`.

Export a pure selector:

```js
function primaryAction(delivery, demoMode) {
  if (!delivery) return null;
  if (demoMode && delivery.status === 'assigned') return ['demo_start_delivery', 'Picked up - start delivery'];
  if (delivery.status === 'assigned') return ['record_arrival', 'Mark arrived at pickup'];
  if (delivery.status === 'arrived') return ['record_pickup', 'Confirm pickup'];
  if (delivery.status === 'picked_up') return ['record_completion', 'Delivered to Customer'];
  return null;
}
```

Expose demo mode to the page as `window.SavoraDemoMode = true|false` from PHP using `savora_demo_mode()`. Add route progress/status nodes. When the delivery is picked up, poll `api/tracking.php?order=<reference>` every two seconds while visible and render percentage; do not create another route timer. Keep **Delivered to Customer** disabled until `route.arrived === true`; the server-side arrival check remains authoritative.

- [ ] **Step 6: Preserve proof-of-delivery behavior**

For `record_completion`, call `uploadEvidence()` only when `activeDelivery.proofRequired === true`; otherwise send `evidenceIds: []`. Do not fabricate evidence in demo mode. The existing proof-required integration test remains unchanged.

- [ ] **Step 7: Run Driver and delivery regressions**

```powershell
node --test tests/dispatch_api_contract.test.js tests/driver_markup.test.js tests/driver_demo_route.test.js tests/dispatch_cutover.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DEMO_MODE='1'
& 'D:\Xampp\php\php.exe' tests/demo_route_service_test.php
& 'D:\Xampp\php\php.exe' tests/delivery_service_test.php
& 'D:\Xampp\php\php.exe' tests/dispatch_service_test.php
```

Expected: all PASS.

- [ ] **Step 8: Commit Task 6**

```powershell
git add api/dispatch.php lib/services/delivery_service.php lib/services/dispatch_service.php lib/repositories/dispatch_repository.php lib/repositories/order_repository.php driver_dashboard.php driver_delivery.php js/driver_dashboard.js js/driver_delivery.js tests/delivery_service_test.php tests/dispatch_api_contract.test.js tests/driver_markup.test.js tests/driver_demo_route.test.js
git commit -m "feat: connect Driver simulated delivery"
```

---

### Task 7: Build the Customer Live Tracking Card and Receipt Action

**Files:**
- Create: `js/customer_tracking.js`
- Create: `tests/customer_tracking.test.js`
- Modify: `customer_history.php`
- Modify: `css/customer_style.css`
- Modify: `tests/customer_markup.test.js`

**Interfaces:**
- Produces `window.SavoraCustomerTracking` with pure exports `displayState(order)`, `nextDelay(failures)`, and browser method `mount(options)`.
- `mount()` reads `api/orders.php`, reads active route snapshots from `api/tracking.php`, and posts `confirm_received` to `api/orders.php`.

- [ ] **Step 1: Write failing Customer tracking unit/markup tests**

```js
const tracking = require('../js/customer_tracking.js');
assert.equal(tracking.displayState({ status: 'pending', payment: { method: 'seapay', status: 'pending' } }), 'waiting_payment');
assert.equal(tracking.displayState({ status: 'confirmed', payment: { status: 'paid' } }), 'preparing');
assert.equal(tracking.displayState({ status: 'picked_up' }), 'on_the_way');
assert.equal(tracking.displayState({ status: 'delivered' }), 'waiting_confirmation');
assert.equal(tracking.displayState({ status: 'completed' }), 'completed');
assert.equal(tracking.nextDelay(0), 2000);
assert.equal(tracking.nextDelay(4), 15000);

assert.match(historyPage, /data-customer-live-order/);
assert.match(historyPage, /data-tracking-map/);
assert.match(historyPage, /data-confirm-received/);
assert.match(historyPage, /assets\/vendor\/leaflet\/leaflet\.css/);
assert.match(historyPage, /assets\/vendor\/leaflet\/leaflet\.js/);
```

- [ ] **Step 2: Run tests and confirm RED**

```powershell
node --test tests/customer_tracking.test.js tests/customer_markup.test.js
```

Expected: missing module, hooks, and Leaflet assets.

- [ ] **Step 3: Add stable tracking markup and local Leaflet assets**

Replace only the active-order rendering target with semantic hooks:

```html
<article class="active-order-summary" data-customer-live-order>
  <p data-live-order-status role="status" aria-live="polite"></p>
  <ol data-live-order-progress class="order-progress"></ol>
  <section data-live-driver hidden>
    <div class="customer-tracking-map" data-tracking-map aria-label="Live Driver route"></div>
    <p><span data-route-progress>0%</span> · <span data-route-updated>Waiting for route</span></p>
  </section>
  <button type="button" class="primary-action" data-confirm-received hidden></button>
  <p data-live-order-feedback role="status" aria-live="polite"></p>
</article>
```

Include local Leaflet CSS/JS and `js/customer_tracking.js`; retain an accessible no-order empty state. Remove the old inline one-shot `renderActiveOrder()` ownership so only the new module writes the live card, while leaving completed history, review, and reorder rendering in `customer_history.php`.

- [ ] **Step 4: Implement pure state and retry helpers**

```js
function displayState(order) {
  if (order?.status === 'pending' && order?.payment?.method === 'seapay' && order?.payment?.status !== 'paid') return 'waiting_payment';
  return ({ pending: 'waiting_restaurant', confirmed: 'preparing', preparing: 'preparing', ready_for_pickup: 'finding_driver', assigned: 'driver_assigned', picked_up: 'on_the_way', delivered: 'waiting_confirmation', completed: 'completed' })[order?.status] || 'unavailable';
}
function nextDelay(failures) {
  return Math.min(15000, 2000 * (2 ** Math.max(0, Number(failures) || 0)));
}
```

Treat `pending`, `confirmed`, `preparing`, `ready_for_pickup`, `assigned`, `picked_up`, and `delivered` as active. Move only `completed`, `cancelled`, and `refunded` to final history; this is required so the receipt-confirmation button cannot disappear while an order is waiting on the Customer.

- [ ] **Step 5: Implement bounded polling and visibility control**

Use recursive timeout, not interval:

```js
let timer = 0;
let stopped = false;
let failures = 0;
async function refresh() {
  if (stopped || document.visibilityState !== 'visible') return schedule(2000);
  try {
    const snapshot = await SavoraApi.get('api/orders.php?pageSize=50');
    const active = (snapshot.orders || []).find(order => !['completed', 'cancelled', 'refunded'].includes(order.status));
    renderOrder(active || null);
    if (active && ['assigned', 'picked_up', 'delivered'].includes(active.status)) {
      const route = await SavoraApi.get(`api/tracking.php?order=${encodeURIComponent(active.referenceCode || active.id)}`);
      renderRoute(active, route);
    }
    failures = 0;
  } catch (error) {
    failures += 1;
    renderFailure(error);
  }
  schedule(nextDelay(failures));
}
function schedule(delay) {
  clearTimeout(timer);
  timer = window.setTimeout(refresh, delay);
}
```

On `visibilitychange`, trigger an immediate refresh when visible. Stop polling on page unload.

- [ ] **Step 6: Render Leaflet route with fallback**

Create the map for `picked_up` and keep its final arrived state visible for `delivered` until the Customer confirms receipt. Add start/end/current markers and one polyline through `[start,current,end]`. Use the standard interactive tile URL with attribution only when online; if tile events fail, add `is-map-fallback` and retain the Leaflet vector/marker surface. Never request tiles programmatically outside the visible viewport.

- [ ] **Step 7: Implement Customer receipt confirmation**

For delivered orders, label the button by method:

```js
button.textContent = order.paymentMethod === 'cash' ? 'Received and paid' : 'I received my order';
```

Post:

```js
await SavoraApi.post('api/orders.php', {
  action: 'confirm_received',
  payload: { referenceCode: order.referenceCode || order.id, expectedVersion: Number(order.version) }
}, SavoraApi.intentKey(`customer-confirm-received-${order.id}`));
```

Clear the intent key only after success, then refresh immediately.

- [ ] **Step 8: Add responsive/fallback styles**

Add a fixed minimum map height, visible route status, mobile stacking, focus styles, and `.is-map-fallback` background grid. Do not change unrelated Customer colors/components.

- [ ] **Step 9: Run Customer tests and PHP syntax**

```powershell
node --test tests/customer_tracking.test.js tests/customer_markup.test.js tests/order_api_contract.test.js
& 'D:\Xampp\php\php.exe' -l customer_history.php
```

Expected: all tests PASS and PHP reports no syntax errors.

- [ ] **Step 10: Commit Task 7**

```powershell
git add js/customer_tracking.js customer_history.php css/customer_style.css tests/customer_tracking.test.js tests/customer_markup.test.js
git commit -m "feat: add Customer live delivery tracking"
```

---

### Task 8: Make Restaurant Live Orders Match the Simple Demo

**Files:**
- Modify: `restaurant_orders.php`
- Modify: `js/restaurant_orders.js`
- Modify: `lib/repositories/order_repository.php`
- Modify: `lib/services/order_transition_service.php`
- Modify: `lib/services/dispatch_service.php`
- Modify: `tests/restaurant_markup.test.js`
- Modify: `tests/restaurant_state.test.js`

**Interfaces:**
- Pending orders expose `accept`; confirmed/preparing orders expose `ready`; ready/assigned/picked-up/delivered orders are read-only on the Restaurant page.
- Entering `ready_for_pickup` creates a dispatch and immediately offers it to the nearest fresh eligible Driver inside the same transaction.
- Live data refreshes every two seconds with 15-second maximum failure backoff.

- [ ] **Step 1: Write failing Restaurant action/polling tests**

```js
assert.equal(actionsFor({ status: 'pending' }).primary, 'accept');
assert.equal(actionsFor({ status: 'confirmed' }).primary, 'ready');
assert.equal(actionsFor({ status: 'preparing' }).primary, 'ready');
assert.equal(actionsFor({ status: 'ready_for_pickup' }).primary, null);
assert.equal(nextRestaurantDelay(0), 2000);
assert.equal(nextRestaurantDelay(4), 15000);
assert.match(controller, /document\.visibilityState/);
assert.doesNotMatch(controller, /setInterval\s*\(/);
assert.match(orderTransitionService, /dispatch_offer_next_driver_in_transaction/);
```

- [ ] **Step 2: Run Restaurant tests and confirm RED**

```powershell
node --test tests/restaurant_markup.test.js tests/restaurant_state.test.js
```

Expected: action labels/refresh helpers are absent or current ready transition does not support confirmed.

- [ ] **Step 3: Export pure action and delay helpers**

```js
function actionsFor(order) {
  if (order?.status === 'pending') return { primary: 'accept', target: 'confirmed', label: 'Accept and start preparing', reject: true };
  if (['confirmed', 'preparing'].includes(order?.status)) return { primary: 'ready', target: 'ready_for_pickup', label: 'Food is ready', reject: true };
  return { primary: null, target: null, label: '', reject: false };
}
function nextRestaurantDelay(failures) {
  return Math.min(15000, 2000 * (2 ** Math.max(0, Number(failures) || 0)));
}
```

Render buttons from this contract. Keep reject mapped to `cancelled` only while `reject` is true.

- [ ] **Step 4: Create the first Driver offer when food becomes ready**

Change `order_repository_create_dispatch()` to return the dispatch id for both insert and duplicate-key paths:

```php
function order_repository_create_dispatch(mysqli $conn, int $orderId): int
{
    $statement = $conn->prepare("INSERT INTO delivery_dispatches(order_id,status,attempt_count) VALUES(?,'searching_driver',0) ON DUPLICATE KEY UPDATE status=IF(assigned_driver_user_id IS NULL,'searching_driver',status),version=version+1");
    $statement->bind_param('i', $orderId);
    $statement->execute();
    $statement->close();
    $row = order_repository_one($conn, 'SELECT id FROM delivery_dispatches WHERE order_id=? LIMIT 1 FOR UPDATE', 'i', [$orderId]);
    return (int) ($row['id'] ?? 0);
}
```

Require `dispatch_service.php` from `order_transition_service.php`. In the existing order transaction:

```php
$dispatch = null;
if ($nextStatus === 'ready_for_pickup') {
    $dispatchId = order_repository_create_dispatch($conn, (int) $order['id']);
    if ($dispatchId <= 0) throw new RuntimeException('Dispatch was not created.');
    $dispatch = dispatch_offer_next_driver_in_transaction($conn, $dispatchId, $actorId);
}
```

Include the safe offer/dispatch summary in the transition response. A missing eligible Driver is not an order failure; it leaves the dispatch in `searching_driver`, and subsequent refresh/admin retry can offer again.

- [ ] **Step 5: Add bounded live refresh**

After every successful action, refresh immediately. While the page is visible, recursively reload `api/orders.php?pageSize=50`; preserve the current selected order when it still exists. Back off on failure and stop on unload.

- [ ] **Step 6: Update labels and feedback**

Use exactly:

- `Accept and start preparing`
- `Food is ready`
- `Waiting for a Driver` after `ready_for_pickup`

Do not label `confirmed` as merely accepted; present it as active preparation to match the Customer card.

- [ ] **Step 7: Run Restaurant and order transition tests**

```powershell
node --test tests/restaurant_markup.test.js tests/restaurant_state.test.js tests/order_transition_contract.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' tests/order_transition_service_test.php
```

Expected: all PASS.

- [ ] **Step 8: Commit Task 8**

```powershell
git add restaurant_orders.php js/restaurant_orders.js lib/repositories/order_repository.php lib/services/order_transition_service.php lib/services/dispatch_service.php tests/restaurant_markup.test.js tests/restaurant_state.test.js
git commit -m "feat: simplify Restaurant demo order flow"
```

---

### Task 9: Verify the Integrated Four-role Demo and Write the Runbook

**Files:**
- Create: `tests/hybrid_demo_flow_test.php`
- Create: `tests/hybrid_demo_contract.test.js`
- Create: `docs/HYBRID_PAYMENT_GPS_DEMO.md`
- Modify only if a verified defect is found: files from Tasks 1-8

**Interfaces:**
- Produces one database integration test for the complete state/payment sequence.
- Produces a runbook for simultaneous and sequential testing.

- [ ] **Step 1: Write the integrated database scenario**

Within one cleanup-safe test fixture, execute and assert this exact sequence:

```php
// SeaPay demo branch
// Driver starts a demo shift from saved profile coordinates before dispatch.
// pending/payment pending -> simulate -> payment paid -> Restaurant confirmed
// -> ready_for_pickup -> offer -> Driver assigned -> demo route picked_up
// -> route progress at injected 30 seconds is 0.5 -> early delivery rejected
// -> route arrived at injected 61 seconds -> Driver delivered
// -> Customer completed; payment remains paid.

// COD branch
// pending/payment pending -> Restaurant confirmed -> ready_for_pickup
// -> Driver assigned -> demo route picked_up -> route arrived
// -> Driver delivered/payment still pending
// -> Customer completed/payment paid.
```

Assert every order history actor role, current version increment, notification existence, and idempotent replay response.

- [ ] **Step 2: Write the static integrated contract test**

```js
assert.match(fs.readFileSync('customer_checkout.php', 'utf8'), /pay_now|pay_on_receipt/);
assert.match(fs.readFileSync('api/payment_demo.php', 'utf8'), /savora_demo_mode/);
assert.match(fs.readFileSync('api/tracking.php', 'utf8'), /demo_route_snapshot/);
assert.match(fs.readFileSync('js/customer_tracking.js', 'utf8'), /confirm_received/);
assert.match(fs.readFileSync('js/driver_delivery.js', 'utf8'), /demo_start_delivery/);
assert.match(fs.readFileSync('js/driver_dashboard.js', 'utf8'), /demo_start_shift/);
assert.match(fs.readFileSync('js/restaurant_orders.js', 'utf8'), /Food is ready/);
```

- [ ] **Step 3: Run the complete relevant automated suite**

```powershell
node --test tests/checkout_contract.test.js tests/customer_markup.test.js tests/customer_tracking.test.js tests/dispatch_api_contract.test.js tests/driver_demo_route.test.js tests/driver_markup.test.js tests/hybrid_demo_contract.test.js tests/migration_registry.test.js tests/order_api_contract.test.js tests/order_transition_contract.test.js tests/restaurant_markup.test.js tests/restaurant_state.test.js tests/sepay_webhook_contract.test.js tests/tracking_api_contract.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DEMO_MODE='1'
$phpTests=@('tests/migration_integrity_test.php','tests/environment_test.php','tests/payment_confirmation_service_test.php','tests/payment_gate_service_test.php','tests/customer_receipt_service_test.php','tests/demo_route_service_test.php','tests/delivery_service_test.php','tests/dispatch_service_test.php','tests/order_query_service_test.php','tests/order_transition_service_test.php','tests/hybrid_demo_flow_test.php')
foreach($testFile in $phpTests){ & 'D:\Xampp\php\php.exe' $testFile; if($LASTEXITCODE -ne 0){ exit $LASTEXITCODE } }
```

Expected: zero Node failures and every PHP file prints PASS/ok with exit code 0.

- [ ] **Step 4: Lint every changed PHP file**

```powershell
$changedPhp = git diff --name-only HEAD~9..HEAD -- '*.php'
foreach($phpFile in $changedPhp){ & 'D:\Xampp\php\php.exe' -l $phpFile; if($LASTEXITCODE -ne 0){ exit $LASTEXITCODE } }
```

Expected: `No syntax errors detected` for every file.

- [ ] **Step 5: Apply development migrations and verify runtime flags without printing secrets**

```powershell
Remove-Item Env:SAVORA_ENV -ErrorAction SilentlyContinue
Remove-Item Env:SAVORA_DB_NAME -ErrorAction SilentlyContinue
& 'D:\Xampp\php\php.exe' scripts/migrate.php
& 'D:\Xampp\mysql\bin\mysql.exe' --host=localhost --port=3307 --user=root --database=savora_db --batch --skip-column-names --execute="SELECT version FROM schema_migrations WHERE version IN ('020_sepay_webhook_hardening','021_hybrid_payment_gps_demo') ORDER BY version;"
```

Expected: both migration names print. Do not output API keys or bank data.

- [ ] **Step 6: Write the four-role runbook**

Document exact preparation and button sequence:

1. Set `'SAVORA_DEMO_MODE' => true` in ignored `config/local.php`; no machine-wide environment change is required.
2. Open Customer, Restaurant, Driver, and Admin in four isolated browser profiles or different browsers; do not assume multiple private windows from one profile have separate cookies. Otherwise use logout/login sequentially.
3. Driver selects **Start demo shift** so the saved Driver location is fresh and online.
4. Customer places one SeaPay demo order and selects **Simulate successful payment**.
5. Restaurant selects **Accept and start preparing**, then **Food is ready**.
6. Driver accepts the automatically created offer, then selects **Picked up - start delivery**.
7. Customer watches the 60-second route; Driver selects **Delivered to Customer** at the end.
8. Customer selects **I received my order** and verifies completed history.
9. Repeat with COD; verify payment remains pending until **Received and paid**.
10. Admin verifies the same order, payment, dispatch, and audit records.

Also document the sequential fallback and note that route time continues while switching roles.

- [ ] **Step 7: Perform manual browser verification**

Use the runbook against `http://localhost/Savora/`. Record each role's observed status in the runbook's verification table. Confirm no console error, no forced page refresh, map fallback remains usable when network is disabled, and repeated button clicks do not duplicate state.

- [ ] **Step 8: Run `git diff --check` and inspect scope**

```powershell
git diff --check
git status --short
git diff --stat
```

Expected: no whitespace errors; only planned feature/test/doc files are modified. Preserve unrelated untracked files.

- [ ] **Step 9: Commit Task 9**

```powershell
git add tests/hybrid_demo_flow_test.php tests/hybrid_demo_contract.test.js docs/HYBRID_PAYMENT_GPS_DEMO.md
git commit -m "test: verify hybrid payment GPS demo"
```

- [ ] **Step 10: Final verification after the last commit**

Repeat Step 3 and Step 4 from the committed tree. Report the exact Node pass/fail count, PHP test-file count, applied migration names, and any manual limitation. Do not claim completion from earlier output.
