# Public Customer Home Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the public Savora entry point open the Customer Home immediately, preserve the existing customer navigation, allow a guest local cart, and require authentication only for Checkout and account-owned features.

**Architecture:** Keep `customer_dashboard.php` as the existing Customer Home renderer and make only the dashboard, product detail, and cart pages guest-readable. Move the current login screen to `login.php`, make `index.php` route to Customer Home, and centralize safe customer return URLs in a small PHP access helper. The current authenticated APIs, session validation, CSRF, idempotency, and server-authoritative checkout remain unchanged.

**Tech Stack:** PHP 8.x, MySQL-backed session security, existing Savora PHP templates, vanilla JavaScript, browser `localStorage`, Node built-in test runner, PHP CLI/CGI, XAMPP Apache on port 8085.

## Global Constraints

- The existing Customer navigation visual hierarchy and labels remain unchanged: `Discover`, `Orders`, `Favorites`, `Wallet`, `Profile`, cart, and authenticated avatar/logout controls.
- All newly introduced visible UI copy is English 100%.
- Guests may read the catalog, open product detail, and add items to the existing local cart.
- Guests must authenticate before Checkout, Profile, Orders, Favorites, Wallet, or any account-owned write.
- Guest cart contents stay in `localStorage` and are never trusted for prices, fees, promotions, address ownership, or order authorization.
- `api/catalog.php` customer `GET` remains public read-only; profile, orders, checkout, location writes, and other mutating APIs remain authenticated.
- No database schema or migration changes are allowed for this feature.
- Existing unrelated worktree changes must remain untouched: `lib/database.php`, audit prompt files, and the pre-existing customer-address spec/plan files.

---

### Task 1: Add safe login routing and return destinations

**Files:**
- Create: `lib/customer_access.php`
- Create: `login.php`
- Modify: `index.php`
- Modify: `auth.php`
- Modify: `components/auth_header.php`
- Modify: `register.php`
- Modify: `forgot_password.php`
- Modify: `reset_password.php`
- Modify: `registration_result.php`
- Test: `tests/auth_onboarding_markup.test.js`
- Test: `tests/customer_guest_access.test.js`

**Interfaces:**
- `customer_login_url(string $returnTo = ''): string` returns `login.php` with a validated internal `return_to` query when valid.
- `customer_safe_return_to(mixed $candidate): string` returns an allowed Savora route or an empty string.
- `customer_redirect_to_login(string $returnTo, string $notice = 'Please sign in to continue.'): never` stores the notice in the session and sends a safe redirect to `login.php`.

- [ ] **Step 1: Write the failing route contract test**

Add `tests/customer_guest_access.test.js` with these concrete assertions:

```js
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('the public entry point routes to Customer Home and login is explicit', () => {
  assert.match(read('index.php'), /customer_dashboard\.php/);
  assert.match(read('login.php'), /name="username"/);
  assert.match(read('login.php'), /name="password"/);
  assert.match(read('auth.php'), /login\.php/);
});

test('customer access helper allows only internal customer routes', () => {
  const helper = read('lib/customer_access.php');
  assert.match(helper, /function customer_safe_return_to/);
  assert.match(helper, /customer_checkout\.php/);
  assert.match(helper, /customer_profile\.php/);
  assert.match(helper, /parse_url/);
  assert.match(helper, /https?:|\/\//);
});

test('login preserves a validated return route', () => {
  assert.match(read('login.php'), /return_to/);
  assert.match(read('auth.php'), /customer_safe_return_to/);
  assert.match(read('auth.php'), /customer_login_url/);
});
```

- [ ] **Step 2: Run the new test and verify it fails**

Run: `node --test tests/customer_guest_access.test.js`

Expected: FAIL because `login.php`, `lib/customer_access.php`, and the public entry route do not yet exist.

- [ ] **Step 3: Implement the safe route helper**

Create `lib/customer_access.php` with this behavior:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/session_security.php';

function customer_allowed_return_paths(): array
{
    return [
        'customer_dashboard.php', 'product_detail.php', 'customer_cart.php',
        'customer_checkout.php', 'customer_history.php', 'customer_favorites.php',
        'customer_profile.php', 'customer_wallet.php'
    ];
}

