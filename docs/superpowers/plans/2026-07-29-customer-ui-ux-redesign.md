# Savora Customer UI/UX Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the approved Savora visual system and safe, responsive local-demo behavior across all eight Customer pages.

**Architecture:** Keep PHP as the server-rendered page shell and use three shared browser scripts: catalog data, local demo state, and UI helpers. Page-specific scripts render their own sections with DOM APIs, while the shared header, cart drawer, dialog behavior, state persistence and responsive CSS remain centralized.

**Tech Stack:** PHP 8.2, vanilla JavaScript (browser APIs plus Node built-in test runner), CSS custom properties and media queries, Font Awesome, existing Leaflet CDN.

## Global Constraints

- Preserve the existing customer session guard in `components/customer_header.php`.
- Keep Customer data local-only for this release; never represent a local action as server-confirmed payment or an account/password update.
- Use the mockups in `docs/design/mockups/` as visual references, not as fixed desktop-only layouts.
- Render local state with DOM node creation and `textContent`; do not interpolate `localStorage` values into `innerHTML`, inline JavaScript, style attributes or URLs.
- Keep the pages usable at 320px, 768px and 1440px, with touch targets at least 44px where feasible.
- Use semantic controls, visible focus styles, labelled dialogs and live regions; meet WCAG AA contrast for normal text.
- The workspace is not a Git repository, so do not run commit commands. Record verification output in task reports instead.

---

## File structure and interfaces

| File | Responsibility |
| --- | --- |
| `js/customer_catalog.js` | Immutable product, restaurant, category and recommendation data used by the Customer UI. |
| `js/customer_state.js` | Versioned local-demo state, validation and pure cart/wallet/profile/order/favorite mutations. Exports CommonJS for Node tests and `window.SavoraState` in a browser. |
| `js/customer_ui.js` | Safe DOM constructors, shared header/cart updates, toast/dialog/focus helpers, and responsive menu behavior. Exposes `window.SavoraUI`. |
| `css/customer_style.css` | Savora design tokens, desktop/mobile layout, components, focus and dialog styles. |
| `components/customer_header.php` | Session guard, page title, semantic global navigation and mobile menu trigger. |
| `components/customer_footer.php` | Cart drawer, toast/live region, reusable dialogs and shared script loading. |
| `customer_*.php`, `product_detail.php` | Semantic page shells with small page-specific DOM renderers and event listeners. |
| `tests/customer_state.test.js` | Pure state-unit tests under `node --test`. |
| `tests/customer_markup.test.js` | Browserless source assertions for safe rendering hooks and accessibility markup. |

Shared JavaScript contracts:

```js
// js/customer_state.js
SavoraState.load();
SavoraState.persist(state);
SavoraState.addCartLine(state, product, quantity, options);
SavoraState.removeCartLine(state, lineId);
SavoraState.setProfile(state, profilePatch);
SavoraState.topUpWallet(state, amount);
SavoraState.placeDemoOrder(state, { address, paymentMethod, promoCode });
SavoraState.toggleFavorite(state, kind, id);
SavoraState.getActiveOrder(state);

// js/customer_ui.js
SavoraUI.el(tagName, attributes, children);
SavoraUI.renderCartDrawer();
SavoraUI.openDialog(dialogId, trigger);
SavoraUI.closeDialog(dialogId);
SavoraUI.showToast(message, tone);
SavoraUI.refreshChrome();
```

## Task 1: Establish safe Customer state and its tests

**Files:**

- Create: `js/customer_catalog.js`
- Create: `js/customer_state.js`
- Create: `tests/customer_state.test.js`
- Modify: `components/customer_footer.php`

**Interfaces:**

- Consumes: browser `localStorage`, catalog product IDs and the existing authenticated customer name emitted by PHP.
- Produces: `window.SavoraCatalog` and `window.SavoraState` for every page renderer.

- [ ] **Step 1: Write failing tests for state normalization and mutations.**

