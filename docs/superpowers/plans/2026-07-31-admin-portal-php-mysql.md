# Savora Admin Portal PHP/MySQL Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build eleven English Admin pages that match the approved mockups and operate on shared PHP/MySQL data with controlled actions, audit records, notifications, and cross-role state consistency.

**Architecture:** Keep the existing flat PHP application and add focused procedural modules for schema migration, security, Admin queries, and domain commands. Pages remain server rendered and progressively enhanced by one shared Admin UI controller; all state changes flow through a CSRF-protected action endpoint and MySQL transactions.

**Tech Stack:** PHP 8 / mysqli, MySQL on XAMPP, HTML5, CSS3, vanilla JavaScript, local Font Awesome, Node test runner, PHP CLI assertions, Playwright-compatible browser QA.

## Global Constraints

- Source of truth: `docs/superpowers/specs/2026-07-31-admin-portal-php-mysql-design.md`.
- Visual references: `docs/mockups/admin-portal/01-admin-overview.png` through `11-settings-audit.png`.
- Exactly eleven top-level Admin routes and English UI copy only.
- Palette: `#073B2B`, `#04291E`, `#EF634B`, `#FBF9F3`, `#E8EDDF`, `#1C2923`, `#657169`, `#DFE4DA`, and focus `#1B75D0`.
- Existing Customer, Restaurant, Driver, and Admin demo logins remain valid.
- No framework, Composer package, remote CSS, remote JavaScript, or remote image dependency.
- MySQL is authoritative for accounts, applications, profiles, orders, dispatch, finance, cases, notifications, settings, and audit.
- State-changing requests require Admin role, CSRF token, prepared statements, and idempotency for sensitive actions.
- Persisted values render through centralized escaping; no persisted value is interpolated into `innerHTML` or inline handlers.
- Details use drawers/dialogs and do not create a twelfth top-level page.

---

### Task 1: Shared database, migration, seed, and security foundation

**Files:**
- Create: `lib/platform_schema.php`
- Create: `lib/admin_security.php`
- Create: `lib/admin_repository.php`
- Create: `lib/admin_actions.php`
- Create: `tests/admin_schema_test.php`
- Create: `tests/admin_security.test.js`
- Modify: `db.php`
- Modify: `auth.php`

**Interfaces:**
- Produces: `platform_migrate(mysqli $conn): void`, `platform_seed(mysqli $conn): void`, `admin_escape(mixed $value): string`, `admin_csrf_token(): string`, `admin_verify_csrf(string $token): bool`, `admin_require_role(): void`, `admin_page_data(mysqli $conn, string $page, array $filters = []): array`, and `admin_execute_action(mysqli $conn, string $action, array $payload, int $actorId, string $idempotencyKey): array`.
- Consumes: the existing `$conn` mysqli connection and PHP session identity.

- [ ] **Step 1: Write failing schema and security tests**

Create `tests/admin_schema_test.php` to set `SAVORA_DB_NAME=savora_test`, require `db.php`, assert every approved table exists through `information_schema.tables`, run `platform_migrate()` and `platform_seed()` twice, and assert demo usernames remain unique. Create `tests/admin_security.test.js` to assert `db.php` honors `SAVORA_DB_NAME`, `auth.php` blocks non-active users, security helpers use `hash_equals`, and Admin SQL modules contain `prepare(` rather than interpolated request values.

- [ ] **Step 2: Run tests and confirm the red state**

Run:

```powershell
$env:SAVORA_DB_NAME='savora_test'; D:\Xampp\php\php.exe tests\admin_schema_test.php
node --test tests\admin_security.test.js
```

Expected: FAIL because the platform modules and tables do not exist.

- [ ] **Step 3: Implement the schema boundary**

Make `db.php` read `SAVORA_DB_NAME`, require `lib/platform_schema.php`, then call:

```php
platform_migrate($conn);
platform_seed($conn);
```

Create idempotent tables and safe demo records for identity, applications, profiles, orders, dispatch, cases, ledger, refunds, payouts, promotions, fee rules, service areas, settings, notifications, sessions, idempotency, and audit. Alter the existing `users` table additively with `email`, `phone`, `status`, `session_version`, `last_login_at`, `updated_at`, and `version`.

