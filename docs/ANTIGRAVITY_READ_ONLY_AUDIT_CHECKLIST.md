# Savora Read-Only Full-System Audit Plan

> **For Antigravity:** Execute this checklist strictly as a read-only tester. Do not implement fixes, edit files, create report artifacts, mutate API state, or change MySQL data. Return the complete report only in your final response.

**Goal:** Determine whether all four Savora roles, their 35 pages, shared workflows, PHP/API backend and MySQL data model are complete, consistent and ready for demo, UAT or production.

**Audit architecture:** Build an evidence-based inventory first, then trace every cross-role business flow from UI through JavaScript, PHP and MySQL. Separate confirmed runtime/static evidence from assumptions and mark every mutation-dependent check as `NOT EXECUTED — READ-ONLY RESTRICTION`.

**Tech stack:** PHP 8.x, MySQL, HTML5, CSS, vanilla JavaScript, Node.js tests, Font Awesome, Leaflet/GPS or simulated location data.

## Global constraints

- Source tree, Git state, configuration, packages, API data and MySQL data are immutable during this audit.
- The only output is Antigravity's final chat response; no report file may be created.
- Never run an authenticated or PHP route until its possible database/session side effects have been reviewed.
- Only `SELECT`, `SHOW`, `DESCRIBE` and read-only `information_schema` queries are permitted against MySQL.
- Do not run PHP integration tests that insert, update, delete, migrate or seed data.
- Do not call mutating HTTP methods or click state-changing controls.
- Every PASS requires evidence; every unverified item receives an explicit non-PASS status.
- English UI copy is expected across all four portals.
- Approved visual palette: `#073B2B`, `#04291E`, `#EF634B`, `#FBF9F3`, `#E8EDDF`, `#1C2923`, `#657169`, `#DFE4DA`, focus `#1B75D0`.

---

## Status vocabulary

Use exactly these statuses in audit matrices:

| Status | Meaning |
|---|---|
| `PASS` | Complete and supported by direct evidence. |
| `PARTIAL` | Some layers exist, but an interaction, persistence path or role consumer is incomplete. |
| `FAIL` | Required behavior is absent, contradictory or demonstrably broken. |
| `UNREACHABLE` | Code exists but no active UI/API route invokes it. |
| `NOT EXECUTED — READ-ONLY RESTRICTION` | Verification would require a prohibited write. |
| `NOT APPLICABLE` | Requirement legitimately does not apply; include the reason. |

## Phase 1 — Prove read-only starting state

- [ ] Record repository root, current branch and current commit with `git rev-parse --show-toplevel`, `git branch --show-current` and `git rev-parse HEAD`.
- [ ] Capture the exact initial output of `git status --short` in the report; treat pre-existing untracked files as user-owned.
- [ ] Run `git diff --stat`, `git diff --check` and `git log -10 --oneline --decorate` as read-only context.
- [ ] Inspect `db.php`, `auth.php`, `lib/platform_schema.php` and `lib/session_security.php` before starting any PHP server or authenticated browser session.
- [ ] Identify every startup side effect: database creation, migration, seed, `last_login_at`, `last_seen_at`, session insertion and audit insertion.
- [ ] If startup/login would write state, do not launch or log in; state the exact blocking side effect.
- [ ] List every command planned for execution and classify it `READ-ONLY SAFE`, `MUTATING — SKIP` or `UNCERTAIN — SKIP`.

Expected evidence: initial commit SHA, initial Git status, side-effect map and permitted-command list.

## Phase 2 — Build the 35-page inventory

For every route, record: file exists, shared shell, one main landmark, page heading, expected actions, action handler, data source, persistence target, related API, related tables, upstream role, downstream role, responsive evidence, accessibility evidence and status.

### Customer — 8 pages

- [ ] `customer_dashboard.php` — restaurant discovery, availability, categories, catalog and active-order visibility.
- [ ] `product_detail.php` — selected dish, portion/options/add-ons, allergens, quantity, favorites and add-to-cart.
- [ ] `customer_cart.php` — cart lines, quantities, single-Restaurant constraint, promo presentation and totals.
- [ ] `customer_checkout.php` — address, delivery note, payment selection, validation, authoritative order submission and success/error states.
- [ ] `customer_history.php` — order history, live status, reorder rules, tracking entry and issue/report entry.
- [ ] `customer_favorites.php` — Restaurant/dish tabs, independent toggles, empty states and persistence.
- [ ] `customer_profile.php` — profile fields, address ownership, validation and persistence truthfulness.
- [ ] `customer_wallet.php` — balance, top-up claims, transactions and consistency with MySQL payment/wallet records.