```js
// tests/customer_state.test.js
const test = require('node:test');
const assert = require('node:assert/strict');
const State = require('../js/customer_state.js');

test('normalizes malformed persisted state without copying unsafe fields', () => {
  const state = State.normalize({
    cart: [{ id: '1', quantity: '2', note: '<img src=x onerror=alert(1)>' }],
    wallet: { balance: '20.5' }
  });
  assert.equal(state.cart[0].quantity, 2);
  assert.equal(state.cart[0].note, '<img src=x onerror=alert(1)>');
  assert.equal(state.wallet.balance, 20.5);
  assert.equal(Object.hasOwn(state.cart[0], 'onerror'), false);
});

test('adds compatible cart lines and calculates a demo order once', () => {
  let state = State.defaultState();
  state = State.addCartLine(state, { id: '1', name: 'Pasta', price: 12.5 }, 2, []);
  const result = State.placeDemoOrder(state, { address: '12 Food Street', paymentMethod: 'cash' });
  assert.equal(result.state.cart.length, 0);
  assert.equal(result.order.status, 'preparing');
  assert.equal(result.order.total, 27);
});

test('rejects an empty delivery address and insufficient wallet payment', () => {
  const state = State.addCartLine(State.defaultState(), { id: '1', name: 'Pasta', price: 12.5 }, 1, []);
  assert.throws(() => State.placeDemoOrder(state, { address: '', paymentMethod: 'cash' }), /address/i);
  assert.throws(() => State.placeDemoOrder(state, { address: '1 Main', paymentMethod: 'wallet' }), /balance/i);
});
```

- [ ] **Step 2: Run the tests to confirm the module is absent.**

Run: `node --test tests/customer_state.test.js`

Expected: FAIL with `Cannot find module '../js/customer_state.js'`.

- [ ] **Step 3: Implement catalog and state module.**

Use a UMD-style shell so the same pure functions are testable in Node and available in a browser:

```js
(function attachState(root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraState = api;
}(typeof window === 'undefined' ? null : window, function createState() {
  const KEY = 'savora_customer_state_v2';
  const DELIVERY_FEE = 2;
  const defaultState = () => ({ version: 2, cart: [], favorites: { restaurants: [], products: [] }, profile: {}, wallet: { balance: 0, transactions: [] }, orders: [] });
  const text = value => typeof value === 'string' ? value.slice(0, 500) : '';
  const number = value => Number.isFinite(Number(value)) ? Number(value) : 0;
  const copy = value => JSON.parse(JSON.stringify(value));
  const lineTotal = line => line.unitPrice * line.quantity;
  function normalize(raw) {
    const state = defaultState();
    const source = raw && typeof raw === 'object' ? raw : {};
    state.profile = Object.fromEntries(['fullName', 'email', 'address', 'phone'].map(key => [key, text(source.profile?.[key])]));
    state.wallet.balance = Math.max(0, number(source.wallet?.balance));
    state.wallet.transactions = Array.isArray(source.wallet?.transactions) ? source.wallet.transactions.map(item => ({ id: text(item.id), kind: item.kind === 'credit' ? 'credit' : 'debit', amount: Math.max(0, number(item.amount)), label: text(item.label), createdAt: text(item.createdAt) })).filter(item => item.id) : [];
    state.favorites.products = [...new Set(Array.isArray(source.favorites?.products) ? source.favorites.products.map(String) : [])];
    state.favorites.restaurants = [...new Set(Array.isArray(source.favorites?.restaurants) ? source.favorites.restaurants.map(String) : [])];
    state.cart = Array.isArray(source.cart) ? source.cart.map(line => ({ lineId: text(line.lineId), id: text(line.id), name: text(line.name), image: text(line.image), unitPrice: Math.max(0, number(line.unitPrice)), quantity: Math.max(1, Math.floor(number(line.quantity))), options: Array.isArray(line.options) ? line.options.map(option => ({ id: text(option.id), label: text(option.label), price: Math.max(0, number(option.price)) })) : [], note: text(line.note) })).filter(line => line.lineId && line.id) : [];
    state.orders = Array.isArray(source.orders) ? source.orders.map(order => ({ ...order, id: text(order.id), status: ['confirmed', 'preparing', 'on_the_way', 'completed', 'cancelled'].includes(order.status) ? order.status : 'completed' })).filter(order => order.id) : [];
    return state;
  }
  function load() { return typeof localStorage === 'undefined' ? defaultState() : normalize(JSON.parse(localStorage.getItem(KEY) || 'null')); }
  function persist(state) { const next = normalize(state); if (typeof localStorage !== 'undefined') localStorage.setItem(KEY, JSON.stringify(next)); return next; }
  function addCartLine(state, product, quantity, options = [], note = '') {
    const next = normalize(state); const qty = Math.max(1, Math.floor(number(quantity))); const normalizedOptions = options.map(option => ({ id: text(option.id), label: text(option.label), price: Math.max(0, number(option.price)) })); const unitPrice = Math.max(0, number(product.price)) + normalizedOptions.reduce((sum, option) => sum + option.price, 0); const key = JSON.stringify([String(product.id), normalizedOptions, text(note)]); const existing = next.cart.find(line => JSON.stringify([line.id, line.options, line.note]) === key); if (existing) existing.quantity += qty; else next.cart.push({ lineId: `${String(product.id)}-${Date.now()}-${Math.random().toString(16).slice(2)}`, id: String(product.id), name: text(product.name), image: text(product.image), unitPrice, quantity: qty, options: normalizedOptions, note: text(note) }); return next;
  }
  function removeCartLine(state, lineId) { const next = normalize(state); next.cart = next.cart.filter(line => line.lineId !== lineId); return next; }
  function updateCartQuantity(state, lineId, delta) { const next = normalize(state); const line = next.cart.find(item => item.lineId === lineId); if (!line) return next; line.quantity += Math.trunc(number(delta)); return line.quantity > 0 ? next : removeCartLine(next, lineId); }
  function setProfile(state, patch) { const next = normalize(state); for (const key of ['fullName', 'email', 'address', 'phone']) if (Object.hasOwn(patch, key)) next.profile[key] = text(patch[key]); return next; }
  function topUpWallet(state, amount) { const next = normalize(state); const credit = Math.max(0, number(amount)); if (!credit) throw new Error('Top-up amount must be positive'); next.wallet.balance += credit; next.wallet.transactions.unshift({ id: `topup-${Date.now()}`, kind: 'credit', amount: credit, label: 'Local demo top-up', createdAt: new Date().toISOString() }); return next; }
  function toggleFavorite(state, kind, id) { const next = normalize(state); if (!['products', 'restaurants'].includes(kind)) throw new Error('Unsupported favorite kind'); const list = next.favorites[kind]; const value = String(id); next.favorites[kind] = list.includes(value) ? list.filter(item => item !== value) : [...list, value]; return next; }
  function placeDemoOrder(state, input) { const next = normalize(state); const address = text(input.address).trim(); if (!address) throw new Error('Delivery address is required'); if (!next.cart.length) throw new Error('Cart is empty'); const subtotal = next.cart.reduce((sum, line) => sum + lineTotal(line), 0); const total = subtotal + DELIVERY_FEE; if (input.paymentMethod === 'wallet' && next.wallet.balance < total) throw new Error('Insufficient wallet balance'); const order = { id: `SVR-${Date.now()}`, status: 'preparing', address, paymentMethod: input.paymentMethod === 'wallet' ? 'wallet' : 'cash', promoCode: text(input.promoCode), items: copy(next.cart), subtotal, deliveryFee: DELIVERY_FEE, total, createdAt: new Date().toISOString() }; if (order.paymentMethod === 'wallet') { next.wallet.balance -= total; next.wallet.transactions.unshift({ id: `order-${order.id}`, kind: 'debit', amount: total, label: `Local demo order ${order.id}`, createdAt: order.createdAt }); } next.orders.unshift(order); next.cart = []; return { state: next, order };
  }
  function getActiveOrder(state) { return state.orders.find(order => ['confirmed', 'preparing', 'on_the_way'].includes(order.status)) || null; }
  return { KEY, DELIVERY_FEE, defaultState, normalize, load, persist, addCartLine, removeCartLine, updateCartQuantity, topUpWallet, setProfile, toggleFavorite, getActiveOrder, placeDemoOrder };
}));
```