- [ ] **Step 4: Implement shared security and command/query contracts**

Implement session guard, CSRF, output escaping, request metadata, idempotency lookup, transaction wrapper, audit append, notification append, and the function signatures listed above. Update `auth.php` to reject `suspended` and `blocked` accounts before creating the authenticated session.

- [ ] **Step 5: Run foundation verification**

Run:

```powershell
$env:SAVORA_DB_NAME='savora_test'; D:\Xampp\php\php.exe tests\admin_schema_test.php
node --test tests\admin_security.test.js
D:\Xampp\php\php.exe -l db.php
D:\Xampp\php\php.exe -l auth.php
```

Expected: schema test PASS, security tests PASS, and both PHP files report no syntax errors.

- [ ] **Step 6: Commit**

```powershell
git add db.php auth.php lib/platform_schema.php lib/admin_security.php lib/admin_repository.php lib/admin_actions.php tests/admin_schema_test.php tests/admin_security.test.js
git commit -m "feat: add admin mysql foundation"
```

### Task 2: Admin shell, design system, and interaction toolkit

**Files:**
- Create: `components/admin_header.php`
- Create: `components/admin_footer.php`
- Create: `css/admin_style.css`
- Create: `js/admin_ui.js`
- Create: `tests/admin_markup.test.js`
- Create: `tests/admin_ui.test.js`
- Modify: `admin_dashboard.php`

**Interfaces:**
- Consumes: `admin_require_role()`, `admin_escape()`, and `admin_csrf_token()` from Task 1.
- Produces: eleven-route navigation, `SavoraAdminUI.openDrawer()`, `closeDrawer()`, `openDialog()`, `closeDialog()`, `showToast()`, `applyTableFilter()`, `requestAction()`, `formatMoney()`, and shared semantic components.

- [ ] **Step 1: Write failing shell and UI tests**

Assert the header exposes exactly the eleven approved routes, local Font Awesome, skip link, global search, notification control, Admin Mode badge, avatar, mobile navigation dialog, and `aria-current`. Assert the footer exposes toast, drawer and confirmation dialog roots. Assert `admin_ui.js` avoids `innerHTML`, restores dialog focus, supports Escape, uses `fetch`, sends CSRF and idempotency headers, and renders field errors with DOM APIs.

- [ ] **Step 2: Run tests and confirm failure**

Run:

```powershell
node --test tests\admin_markup.test.js tests\admin_ui.test.js
```

Expected: FAIL because the shared Admin shell and controller do not exist.

- [ ] **Step 3: Implement shared Admin markup**

Use the route map:

```php
$admin_routes = [
  'admin_dashboard.php' => ['Overview', 'fa-house'],
  'admin_accounts.php' => ['Accounts', 'fa-user-shield'],
  'admin_customers.php' => ['Customers', 'fa-users'],
  'admin_restaurants.php' => ['Restaurants', 'fa-store'],
  'admin_drivers.php' => ['Drivers', 'fa-motorcycle'],
  'admin_orders.php' => ['Orders', 'fa-bag-shopping'],
  'admin_cases.php' => ['Cases & Refunds', 'fa-shield-heart'],
  'admin_finance.php' => ['Finance', 'fa-circle-dollar-to-slot'],
  'admin_promotions.php' => ['Promotions & Fees', 'fa-tags'],
  'admin_analytics.php' => ['Analytics', 'fa-chart-column'],
  'admin_settings.php' => ['Settings & Audit', 'fa-gears'],
];
```

Render the shared sidebar/top bar/mobile dialog and shared footer overlays. Replace the existing static `admin_dashboard.php` shell with header/footer includes and one `<main id="admin-main">` landmark.

- [ ] **Step 4: Implement the mockup-matched CSS and safe interactions**