### Restaurant Owner — 11 pages

- [ ] `restaurant_dashboard.php` — KPIs, live queue, top items, alerts and accepting-orders state.
- [ ] `restaurant_orders.php` — new order visibility, accept/cancel/preparing/ready transitions and Customer/Driver effects.
- [ ] `restaurant_order_history.php` — completed/cancelled/refunded history, details and support follow-up.
- [ ] `restaurant_menu.php` — menu inventory, search/filter, availability toggles and Customer catalog propagation.
- [ ] `restaurant_menu_item.php` — create/edit/draft/publish, price, options, add-ons, validation and API synchronization.
- [ ] `restaurant_profile.php` — storefront name, cuisine, address, imagery, contact data and Customer-facing propagation.
- [ ] `restaurant_operations.php` — accepting orders, service modes, preparation time, radius and business/special hours.
- [ ] `restaurant_finance.php` — completed-order revenue, fees, refunds, payout data and immutable financial source.
- [ ] `restaurant_invoices.php` — invoice/payout statement views, filters, print/download claims and source data.
- [ ] `restaurant_analytics.php` — revenue/order/menu/kitchen metrics, filters and mathematical correctness.
- [ ] `restaurant_reviews.php` — Customer reviews, rating aggregates, response validation and visibility.

### Delivery Driver — 5 pages

- [ ] `driver_dashboard.php` — online/offline state, eligibility, location, offers, exclusivity, timeout, accept and decline.
- [ ] `driver_delivery.php` — active assignment, route, pickup/drop-off, parties, items and ordered milestone transitions.
- [ ] `driver_history.php` — completed/failed/cancelled deliveries, filters, detail and source consistency.
- [ ] `driver_earnings.php` — earnings, payout, bonuses, COD collected/due/settled and finance consistency.
- [ ] `driver_profile.php` — identity, vehicle, documents, service area, preferences, GPS/location and eligibility.

### Admin — 11 pages

- [ ] `admin_dashboard.php` — platform KPIs, live operations, approval queue, alerts and system health.
- [ ] `admin_accounts.php` — all roles, status, session/security interventions, reason, version and audit.
- [ ] `admin_customers.php` — Customer profile, orders, wallet insight, cases and non-editable balance integrity.
- [ ] `admin_restaurants.php` — applications, exact required documents, decision workflow and account creation.
- [ ] `admin_drivers.php` — applications, license/vehicle/background documents, expiry, eligibility and account creation.
- [ ] `admin_orders.php` — authoritative order timeline, payment, dispatch, reassignment, cancellation and incident creation.
- [ ] `admin_cases.php` — four-role conversation, evidence, SLA, information requests, resolution and refund.
- [ ] `admin_finance.php` — ledger, Restaurant/Driver payouts, refunds and COD reconciliation.
- [ ] `admin_promotions.php` — promotions, fees, service areas, effective dates and Customer checkout effects.
- [ ] `admin_analytics.php` — filters, KPI definitions, charts, tables, exports and query correctness.
- [ ] `admin_settings.php` — versioned platform settings, templates, audit log and protected full-access Admin.

## Phase 3 — Shared UI, design and accessibility audit

- [ ] Map every header/footer/component include and verify each portal uses a consistent shell.
- [ ] Verify active navigation, logout, mobile menu, dropdown/drawer/dialog behavior and focus restoration from source.
- [ ] Confirm one `<main>` landmark per route, logical heading hierarchy and unique page title.
- [ ] Check labels, accessible names, `aria-current`, `aria-expanded`, `aria-controls`, live regions and dialog semantics.
- [ ] Check keyboard paths for menus, dialogs, tabs, tables, filters, confirmation actions and close behavior.
- [ ] Confirm visible `:focus-visible` treatment uses or remains compatible with `#1B75D0`.
- [ ] Inspect responsive breakpoints for sidebar collapse, table overflow, master-detail stacking and form usability.
- [ ] Find hard-coded dimensions, clipped content, overlapping controls, low contrast and touch targets below practical size.
- [ ] Search for mojibake, Vietnamese copy, inconsistent capitalization, legacy branding, placeholder `#` links and unimplemented buttons.
- [ ] Check loading, empty, validation, server error, authorization error, stale-version and retry states.
- [ ] Compare the Admin implementation with `docs/mockups/admin-portal/*.png` without modifying images.
- [ ] Record each visual/accessibility concern by route, selector and source file line.