Give `customer_catalog.js` the same UMD export pattern (`module.exports = api` and `window.SavoraCatalog = api`) so `require('../js/customer_catalog.js')` in Task 3 works. Define complete data for all products: restaurant name, category, image URL, price, description, prep time, calories, dietary tags, allergens, portions and product-specific add-ons. It must not reuse burger information for pizza, sushi or boba.

- [ ] **Step 4: Load catalog and state scripts from the footer after the reusable overlay markup.**

```php
<script src="js/customer_catalog.js"></script>
<script src="js/customer_state.js"></script>
```

Do not reference `js/customer_ui.js` until Task 2 creates it. Do not remove the PHP session guard. At the end of this task, preserve old page globals only long enough for the pages to load; Task 3 through Task 6 remove every old `cart`, `walletBalance` and `foodDatabase` global as part of each route migration.

- [ ] **Step 5: Run state tests and PHP lint.**

Run:

```powershell
node --test tests/customer_state.test.js
$php = 'D:\Xampp\php\php.exe'
Get-ChildItem *.php,components\*.php | ForEach-Object { & $php -l $_.FullName }
```

Expected: all Node tests pass; each PHP file reports `No syntax errors detected`.

## Task 2: Build the shared Savora chrome, design system and accessibility primitives

**Files:**

- Modify: `css/customer_style.css`
- Modify: `components/customer_header.php`
- Modify: `components/customer_footer.php`
- Create: `js/customer_ui.js`
- Create: `tests/customer_markup.test.js`

**Interfaces:**

- Consumes: `SavoraState.load()` and `SavoraCatalog` from Task 1.
- Produces: semantic responsive navigation, cart drawer, dialogs, live region and `SavoraUI` helpers used by all later tasks.

- [ ] **Step 1: Write failing browserless markup and source-safety tests.**

```js
// tests/customer_markup.test.js
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const read = name => fs.readFileSync(path.join(__dirname, '..', name), 'utf8');

test('shared chrome provides semantic navigation, dialog and live-status hooks', () => {
  const header = read('components/customer_header.php');
  const footer = read('components/customer_footer.php');
  assert.match(header, /<nav[^>]*aria-label="Customer navigation"/);
  assert.match(header, /aria-controls="customer-mobile-menu"/);
  assert.match(footer, /role="dialog"/);
  assert.match(footer, /aria-live="polite"/);
});

test('shared renderer does not inject persisted data via innerHTML', () => {
  for (const source of ['components/customer_footer.php', 'js/customer_ui.js']) {
    assert.doesNotMatch(read(source), /innerHTML\s*=/);
  }
});
```

- [ ] **Step 2: Run markup tests to confirm the current markup fails.**

Run: `node --test tests/customer_markup.test.js`

Expected: FAIL because the current header lacks accessible menu controls and the existing footer renderer uses `innerHTML`.

- [ ] **Step 3: Replace the overlapping legacy CSS with Savora component styles.**

Define tokens and components rather than adding page-specific inline styles:

```css
:root {
  --savora-forest: #073b2b;
  --savora-forest-strong: #04291e;
  --savora-coral: #ef634b;
  --savora-ivory: #fbf9f3;
  --savora-sage: #e8eddf;
  --savora-ink: #1c2923;
  --savora-muted: #657169;
  --savora-line: #dfe4da;
  --savora-focus: #1b75d0;
  --savora-radius: 16px;
}

:focus-visible { outline: 3px solid var(--savora-focus); outline-offset: 3px; }
@media (max-width: 768px) { .customer-two-column { grid-template-columns: 1fr; } }
```

Implement named classes for `.customer-shell`, `.customer-header`, `.customer-nav`, `.customer-mobile-menu`, `.page-hero`, `.surface-card`, `.primary-action`, `.secondary-action`, `.order-summary`, `.empty-state`, `.status-chip`, `.dialog`, `.drawer` and every mockup-derived page layout. Avoid `overflow-x: hidden` as a workaround for layout bugs.

- [ ] **Step 4: Replace header and footer interaction markup with accessible components.**

In `customer_header.php`:

```php
<nav class="customer-header" aria-label="Customer navigation">
  <a class="brand" href="customer_dashboard.php" aria-label="Savora home">Savora</a>
  <button class="mobile-menu-toggle" type="button" aria-expanded="false" aria-controls="customer-mobile-menu" aria-label="Open navigation menu">
    <i class="fa-solid fa-bars" aria-hidden="true"></i>
  </button>
  <div id="customer-mobile-menu" class="customer-nav" data-open="false">
    <?php foreach ([
      'customer_dashboard.php' => ['Discover', 'fa-compass'],
      'customer_history.php' => ['Orders', 'fa-bag-shopping'],
      'customer_favorites.php' => ['Favorites', 'fa-heart'],
      'customer_wallet.php' => ['Wallet', 'fa-wallet'],
      'customer_profile.php' => ['Profile', 'fa-user']
    ] as $route => [$label, $icon]): ?>
      <a href="<?php echo $route; ?>"<?php echo $current_page === $route ? ' aria-current="page"' : ''; ?>><i class="fa-solid <?php echo $icon; ?>" aria-hidden="true"></i><?php echo $label; ?></a>
    <?php endforeach; ?>
  </div>
</nav>
```

In `customer_footer.php`, use `<aside role="dialog" aria-modal="true" aria-labelledby="cart-title">` for the cart drawer and `<section role="dialog" ...>` for the product/menu/top-up dialogs. Give every close, quantity and icon-only button an `aria-label`. Add `<div id="toast-container" aria-live="polite" aria-atomic="true"></div>`.

- [ ] **Step 5: Implement `SavoraUI` without unsafe HTML strings.**

```js
function el(tagName, attributes = {}, children = []) {
  const node = document.createElement(tagName);
  for (const [name, value] of Object.entries(attributes)) {
    if (value === false || value == null) continue;
    if (name === 'className') node.className = value;
    else if (name.startsWith('on')) node.addEventListener(name.slice(2).toLowerCase(), value);
    else node.setAttribute(name, String(value));
  }
  for (const child of [].concat(children)) node.append(child instanceof Node ? child : document.createTextNode(String(child)));
  return node;
}
```

Use this helper for cart rows, menu cards, transaction rows and all user-provided notes. Provide `openDialog`, `closeDialog`, Escape close, focus return and `refreshChrome` (cart count + avatar + navigation state).

Load the helper after the Task 1 scripts:

```php
<script src="js/customer_catalog.js"></script>
<script src="js/customer_state.js"></script>
<script src="js/customer_ui.js"></script>
```

- [ ] **Step 6: Re-run shared tests.**

Run: `node --test tests/customer_state.test.js tests/customer_markup.test.js`

Expected: all tests pass.

## Task 3: Implement Discovery and Product Detail from the approved mockups

**Files:**

- Modify: `customer_dashboard.php`
- Modify: `product_detail.php`
- Modify: `css/customer_style.css`
- Modify: `tests/customer_markup.test.js`

**Interfaces:**

- Consumes: `SavoraCatalog`, `SavoraState`, `SavoraUI` and shared header/footer.
- Produces: accessible discovery filtering and correct product metadata/details.

- [ ] **Step 1: Add failing behavior checks to the Node test.**

```js
test('catalog product records keep restaurant-specific detail data', () => {
  const Catalog = require('../js/customer_catalog.js');
  const pizza = Catalog.products['2'];
  assert.equal(pizza.restaurant, 'Pizza Hut');
  assert.notEqual(pizza.ingredients.join('|'), Catalog.products['1'].ingredients.join('|'));
  assert.ok(pizza.addOns.every(option => option.productId === pizza.id));
});

test('discovery source contains no hard-coded active-order number', () => {
  assert.doesNotMatch(read('customer_dashboard.php'), /Order #1042/);
});
```

- [ ] **Step 2: Run tests to observe the expected failure.**

Run: `node --test tests/customer_state.test.js tests/customer_markup.test.js`

Expected: FAIL because catalog module/data and the hard-coded dashboard order are still present.

- [ ] **Step 3: Rewrite the discovery page into semantic, data-driven sections.**

Use `<main>`, `<section aria-labelledby>`, `<button>` category controls and `<a>` cards linking to `product_detail.php?id=<id>`. Attach one filter function that intersects normalized search terms with the selected category:

```js
const matches = item =>
  (selectedCategory === 'all' || item.categories.includes(selectedCategory)) &&
  `${item.name} ${item.restaurant} ${item.categories.join(' ')}`.toLowerCase().includes(query);
```

Render a no-results `.empty-state` if `matches` returns no products or restaurants. Render active tracking only when `SavoraState.load().orders.find(order => ['confirmed', 'preparing', 'on_the_way'].includes(order.status))` exists; otherwise render the empty tracking CTA. The mock map is never constructed for an empty state.

- [ ] **Step 4: Rewrite product detail using the selected catalog record.**

Validate `id` against `SavoraCatalog.products`; show a semantic not-found state with a Discover link for an unknown ID. Bind quantity, choices and special instructions with `addEventListener`, calculate price from the product’s own portion/add-on list, and call `SavoraState.addCartLine`. Use `<fieldset>`/`<legend>` for size and add-ons, labels tied to controls, and a mobile-safe `.customer-two-column` layout.

- [ ] **Step 5: Run the tests and manually inspect the three reference widths.**

Run:

```powershell
node --test tests/customer_state.test.js tests/customer_markup.test.js
& 'D:\Xampp\php\php.exe' -l customer_dashboard.php
& 'D:\Xampp\php\php.exe' -l product_detail.php
```

Expected: green tests and two PHP syntax-success messages. Inspect 320px, 768px and 1440px in a browser; verify no horizontal overflow, all card actions keyboard-focusable, correct restaurant/product data and no active-order fabrication.

## Task 4: Implement Cart and Checkout safely and responsively

**Files:**

- Modify: `customer_cart.php`
- Modify: `customer_checkout.php`
- Modify: `css/customer_style.css`
- Modify: `tests/customer_state.test.js`

**Interfaces:**

- Consumes: Task 1 state mutations and Task 2 cart drawer renderer.
- Produces: a full-cart UI, validated demo checkout and synchronized local order/history state.

- [ ] **Step 1: Extend the state tests for cart quantity, promo and order placement.**

```js
test('quantity reduction removes a cart line at zero', () => {
  let state = State.addCartLine(State.defaultState(), { id: '1', name: 'Pasta', price: 12.5 }, 1, []);
  state = State.updateCartQuantity(state, state.cart[0].lineId, -1);
  assert.equal(state.cart.length, 0);
});

test('one placement records a local order and wallet debit exactly once', () => {
  let state = State.addCartLine(State.defaultState(), { id: '1', name: 'Pasta', price: 10 }, 1, []);
  state.wallet.balance = 20;
  const first = State.placeDemoOrder(state, { address: 'One Street', paymentMethod: 'wallet' });
  assert.equal(first.state.wallet.balance, 8);
  assert.equal(first.state.orders.length, 1);
});
```

- [ ] **Step 2: Run the tests to confirm the new cases fail.**

Run: `node --test tests/customer_state.test.js`

Expected: FAIL until `updateCartQuantity` and wallet debit semantics are complete.

- [ ] **Step 3: Replace cart string rendering with DOM rendering and real empty state.**

Use `SavoraUI.el` to render each item, with labelled buttons such as `aria-label="Increase quantity for Truffle mushroom pasta"` and an explicit `Remove` button. Build the summary from the normalized state. Add a promo-code UI that safely supports a small static set of demo codes or explains that no code is applied; do not claim a discount that the state does not calculate. Render a one-column mobile layout and a full-width Discover CTA for an empty cart.

- [ ] **Step 4: Implement checkout validation and single-submit protection.**

Use a real `<form>` with labelled address, note, payment radio group and promo input. Before calling `placeDemoOrder`, disable the submit button and set `aria-busy="true"`; on validation failure restore it and announce the error through `SavoraUI.showToast`. On success, persist the returned state, render a clear “Demo order placed locally” success message, then navigate to history or dashboard. The handler must not use `alert()`.

- [ ] **Step 5: Verify behavior and markup safety.**

Run:

```powershell
node --test tests/customer_state.test.js tests/customer_markup.test.js
& 'D:\Xampp\php\php.exe' -l customer_cart.php
& 'D:\Xampp\php\php.exe' -l customer_checkout.php
```

Expected: all Node tests pass and both PHP files report no syntax errors. In the browser, test empty cart, line removal, valid cash order, insufficient wallet order and a double click on Place order.

## Task 5: Implement Orders and Favorites from the approved mockups

**Files:**

- Modify: `customer_history.php`
- Modify: `customer_favorites.php`
- Modify: `css/customer_style.css`
- Modify: `tests/customer_state.test.js`

**Interfaces:**

- Consumes: local `orders`, `favorites`, product and restaurant catalogs.
- Produces: semantic status filters, conditional tracking, reorder behavior and favorite tabs/removal.

- [ ] **Step 1: Add failing state tests for favorites and history status.**

```js
test('favorite toggling is idempotent and scoped by kind', () => {
  let state = State.defaultState();
  state = State.toggleFavorite(state, 'products', '1');
  state = State.toggleFavorite(state, 'products', '1');
  assert.deepEqual(state.favorites.products, []);
  assert.deepEqual(state.favorites.restaurants, []);
});

test('active order selection excludes completed and cancelled orders', () => {
  const order = State.getActiveOrder({ orders: [{ status: 'completed' }, { status: 'on_the_way', id: 'active' }] });
  assert.equal(order.id, 'active');
});
```

- [ ] **Step 2: Run the tests and confirm they fail.**

Run: `node --test tests/customer_state.test.js`

Expected: FAIL until `toggleFavorite` and `getActiveOrder` are implemented.

- [ ] **Step 3: Render Orders with status-aware tracking/history.**

Use `<button>` filter controls with `aria-pressed`, `<ol>` or sectioned lists for the history, and text plus icon/status chip for every status. If no local active order exists, show the approved empty-state CTA rather than an invented order/map. Reorder adds the exact recorded product configuration back to state through `SavoraState.addCartLine`.

- [ ] **Step 4: Render Favorites with semantic tabs and safe card actions.**

Implement a `role="tablist"` with `role="tab"` and associated `role="tabpanel"` sections. Restaurant cards are links/buttons as appropriate; heart actions are separate labelled buttons so they do not conflict with card navigation. Render product/restaurant empty states from state rather than hard-coded two-card content.

- [ ] **Step 5: Verify the task.**

Run:

```powershell
node --test tests/customer_state.test.js tests/customer_markup.test.js
& 'D:\Xampp\php\php.exe' -l customer_history.php
& 'D:\Xampp\php\php.exe' -l customer_favorites.php
```

Expected: all tests pass; both PHP files are syntax-valid. Browser-check filters, tab keyboard behavior, remove favorite, reorder and an empty state at 320px and 1440px.

## Task 6: Implement Profile and Savora Pay with accurate local-demo feedback

**Files:**

- Modify: `customer_profile.php`
- Modify: `customer_wallet.php`
- Modify: `css/customer_style.css`
- Modify: `tests/customer_state.test.js`

**Interfaces:**

- Consumes: Task 1 `setProfile`, `topUpWallet`, `load` and the shared toast/state update path.
- Produces: local-demo account settings and a synchronized wallet with accessible transaction semantics.

- [ ] **Step 1: Write failing state tests.**

```js
test('profile patch preserves allowed fields and ignores password claims', () => {
  const state = State.setProfile(State.defaultState(), { fullName: 'Nguyen', address: '1 Food Lane', password: 'secret' });
  assert.equal(state.profile.fullName, 'Nguyen');
  assert.equal(state.profile.address, '1 Food Lane');
  assert.equal(Object.hasOwn(state.profile, 'password'), false);
});

test('wallet top-up updates balance and prepends a credit transaction', () => {
  const state = State.topUpWallet(State.defaultState(), 50);
  assert.equal(state.wallet.balance, 50);
  assert.equal(state.wallet.transactions[0].kind, 'credit');
});
```

- [ ] **Step 2: Run the test to confirm the failure.**

Run: `node --test tests/customer_state.test.js`

Expected: FAIL until profile field allow-listing and wallet transactions are implemented.

