# Savora Driver Portal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build five responsive, English-only driver portal pages from the approved mockups and connect driver dispatch and delivery milestones to the same local orders used by Savora customers and restaurant owners.

**Architecture:** Add a driver-specific authenticated PHP shell, one focused controller per page, and a UMD-style `SavoraDriverState` module that stores driver profile, availability, exclusive offers, assignments, milestones, and earnings in local storage. Customer orders remain the shared source of truth for order status; restaurant actions end at `ready_for_pickup`, while the driver module owns assignment, pickup, `on_the_way`, and `completed` transitions.

**Tech Stack:** PHP 8-compatible templates, semantic HTML, CSS, vanilla JavaScript, localStorage, Node.js `node:test`, existing local Font Awesome assets, optional existing Leaflet assets.

## Global Constraints

- Five top-level pages only: Overview, Active Delivery, History, Earnings, Profile.
- All visible portal copy is English.
- Forest green `#073B2B`; dark forest `#04291E`; coral `#EF634B`; ivory `#FBF9F3`; sage `#E8EDDF`; text `#1C2923`; secondary text `#657169`; border `#DFE4DA`; focus `#1B75D0`.
- Each delivery offer is exclusive to one online driver for exactly 30 seconds.
- Rejecting or expiring an offer clears it and keeps the customer order at `ready_for_pickup`.
- A driver can have only one active delivery in the local demo.
- Restaurant controls stop at `ready_for_pickup`; driver pickup sets `on_the_way`; driver completion sets `completed`.
- Use local assets and existing no-framework conventions; add no package dependency.
- Preserve the existing customer and restaurant portals except for targeted driver-status integration.
- Mockup references live in `docs/mockups/driver-portal/01-driver-overview.png` through `05-driver-profile-settings.png`.

---

### Task 1: Driver Dispatch and Delivery State

**Files:**
- Create: `js/driver_state.js`
- Create: `tests/driver_state.test.js`
- Modify: `js/restaurant_state.js`
- Modify: `tests/restaurant_state.test.js`

**Interfaces:**
- Consumes: customer state shaped as `{ orders: Order[] }` and restaurant state shaped as `{ profile: RestaurantProfile }`.
- Produces: global/CommonJS `SavoraDriverState` with `KEY`, `OFFER_DURATION_MS`, `defaultState()`, `normalize(raw)`, `load()`, `persist(state)`, `setAvailability(state, online)`, `setLocation(state, patch)`, `setProfile(state, patch)`, `setPreferences(state, patch)`, `createOffer(state, customerState, restaurantState, now)`, `expireOffer(state, now)`, `declineOffer(state, orderId, now)`, `acceptOffer(state, customerState, restaurantState, orderId, now)`, `updateMilestone(state, customerState, orderId, milestone, now)`, `activeDelivery(state)`, `deliveryForOrder(state, orderId)`, `deriveHistory(state)`, and `deriveEarnings(state)`.

- [ ] **Step 1: Write failing dispatch-state tests**

Add Node tests covering the 30-second window, online eligibility, exclusive offer, rejection, idempotent acceptance, one-active-delivery rule, milestone ownership, and derived earnings:

```js
const test = require('node:test');
const assert = require('node:assert/strict');
const DriverState = require('../js/driver_state.js');

const readyOrder = {
  id: 'SV-1042',
  status: 'ready_for_pickup',
  restaurantId: 'green-bowl',
  restaurantName: 'Green Bowl Kitchen',
  customerName: 'Emma Wilson',
  address: '28 River Lane, Apt 4B',
  deliveryNote: 'Please leave at the front desk.',
  paymentMethod: 'cash',
  deliveryFee: 6.8,
  total: 24.5,
  createdAt: '2026-07-30T05:00:00.000Z',
  items: [{ id: 'bowl', name: 'Grilled Chicken Bowl', quantity: 1, unitPrice: 17.7 }]
};
const restaurant = { profile: { id: 'green-bowl', name: 'Green Bowl Kitchen', address: '145 Pine Street' } };

test('offers one ready order to an online idle driver for exactly 30 seconds', () => {
  const online = DriverState.setAvailability(DriverState.defaultState(), true);
  const next = DriverState.createOffer(online, { orders: [readyOrder] }, restaurant, 1000);
  assert.equal(next.currentOffer.orderId, 'SV-1042');
  assert.equal(next.currentOffer.expiresAt, 31000);
});

test('decline and timeout clear the offer without changing the shared order status', () => {
  const offered = DriverState.createOffer(
    DriverState.setAvailability(DriverState.defaultState(), true),
    { orders: [readyOrder] },
    restaurant,
    1000
  );
  assert.equal(DriverState.declineOffer(offered, 'SV-1042', 2000).currentOffer, null);
  assert.equal(DriverState.expireOffer(offered, 31000).currentOffer, null);
  assert.equal(readyOrder.status, 'ready_for_pickup');
});

test('pickup and delivery are driver-owned shared order transitions', () => {
  const offered = DriverState.createOffer(
    DriverState.setAvailability(DriverState.defaultState(), true),
    { orders: [readyOrder] },
    restaurant,
    1000
  );
  const accepted = DriverState.acceptOffer(offered, { orders: [readyOrder] }, restaurant, 'SV-1042', 2000);
  const pickedUp = DriverState.updateMilestone(accepted.state, accepted.customerState, 'SV-1042', 'picked_up', 3000);
  const delivered = DriverState.updateMilestone(pickedUp.state, pickedUp.customerState, 'SV-1042', 'delivered', 4000);
  assert.equal(pickedUp.customerState.orders[0].status, 'on_the_way');
  assert.equal(delivered.customerState.orders[0].status, 'completed');
  assert.equal(delivered.customerState.orders[0].statusHistory.at(-1).actor, 'driver');
});
```

- [ ] **Step 2: Run tests and verify the missing module failure**

Run:

```powershell
node --test tests/driver_state.test.js
```

Expected: FAIL because `js/driver_state.js` does not exist.

- [ ] **Step 3: Implement the minimal normalized driver state and transition API**

Create a UMD module following the existing customer/restaurant pattern. Its core transition rules must be explicit:

```js
const OFFER_DURATION_MS = 30000;
const ACTIVE_DELIVERY_STATUSES = ['assigned', 'arrived', 'picked_up'];
const MILESTONE_TRANSITIONS = {
  assigned: ['arrived'],
  arrived: ['picked_up'],
  picked_up: ['delivered'],
  delivered: []
};

function createOffer(state, customerState, restaurantState, now = Date.now()) {
  const next = normalize(state);
  if (!next.online || activeDelivery(next) || next.currentOffer) return next;
  const order = (customerState.orders || []).find(item =>
    item.status === 'ready_for_pickup' &&
    !next.declinedOrderIds.includes(item.id) &&
    !next.deliveries.some(delivery => delivery.orderId === item.id)
  );
  if (!order) return next;
  next.currentOffer = offerSnapshot(order, restaurantState, now, now + OFFER_DURATION_MS);
  return next;
}

function updateMilestone(state, customerState, orderId, milestone, now = Date.now()) {
  const next = normalize(state);
  const customer = copy(customerState);
  const delivery = next.deliveries.find(item => item.orderId === text(orderId));
  if (!delivery || !MILESTONE_TRANSITIONS[delivery.status].includes(milestone)) {
    throw new Error('Invalid delivery milestone transition');
  }
  delivery.status = milestone;
  delivery.milestones.push({ status: milestone, createdAt: new Date(now).toISOString() });
  if (milestone === 'picked_up' || milestone === 'delivered') {
    const order = customer.orders.find(item => item.id === delivery.orderId);
    order.status = milestone === 'picked_up' ? 'on_the_way' : 'completed';
    order.statusHistory = Array.isArray(order.statusHistory) ? order.statusHistory : [];
    order.statusHistory.push({ status: order.status, createdAt: new Date(now).toISOString(), actor: 'driver' });
  }
  return { state: normalize(next), customerState: customer };
}
```

Normalization must bound strings, coordinates, money, distance, service radius, and arrays; it must drop unknown fields rather than merging untrusted objects.

- [ ] **Step 4: Remove restaurant-owned dispatch transitions with tests**

Change `ORDER_TRANSITIONS` to:

```js
const ORDER_TRANSITIONS = {
  pending: ['confirmed', 'cancelled'],
  confirmed: ['preparing', 'cancelled'],
  preparing: ['ready_for_pickup', 'cancelled'],
  ready_for_pickup: [],
  on_the_way: [],
  completed: [],
  cancelled: []
};
```

Update the transition test so `ready_for_pickup -> on_the_way`, `ready_for_pickup -> completed`, and `on_the_way -> completed` are rejected for the restaurant actor.