Define the approved variables, 240px desktop sidebar, compact top bar, 12-column content grid, KPI cards, data tables, charts, tabs, badges, alerts, drawer, modal, toast, skeleton, empty/error states, and responsive breakpoints at 1200px, 900px, 768px, and 480px. Implement focus-visible `#1B75D0`, reduced-motion handling, scroll-safe tables, card conversion, focus trapping, outside-click close, Escape, URL-backed filters, and safe action submission.

- [ ] **Step 5: Verify and commit**

Run:

```powershell
node --test tests\admin_markup.test.js tests\admin_ui.test.js
D:\Xampp\php\php.exe -l components\admin_header.php
D:\Xampp\php\php.exe -l components\admin_footer.php
D:\Xampp\php\php.exe -l admin_dashboard.php
node --check js\admin_ui.js
```

Expected: all tests PASS and syntax checks succeed.

```powershell
git add components/admin_header.php components/admin_footer.php css/admin_style.css js/admin_ui.js admin_dashboard.php tests/admin_markup.test.js tests/admin_ui.test.js
git commit -m "feat: add admin portal shell"
```

### Task 3: Overview, Analytics, and Settings & Audit pages

**Files:**
- Create: `admin_analytics.php`
- Create: `admin_settings.php`
- Create: `tests/admin_insights.test.js`
- Modify: `admin_dashboard.php`
- Modify: `lib/admin_repository.php`
- Modify: `lib/admin_actions.php`
- Modify: `css/admin_style.css`

**Interfaces:**
- Consumes: shared shell and `admin_page_data()`.
- Produces: `overview`, `analytics`, and `settings` query datasets plus `update_setting` and `update_notification_template` commands.

- [ ] **Step 1: Write failing page-contract tests**

Assert page titles, mockup hooks, KPI names, chart accessibility labels, date/filter controls, export controls, settings tabs, notification templates, security controls, immutable audit table, and shared header/footer includes. Assert no hard-coded legacy Admin sample rows remain.

- [ ] **Step 2: Run the targeted test**

```powershell
node --test tests\admin_insights.test.js
```

Expected: FAIL because two routes and required hooks are missing.

- [ ] **Step 3: Implement MySQL queries and settings commands**

Add grouped queries for current orders, approvals, ledger totals, alerts, analytics ranges, Restaurant/Driver performance, retention, platform settings, notification templates, and audit history. Validate allowed setting keys and append versioned audit records for every update.

- [ ] **Step 4: Implement the three mockup-matched pages**

Render Overview with four KPIs, live operations, approval queue, trend/status charts, alerts, and recent activity. Render Analytics with filters, KPI cards, accessible CSS/SVG charts and performance tables. Render Settings & Audit with four setting cards, notification/security panels, filters, and immutable audit table.

- [ ] **Step 5: Verify and commit**

```powershell
node --test tests\admin_insights.test.js tests\admin_markup.test.js tests\admin_ui.test.js
D:\Xampp\php\php.exe -l admin_dashboard.php
D:\Xampp\php\php.exe -l admin_analytics.php
D:\Xampp\php\php.exe -l admin_settings.php
```

Expected: all targeted tests PASS and PHP lint succeeds.

```powershell
git add admin_dashboard.php admin_analytics.php admin_settings.php lib/admin_repository.php lib/admin_actions.php css/admin_style.css tests/admin_insights.test.js
git commit -m "feat: add admin insights and settings"
```

### Task 4: Accounts and Customer Management

**Files:**
- Create: `admin_accounts.php`
- Create: `admin_customers.php`
- Create: `admin_action.php`
- Create: `tests/admin_identity.test.js`
- Modify: `lib/admin_repository.php`
- Modify: `lib/admin_actions.php`
- Modify: `js/admin_ui.js`

**Interfaces:**
- Produces: accounts/customer list and drawer queries; commands `suspend_account`, `reactivate_account`, `block_account`, `revoke_sessions`, and `reset_password`.
- Consumes: shared JSON contract `{ok,message,data?,errors?,referenceId?}`.

- [ ] **Step 1: Write failing identity page tests**

Assert mockup headings, role/status/date filters, account and Customer tables, masked data, security/session history, wallet ledger, drawer semantics, and confirmation reason fields. Assert no direct wallet-balance input exists.

