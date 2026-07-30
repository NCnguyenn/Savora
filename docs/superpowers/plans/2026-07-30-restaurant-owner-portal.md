# Savora Restaurant Owner Portal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build all eleven responsive and accessible Restaurant Portal screens from the approved mockups, using shared components and a safe local-demo data bridge that keeps Restaurant actions consistent with the existing Customer experience.

**Architecture:** Keep the existing PHP/HTML/CSS/vanilla-JavaScript stack. A shared Restaurant header/footer owns authentication, navigation, utility controls, scripts, toast/dialog regions, and page titles; domain scripts own orders, menu/storefront, and insight pages. `SavoraRestaurantState` persists restaurant configuration and derives operational metrics while updating Customer orders through the existing `SavoraState` object, and `customer_catalog.js` reads safe restaurant overrides so menu/profile/availability changes appear in Customer discovery.

**Tech Stack:** PHP 8-compatible templates, semantic HTML5, CSS custom properties and responsive media queries, vanilla JavaScript UMD modules, browser `localStorage`, Node.js built-in test runner, XAMPP PHP/Apache, Font Awesome and existing local image assets.

## Global Constraints

- Implement the eleven screens listed in `docs/superpowers/specs/2026-07-30-restaurant-owner-mockups-design.md`.
- Use English UI copy only.
- Use `#073B2B`, `#04291E`, `#EF634B`, `#FBF9F3`, `#E8EDDF`, `#1C2923`, `#657169`, `#DFE4DA`, and accessibility focus `#1B75D0`.
- Match the approved PNGs in `docs/mockups/restaurant-owner/` while adapting layouts at 320px, 768px, and 1440px.
- Every Restaurant route must use the shared shell, semantic landmarks, keyboard-visible focus, labeled controls, live regions for mutations, safe text rendering, and no inline event handlers.
- Preserve the existing Customer UI and all existing Customer tests.
- Restaurant actions must persist locally, validate status transitions, and update the Customer order/catalog state in the same browser.
- A cart/order belongs to exactly one restaurant; legacy data is normalized without executing persisted markup.
- No remote images, new CDN dependencies, framework migration, production payment claims, or backend/database claims.

---

### Task 1: Shared Restaurant state and Customer bridge

**Files:**
- Create: `js/restaurant_state.js`
- Modify: `js/customer_state.js`
- Modify: `js/customer_catalog.js`
- Modify: `customer_history.php`
- Modify: `customer_dashboard.php`
- Test: `tests/restaurant_state.test.js`
- Test: `tests/customer_state.test.js`

**Interfaces:**
- Produces: `SavoraRestaurantState.KEY`, `defaultState()`, `normalize(raw)`, `load()`, `persist(state)`, `updateOrderStatus(customerState, orderId, nextStatus, metadata)`, `setMenuItem(state, item)`, `setItemAvailability(state, id, available)`, `setProfile(state, patch)`, `setOperations(state, patch)`, `setReviewReply(state, reviewId, reply)`, `deriveFinance(customerState)`, and `deriveAnalytics(customerState)`.
- Extends: `SavoraState` order statuses with `pending` and `ready_for_pickup`; cart/order lines preserve `restaurantId` and `restaurantName`.
- Consumes later: all Restaurant page controllers and Customer discovery/history.

- [ ] **Step 1: Write failing state tests**

Add tests that require `restaurant_state.js` and assert:

```js
test('accepts only valid order transitions and records an audit event', () => {
  const customer = { orders: [{ id: 'SVR-1', status: 'pending', items: [], total: 20 }] };
  const next = RestaurantState.updateOrderStatus(customer, 'SVR-1', 'confirmed', { prepMinutes: 20 });
  assert.equal(next.orders[0].status, 'confirmed');
  assert.equal(next.orders[0].prepMinutes, 20);
  assert.equal(next.orders[0].statusHistory.at(-1).status, 'confirmed');
  assert.throws(() => RestaurantState.updateOrderStatus(next, 'SVR-1', 'completed'), /transition/i);
});

test('menu and storefront changes normalize to safe customer overrides', () => {
  let state = RestaurantState.setItemAvailability(RestaurantState.defaultState(), '1', false);
  state = RestaurantState.setProfile(state, { name: 'Savora Kitchen', address: '<img onerror=alert(1)>' });
  assert.equal(state.menuItems.find(item => item.id === '1').available, false);
  assert.equal(state.profile.address, '<img onerror=alert(1)>');
  assert.equal(Object.hasOwn(state.profile, 'onerror'), false);
});

test('customer cart rejects items from a second restaurant', () => {
  let state = CustomerState.addCartLine(CustomerState.defaultState(), {
    id: '1', restaurantId: 'savora-kitchen', restaurant: 'Savora Kitchen', name: 'Pasta', price: 12
  }, 1);
  assert.throws(() => CustomerState.addCartLine(state, {
    id: '2', restaurantId: 'pizza-hut', restaurant: 'Pizza Hut', name: 'Pizza', price: 10
  }, 1), /one restaurant/i);
});
```

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```powershell
node --test tests\restaurant_state.test.js tests\customer_state.test.js
```

