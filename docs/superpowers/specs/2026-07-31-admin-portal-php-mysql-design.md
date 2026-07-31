# Savora Admin Portal PHP/MySQL Design

Date: 2026-07-31
Status: Approved design; pending written-spec review

## Goal

Implement eleven production-style Admin pages from the approved mockups and connect them to shared PHP/MySQL business data. Admin actions must interact consistently with Customer, Restaurant Owner, and Delivery Driver workflows while preserving role-owned transitions, financial history, auditability, and existing demo logins.

## Approved Product Scope

- One full-access Admin account.
- Controlled intervention rather than unrestricted record editing.
- Eleven top-level Admin pages; record details use drawers or dialogs and do not create additional top-level pages.
- English UI copy only.
- MySQL is authoritative for accounts, applications, role profiles, orders, dispatch, finance, cases, notifications, settings, and audit.
- Existing Customer, Restaurant, Driver, and Admin demo credentials remain usable.
- Browser local storage remains only for an unsubmitted cart, UI filters, and other non-authoritative preferences.

## Visual Source of Truth

The Admin implementation follows:

- `docs/superpowers/specs/2026-07-31-admin-portal-mockups-design.md`
- `docs/mockups/admin-portal/01-admin-overview.png` through `11-settings-audit.png`

Palette:

- Primary forest green: `#073B2B`
- Deep forest green: `#04291E`
- Coral CTA: `#EF634B`
- Ivory background: `#FBF9F3`
- Sage surface: `#E8EDDF`
- Primary text: `#1C2923`
- Secondary text: `#657169`
- Border: `#DFE4DA`
- Accessibility focus: `#1B75D0`

## Delivery Strategy

The feature is implemented as testable vertical slices. A slice contains its schema, seed data, query layer, command layer, page UI, cross-role effects, and automated tests before the next slice starts.

1. Foundation and shared Admin shell.
2. Identity and partner approval.
3. Orders, dispatch, cases, and refunds.
4. Finance, promotions, fees, and service areas.
5. Overview, analytics, settings, and audit.
6. Cross-role migration and end-to-end verification.

## Architecture

### Request flow

Admin pages are server-rendered PHP with progressive JavaScript enhancement.

Read flow:

`Admin page -> query service -> repository -> MySQL -> escaped HTML`

Write flow:

`Admin form -> CSRF/idempotency validation -> command service -> MySQL transaction -> domain records + history + audit + notifications -> JSON response -> UI refresh`

Customer, Restaurant, and Driver actions call the same domain services or feature endpoints. No portal maintains a second authoritative copy of an order, account, wallet, dispatch, or payout.

### Boundaries

- Page files select filters, call query services, and render templates.
- Query services perform filtering, pagination, and reporting without state changes.
- Command services validate role permissions and legal transitions.
- Repositories own prepared MySQL statements.
- Transactions keep business changes, history, ledger, notifications, and audit consistent.
- JavaScript owns UI behavior only; it cannot authorize actions or decide legal transitions.

### Shared Admin files

- `components/admin_header.php`: authentication guard, sidebar, breadcrumb, top bar, global search, notifications, and CSRF exposure.
- `components/admin_footer.php`: shared dialogs, toast live region, and scripts.
- `css/admin_style.css`: approved design system and responsive behavior.
- `js/admin_ui.js`: navigation, drawers, dialogs, filters, confirmation, form errors, focus management, and toasts.
- `lib/admin_bootstrap.php`: Admin session guard, shared dependencies, and page helpers.
- `lib/security.php`: CSRF, output escaping, sensitive-action re-authentication, and request metadata.
- `lib/http.php`: consistent JSON success and error responses.

### Domain modules

- Accounts and applications
- Restaurants and drivers
- Orders and dispatch
- Cases and refunds
- Ledger, payouts, and COD reconciliation
- Promotions, fee rules, and service areas
- Analytics and settings
- Notifications and immutable audit

Each module exposes focused query and command services rather than one large Admin service.

## Admin Routes

1. `admin_dashboard.php` — System Overview
2. `admin_accounts.php` — Accounts & Access
3. `admin_customers.php` — Customer Management
4. `admin_restaurants.php` — Restaurants & Approvals
5. `admin_drivers.php` — Drivers & Verification
6. `admin_orders.php` — Orders & Dispatch
7. `admin_cases.php` — Cases, Incidents & Refunds
8. `admin_finance.php` — Finance & Reconciliation
9. `admin_promotions.php` — Promotions, Fees & Service Areas
10. `admin_analytics.php` — Analytics & Reports
11. `admin_settings.php` — Settings & Audit Log

All routes require an authenticated `admin` role. Search, filter, pagination, sort, date range, and active tab are represented in the query string so reload and copy/paste preserve the view.

## Page Responsibilities

### System Overview

Reads operational KPIs, live order counts, delayed orders, approval queue, financial summary, alerts, and recent Admin activity. Cards and rows link to the relevant filtered page. It does not change domain state directly.

### Accounts & Access