- [ ] **Step 2: Run the targeted test**

```powershell
node --test tests\admin_identity.test.js
```

Expected: FAIL because routes and action endpoint do not exist.

- [ ] **Step 3: Implement account/customer queries and commands**

Paginate with allowlisted sort keys. Mask contact and finance identifiers. Commands lock the user row, enforce version and current status, append `account_status_history`, increment `session_version` when revoking/suspending, append audit and notification, and return the updated drawer record.

- [ ] **Step 4: Implement both pages and action endpoint**

Match the Accounts and Customers mockups with summary cards, data table, selected drawer, security history, Customer tabs, immutable ledger, contextual actions, confirmation dialog, inline validation, and toast updates.

- [ ] **Step 5: Verify and commit**

```powershell
node --test tests\admin_identity.test.js tests\admin_ui.test.js tests\admin_security.test.js
D:\Xampp\php\php.exe -l admin_accounts.php
D:\Xampp\php\php.exe -l admin_customers.php
D:\Xampp\php\php.exe -l admin_action.php
```

Expected: tests PASS and PHP lint succeeds.

```powershell
git add admin_accounts.php admin_customers.php admin_action.php lib/admin_repository.php lib/admin_actions.php js/admin_ui.js tests/admin_identity.test.js
git commit -m "feat: add admin identity management"
```

### Task 5: Restaurant and Driver approval workflows

**Files:**
- Create: `admin_restaurants.php`
- Create: `admin_drivers.php`
- Create: `tests/admin_approvals_test.php`
- Create: `tests/admin_partners.test.js`
- Modify: `lib/admin_repository.php`
- Modify: `lib/admin_actions.php`

**Interfaces:**
- Produces: application and active-partner queries; commands `approve_restaurant`, `request_restaurant_changes`, `reject_restaurant`, `approve_driver`, `request_driver_changes`, `reject_driver`, `set_restaurant_status`, and `set_driver_eligibility`.

- [ ] **Step 1: Write failing workflow and markup tests**

PHP tests submit deterministic applications, approve them, assert exactly one created account/profile, retry with the same idempotency key, reject incomplete/stale applications, and verify credential hash removal. Node tests assert the four tabs, application queues, document review panels, expiry/eligibility warnings, reviewer notes, and exact action labels.

- [ ] **Step 2: Run tests and confirm failure**

```powershell
$env:SAVORA_DB_NAME='savora_test'; D:\Xampp\php\php.exe tests\admin_approvals_test.php
node --test tests\admin_partners.test.js
```

Expected: FAIL because approval commands and pages are missing.

- [ ] **Step 3: Implement transactional approvals**

Lock application rows; validate state, version, documents and uniqueness; create the user and profile; clear application password hash; record decision; append audit and notification; commit once. Request changes and rejection never create a user. Driver document expiration updates eligibility.

- [ ] **Step 4: Implement both mockup pages**

Render pending/active/alert/suspended tabs, SLA KPIs, application queues, detailed document panels, notes, safeguard copy, and approval actions. Use the shared confirmation and field-error mechanisms.

- [ ] **Step 5: Verify and commit**

```powershell
$env:SAVORA_DB_NAME='savora_test'; D:\Xampp\php\php.exe tests\admin_approvals_test.php
node --test tests\admin_partners.test.js tests\admin_identity.test.js
D:\Xampp\php\php.exe -l admin_restaurants.php
D:\Xampp\php\php.exe -l admin_drivers.php
```

Expected: all tests PASS.

```powershell
git add admin_restaurants.php admin_drivers.php lib/admin_repository.php lib/admin_actions.php tests/admin_approvals_test.php tests/admin_partners.test.js
git commit -m "feat: add partner approval workflows"
```

### Task 6: Orders, dispatch, and controlled intervention

**Files:**
- Create: `admin_orders.php`
- Create: `tests/admin_orders_test.php`
- Create: `tests/admin_orders.test.js`
- Modify: `lib/admin_repository.php`
- Modify: `lib/admin_actions.php`