- [ ] **Step 5: Run state tests**

Run:

```powershell
node --test tests/driver_state.test.js tests/customer_state.test.js tests/restaurant_state.test.js
```

Expected: all tests PASS.

- [ ] **Step 6: Commit the state boundary**

```powershell
git add js/driver_state.js js/restaurant_state.js tests/driver_state.test.js tests/restaurant_state.test.js
git commit -m "feat: add driver dispatch state"
```

### Task 2: Authenticated Driver Shell and Design System

**Files:**
- Create: `components/driver_header.php`
- Create: `components/driver_footer.php`
- Create: `css/driver_style.css`
- Create: `js/driver_ui.js`
- Create: `tests/driver_markup.test.js`

**Interfaces:**
- Consumes: `$_SESSION` driver identity and `SavoraDriverState`.
- Produces: a shared `.driver-shell`, five-route navigation, responsive mobile navigation, `SavoraDriverUI.el()`, `money()`, `formatDate()`, `showToast()`, `announce()`, `openDialog()`, and `closeDialog()`.

- [ ] **Step 1: Write failing shell and palette tests**

```js
test('Driver shell authenticates the driver and exposes exactly five top-level routes', () => {
  const header = read('components/driver_header.php');
  assert.match(header, /\(\$_SESSION\['role'\]\s*\?\?\s*''\)\s*!==\s*'driver'/);
  for (const route of [
    'driver_dashboard.php',
    'driver_delivery.php',
    'driver_history.php',
    'driver_earnings.php',
    'driver_profile.php'
  ]) assert.match(header, new RegExp(route.replace('.', '\\.')));
  assert.match(header, /aria-current="page"/);
});

test('Driver stylesheet contains the approved palette and accessible focus color', () => {
  const css = read('css/driver_style.css');
  for (const color of ['#073b2b', '#04291e', '#ef634b', '#fbf9f3', '#e8eddf', '#1c2923', '#657169', '#dfe4da', '#1b75d0']) {
    assert.match(css.toLowerCase(), new RegExp(color));
  }
  assert.match(css, /:focus-visible/);
});
```

- [ ] **Step 2: Run markup tests and verify missing-file failures**

Run:

```powershell
node --test tests/driver_markup.test.js
```

Expected: FAIL because the driver shell files do not exist.

- [ ] **Step 3: Build the shared PHP shell**

The header must define exactly these routes:

```php
$driver_routes = [
    'driver_dashboard.php' => ['Overview', 'fa-house'],
    'driver_delivery.php' => ['Active Delivery', 'fa-motorcycle'],
    'driver_history.php' => ['History', 'fa-clock-rotate-left'],
    'driver_earnings.php' => ['Earnings', 'fa-wallet'],
    'driver_profile.php' => ['Profile', 'fa-user']
];
```

Include a skip link, desktop sidebar, mobile menu button, online indicator, authenticated driver name, and one `<main id="driver-main">` per page. Help & Support is a contextual footer action, not a sixth route.

- [ ] **Step 4: Build shared CSS and UI helpers**

Start `driver_style.css` with:

```css
:root {
  --driver-forest: #073b2b;
  --driver-forest-dark: #04291e;
  --driver-coral: #ef634b;
  --driver-ivory: #fbf9f3;
  --driver-sage: #e8eddf;
  --driver-text: #1c2923;
  --driver-muted: #657169;
  --driver-border: #dfe4da;
  --driver-focus: #1b75d0;
}

.driver-body { margin: 0; background: var(--driver-ivory); color: var(--driver-text); }
.driver-shell { min-height: 100vh; display: grid; grid-template-columns: 264px minmax(0, 1fr); }
:where(a, button, input, select, textarea):focus-visible {
  outline: 3px solid var(--driver-focus);
  outline-offset: 3px;
}
@media (max-width: 860px) {
  .driver-shell { display: block; padding-bottom: 74px; }
  .driver-primary-nav { position: fixed; inset: auto 0 0; display: grid; grid-template-columns: repeat(5, 1fr); }
}
```

`driver_ui.js` must create DOM with `textContent`, never `innerHTML`, and centralize dialog focus restoration and toast announcements.

- [ ] **Step 5: Run shell tests**

Run:

```powershell
node --test tests/driver_markup.test.js
```

Expected: PASS.

- [ ] **Step 6: Commit the shared shell**