Expected: FAIL because `js/restaurant_state.js`, `pending`, `ready_for_pickup`, restaurant ownership, and safe transition helpers do not exist.

- [ ] **Step 3: Implement minimal state and bridge behavior**

Implement UMD state modules with:

```js
const ORDER_TRANSITIONS = {
  pending: ['confirmed', 'cancelled'],
  confirmed: ['preparing', 'cancelled'],
  preparing: ['ready_for_pickup', 'cancelled'],
  ready_for_pickup: ['on_the_way', 'completed'],
  on_the_way: ['completed'],
  completed: [],
  cancelled: []
};
```

Normalize all text to bounded strings, numeric fields to finite non-negative values, status history to allowed records, and restaurant configuration to allowlisted fields. Make `placeDemoOrder()` create `pending` orders with restaurant identity and status history. Make `customer_catalog.js` merge only allowlisted Restaurant state fields and local `assets/images/catalog/` image paths.

- [ ] **Step 4: Update Customer status rendering**

Add readable labels and progress handling for `pending` and `ready_for_pickup` in Customer dashboard/history. Render persisted values only through `textContent`/DOM node creation.

- [ ] **Step 5: Verify GREEN and regression**

Run:

```powershell
node --test tests\restaurant_state.test.js tests\customer_state.test.js tests\customer_markup.test.js
```

Expected: all tests pass with zero failures.

- [ ] **Step 6: Commit**

```powershell
git add js/restaurant_state.js js/customer_state.js js/customer_catalog.js customer_history.php customer_dashboard.php tests/restaurant_state.test.js tests/customer_state.test.js
git commit -m "feat: add restaurant state and customer bridge"
```

---

### Task 2: Shared Restaurant shell and Overview

**Files:**
- Create: `components/restaurant_header.php`
- Create: `components/restaurant_footer.php`
- Create: `css/restaurant_style.css`
- Create: `js/restaurant_ui.js`
- Modify: `restaurant_dashboard.php`
- Test: `tests/restaurant_markup.test.js`

**Interfaces:**
- Produces: shared route/title map, responsive sidebar, mobile navigation dialog, search, notification badge, accepting-orders control, owner menu, toast live region, `SavoraRestaurantUI.el()`, `showToast()`, `formatMoney()`, `statusChip()`, `refreshShell()`, and accessible dialog helpers.
- Consumes: `SavoraRestaurantState` from Task 1.
- Used by: all ten later Restaurant routes.

- [ ] **Step 1: Write failing shell and Overview markup tests**

Assert the shared component files exist; `restaurant_dashboard.php` includes both components; role guard remains; page has one `<main>`, an `h1` containing `Restaurant Overview`, KPI hooks, chart/table hooks, no `href="#"`, no inline handlers, and local Font Awesome/CSS/JS assets.

- [ ] **Step 2: Run markup test and verify RED**

Run:

```powershell
node --test tests\restaurant_markup.test.js
```

Expected: FAIL because shared Restaurant components and semantic Overview hooks do not exist.

- [ ] **Step 3: Implement shared authenticated shell**

`restaurant_header.php` must:

```php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'restaurant') {
    header('Location: index.php');
    exit();
}
```

Render the route map for Overview, Live Orders, Order History, Menu, Storefront, Finance, Analytics, and Reviews with `aria-current="page"`, a keyboard-operable mobile menu, skip link, search label, notifications, accepting-orders button, and account menu.

- [ ] **Step 4: Implement shared CSS and UI behavior**

Define the approved color variables, desktop sidebar/grid primitives, cards, tables, chips, forms, dialogs, toasts, `:focus-visible` blue ring, reduced-motion handling, and breakpoints:

```css
@media (max-width: 1024px) { /* compact sidebar and two-column reductions */ }
@media (max-width: 768px) { /* mobile top bar, single-column content */ }
@media (max-width: 480px) { /* 320px-safe controls and tables */ }
```