Searches every created account by role and state. Shows sign-in and security history. Supported commands are reset password, revoke sessions, suspend, reactivate, and block. Restaurant and Driver accounts cannot be manually created here to bypass approval.

### Customers

Shows profile, masked contact details, orders, wallet ledger, reviews, refunds, cases, and risk indicators. Admin may open a case or suspend/reactivate the account. Wallet balance has no direct edit control.

### Restaurants & Approvals

Shows pending applications, document completeness, active restaurants, accepting-orders state, operational health, and payout status. Commands are approve, request changes, reject, pause new orders, suspend, and reactivate.

### Drivers & Verification

Shows pending applications, identity and vehicle documents, expiration, eligibility, online status, delivery performance, and COD balance. Commands are approve, request changes, reject, mark ineligible, suspend, and reactivate.

### Orders & Dispatch

Shows live orders, order history, items, payment, role-attributed status history, dispatch offers, assigned Driver, and milestones. Controlled commands are reassign Driver, cancel order, and open incident. A reason is mandatory.

### Cases, Incidents & Refunds

Shows SLA queue, messages, evidence metadata, linked order/payment, and affected roles. Commands are request information, reassign Driver, cancel order, partial refund, full refund, and resolve case.

### Finance & Reconciliation

Shows immutable ledger entries, Restaurant payouts, Driver payouts, COD reconciliation, and refunds. Commands are hold, release, retry payout, append adjustment, and settle COD. Balances cannot be overwritten.

### Promotions, Fees & Service Areas

Shows promotions, redemption usage, versioned platform and delivery fees, and service-area health. Commands create, schedule, pause, or reactivate records. Fee changes never apply retroactively.

### Analytics & Reports

Shows GMV, completion funnel, cancellation rate, delivery time, hourly demand, Restaurant performance, Driver efficiency, and Customer retention. Supports filters and CSV/PDF exports without changing domain state.

### Settings & Audit Log

Shows platform timeouts, notification templates, security controls, maintenance state, and the immutable audit log. Settings changes are versioned and audited. Audit entries cannot be updated or deleted.

## Data Model

### Identity and profiles

- `users`: username, password hash, role, full name, email, phone, status, session version, last login, timestamps, version.
- `customer_profiles`: user reference and Customer metadata.
- `restaurant_applications`: applicant identity, proposed store data, password hash, status, reviewer fields, reason, timestamps, version.
- `restaurant_application_documents`: application reference, document type, stored path, MIME, verification state, expiration, reviewer note.
- `driver_applications`: applicant identity, vehicle, service area, password hash, status, reviewer fields, reason, timestamps, version.
- `driver_application_documents`: application reference, document type, stored path, MIME, verification state, expiration, reviewer note.
- `restaurants`: owner user reference, profile, address, coordinates, operational status, accepting-orders state, timestamps, version.
- `driver_profiles`: user reference, identity, vehicle, document eligibility, service radius, availability, timestamps, version.
- `account_status_history`: user, previous status, next status, actor, reason, timestamp.

Application status is `pending`, `needs_changes`, `approved`, or `rejected`. Created account status is `active`, `suspended`, or `blocked`.

### Orders and dispatch

- `orders`
- `order_items`
- `order_status_history`
- `delivery_dispatches`
- `delivery_offers`
- `deliveries`
- `delivery_milestones`

Order states:

`pending -> confirmed -> preparing -> ready_for_pickup -> on_the_way -> completed`

Terminal exception states are `cancelled` and `refunded`. A partial refund appends refund and ledger records without replacing a completed order's fulfillment status; `refunded` is reserved for a fully refunded order.

Dispatch states:

`searching_driver -> offer_sent -> assigned -> arrived -> picked_up -> delivered`

Driver decline or offer timeout creates a dispatch event and redispatches without changing the Customer-visible order state.

### Finance

- `payments`
- `wallet_transactions`
- `ledger_entries`
- `refunds`
- `payouts`
- `payout_items`
- `cod_reconciliations`

Completed orders append Customer payment, platform commission, Restaurant payable, and Driver earning entries. Refunds and adjustments append compensating entries. Displayed balances are derived from the ledger.

### Cases and platform configuration

- `support_cases`
- `case_messages`
- `case_attachments`
- `notifications`
- `promotions`
- `promotion_redemptions`
- `service_areas`
- `fee_rules`
- `platform_settings`
- `audit_logs`
- `idempotency_keys`
- `user_sessions`

## Approval Transactions

Restaurant or Driver submission creates only an application and document records. Approval runs one transaction:

1. Lock and reload the application.
2. Validate `pending`, document completeness, unique credentials, and current version.
3. Create one active `users` account.
4. Create the Restaurant or Driver profile.
5. Remove the credential hash from the approved application after it has been transferred to `users`.
6. Mark the application approved with Admin and time.
7. Write the audit event with before/after summary and reason.
8. Queue the activation notification.
9. Commit and return the created account/profile identifiers.

Request changes updates the application without creating a user. Rejection records a reason and creates no user account. Repeated approval with the same idempotency key returns the original result.

## Cross-Role Rules

### Account suspension