```powershell
git add components/driver_header.php components/driver_footer.php css/driver_style.css js/driver_ui.js tests/driver_markup.test.js
git commit -m "feat: add driver portal shell"
```

### Task 3: Overview, Location, and 30-Second Offer

**Files:**
- Replace: `driver_dashboard.php`
- Create: `js/driver_dashboard.js`
- Modify: `css/driver_style.css`
- Modify: `tests/driver_markup.test.js`

**Interfaces:**
- Consumes: `SavoraState.load()`, `SavoraRestaurantState.load()`, and `SavoraDriverState` offer/location APIs.
- Produces: online toggle, GPS/manual location controls, live summary cards, 30-second exclusive offer dialog, accept/decline navigation to `driver_delivery.php`.

- [ ] **Step 1: Add failing Overview markup tests**

Assert one main landmark and these hooks:

```js
for (const hook of [
  'data-driver-availability',
  'data-driver-location',
  'data-use-driver-gps',
  'data-enter-driver-address',
  'data-driver-map',
  'data-driver-summary',
  'data-delivery-offer',
  'data-offer-countdown',
  'data-accept-offer',
  'data-decline-offer'
]) assert.match(page, new RegExp(hook));
assert.doesNotMatch(page, /\son[a-z]+\s*=/i);
assert.doesNotMatch(page, /href\s*=\s*["']#["']/i);
```

- [ ] **Step 2: Run the focused test and verify failure**

```powershell
node --test tests/driver_markup.test.js --test-name-pattern="Overview"
```

Expected: FAIL because the old static dashboard lacks the hooks and shared shell.

- [ ] **Step 3: Replace the static dashboard with semantic mockup-aligned markup**

Use shared header/footer and include:

```php
<main id="driver-main" class="driver-main" data-driver-page="overview">
  <header class="driver-page-heading">
    <div><p class="driver-eyebrow">Driver overview</p><h1>Good afternoon, <span data-driver-first-name>Driver</span></h1></div>
    <button type="button" class="driver-availability" data-driver-availability aria-pressed="false">Offline</button>
  </header>
  <section class="driver-overview-grid">
    <div>
      <article class="driver-card" data-driver-location></article>
      <div class="driver-map" data-driver-map role="img" aria-label="Map showing the driver's current service area"></div>
      <section class="driver-kpi-grid" data-driver-summary></section>
    </div>
    <aside class="driver-card driver-offer" data-delivery-offer aria-live="polite"></aside>
  </section>
</main>
```

Add a labelled manual-address dialog and keep it English-only.

- [ ] **Step 4: Implement Overview behavior**

`driver_dashboard.js` must:

1. Toggle and persist availability.
2. Use `navigator.geolocation.getCurrentPosition` only after the driver presses “Use GPS”.
3. Save manual address only after non-empty validation.
4. Call `expireOffer` before `createOffer`.
5. Update the countdown from `expiresAt - Date.now()` once per second.
6. Accept by persisting both returned driver state and customer state, then navigate to `driver_delivery.php`.
7. Decline by persisting driver state, announcing reassignment, and rendering the no-offer/searching state.
8. Render full restaurant, pickup address, customer, drop-off address, item list, distance, earnings, and payment details from the offer snapshot.

- [ ] **Step 5: Run Overview and state tests**

```powershell
node --test tests/driver_markup.test.js tests/driver_state.test.js
```

Expected: PASS.

- [ ] **Step 6: Commit Overview**

```powershell
git add driver_dashboard.php js/driver_dashboard.js css/driver_style.css tests/driver_markup.test.js
git commit -m "feat: build driver overview and offers"
```

### Task 4: Active Delivery Workflow

**Files:**
- Create: `driver_delivery.php`
- Create: `js/driver_delivery.js`
- Modify: `css/driver_style.css`
- Modify: `tests/driver_markup.test.js`
- Modify: `tests/driver_state.test.js`

**Interfaces:**
- Consumes: `SavoraDriverState.activeDelivery()` and `updateMilestone()`.
- Produces: route map, pickup/drop-off cards, item/payment/note details, and exactly one valid next-milestone action.

- [ ] **Step 1: Add failing delivery-page tests**

```js
for (const hook of [
  'data-active-delivery',
  'data-delivery-map',
  'data-delivery-timeline',
  'data-pickup-details',
  'data-customer-details',
  'data-delivery-items',
  'data-delivery-payment',
  'data-delivery-primary-action',
  'data-report-issue'
]) assert.match(page, new RegExp(hook));
```