Use event delegation and DOM methods only.

- [ ] **Step 5: Build Overview from live local-demo data**

Replace the static dashboard with semantic cards, an accessible CSS/SVG chart, live queue, top items, and low-stock alert derived from Restaurant/Customer state. Use buttons and links that navigate to real routes.

- [ ] **Step 6: Verify GREEN**

Run:

```powershell
node --test tests\restaurant_markup.test.js tests\customer_markup.test.js
D:\xampp\php\php.exe -l restaurant_dashboard.php
D:\xampp\php\php.exe -l components\restaurant_header.php
D:\xampp\php\php.exe -l components\restaurant_footer.php
```

Expected: all tests and syntax checks pass.

- [ ] **Step 7: Commit**

```powershell
git add components/restaurant_header.php components/restaurant_footer.php css/restaurant_style.css js/restaurant_ui.js restaurant_dashboard.php tests/restaurant_markup.test.js
git commit -m "feat: build restaurant shell and overview"
```

---

### Task 3: Live Order Center and Order History

**Files:**
- Create: `restaurant_orders.php`
- Create: `restaurant_order_history.php`
- Create: `js/restaurant_orders.js`
- Modify: `tests/restaurant_markup.test.js`
- Modify: `tests/restaurant_state.test.js`

**Interfaces:**
- Consumes: `SavoraRestaurantState.updateOrderStatus()` and shared UI helpers.
- Produces: live queue filters, order selection/details, accepting/rejecting, preparation-time updates, ready-for-pickup handoff, order-history filters, export-safe table view, and invoice/reorder navigation.

- [ ] **Step 1: Write failing order-page tests**

Assert both routes use the shared shell and expose semantic tabs/filters, live regions, order detail, prep-time field, Accept/Reject/Ready actions, history table, status timeline, and no unsafe HTML insertion or inline handlers. Extend state tests for every valid/invalid transition.

- [ ] **Step 2: Run focused tests and verify RED**

Run:

```powershell
node --test tests\restaurant_state.test.js tests\restaurant_markup.test.js
```

Expected: FAIL because the two routes and controller do not exist.

- [ ] **Step 3: Implement Live Order Center**

Render status counters, filter tabs, queue cards, selected-order details, customer address/note, totals, preparation selector, and actions. Persist status updates through Customer state, show confirmation/toast feedback, disable invalid actions, and move orders between columns without a reload.

- [ ] **Step 4: Implement Order History**

Render completed/cancelled/refunded summaries, date/search/status/fulfillment filters, accessible responsive table/cards, details drawer, audit timeline, pagination controls, invoice link, and reorder-details action.

- [ ] **Step 5: Verify GREEN**

Run Node tests plus:

```powershell
D:\xampp\php\php.exe -l restaurant_orders.php
D:\xampp\php\php.exe -l restaurant_order_history.php
```

Expected: zero failures.

- [ ] **Step 6: Commit**

```powershell
git add restaurant_orders.php restaurant_order_history.php js/restaurant_orders.js tests/restaurant_markup.test.js tests/restaurant_state.test.js
git commit -m "feat: add restaurant order operations"
```

---

### Task 4: Menu Management and Add/Edit Menu Item

**Files:**
- Create: `restaurant_menu.php`
- Create: `restaurant_menu_item.php`
- Create: `js/restaurant_menu.js`
- Modify: `tests/restaurant_markup.test.js`
- Modify: `tests/restaurant_state.test.js`

**Interfaces:**
- Consumes: `setMenuItem()` and `setItemAvailability()`.
- Produces: searchable/filterable menu cards, category tabs, availability/stock controls, add/edit form, option groups, validation, local image allowlisting, publish/draft state, and Customer preview.

- [ ] **Step 1: Write failing menu tests**

Test menu normalization, item upsert, availability persistence, invalid price rejection, unsafe image fallback, route shell inclusion, labeled editor fields, validation/status regions, and Customer-preview hooks.

- [ ] **Step 2: Verify RED**

Run:

```powershell
node --test tests\restaurant_state.test.js tests\restaurant_markup.test.js
```

Expected: FAIL because menu routes and controller do not exist.

- [ ] **Step 3: Implement Menu Management**

Match the mockup grid and categories; render images through the catalog allowlist; support search, category, availability, sort, grid/list view, stock chips, keyboard-accessible toggles, and navigation to `restaurant_menu_item.php?id=<id>`.

- [ ] **Step 4: Implement Add/Edit Menu Item**