- Suspended Customer cannot sign in or place new orders; historical records remain.
- Suspended Restaurant disappears from discovery and cannot receive new orders. Active orders require explicit resolution.
- Suspended Driver becomes ineligible for new offers. A non-emergency suspension requires an active delivery to be completed or reassigned first.

### Order ownership

- Customer creates an order and may cancel only while policy permits.
- Restaurant owns confirmation, preparation, and ready-for-pickup transitions.
- Driver owns arrival, pickup, on-the-way, and delivery transitions.
- Admin uses named exception commands and cannot submit an arbitrary status.

### Notifications

Successful commands append role-specific notifications. Examples include application decision, account suspension, Restaurant pause, Driver assignment, order cancellation, refund, payout status, and fee activation.

### Existing demo data

The migration preserves existing user rows and enriches them with safe defaults. It creates deterministic Restaurant, Driver, application, order, dispatch, finance, case, promotion, and audit seed records for Admin testing. Historical browser-local demo orders are not imported because server code cannot safely enumerate each browser's local storage; the new shared MySQL seed becomes authoritative after migration.

## UI Behavior

- Shared desktop shell matches the approved mockups.
- Tablet collapses the sidebar while preserving all routes.
- Mobile uses a compact navigation dialog and transforms dense tables into scroll-safe cards or labelled table wrappers.
- Row selection opens a right drawer.
- Sensitive actions open a confirmation dialog with a required reason.
- Dialogs trap focus, support Escape when safe, and restore focus to their trigger.
- Toasts and validation summaries use live regions.
- Status uses text and icon/color.
- PII is masked until intentionally revealed for an authorized task.
- Lists implement loading, empty, filtered-empty, error, and populated states.

## Security

- PHP session and role guard on every page and action endpoint.
- CSRF token on every state-changing request.
- Prepared MySQL statements only.
- Central HTML escaping for persisted content.
- MIME, extension, size, and randomized-name validation for documents.
- Password re-authentication for refund, payout, fee, suspension, and security actions.
- Session version supports revocation.
- Optimistic record versioning returns conflict instead of overwriting newer state.
- Audit has no update or delete endpoint.
- Server logs receive detailed errors; users receive safe reference identifiers.

## HTTP and Error Contract

- `401`: missing authentication.
- `403`: wrong role or prohibited command.
- `409`: stale record, illegal current state, or conflicting assignment.
- `419`: invalid or expired CSRF token.
- `422`: field validation failure.
- `500`: unexpected server failure after rollback.

JSON responses contain `ok`, `message`, optional `data`, optional field `errors`, and a safe `referenceId`. Failed forms preserve entered values. Conflict responses reload the drawer with current data.

## Test Strategy

### Schema and seed tests

- Migration is repeatable.
- Required foreign keys and unique constraints exist.
- Demo accounts remain valid.
- Seed records are deterministic and idempotent.

### Service tests

- Approval creates exactly one user/profile.
- Incomplete or stale applications cannot be approved.
- Role-owned order transitions are enforced.
- Ineligible Drivers receive no offers.
- Refund cannot exceed the remaining refundable amount.
- Finance adjustment appends a ledger entry.
- Suspension preserves history and enforces role-specific effects.
- Idempotent retry returns the original result.

### Route and security tests

- Every Admin page requires Admin role.
- POST endpoints reject missing CSRF and invalid role.
- Persistent output is escaped.
- Validation maps to the documented HTTP contract.

### Markup and interaction tests

- Exactly eleven navigation routes exist.
- Tables, drawers, dialogs, tabs, and filters expose accessible semantics.
- Visible interface copy is English.
- Responsive CSS covers 1440px, 768px, and 320px.

### Cross-role tests

- Approved Restaurant/Driver can sign in.
- Customer order appears for Restaurant.
- Restaurant ready-for-pickup enters dispatch.
- Driver pickup and delivery update every portal.
- Admin refund updates payment, ledger, settlement, order, audit, and notifications atomically.
- Suspension changes Customer discovery or Driver eligibility as specified.

### Browser QA

Automated browser QA covers navigation, filtering, drawer and dialog focus, approval, order exception, refund, finance integrity, responsive overflow, and role-visible results.

## Non-Goals

- External payment-gateway settlement.
- External email or SMS delivery; notifications are queued and shown in-app.
- Live third-party GPS maps or route optimization.
- OCR or external identity verification.
- Multiple Admin permission levels.
- Importing historical local-storage records from arbitrary browsers.

The database and UI are structured so external providers can be added later without changing the approved eleven-page navigation.

## Acceptance Criteria

- Eleven Admin routes match their approved mockups and use English UI copy.
- All metrics and records come from MySQL queries rather than hard-coded Admin markup.
- Approved Restaurant and Driver accounts are created only through approval.
- Controlled commands enforce legal transitions and mandatory reasons.
- Audit, history, notifications, and financial entries remain consistent with domain changes.
- Customer, Restaurant, Driver, and Admin observe the same authoritative order state.
- Existing demo accounts continue to sign in.
- No top-level page is added for detail records.
- PHP lint, schema/service/security/markup tests, cross-role integration tests, and browser QA pass.