Add state assertions that `assigned -> arrived -> picked_up -> delivered` is allowed and skipped/repeated transitions throw.

- [ ] **Step 2: Run focused tests and verify failure**

```powershell
node --test tests/driver_markup.test.js tests/driver_state.test.js --test-name-pattern="delivery|milestone"
```

Expected: FAIL because the route and milestone UI do not exist.

- [ ] **Step 3: Create the Active Delivery page**

Create a responsive two-column layout with map/route on the left and timeline/details/actions on the right. The empty state must say “No active delivery” and link back to Overview.

- [ ] **Step 4: Implement milestone rendering and actions**

Use this action map:

```js
const nextAction = {
  assigned: { milestone: 'arrived', label: 'Confirm arrival' },
  arrived: { milestone: 'picked_up', label: 'Confirm pickup' },
  picked_up: { milestone: 'delivered', label: 'Confirm delivery' }
};
```

On successful updates, persist driver and customer states together, announce the new state, and re-render. “Open in Maps”, “Call customer”, and “Report an issue” must be valid buttons/links with safe URLs; no placeholder `href="#"`.

- [ ] **Step 5: Run focused tests**

```powershell
node --test tests/driver_markup.test.js tests/driver_state.test.js
```

Expected: PASS.

- [ ] **Step 6: Commit Active Delivery**

```powershell
git add driver_delivery.php js/driver_delivery.js css/driver_style.css tests/driver_markup.test.js tests/driver_state.test.js
git commit -m "feat: add active driver delivery workflow"
```

### Task 5: Delivery History and Detail Drawer

**Files:**
- Create: `driver_history.php`
- Create: `js/driver_history.js`
- Modify: `css/driver_style.css`
- Modify: `tests/driver_markup.test.js`

**Interfaces:**
- Consumes: `SavoraDriverState.deriveHistory(state)`.
- Produces: summary KPIs, text/date/status filters, accessible table/cards, selected delivery drawer, and client-side CSV export.

- [ ] **Step 1: Add failing History tests**

Require hooks for summary, search, date, status, results, drawer, close control, and export. Require a `<caption>` and labelled filter controls.

- [ ] **Step 2: Run the focused test and verify failure**

```powershell
node --test tests/driver_markup.test.js --test-name-pattern="History"
```

Expected: FAIL because the page does not exist.

- [ ] **Step 3: Build History markup**

Use the mockup columns: Order, Date, Restaurant, Customer, Route, Status, Earnings, Action. Add a responsive card representation for narrow screens and a dialog/drawer labelled “Delivery details”.

- [ ] **Step 4: Implement filters, drawer, and export**

Filter normalized records only; create rows with `SavoraDriverUI.el`; use `URL.createObjectURL(new Blob(...))` for local CSV export and revoke the URL immediately after download.

- [ ] **Step 5: Run markup and state tests**

```powershell
node --test tests/driver_markup.test.js tests/driver_state.test.js
```

Expected: PASS.

- [ ] **Step 6: Commit History**

```powershell
git add driver_history.php js/driver_history.js css/driver_style.css tests/driver_markup.test.js
git commit -m "feat: add driver delivery history"
```

### Task 6: Earnings and COD Reconciliation

**Files:**
- Create: `driver_earnings.php`
- Create: `js/driver_earnings.js`
- Modify: `css/driver_style.css`
- Modify: `tests/driver_markup.test.js`
- Modify: `tests/driver_state.test.js`

**Interfaces:**
- Consumes: `SavoraDriverState.deriveEarnings(state)`.
- Produces: weekly KPIs, CSS bar chart, next-payout card, COD balance, recent earnings table, and printable local statement.

- [ ] **Step 1: Add failing earnings derivation and markup tests**

```js
test('earnings include completed deliveries only and reconcile COD separately', () => {
  const summary = DriverState.deriveEarnings(DriverState.normalize({ deliveries: [
    { orderId: 'done', status: 'delivered', earnings: 6.8, paymentMethod: 'cash', orderTotal: 24.5, deliveredAt: '2026-07-30T12:00:00.000Z' },
    { orderId: 'active', status: 'picked_up', earnings: 99, paymentMethod: 'cash', orderTotal: 99 }
  ] }));
  assert.equal(summary.total, 6.8);
  assert.equal(summary.codCollected, 24.5);
});
```