Use a real form with name, description, category, local image selection, price, compare-at price, tax category, option groups, add-ons, availability, stock, prep time, dietary tags, live Customer preview, save draft, and publish. On success update Restaurant state and Customer catalog bridge and navigate back to Menu.

- [ ] **Step 5: Verify GREEN and PHP syntax**

Run focused Node tests and PHP lint for both routes. Expected: zero failures.

- [ ] **Step 6: Commit**

```powershell
git add restaurant_menu.php restaurant_menu_item.php js/restaurant_menu.js tests/restaurant_markup.test.js tests/restaurant_state.test.js
git commit -m "feat: add restaurant menu management"
```

---

### Task 5: Store Profile and Operations & Opening Hours

**Files:**
- Create: `restaurant_profile.php`
- Create: `restaurant_operations.php`
- Create: `js/restaurant_storefront.js`
- Modify: `tests/restaurant_markup.test.js`
- Modify: `tests/restaurant_state.test.js`

**Interfaces:**
- Consumes: `setProfile()` and `setOperations()`.
- Produces: branded profile editor, `Use current location`, manual address entry, map-pin fallback, delivery settings, weekly/special hours, store status/capacity controls, fulfillment settings, and Customer storefront/status preview.

- [ ] **Step 1: Write failing storefront tests**

Assert safe profile/operations persistence, normalized weekly hours, valid delivery radius/capacity, both address methods, geolocation status live region, manual address labels, map fallback, Customer preview, and no permission claim before geolocation succeeds.

- [ ] **Step 2: Verify RED**

Run focused state/markup tests. Expected: FAIL because storefront routes and controller do not exist.

- [ ] **Step 3: Implement Store Profile**

Build the mockup form and preview. `Use current location` calls `navigator.geolocation.getCurrentPosition`; on success persist coordinates and announce success; on denial/error keep manual fields usable and announce the error. Manual entry persists bounded text. Use the existing local Leaflet assets for the map and a visible no-tile fallback.

- [ ] **Step 4: Implement Operations & Opening Hours**

Build store-open status, weekly hours, copy-to-all, special hours, capacity warning, prep-time/capacity settings, fulfillment toggles, pickup instructions, and Customer status preview. Persist changes and make accepting-orders state visible in the shared shell.

- [ ] **Step 5: Verify GREEN and PHP syntax**

Run focused Node tests and lint both PHP routes. Expected: zero failures.

- [ ] **Step 6: Commit**

```powershell
git add restaurant_profile.php restaurant_operations.php js/restaurant_storefront.js tests/restaurant_markup.test.js tests/restaurant_state.test.js
git commit -m "feat: add restaurant storefront operations"
```

---

### Task 6: Revenue & Payouts and Invoices & Statements

**Files:**
- Create: `restaurant_finance.php`
- Create: `restaurant_invoices.php`
- Create: `js/restaurant_finance.js`
- Modify: `tests/restaurant_markup.test.js`
- Modify: `tests/restaurant_state.test.js`

**Interfaces:**
- Consumes: `deriveFinance(customerState)`.
- Produces: finance KPIs/chart, transaction filters, payout preview/account, invoice/statement tabs, document table, safe invoice preview, print action, and demo-download feedback.

- [ ] **Step 1: Write failing finance tests**

Test that only completed/refunded orders contribute to totals, cancelled/pending orders do not, refund values are negative exactly once, and both routes label all tables/filters while avoiding real-payment or generated-PDF claims.

- [ ] **Step 2: Verify RED**

Run focused tests. Expected: FAIL because finance derivation/routes do not exist.

- [ ] **Step 3: Implement Revenue & Payouts**

Render derived metrics, accessible SVG/CSS chart, next payout, transaction filters/table/cards, payout account, date range, and demo request-payout confirmation.

- [ ] **Step 4: Implement Invoices & Statements**

Render order invoices, payout statements, tax-document empty state, filters, document status/actions, selected invoice preview, print through `window.print()`, and transparent messaging that demo downloads are not server-generated accounting documents.

- [ ] **Step 5: Verify GREEN and PHP syntax**

Run focused Node tests and PHP lint. Expected: zero failures.

- [ ] **Step 6: Commit**

```powershell
git add restaurant_finance.php restaurant_invoices.php js/restaurant_finance.js tests/restaurant_markup.test.js tests/restaurant_state.test.js
git commit -m "feat: add restaurant finance documents"
```

---

### Task 7: Business Analytics and Ratings & Feedback

**Files:**
- Create: `restaurant_analytics.php`
- Create: `restaurant_reviews.php`
- Create: `js/restaurant_insights.js`
- Modify: `tests/restaurant_markup.test.js`
- Modify: `tests/restaurant_state.test.js`