## Phase 4 — UI interaction-to-handler trace

For every button, form, link, toggle and select that claims to change state:

- [ ] Identify the HTML hook: `id`, `name`, `data-*`, form action or link target.
- [ ] Identify the JavaScript event listener or native form handler.
- [ ] Identify client validation and normalization.
- [ ] Identify the state mutation function and whether it uses localStorage, session, API or MySQL.
- [ ] Identify the API command/action and its allowed role.
- [ ] Identify the SQL transaction and affected tables/fields.
- [ ] Identify every other role expected to observe the change.
- [ ] Verify the consumer reads from the same authoritative source.
- [ ] Mark controls with no handler, dead handlers, unreachable endpoints or false success copy.
- [ ] Check that error paths do not persist local state after a failed server operation.

Required output: one interaction trace table per role plus a list of orphan UI controls and unreachable backend actions.

## Phase 5 — Core feature audit

### 5.1 Food ordering

- [ ] Trace Restaurant storefront/menu ownership into Customer discovery.
- [ ] Verify one cart cannot silently combine multiple Restaurants.
- [ ] Verify product IDs, names, availability and prices have an authoritative server source.
- [ ] Inspect portions, option groups and add-on prices; determine whether backend recalculates every price-bearing choice.
- [ ] Inspect quantity bounds, duplicate lines, removal and malformed persisted cart normalization.
- [ ] Trace address, delivery note, promo, payment method, subtotal, delivery fee and total into order creation.
- [ ] Verify client-provided totals cannot become authoritative MySQL amounts.
- [ ] Verify order, order items, payment and status history are written transactionally in code.
- [ ] Verify Restaurant receives the same reference, items, totals and Customer delivery data.
- [ ] Verify idempotent retry cannot create a duplicate order or double wallet debit.
- [ ] Check empty cart, closed Restaurant, unavailable menu item, mixed Restaurant, insufficient wallet and duplicate reference handling.

### 5.2 Delivery and GPS tracking

- [ ] Identify GPS source: browser geolocation, Leaflet, simulated coordinates, Driver profile location or fixed demo path.
- [ ] Confirm permission denial, unavailable location, stale location, invalid coordinates and map-tile failure states.
- [ ] Trace Restaurant `ready_for_pickup` to dispatch creation.
- [ ] Trace Driver offer exclusivity, expiry, decline, reassignment and acceptance.
- [ ] Verify acceptance materializes one `deliveries` record and one authoritative Driver assignment.
- [ ] Verify only the assigned eligible Driver can update milestones.
- [ ] Verify transition order: `assigned → arrived → picked_up → delivered` with no skip, repeat or regression.
- [ ] Verify Customer tracking, Restaurant order history and Admin order monitor interpret statuses consistently.
- [ ] Determine whether location updates persist/share across roles or exist only in one browser's localStorage.
- [ ] State explicitly whether GPS is real-time, simulated, static demo or incomplete.

### 5.3 Payments and finance

- [ ] Inventory every payment method shown by Customer UI and every method accepted by PHP/API/schema.
- [ ] Verify wallet balance locking, debit, transaction entry, insufficient balance and idempotent retry.
- [ ] Verify cash orders remain pending until delivery and create consistent COD obligations.
- [ ] Verify card/online payment has an actual provider flow, secure tokenization and webhook/confirmation, or classify it as demo/incomplete.
- [ ] Verify `payments.status` semantics match order state and UI labels.
- [ ] Trace completed sales into ledger, Restaurant revenue and Driver earnings.
- [ ] Verify refunds cannot exceed captured/paid amount and use compensating ledger entries.
- [ ] Verify full versus partial refund effects on order status.
- [ ] Verify payout hold/release and COD settlement use versions, reasons and audit logs.
- [ ] Compare Admin finance, Restaurant finance, Driver earnings and Customer wallet calculations for the same order.

### 5.4 Restaurant dashboard