Require page hooks for KPIs, chart, payout, cash balance, statement action, and table.

- [ ] **Step 2: Run focused tests and verify failure**

```powershell
node --test tests/driver_state.test.js tests/driver_markup.test.js --test-name-pattern="earning|Earning"
```

Expected: FAIL until derivation and page hooks exist.

- [ ] **Step 3: Build the Earnings page**

Match the approved mockup hierarchy and render charts with semantic labels plus CSS bars; do not add a chart dependency.

- [ ] **Step 4: Implement earnings rendering and local statement**

Use completed driver deliveries only. Keep “Next payout” and settlement language clearly marked as local preview data. Use `window.print()` for “Download statement” and never claim that a server-generated financial document exists.

- [ ] **Step 5: Run tests**

```powershell
node --test tests/driver_state.test.js tests/driver_markup.test.js
```

Expected: PASS.

- [ ] **Step 6: Commit Earnings**

```powershell
git add driver_earnings.php js/driver_earnings.js css/driver_style.css tests/driver_markup.test.js tests/driver_state.test.js
git commit -m "feat: add driver earnings dashboard"
```

### Task 7: Driver Profile, GPS, Vehicle, Documents, and Preferences

**Files:**
- Create: `driver_profile.php`
- Create: `js/driver_profile.js`
- Modify: `css/driver_style.css`
- Modify: `tests/driver_markup.test.js`
- Modify: `tests/driver_state.test.js`

**Interfaces:**
- Consumes: driver profile/location/preferences setters.
- Produces: validated profile, vehicle, document, current-location/manual-address, service-area, notification, COD, and route preferences.

- [ ] **Step 1: Add failing profile normalization and markup tests**

Verify setters preserve only allowlisted fields, coordinates stay within latitude/longitude bounds, service radius stays between `0.1` and `50`, and the page has labelled forms for all mockup sections.

- [ ] **Step 2: Run focused tests and verify failure**

```powershell
node --test tests/driver_state.test.js tests/driver_markup.test.js --test-name-pattern="profile|location|Profile"
```

Expected: FAIL until the setters and page exist.

- [ ] **Step 3: Build Profile & Settings markup**

Include Personal information, Vehicle, Documents, Location & service area, Delivery preferences, and Account. Use real buttons and forms; “Sign out” links to `logout.php`.

- [ ] **Step 4: Implement safe editing and geolocation**

Validate required fields, use explicit edit/save/cancel states, request geolocation only from the GPS button, allow manual address entry, announce errors/success, and persist preferences. Avoid claiming actual document verification beyond local demo labels.

- [ ] **Step 5: Run tests**

```powershell
node --test tests/driver_state.test.js tests/driver_markup.test.js
```

Expected: PASS.

- [ ] **Step 6: Commit Profile**

```powershell
git add driver_profile.php js/driver_profile.js css/driver_style.css tests/driver_markup.test.js tests/driver_state.test.js
git commit -m "feat: add driver profile and location settings"
```

### Task 8: Customer and Restaurant Driver Visibility

**Files:**
- Modify: `components/customer_footer.php`
- Modify: `components/restaurant_footer.php`
- Modify: `customer_dashboard.php`
- Modify: `customer_history.php`
- Modify: `js/restaurant_orders.js`
- Modify: `tests/customer_markup.test.js`
- Modify: `tests/restaurant_markup.test.js`
- Modify: `tests/restaurant_state.test.js`

**Interfaces:**
- Consumes: `SavoraDriverState.deliveryForOrder(state, orderId)`.
- Produces: customer-visible “Searching for driver”/assigned/on-the-way details and restaurant-visible dispatch status without restaurant-owned dispatch buttons.

- [ ] **Step 1: Add failing cross-role tests**

```js
assert.match(customerFooter, /js\/driver_state\.js/);
assert.match(restaurantFooter, /js\/driver_state\.js/);
assert.doesNotMatch(restaurantOrders, /Hand off order|Complete handoff|dispatch:\s*'on_the_way'|complete:\s*'completed'/);
assert.match(restaurantOrders, /deliveryForOrder/);
assert.match(customerHistory, /deliveryForOrder/);
```

- [ ] **Step 2: Run focused customer/restaurant tests and verify failure**

```powershell
node --test tests/customer_markup.test.js tests/restaurant_markup.test.js tests/restaurant_state.test.js
```

Expected: FAIL on missing driver integration and old restaurant transitions.