**Interfaces:**
- Consumes: `deriveAnalytics()` and `setReviewReply()`.
- Produces: date-range analytics, order/revenue charts, status distribution, ordering-time heatmap, menu/kitchen metrics, verified-review filters, selected review context, safe public reply draft/publish flow, and insight chips.

- [ ] **Step 1: Write failing insight tests**

Test deterministic analytics from fixture orders, empty-data fallbacks, bounded review replies, route landmarks, chart accessible names/descriptions, review filters, labeled public-reply textarea, character count, and live publish feedback.

- [ ] **Step 2: Verify RED**

Run focused tests. Expected: FAIL because insight routes/controller do not exist.

- [ ] **Step 3: Implement Business Analytics**

Render the approved KPI/chart grid using semantic summaries and accessible SVG/CSS graphics with text equivalents. Provide 30-day/7-day switches, empty-state behavior, and export-demo feedback.

- [ ] **Step 4: Implement Ratings & Feedback**

Render rating summaries/distribution, verified review list and filters, selected order/item context, safe reply composer, save draft/publish, and most-mentioned insights. Persist replies as text and render with DOM nodes.

- [ ] **Step 5: Verify GREEN and PHP syntax**

Run focused Node tests and lint both routes. Expected: zero failures.

- [ ] **Step 6: Commit**

```powershell
git add restaurant_analytics.php restaurant_reviews.php js/restaurant_insights.js tests/restaurant_markup.test.js tests/restaurant_state.test.js
git commit -m "feat: add restaurant analytics and reviews"
```

---

### Task 8: Responsive, accessibility, integration, and full regression QA

**Files:**
- Create: `tests/restaurant_browser_qa.mjs`
- Modify: `css/restaurant_style.css`
- Modify: `js/restaurant_ui.js`
- Modify: Restaurant/Customer files only when a failing QA assertion proves a defect.
- Test: `tests/restaurant_browser_qa.mjs`
- Test: all existing `tests/*.test.js`

**Interfaces:**
- Verifies: all eleven routes, 320/768/1440 layouts, shell navigation, focus, keyboard interactions, state persistence, Customer bridge, and no visual overflow.

- [ ] **Step 1: Write browser QA before final responsive fixes**

Build a CDP-based test using the existing Task 7 browser helpers. Log in as `restaurant`, visit all eleven routes at 320×900, 768×1024, and 1440×1000, and assert:

```js
document.querySelector('main') !== null
document.documentElement.scrollWidth <= window.innerWidth
document.title.includes('Savora')
getComputedStyle(document.activeElement).outlineStyle !== 'none'
```

Exercise mobile navigation, accepting-orders toggle, one order status update reflected on Customer history, menu availability reflected in Customer discovery/menu, Store Profile manual address save, geolocation error fallback, and review reply persistence.

- [ ] **Step 2: Run browser QA and verify RED**

Start XAMPP Apache if needed, then run:

```powershell
node tests\restaurant_browser_qa.mjs
```

Expected: FAIL on any remaining responsive, accessibility, or integration defects.

- [ ] **Step 3: Fix only proven QA defects**

Adjust CSS/JS/markup for the exact failing viewport or interaction. Preserve shared components and Customer regressions.

- [ ] **Step 4: Run full fresh verification**

Run:

```powershell
node --test tests\customer_state.test.js tests\customer_markup.test.js tests\restaurant_state.test.js tests\restaurant_markup.test.js
node tests\restaurant_browser_qa.mjs
$files = Get-ChildItem -Path . -Filter '*.php' -File
foreach ($file in $files) { D:\xampp\php\php.exe -l $file.FullName }
D:\xampp\php\php.exe -l components\restaurant_header.php
D:\xampp\php\php.exe -l components\restaurant_footer.php
```

Expected: all Node tests, browser routes/interactions, and PHP syntax checks pass with zero failures.

- [ ] **Step 5: Perform requirement self-review**

Compare all routes against the eleven mockups, spec, Global Constraints, and screenshots at 320/768/1440. Record any intentional visual adaptations in `.superpowers/sdd/restaurant-portal/progress.md`.

- [ ] **Step 6: Commit**

```powershell
git add tests/restaurant_browser_qa.mjs css/restaurant_style.css js/restaurant_ui.js
git add restaurant_*.php components/restaurant_*.php js/restaurant_*.js tests/restaurant_*.js
git commit -m "test: verify restaurant portal experience"
```