**Interfaces:**
- Produces: live/attention/history order queries; commands `reassign_driver`, `cancel_order`, and `open_incident`.
- Consumes: shared order and dispatch tables from Task 1.

- [ ] **Step 1: Write failing order tests**

Assert role-owned transitions, dispatch timeout/rejection without Customer-visible status change, reassign safeguards, mandatory reason, stale-version conflict, order/dispatch timeline markup, filters, KPIs, drawer, and action labels.

- [ ] **Step 2: Run tests and confirm failure**

```powershell
$env:SAVORA_DB_NAME='savora_test'; D:\Xampp\php\php.exe tests\admin_orders_test.php
node --test tests\admin_orders.test.js
```

Expected: FAIL because order intervention is missing.

- [ ] **Step 3: Implement order/dispatch queries and commands**

Lock order and dispatch records, validate legal current state and version, clear or create assignment records as required, append status/dispatch history, audit and notifications, and never accept an arbitrary target status from the client.

- [ ] **Step 4: Implement Orders & Dispatch UI**

Match the mockup with live/attention/history tabs, filters, four KPIs, dense table, order drawer, item/payment summary, role-attributed order timeline, dispatch attempts, and three controlled actions.

- [ ] **Step 5: Verify and commit**

```powershell
$env:SAVORA_DB_NAME='savora_test'; D:\Xampp\php\php.exe tests\admin_orders_test.php
node --test tests\admin_orders.test.js tests\admin_ui.test.js
D:\Xampp\php\php.exe -l admin_orders.php
```

Expected: all tests PASS.

```powershell
git add admin_orders.php lib/admin_repository.php lib/admin_actions.php tests/admin_orders_test.php tests/admin_orders.test.js
git commit -m "feat: add admin order operations"
```

### Task 7: Cases, refunds, finance, and reconciliation

**Files:**
- Create: `admin_cases.php`
- Create: `admin_finance.php`
- Create: `tests/admin_finance_test.php`
- Create: `tests/admin_cases_finance.test.js`
- Modify: `lib/admin_repository.php`
- Modify: `lib/admin_actions.php`

**Interfaces:**
- Produces: case, ledger, payout, COD and refund queries; commands `request_case_information`, `resolve_case`, `issue_refund`, `hold_payout`, `release_payout`, `retry_payout`, `append_adjustment`, and `settle_cod`.

- [ ] **Step 1: Write failing finance integrity and markup tests**

PHP tests assert refund limits, idempotent retry, partial refund preserves completed fulfillment status, full refund sets refunded, compensating ledger entries, settlement impact, and immutable balance derivation. Node tests assert SLA queue, four-role timeline, evidence metadata, resolution form, finance tabs, ledger, payout batch, COD panel, and no balance-edit control.

- [ ] **Step 2: Run tests and confirm failure**

```powershell
$env:SAVORA_DB_NAME='savora_test'; D:\Xampp\php\php.exe tests\admin_finance_test.php
node --test tests\admin_cases_finance.test.js
```

Expected: FAIL because case/refund/finance commands and pages are missing.

- [ ] **Step 3: Implement case and finance transactions**

Lock payment/order/case/ledger rows, derive remaining refundable amount, append refund and compensating ledger entries, update payout/COD state, append case messages, audit and notifications, and commit atomically.

- [ ] **Step 4: Implement both mockup pages**

Cases uses a three-column queue/conversation/resolution workspace with priority/SLA, attachments, refund impact and notification recipients. Finance uses tabs, four KPIs, immutable ledger, payout batch, COD reconciliation and integrity banner.

- [ ] **Step 5: Verify and commit**

```powershell
$env:SAVORA_DB_NAME='savora_test'; D:\Xampp\php\php.exe tests\admin_finance_test.php
node --test tests\admin_cases_finance.test.js tests\admin_orders.test.js
D:\Xampp\php\php.exe -l admin_cases.php
D:\Xampp\php\php.exe -l admin_finance.php
```

Expected: all tests PASS.

```powershell
git add admin_cases.php admin_finance.php lib/admin_repository.php lib/admin_actions.php tests/admin_finance_test.php tests/admin_cases_finance.test.js
git commit -m "feat: add admin cases and finance"
```