- [ ] **Step 3: Load driver state in both portals**

Load `js/driver_state.js` after `customer_state.js` and before the page code reads it. Preserve existing script ordering and all current catalog/UI dependencies.

- [ ] **Step 4: Remove restaurant dispatch/complete controls**

For `ready_for_pickup`, render dispatch information:

```js
const delivery = root.SavoraDriverState
  ? root.SavoraDriverState.deliveryForOrder(root.SavoraDriverState.load(), order.id)
  : null;
const dispatchCopy = delivery
  ? `${delivery.driverName} · ${delivery.status.replace('_', ' ')}`
  : 'Searching for an available driver';
panel.append(ui().el('section', { className: 'restaurant-dispatch-status' }, [
  heading('h3', 'Driver dispatch'),
  ui().el('p', {}, dispatchCopy)
]));
```

Keep restaurant actions only for reject, accept, prepare, and ready.

- [ ] **Step 5: Add customer assignment and live driver details**

On customer tracking/history, render:

- “Searching for a nearby driver” while the order is ready without an assignment.
- Assigned driver name, vehicle, and masked phone after acceptance.
- Driver milestone copy while retaining the existing shared order progress.
- Driver coordinates on the customer map only when a delivery exists; otherwise keep the degraded fallback.

- [ ] **Step 6: Run all state and markup tests**

```powershell
node --test tests/customer_state.test.js tests/restaurant_state.test.js tests/driver_state.test.js tests/customer_markup.test.js tests/restaurant_markup.test.js tests/driver_markup.test.js
```

Expected: all tests PASS.

- [ ] **Step 7: Commit cross-role integration**

```powershell
git add components/customer_footer.php components/restaurant_footer.php customer_dashboard.php customer_history.php js/restaurant_orders.js tests/customer_markup.test.js tests/restaurant_markup.test.js tests/restaurant_state.test.js
git commit -m "feat: connect driver updates across portals"
```

### Task 9: Browser QA, Responsive Review, and Final Verification

**Files:**
- Create: `tests/driver_browser_qa.mjs`
- Modify: `css/driver_style.css`
- Modify: driver PHP/JS files only when QA exposes a concrete defect.

**Interfaces:**
- Consumes: locally served Savora routes and seeded localStorage fixtures.
- Produces: automated desktop/mobile smoke coverage for all five pages and the dispatch-to-delivery flow.

- [ ] **Step 1: Write browser QA before final polish**

Follow the existing Chrome DevTools Protocol harness. Seed a ready order and restaurant profile in localStorage, then assert:

1. Driver authentication fixture opens all five routes.
2. Mobile and desktop layouts have no horizontal overflow.
3. Online creates a 30-second offer with restaurant/customer/item/address details.
4. Decline removes the offer without changing `ready_for_pickup`.
5. Accept creates one active delivery.
6. Arrival, pickup, and delivered controls appear in sequence.
7. Pickup changes the customer order to `on_the_way`.
8. Delivery changes it to `completed`.
9. History and earnings include the completed delivery.
10. Keyboard focus can open and close dialogs and reach all primary controls.

- [ ] **Step 2: Run the browser QA and capture the first failures**

Run:

```powershell
node tests/driver_browser_qa.mjs
```

Expected: initial FAIL only where real integration or responsive defects remain.

- [ ] **Step 3: Fix only observed QA defects**

Keep fixes scoped to overflow, focus, copy, state synchronization, and mockup alignment. Do not add a sixth page or unrelated feature.

- [ ] **Step 4: Run the full automated suite**

```powershell
node --test tests/*.test.js
node tests/driver_browser_qa.mjs
```

Expected: all unit/markup tests PASS and driver browser QA reports zero failures.

- [ ] **Step 5: Perform visual comparison**

At desktop and mobile widths, compare each route to its corresponding image in `docs/mockups/driver-portal/`. Verify palette, spacing, hierarchy, English copy, focus visibility, empty states, active states, and readable pickup/drop-off addresses.

- [ ] **Step 6: Review the final diff and working tree**

```powershell
git diff --check
git status --short
git diff --stat
```

Expected: no whitespace errors; only driver portal and targeted cross-role files are changed. Existing unrelated customer-address documents remain untouched.

- [ ] **Step 7: Commit final QA fixes**

```powershell
git add tests/driver_browser_qa.mjs css/driver_style.css driver_*.php js/driver_*.js
git commit -m "test: verify driver portal experience"
```