function customer_safe_return_to(mixed $candidate): string
{
    $value = is_string($candidate) ? trim($candidate) : '';
    if ($value === '' || preg_match('/[\r\n]/', $value) === 1) return '';
    $parts = parse_url($value);
    if ($parts === false || isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass'])) return '';
    $path = ltrim((string) ($parts['path'] ?? ''), '/');
    if (!in_array($path, customer_allowed_return_paths(), true)) return '';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    return $path . $query;
}

function customer_login_url(string $returnTo = ''): string
{
    $safe = customer_safe_return_to($returnTo);
    return 'login.php' . ($safe === '' ? '' : '?return_to=' . rawurlencode($safe));
}

function customer_redirect_to_login(string $returnTo, string $notice = 'Please sign in to continue.'): never
{
    savora_start_session();
    $_SESSION['auth_notice'] = $notice;
    header('Location: ' . customer_login_url($returnTo));
    exit();
}
```

Reject absolute, protocol-relative, CRLF-containing, and non-allowlisted destinations. Keep query text encoded in the redirect URL and never use a raw user-supplied URL in a `Location` header.

- [ ] **Step 4: Move the existing login renderer to `login.php`**

Copy the current `index.php` login renderer into `login.php`, require `lib/customer_access.php`, read `$_GET['return_to']` through `customer_safe_return_to`, include it as a hidden input, and keep all current login form controls, error handling, demo accounts, and English copy. Set the auth header link to `register.php` and its label to `Create account`.

- [ ] **Step 5: Make `index.php` the public Customer Home entry**

Replace the login renderer in `index.php` with a strict redirect to `customer_dashboard.php`. The route must not require a session and must not touch database state.

- [ ] **Step 6: Update authentication redirects and links**

In `auth.php`, read the posted `return_to` with `customer_safe_return_to`. On validation/rate-limit failures, redirect to `login.php` and preserve the validated return route. On successful login, use the validated return route only when the logged-in role is `customer`; otherwise use the existing role destination. Update shared auth defaults and all auth-page links from `index.php` to `login.php`, while keeping `index.php` as the home link.

- [ ] **Step 7: Run the route tests**

Run: `node --test tests/customer_guest_access.test.js tests/auth_onboarding_markup.test.js`

Expected: PASS for the new public entry/login contracts; update the existing auth markup expectations from `index.php` to `login.php` without weakening field, English-only, or role-registration checks.

- [ ] **Step 8: Commit the isolated routing task**

Run:

```powershell
git add -- index.php login.php auth.php components/auth_header.php register.php forgot_password.php reset_password.php registration_result.php lib/customer_access.php tests/auth_onboarding_markup.test.js tests/customer_guest_access.test.js
git commit -m "feat: route public home and preserve login return paths"
```

---

### Task 2: Preserve the current customer navigation while making public pages guest-readable

**Files:**
- Modify: `components/customer_header.php`
- Modify: `components/customer_footer.php`
- Modify: `css/customer_style.css`
- Modify: `tests/customer_markup.test.js`
- Modify: `tests/customer_guest_access.test.js`

**Interfaces:**
- `window.SavoraCustomerAuthenticated` is a boolean emitted by the header and consumed by public customer JavaScript.
- The current `.customer-header`, `.customer-nav`, `.customer-actions`, `.cart-btn`, `.user-dropdown`, and mobile menu hooks remain present.

- [ ] **Step 1: Add public-page guard tests**

Extend `tests/customer_guest_access.test.js`:

```js
test('the existing customer chrome supports guest and protected routes', () => {
  const header = read('components/customer_header.php');
  assert.match(header, /customer_dashboard\.php/);
  assert.match(header, /customer_cart\.php/);
  assert.match(header, /customer_redirect_to_login/);
  assert.match(header, /SavoraCustomerAuthenticated/);
  assert.match(header, /Sign in/);
  assert.match(header, /Log out/);
});

test('the existing navigation labels remain unchanged', () => {
  const header = read('components/customer_header.php');
  for (const label of ['Discover', 'Orders', 'Favorites', 'Wallet', 'Profile']) {
    assert.match(header, new RegExp(label));
  }
  assert.match(header, /Open cart/);
});
```

- [ ] **Step 2: Run the new guest chrome tests and verify the new assertions fail**

Run: `node --test tests/customer_guest_access.test.js`

Expected: FAIL on guest guard/sign-in state because the current header always requires an authenticated customer session.

- [ ] **Step 3: Make only the public customer pages guest-readable**

In `components/customer_header.php`, start the session and determine `$current_page` before enforcing the role. Permit guest rendering only for `customer_dashboard.php`, `product_detail.php`, and `customer_cart.php`. For every other customer page, call `customer_redirect_to_login($current_page, 'Please sign in to continue.')`. For authenticated requests, keep the current `savora_validate_session(..., 'customer')` and CSRF behavior exactly intact.

Set `$customer_is_authenticated` from the validated session result and emit:

```php
<script>window.SavoraCustomerAuthenticated = <?= $customer_is_authenticated ? 'true' : 'false' ?>;</script>
```

Use `customer_login_url($route)` for guest links to Orders, Favorites, Wallet, and Profile. Keep Discover linked to `customer_dashboard.php` and keep the cart button available. For guests, render a `Sign in` link in the existing customer action area; for authenticated users, render the current avatar dropdown and `Log out` links unchanged.

- [ ] **Step 4: Keep footer account links safe for guests**

Use the same `customer_login_url()` helper for footer links to Profile, Wallet, and Orders. Keep the existing cart drawer, product customization modal, dialog hooks, scripts, and accessible landmarks unchanged.

- [ ] **Step 5: Add only the minimal guest action styling**

If the current stylesheet does not provide a suitable guest action link, add a `.customer-sign-in` rule that uses the existing navigation color, spacing, focus ring, and typography. Do not change current authenticated navigation dimensions, labels, or breakpoints.

- [ ] **Step 6: Run chrome and guest access tests**

Run: `node --test tests/customer_markup.test.js tests/customer_guest_access.test.js`

Expected: PASS, including the existing navigation labels, mobile menu hooks, dialog hooks, no-inline-handler checks, and guest route contracts.

- [ ] **Step 7: Commit the navigation task**

Run:

```powershell
git add -- components/customer_header.php components/customer_footer.php css/customer_style.css tests/customer_markup.test.js tests/customer_guest_access.test.js
git commit -m "feat: preserve customer navigation for guest browsing"
```

---

### Task 3: Make Home, product detail, and location behavior safe for guests

**Files:**
- Modify: `customer_dashboard.php`
- Modify: `product_detail.php`
- Modify: `js/customer_location.js`
- Modify: `tests/customer_guest_access.test.js`
- Modify: `tests/customer_state.test.js` only if a guest-location storage contract is added

**Interfaces:**
- Public pages use `window.SavoraCustomerAuthenticated` to decide whether profile/order/favorite/location APIs may be called.
- `SavoraState` remains the only cart state API; no server cart is introduced.

- [ ] **Step 1: Add failing guest API-boundary assertions**

Extend `tests/customer_guest_access.test.js`:

```js
test('public customer renderers make account API calls conditional', () => {
  for (const file of ['customer_dashboard.php', 'product_detail.php', 'js/customer_location.js']) {
    const source = read(file);
    assert.match(source, /SavoraCustomerAuthenticated/);
  }
  assert.match(read('customer_dashboard.php'), /api\/catalog\.php|catalog\.hydrate/);
  assert.match(read('product_detail.php'), /Add to cart/);
});
```

- [ ] **Step 2: Run the boundary test and verify it fails**

Run: `node --test tests/customer_guest_access.test.js`

Expected: FAIL because the dashboard and product detail currently call `api/profile.php` unconditionally and the location client always calls the authenticated location API.

- [ ] **Step 3: Make dashboard profile/orders optional**

In `customer_dashboard.php`:

1. Initialize `profileSnapshot` as `{ favorites: [] }`.
2. Fetch `api/profile.php` and `api/orders.php` only when `window.SavoraCustomerAuthenticated === true`.
3. For guests, keep `serverOrders` empty and render the existing active-order area as a sign-in prompt linking through `customer_login_url` or the equivalent safe route.
4. Make favorite buttons redirect to `login.php?return_to=customer_dashboard.php` for guests instead of posting to `api/profile.php`.
5. Keep catalog hydration, search, filtering, product cards, restaurant cards, DOM-safe rendering, and local cart behavior unchanged.

- [ ] **Step 4: Make product-detail profile/favorite behavior optional**

In `product_detail.php`, hydrate the catalog for all visitors. Fetch the profile only for authenticated customers. For guests, render the favorite control as a sign-in action and do not call `api/profile.php`; the Add to cart form must continue using `SavoraState.addCartLine()` and `SavoraState.persist()` locally.

- [ ] **Step 5: Store guest location locally and preserve server authority**

In `js/customer_location.js`, add a namespaced guest key such as `savora_guest_location_v1`. When `SavoraCustomerAuthenticated` is false:

1. Load/render the local guest address without calling `api/location.php`.
2. Save a manually entered address locally and notify existing page listeners.
3. Save browser coordinates locally when permission succeeds; do not send them to the server.
4. Keep authenticated load/save behavior unchanged.
5. Show `Address saved locally. Sign in to use it for checkout.` for guest manual saves.

At checkout, only the authenticated server address may be used for quote and order placement.

- [ ] **Step 6: Run public renderer and JavaScript tests**

Run: `node --test tests/customer_guest_access.test.js tests/customer_state.test.js tests/customer_markup.test.js`

Expected: PASS with no new `innerHTML`, inline handler, polling, or remote asset regressions.

- [ ] **Step 7: Commit the public renderer task**

Run:

```powershell
git add -- customer_dashboard.php product_detail.php js/customer_location.js tests/customer_guest_access.test.js tests/customer_state.test.js
git commit -m "feat: support guest customer discovery and local location"
```

---

### Task 4: Keep the cart public and gate Checkout at the authentication boundary

**Files:**
- Modify: `customer_cart.php`
- Modify: `customer_checkout.php` only for explicit authenticated copy/return behavior if required
- Modify: `components/customer_header.php` if cart drawer checkout link needs the same gate
- Modify: `tests/customer_guest_access.test.js`
- Modify: `tests/checkout_contract.test.js` only if the return route contract is added there

**Interfaces:**
- Guest cart remains `SavoraState.load()`/`SavoraState.persist()` backed.
- Checkout remains protected by `customer_header.php` and `api/checkout.php`.

- [ ] **Step 1: Add the cart gate contract test**

Extend `tests/customer_guest_access.test.js`:

```js
test('guest cart remains local and checkout has an authentication gate', () => {
  const cart = read('customer_cart.php');
  assert.match(cart, /SavoraState\.load/);
  assert.match(cart, /customer_checkout\.php/);
  assert.match(cart, /customer_login_url|login\.php/);
  assert.match(read('customer_checkout.php'), /api\/checkout\.php/);
});
```

- [ ] **Step 2: Run the cart contract and verify it fails**

Run: `node --test tests/customer_guest_access.test.js`

Expected: FAIL on the guest checkout link because it currently points directly to `customer_checkout.php` for every visitor.

- [ ] **Step 3: Generate the correct checkout destination**

In `customer_cart.php`, set the initial checkout link to `customer_checkout.php` for authenticated customers and `customer_login_url('customer_checkout.php')` for guests. Keep promo-code query handling encoded and preserve the local cart. The cart page must continue to render without a customer session.

Apply the same guest destination to the cart drawer's `View full cart`/Checkout entry if that link is part of the current drawer flow. Do not place price or order authorization logic in JavaScript.

- [ ] **Step 4: Verify protected checkout behavior**

Confirm `customer_checkout.php` still includes the authenticated customer header and `api/checkout.php` still requires `savora_request_actor($conn, ['customer'])`. Do not weaken either guard. A guest arriving directly at `customer_checkout.php` must receive the login redirect with the `customer_checkout.php` return route.

- [ ] **Step 5: Run cart/checkout tests**

Run: `node --test tests/customer_guest_access.test.js tests/customer_markup.test.js tests/checkout_contract.test.js tests/checkout_cutover.test.js`

Expected: PASS, including server-authoritative quote and placement contracts.

- [ ] **Step 6: Commit the cart gate task**

Run:

```powershell
git add -- customer_cart.php customer_checkout.php components/customer_header.php tests/customer_guest_access.test.js tests/checkout_contract.test.js
git commit -m "feat: require sign in only when guest checks out"
```

---

### Task 5: Execute integration tests and browser QA

**Files:**
- Create: `tests/customer_guest_browser_qa.mjs`
- Modify: `tests/auth_onboarding_http_test.php` if its route list or expected redirect changes
- Modify: `tests/customer_guest_access.test.js` for final contracts

**Interfaces:**
- Browser QA runs against `http://localhost:8085/Savora/`.
- The QA script uses a clean browser context so no previous session or local cart affects the guest assertions.

- [ ] **Step 1: Add a browser QA script covering the approved flow**

The script must assert, in order:

1. Opening `/Savora/` lands on Customer Home and does not show the sign-in form.
2. The current navigation labels and cart control are visible.
3. A catalog item can be opened and added to the local cart without a login request.
4. The cart shows the item and Checkout navigates to `login.php` with a return route.
5. Direct navigation to `customer_profile.php` as a guest also navigates to `login.php` and shows the English notice.
6. Login with the existing demo customer credentials returns to the requested route and shows the authenticated avatar/logout state.
7. Direct navigation to the public Home after login still works.
8. Non-customer demo credentials still reach their existing role dashboards.

Use the repository's existing Playwright/browser QA conventions from `tests/task29_browser_qa.mjs`, including clear diagnostics when the Chrome CDP endpoint is unavailable.

- [ ] **Step 2: Run static and PHP syntax checks**

Run:

```powershell
node --test tests/customer_guest_access.test.js tests/customer_markup.test.js tests/auth_onboarding_markup.test.js tests/customer_state.test.js tests/checkout_contract.test.js
php -l index.php
php -l login.php
php -l auth.php
php -l lib/customer_access.php
php -l components/customer_header.php
php -l customer_dashboard.php
php -l product_detail.php
php -l customer_cart.php
```

Expected: all Node tests pass and every PHP file reports `No syntax errors detected`.

- [ ] **Step 3: Run HTTP/API integration checks**

With Apache on port 8085 and MySQL on the configured local port, run:

```powershell
php tests/auth_onboarding_http_test.php
php tests/catalog_api_endpoint_test.php
php tests/request_security_contract_test.php
```

Expected: public onboarding and catalog GET checks pass; authenticated API security checks remain enforced. Do not create a new database or modify production/demo rows for this feature.

- [ ] **Step 4: Run browser QA**

Run: `node tests/customer_guest_browser_qa.mjs`

Expected: PASS when Chrome is available through the configured CDP endpoint. If the endpoint is unavailable, record the exact environment blocker and complete all available HTTP/static checks without claiming browser QA passed.

- [ ] **Step 5: Inspect final diff and verify database/Git boundaries**

Run:

```powershell
git diff --check
git status --short
git diff --stat
```

Confirm no migration or database schema file was changed, no unrelated user file is staged, and the pre-existing `lib/database.php` local port change remains untouched.

- [ ] **Step 6: Commit QA artifacts only after verification**

Run:

```powershell
git add -- tests/customer_guest_browser_qa.mjs tests/auth_onboarding_http_test.php tests/customer_guest_access.test.js
git commit -m "test: cover public customer home guest flow"
```

Report the exact passing test commands and any browser/database environment limitation.

---

## Self-review checklist

- [ ] Public root route opens Customer Home before login.
- [ ] Existing navigation labels, structure, and authenticated controls remain intact.
- [ ] Guest catalog and local cart work without authenticated APIs.
- [ ] Profile, Orders, Favorites, Wallet, and Checkout require login.
- [ ] Login return destinations are internal and allowlisted.
- [ ] Checkout still uses a server-owned address, quote, and placement path.
- [ ] Restaurant, driver, and admin destinations are unchanged.
- [ ] No database migration or schema change is introduced.
- [ ] English-only UI copy and accessibility hooks remain covered.
- [ ] Static, PHP, HTTP, and browser QA results are reported with evidence.