### Task 8: Promotions, fee rules, and service areas

**Files:**
- Create: `admin_promotions.php`
- Create: `tests/admin_promotions_test.php`
- Create: `tests/admin_promotions.test.js`
- Modify: `lib/admin_repository.php`
- Modify: `lib/admin_actions.php`

**Interfaces:**
- Produces: promotion/fee/service-area queries; commands `save_promotion`, `pause_promotion`, `schedule_fee_rule`, and `set_service_area_status`.

- [ ] **Step 1: Write failing rule and markup tests**

Assert promotion validation, usage schedule, duplicate code prevention, future-effective fee versioning, no retroactive mutation, service-area state, four page tabs, Customer checkout preview, fee cards, and audit note.

- [ ] **Step 2: Run tests and confirm failure**

```powershell
$env:SAVORA_DB_NAME='savora_test'; D:\Xampp\php\php.exe tests\admin_promotions_test.php
node --test tests\admin_promotions.test.js
```

Expected: FAIL because rules and page are missing.

- [ ] **Step 3: Implement rule queries and commands**

Validate code, audience, value, cap, dates and scope; version fee rules with `effective_at`; append audit/notifications; and prevent edits to already-effective historical fee versions.

- [ ] **Step 4: Implement mockup page and verify**

Render four tabs, KPI cards, promotion table/editor, checkout preview, fee cards, service-area health and scheduling note.

```powershell
$env:SAVORA_DB_NAME='savora_test'; D:\Xampp\php\php.exe tests\admin_promotions_test.php
node --test tests\admin_promotions.test.js
D:\Xampp\php\php.exe -l admin_promotions.php
```

Expected: all tests PASS.

- [ ] **Step 5: Commit**

```powershell
git add admin_promotions.php lib/admin_repository.php lib/admin_actions.php tests/admin_promotions_test.php tests/admin_promotions.test.js
git commit -m "feat: add admin promotions and fees"
```

### Task 9: Cross-role MySQL bridge

**Files:**
- Create: `api/platform_state.php`
- Create: `js/platform_bridge.js`
- Create: `tests/admin_cross_role_test.php`
- Modify: `components/customer_footer.php`
- Modify: `components/restaurant_footer.php`
- Modify: `components/driver_footer.php`
- Modify: `js/customer_state.js`
- Modify: `js/restaurant_state.js`
- Modify: `js/driver_state.js`
- Modify: `customer_checkout.php`
- Modify: `restaurant_orders.php`
- Modify: `driver_dashboard.php`
- Modify: `driver_delivery.php`

**Interfaces:**
- Produces: authenticated GET snapshots and POST commands for Customer order placement, Restaurant preparation transitions, dispatch offer/acceptance, and Driver milestones.
- Consumes: the same order, dispatch, finance, audit and notification services used by Admin.

- [ ] **Step 1: Write failing cross-role integration tests**

Test approved partner login, Customer order creation, Restaurant visibility, ready-for-pickup dispatch, eligible Driver acceptance, Driver pickup/delivery, Admin-visible completion, refund propagation, Restaurant suspension discovery effect, and Driver suspension eligibility effect.

- [ ] **Step 2: Run test and confirm failure**

```powershell
$env:SAVORA_DB_NAME='savora_test'; D:\Xampp\php\php.exe tests\admin_cross_role_test.php
```

Expected: FAIL because the shared API bridge does not exist.

- [ ] **Step 3: Implement shared endpoint and progressive bridge**

Return role-scoped snapshots, accept allowlisted role-owned commands, validate CSRF/version/idempotency, and reuse domain commands. Keep local cart/UI preferences but replace authoritative order, wallet, Restaurant operations, dispatch and delivery persistence with MySQL responses.

- [ ] **Step 4: Update the three portals**

Load `js/platform_bridge.js` from each shared footer. Customer checkout writes MySQL orders; Restaurant Live Orders reads and advances those orders only through ready-for-pickup; Driver Overview/Active Delivery reads dispatch and owns pickup/delivery milestones. Preserve current empty, loading and offline-fallback messaging.