- [ ] **Step 3: Replace the profile page’s toast-only form with a labelled local-demo form.**

Include `for`/`id` associations, sensible `autocomplete` attributes and a plainly worded local-demo save confirmation. Omit a password field unless it is visibly disabled/explained as unavailable without backend support. Create the mockup-inspired account, address and security cards as semantic content and ensure mobile stacking.

- [ ] **Step 4: Replace wallet polling with event-driven state refresh.**

Render balance and transactions from `SavoraState.load()` using safe DOM nodes. `topUpWallet` must persist state, immediately call `SavoraUI.refreshChrome`, re-render the balance/activity list and announce the change. Use text labels such as `Credit` and `Debit` alongside color/icon. Remove `setInterval` and the mismatched `wallet-balance` IDs.

- [ ] **Step 5: Verify profile and wallet behavior.**

Run:

```powershell
node --test tests/customer_state.test.js tests/customer_markup.test.js
& 'D:\Xampp\php\php.exe' -l customer_profile.php
& 'D:\Xampp\php\php.exe' -l customer_wallet.php
```

Expected: all Node tests pass and both PHP files are syntax-valid. Browser-check profile reload persistence, a $50 top-up, immediate balance/transaction update and mobile layout.

## Task 7: Perform whole-flow regression and visual verification

**Files:**

- Modify if necessary: any affected Customer files from Tasks 1–6 only.
- Verify: every Customer PHP file, every Node test and all reference breakpoints.

**Interfaces:**

- Consumes: the completed shared shell, state, page renderers and mockup references.
- Produces: evidence that the new Customer experience is cohesive and syntax-safe.

- [ ] **Step 1: Add final source-level regression assertions.**

Extend `tests/customer_markup.test.js` so it checks all eight route files declare a main landmark, the header has all five navigation routes, and old production-facing strings are gone:

```js
test('Customer experience no longer exposes legacy GrabFood or fixed demo order copy', () => {
  const files = ['components/customer_header.php', 'components/customer_footer.php', 'customer_dashboard.php'];
  const source = files.map(read).join('\n');
  assert.doesNotMatch(source, /GrabFood|Order #1042|â€¢|ðŸ/);
});

test('migrated Customer renderers do not inject local state via innerHTML', () => {
  const files = ['components/customer_footer.php', 'customer_dashboard.php', 'product_detail.php', 'customer_cart.php', 'customer_checkout.php', 'customer_history.php', 'customer_favorites.php', 'customer_profile.php', 'customer_wallet.php'];
  for (const file of files) assert.doesNotMatch(read(file), /innerHTML\s*=/);
});
```

- [ ] **Step 2: Run the final automated suite.**

Run: `node --test tests/customer_state.test.js tests/customer_markup.test.js`

Expected: all tests pass with zero failures.

- [ ] **Step 3: Run PHP syntax verification across all Customer files.**

Run:

```powershell
$php = 'D:\Xampp\php\php.exe'
$files = @(
  'customer_dashboard.php', 'product_detail.php', 'customer_cart.php',
  'customer_checkout.php', 'customer_history.php', 'customer_favorites.php',
  'customer_profile.php', 'customer_wallet.php',
  'components\customer_header.php', 'components\customer_footer.php'
)
$files | ForEach-Object { & $php -l $_ }
```

Expected: ten `No syntax errors detected` lines.

- [ ] **Step 4: Conduct visual walkthrough against mockups.**

Open the local site and verify each route while authenticated as Customer at 1440px, 768px and 320px:

1. Discover → product detail → add configured item → cart → checkout → local demo order.
2. Orders shows the new order; reorder restores its configuration.
3. Favorites can switch tabs/remove entries; empty states link to discovery.
4. Profile persists supported fields locally and never claims a password change.
5. Wallet top-up refreshes balance and activity immediately.
6. Use Tab, Shift+Tab and Escape on menu, cart drawer and dialogs.

- [ ] **Step 5: Record verification evidence.**

Add a concise report at `docs/superpowers/plans/2026-07-29-customer-ui-ux-verification.md` containing exact test commands, pass counts, PHP syntax result, breakpoint observations and any deliberate local-demo limitations.