- [ ] Verify dashboard KPIs derive from the same orders/menu/revenue source as detail pages.
- [ ] Verify menu changes can reach Customer catalog and server-authoritative checkout.
- [ ] Verify accepting-orders and operating-hours changes affect Customer availability.
- [ ] Verify Restaurant owns only its orders and cannot mutate Driver-owned milestones.
- [ ] Verify revenue excludes incomplete/cancelled orders and represents refunds consistently.
- [ ] Verify analytics date filters, averages, top-item aggregation and empty periods.

## Phase 6 — Four-role consistency matrix

Build a table with one row per domain state and columns: creator, authorized updater, MySQL table/field, client-state mirror, Customer view, Restaurant view, Driver view, Admin view, synchronization mechanism, stale-data risk and verdict.

Required rows:

- [ ] User account status and `session_version`.
- [ ] Restaurant application status and required documents.
- [ ] Driver application status, documents and eligibility.
- [ ] Restaurant profile, accepting-orders state and service hours.
- [ ] Menu item identity, name, price, options and availability.
- [ ] Cart and checkout totals.
- [ ] Order reference, items, address, payment method and total.
- [ ] Order status and status history.
- [ ] Dispatch status, offer and assigned Driver.
- [ ] Delivery milestones and delivered timestamp.
- [ ] GPS/current location and route progress.
- [ ] Payment capture/pending/refund status.
- [ ] Wallet balance and wallet transactions.
- [ ] Ledger entries and platform fees.
- [ ] Restaurant and Driver payouts.
- [ ] COD collected, due and settled amounts.
- [ ] Support case, messages, evidence and resolution.
- [ ] Promotion, fee rule, service area and platform setting.
- [ ] Notifications and audit logs.

Flag any domain where two roles use different sources, naming, status values, reference formats or timing rules.

## Phase 7 — Approval and identity lifecycle

- [ ] Verify fresh production seed creates exactly the intended full-access Admin and does not create active Restaurant/Driver accounts outside approval.
- [ ] Verify demo partner accounts require an explicit demo flag and cannot activate accidentally in production.
- [ ] Verify Restaurant required documents are exactly `business_registration`, `food_safety_certificate`, `owner_identity`.
- [ ] Verify Driver required documents are exactly `driver_license`, `vehicle_registration`, `background_check`.
- [ ] Verify document verification status, expiry and application version are checked within the approval transaction.
- [ ] Verify approval creates exactly one user and one matching Restaurant/Driver profile.
- [ ] Verify duplicate username/email and repeated approval are rejected.
- [ ] Verify “Request Changes” preserves the information needed for a later successful approval.
- [ ] Verify rejection does not create an account.
- [ ] Verify suspension, blocking, revocation and password recovery invalidate existing sessions.
- [ ] Verify password reset tokens are hashed, expiring, one-time and do not replace the password before use.
- [ ] Verify the sole Admin cannot accidentally suspend or remove itself through Admin controls.

## Phase 8 — Backend/API review

Review at minimum: `auth.php`, `logout.php`, `reset_password.php`, `admin_action.php`, `api/platform_state.php`, `lib/admin_actions.php`, `lib/admin_repository.php`, `lib/admin_security.php`, `lib/session_security.php` and `db.php`.

- [ ] Produce an endpoint/action/command inventory with method, authentication, role, CSRF, idempotency and transaction behavior.
- [ ] Check every role branch denies commands owned by another role.
- [ ] Check session status, DB account status, role, session record and `session_version` on every protected boundary.
- [ ] Check password hashing, reset-token handling, logout revocation and inactive-account login denial.
- [ ] Check all SQL using external input is prepared/bound and no table/column identifiers are user-controlled.
- [ ] Check length, enum, numeric bounds, dates, references, arrays and JSON validation.
- [ ] Check server-authoritative price and financial calculations.
- [ ] Check state-transition allowlists for applications, accounts, orders, deliveries, cases, payments and payouts.
- [ ] Check optimistic locking requires expected version and verifies affected rows.
- [ ] Check idempotency is scoped by actor/key/action and response replay is safe.
- [ ] Check transactions rollback all partial writes on exception.
- [ ] Check error responses do not expose SQL, secrets, hashes, tokens or filesystem paths.
- [ ] Check audit log includes actor, entity, before/after summary, reason, session/IP, result and unique reference.
- [ ] Check notifications target the correct affected role and do not claim delivery through an unimplemented channel.
- [ ] Identify dead legacy functions or duplicate implementations that could confuse future callers.

## Phase 9 — MySQL schema and integrity review