- [ ] **Step 5: Run full cross-role verification and commit**

```powershell
$env:SAVORA_DB_NAME='savora_test'; D:\Xampp\php\php.exe tests\admin_cross_role_test.php
node --test tests\customer_state.test.js tests\customer_markup.test.js tests\restaurant_state.test.js tests\restaurant_markup.test.js tests\driver_state.test.js tests\driver_markup.test.js
```

Expected: cross-role test PASS and all existing suites remain green.

```powershell
git add api/platform_state.php js/platform_bridge.js components/customer_footer.php components/restaurant_footer.php components/driver_footer.php js/customer_state.js js/restaurant_state.js js/driver_state.js customer_checkout.php restaurant_orders.php driver_dashboard.php driver_delivery.php tests/admin_cross_role_test.php
git commit -m "feat: connect admin data across portals"
```

### Task 10: Browser QA, accessibility, and final visual polish

**Files:**
- Create: `tests/admin_browser_qa.mjs`
- Create: `tests/admin_visual_contract.test.js`
- Modify: `css/admin_style.css`
- Modify: `js/admin_ui.js`
- Modify: `admin_dashboard.php`
- Modify: `admin_accounts.php`
- Modify: `admin_customers.php`
- Modify: `admin_restaurants.php`
- Modify: `admin_drivers.php`
- Modify: `admin_orders.php`
- Modify: `admin_cases.php`
- Modify: `admin_finance.php`
- Modify: `admin_promotions.php`
- Modify: `admin_analytics.php`
- Modify: `admin_settings.php`

**Interfaces:**
- Consumes: all eleven completed Admin routes and the approved mockups.
- Produces: screenshots/results at 1440px, 768px, and 320px plus final regression evidence.

- [ ] **Step 1: Write visual/accessibility contract tests**

Assert all eleven routes use the shared shell, one main landmark, page-specific title, English labels, local assets, responsive hooks, no inline event handlers, no `href="#"`, no unsafe dynamic HTML, and exact approved palette variables.

- [ ] **Step 2: Run contract test and fix only proven gaps**

```powershell
node --test tests\admin_visual_contract.test.js
```

Expected: PASS after correcting any reported markup or style mismatch.

- [ ] **Step 3: Run browser QA**

Authenticate as Admin, visit all eleven routes at 1440px, 768px, and 320px, verify no horizontal page overflow, capture screenshots, operate filters/tabs/drawers/dialogs, test keyboard focus/Escape, submit one validation failure, and execute seeded approval/order/refund interactions.

```powershell
node tests\admin_browser_qa.mjs
```

Expected: results JSON reports eleven routes, three viewports, zero overflow failures, and all interaction checks passing.

- [ ] **Step 4: Run final verification**

```powershell
Get-ChildItem -Filter '*.php' -Recurse | ForEach-Object { D:\Xampp\php\php.exe -l $_.FullName }
node --test tests\*.test.js
$env:SAVORA_DB_NAME='savora_test'; D:\Xampp\php\php.exe tests\admin_schema_test.php
$env:SAVORA_DB_NAME='savora_test'; D:\Xampp\php\php.exe tests\admin_approvals_test.php
$env:SAVORA_DB_NAME='savora_test'; D:\Xampp\php\php.exe tests\admin_orders_test.php
$env:SAVORA_DB_NAME='savora_test'; D:\Xampp\php\php.exe tests\admin_finance_test.php
$env:SAVORA_DB_NAME='savora_test'; D:\Xampp\php\php.exe tests\admin_promotions_test.php
$env:SAVORA_DB_NAME='savora_test'; D:\Xampp\php\php.exe tests\admin_cross_role_test.php
```

Expected: every PHP file lints, all Node tests pass, and all PHP integration tests pass.

- [ ] **Step 5: Commit**

```powershell
git add tests/admin_browser_qa.mjs tests/admin_visual_contract.test.js css/admin_style.css js/admin_ui.js admin_*.php
git commit -m "test: verify admin portal experience"
```