Read `lib/platform_schema.php` completely and compare repository/action/API queries with the declared schema.

- [ ] Inventory every table, primary key, unique key, index and timestamp/version field.
- [ ] Verify key relationships among users, profiles, applications, documents, restaurants, menu items, orders, items, payments, dispatches, deliveries and cases.
- [ ] Identify missing foreign keys or application-level integrity assumptions.
- [ ] Verify money fields use appropriate decimal types and calculations avoid unsafe trust in floating-point client values.
- [ ] Verify unique constraints prevent duplicate username, application reference, order reference, payment per order, delivery per order and idempotency key per actor.
- [ ] Verify indexes support dashboard filters and joins by user, Restaurant, Driver, order status, date and reference code.
- [ ] Verify enum/string status sets agree with PHP and JavaScript state machines.
- [ ] Verify audit/ledger/history records are append-only in active code paths.
- [ ] Verify seed mode separation between production-safe identities and demo operational fixtures.
- [ ] Verify migration idempotency without running it against the existing database.
- [ ] Use only `SHOW`, `DESCRIBE`, `SELECT` and `information_schema` if a database connection is available.
- [ ] Compare actual database schema to declared schema and report drift without altering either side.

Suggested read-only queries, after replacing the database name only with the already configured name:

```sql
SELECT DATABASE();
SHOW TABLES;
SELECT table_name, engine, table_collation
FROM information_schema.tables
WHERE table_schema = DATABASE()
ORDER BY table_name;

SELECT table_name, column_name, column_type, is_nullable, column_default, column_key, extra
FROM information_schema.columns
WHERE table_schema = DATABASE()
ORDER BY table_name, ordinal_position;

SELECT table_name, index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_used
FROM information_schema.statistics
WHERE table_schema = DATABASE()
GROUP BY table_name, index_name, non_unique
ORDER BY table_name, index_name;
```

Do not execute any statement copied from migration or seed code.

## Phase 10 — Security and abuse-case review

- [ ] Customer attempts to forge price, total, Restaurant target, payment status or another Customer's order.
- [ ] Restaurant attempts to access another Restaurant's order/application/profile/menu.
- [ ] Driver attempts to accept two active deliveries or mutate an unassigned order.
- [ ] Driver attempts milestone skip, repeat, regression or post-cancellation update.
- [ ] Admin action is replayed, submitted with stale version, missing reason or missing CSRF.
- [ ] Suspended/revoked user reuses an existing page/API session.
- [ ] Application is approved with arbitrary document labels, expired documents or duplicated identity.
- [ ] Refund exceeds paid balance or targets an unpaid cash/card order.
- [ ] COD settlement exceeds outstanding balance.
- [ ] Promotion/fee date, percentage, budget or scope is malformed.
- [ ] Stored text attempts HTML/script injection in names, notes, reviews, case messages or addresses.
- [ ] Query-string identifiers attempt unauthorized record access.
- [ ] Login/reset errors leak account existence or reset token information.

Perform these as source-level traces only. Do not send malicious or mutating requests.

## Phase 11 — Automated-test audit

- [ ] Inventory every test and map it to role, route, feature, backend component and risk.
- [ ] Separate static markup tests, state/unit tests, browser QA and PHP/MySQL integration tests.
- [ ] Inspect tests for meaningful assertions rather than source-token-only checks.
- [ ] Identify production logic that has no behavioral test.
- [ ] Identify tests that can pass while the real cross-role API/data flow is broken.
- [ ] Confirm whether browser QA actually launches the current routes and checks console/network/runtime errors.
- [ ] Confirm whether PHP integration tests isolate their database and clean test records.
- [ ] Do not run tests that mutate MySQL or the filesystem.
- [ ] Safe candidate: run `node --test tests/*.test.js` only after confirming those tests do not write repository files or external data.
- [ ] Run PHP lint read-only with the installed PHP binary and report file count/failures.
- [ ] Record all skipped tests and the exact read-only reason.

## Phase 12 — Requirement traceability verdict

Create this final matrix with evidence and status:

| Required capability | UI pages | JavaScript/state | PHP/API | MySQL | Cross-role consumer | Test evidence | Verdict |
|---|---|---|---|---|---|---|---|
| Browse Restaurants and menus | Customer discovery/detail | Catalog/state | Menu sync/read path | restaurants/menu_items | Restaurant/Admin | Named tests | Status |
| Customize and add to cart | Product/cart | Customer state | Checkout validation | order_items | Restaurant/Admin | Named tests | Status |
| Place order | Checkout/history | Platform bridge | place_order | orders/payments/history | Restaurant/Admin | Named tests | Status |
| GPS delivery tracking | History/tracking/Driver | Driver/UI state | Location/status path | delivery/location data | Customer/Restaurant/Admin | Named tests | Status |
| Multi-method secure payment | Checkout/wallet/finance | Customer state | Payment handling | payments/wallet/ledger/COD | All roles | Named tests | Status |
| Restaurant menu management | Menu/editor | Restaurant state | restaurant_sync_menu | menu_items | Customer/Admin | Named tests | Status |
| Restaurant order management | Orders/history | Restaurant orders | restaurant_order_status | orders/history/dispatch | Customer/Driver/Admin | Named tests | Status |
| Restaurant revenue analytics | Dashboard/finance/analytics | Insight functions | Repository/query path | orders/ledger/refunds/payouts | Admin | Named tests | Status |
| Admin approval/control | Admin partner/account pages | Admin UI | Admin actions | users/applications/docs/audit | Restaurant/Driver | Named tests | Status |

Do not leave cells blank. Use `No implementation found` or a read-only status when appropriate.

## Phase 13 — Final report structure

Return one report in the final response with these sections, in order:

1. **Executive Summary** — concise state of the platform and highest risks.
2. **Read-Only Compliance** — initial/final Git status, commands run, skipped mutation-dependent checks.
3. **Readiness Verdict** — `READY`, `READY WITH CONDITIONS` or `NOT READY` for Demo, UAT and Production separately.
4. **35-Page Inventory** — one row per route with purpose, primary data source, cross-role dependency and status.
5. **Core Requirement Traceability** — completed Phase 12 matrix.
6. **Four-Role Consistency Matrix** — completed Phase 6 matrix.
7. **End-to-End Flow Results** — approval, menu, ordering, dispatch, delivery/GPS, payment/finance, cases and Admin intervention.
8. **Backend/API Findings** — endpoint inventory and defects.
9. **Database Findings** — schema/integrity/index/status/version concerns.
10. **Security Findings** — authentication, authorization, validation, CSRF, idempotency and abuse cases.
11. **UI/UX Findings** — visual consistency, English copy, responsive, accessibility and interaction feedback.
12. **Test Coverage Assessment** — executed/skipped tests and uncovered risks.
13. **Findings by Severity** — BLOCKER, CRITICAL, HIGH, MEDIUM and LOW.
14. **Missing/Demo-Only Capabilities** — explicit list of localStorage-only, simulated, placeholder or unavailable integrations.
15. **Recommended Verification/Fix Order** — textual priority only; do not edit or implement.
16. **Final Declaration** — required read-only completion sentence.

### Finding template

```text
[SAV-ROLE-001] SEVERITY — Specific title
Roles/routes: Customer > customer_checkout.php; Restaurant > restaurant_orders.php
Evidence: api/platform_state.php:line; function_name(); table.field; test name
Expected: Exact intended behavior
Actual/static evidence: Exact observed behavior
Impact: Data, security and business impact
Read-only reproduction/trace: Steps that do not write state
Recommended direction: Text-only remediation direction
Confidence: Confirmed | Strong evidence | Needs runtime verification
```

## Phase 14 — Final immutability proof

- [ ] Run `git status --short` and preserve the exact output.
- [ ] Run `git diff --exit-code` and report its exit status.
- [ ] Compare initial and final Git status line by line.
- [ ] Confirm no file, Git, package, API or MySQL mutation was intentionally performed.
- [ ] If any unexpected difference exists, report it as the first item in the final response; do not attempt cleanup.
- [ ] End with: `READ-ONLY AUDIT COMPLETED. No source code, configuration, Git state, API data, or database data was intentionally modified.`

## Audit completion criteria

The audit is complete only when:

- All 35 routes appear exactly once in the inventory.
- All four stated core requirements have UI, state, backend, database, cross-role and test evidence.
- Every domain row in the four-role consistency matrix has a verdict.
- Every action has an identified owner and persistence path or is reported missing.
- Backend/API and database reviews include concrete file/table evidence.
- Findings are prioritized and contain no implemented fixes.
- Mutation-dependent checks are explicitly marked, not silently omitted.
- Initial and final Git states match.

