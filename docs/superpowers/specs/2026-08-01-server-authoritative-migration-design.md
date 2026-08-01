# Savora Server-Authoritative Migration Design

Date: 2026-08-01
Status: Approved design; pending written-spec review

## Goal

Migrate Savora incrementally from a mixed browser-local/MySQL implementation to a server-authoritative system without rewriting the existing 35 PHP pages. Each domain is cut over completely before the next domain begins, and its legacy authoritative local-storage path is removed in the same phase.

## Context

The current implementation has complete role-oriented UI coverage and a useful PHP/MySQL foundation, but it maintains competing copies of business data:

- Customer, Restaurant, and Driver flows persist orders, menus, profiles, wallets, dispatch, location, and finance in browser local storage.
- Admin pages read MySQL.
- `api/platform_state.php` forwards selected commands to MySQL but does not hydrate every portal from one canonical server snapshot.
- `db.php` creates the database, migrates the schema, and seeds data when it is included by a request.
- Order status, pricing, payment, dispatch, refund, and promotion rules are not represented consistently across JavaScript, PHP, and MySQL.

The migration must preserve the current routes and UI while removing these competing authorities.

## Chosen Approach

Use an incremental server-authoritative migration with domain-level cutovers.

The migration is not a long-lived dual-system design. During one phase, a thin compatibility adapter may translate an existing page to the new domain API. Before that phase is complete, all legacy writers for that domain are disabled and removed.

The alternatives were rejected for these reasons:

- A backend-first rewrite would delay usable improvements and require switching all portals at once.
- Patching the existing monolithic state and action files would preserve split-brain behavior and continue increasing coupling.

## Non-Negotiable Invariants

1. MySQL is the only authority for submitted business records.
2. A domain has exactly one write path at any point in the migration.
3. There is no dual-write between MySQL and local storage.
4. Local storage may retain only an unsubmitted cart, UI filters, display preferences, and explicitly non-authoritative drafts.
5. A failed server command remains failed; the browser must not simulate success locally.
6. Every mutation validates authentication, role ownership, CSRF, idempotency, legal state transition, and optimistic version where applicable.
7. Transactions update the domain record and all required history, ledger, notification, and audit records atomically.
8. Every phase includes a named legacy-removal step and cannot close while its legacy writer remains reachable.
9. Migration and seed commands never execute automatically during a normal web request.
10. No existing top-level Customer, Restaurant, Driver, or Admin route is removed during the migration.

## Target Architecture

Savora remains a server-rendered PHP/MySQL application with progressive JavaScript enhancement. No framework rewrite or new runtime dependency is required.

### Request boundaries

Read flow:

`PHP page or browser GET -> API/query service -> repository -> MySQL -> escaped response/rendering`

Write flow:

`Browser form/command -> request guard -> domain service -> MySQL transaction -> record + history + ledger/notification/audit -> JSON response -> server refresh`

JavaScript renders state and gathers user input. It does not authorize actions, calculate final financial amounts, or decide legal status transitions.

### Database bootstrap

- `db.php` becomes a compatibility include that opens a configured connection only.
- A focused connection helper owns environment validation, connection errors, charset, and port configuration.
- Migration and seed entry points move to explicit CLI scripts.
- Web requests fail safely if the schema is missing rather than creating or mutating it.
- Demo seed data remains available only through an explicit development command and environment flag.

### Domain services and repositories

New code follows the existing procedural PHP style and is separated by responsibility:

- Connection/bootstrap helpers establish infrastructure without domain writes.
- Repositories contain prepared SQL and map rows to stable arrays.
- Domain services enforce permissions, invariants, transitions, idempotency, and transaction boundaries.
- API endpoints validate HTTP input and delegate to services.
- Page files select a view and render escaped output.

The planned domain boundaries are:

- Identity and partner applications
- Restaurant profile and catalog
- Pricing and checkout
- Orders and status history
- Payments, wallets, ledger, refunds, payouts, and COD
- Dispatch, offers, deliveries, milestones, and location
- Notifications and support cases
- Promotions, fees, service areas, settings, analytics, and exports

`api/platform_state.php` and `lib/admin_actions.php` become compatibility routers while domains move out. They are deleted or reduced to stable routing shims after all consumers migrate.

## Canonical Data Contracts

### Identifiers

- Database primary keys remain internal numeric identifiers.
- Public references are generated by the server and are immutable.
- Client-created timestamps or display IDs are never accepted as authoritative record identifiers.
- Every relationship is validated on the server, including menu item ownership by Restaurant and order ownership by Customer.

### Order states

The canonical order lifecycle is:

`pending -> confirmed -> preparing -> ready_for_pickup -> assigned -> picked_up -> delivered`

Terminal exception states are `cancelled` and `refunded`.

Portal labels may use friendly wording, but stored values and API values use the canonical vocabulary. A full refund may set `refunded`; a partial refund keeps the fulfillment state and appends refund records.

### Dispatch and delivery states

Dispatch uses:

`searching_driver -> offer_sent -> assigned`

Delivery uses:

`assigned -> arrived -> picked_up -> delivered`

Decline, timeout, failure, cancellation, and reassignment are explicit events. Reassignment closes the prior active assignment before creating the next one.

### Money

- The server resolves menu prices, selected options, promotions, fees, and service area eligibility.
- The browser receives a quote and submits the quote identifier, not trusted totals.
- Order creation revalidates the quote inside the transaction.
- Wallet balance is derived from server ledger entries.
- Refunds and adjustments append compensating entries; balances are not overwritten.
- Stable idempotency keys belong to a business intent and survive network retries.

An external card provider is a deployment dependency. This migration creates a provider-neutral payment boundary and disables production card confirmation until a specific provider and credentials are configured. Cash and server wallet flows must remain internally consistent without that provider.

## Browser-State Policy

Allowed local-storage data:

- Unsubmitted cart lines and delivery note draft
- Search, filter, sort, and active-tab preferences
- Non-sensitive display preferences

Forbidden local-storage authority after the relevant cutover:

- Submitted orders and order history
- Restaurant menu availability or operational status
- Customer wallet balance or transactions
- Driver availability, active assignment, milestones, earnings, or COD balance
- Payment, refund, payout, case, notification, or audit state

Regression tests scan for prohibited writes and ensure migrated pages hydrate from server responses.

## Domain Cutover Protocol

Every domain phase follows the same sequence:

1. Define the canonical API and database invariants in failing tests.
2. Add or migrate schema using an explicit migration command.
3. Implement repository and domain service behavior.
4. Expose the read and command endpoints.
5. Switch every affected role to the server read model.
6. Disable the old writer before enabling the new writer in production code.
7. Remove the old local mutation and compatibility code.
8. Run unit, PHP integration, cross-role, browser, lint, and legacy-write guard tests.
9. Record rollback instructions and migration verification queries.
10. Close the phase only when there is one reachable writer and one canonical read model.

## Delivery Phases

### Phase 0: Safety baseline

Establish an isolated worktree, baseline test results, a dedicated test database, safe environment conventions, and mutation-aware test commands. Preserve existing untracked user files.

### Phase 1: Side-effect-free database bootstrap

Separate connection, migration, and seed responsibilities. Make normal GET requests free of schema and seed writes. Move session last-seen updates to an intentional heartbeat or authenticated command boundary rather than every page read.

### Phase 2: Canonical contracts and schema integrity

Define shared status constants, public identifiers, API response envelopes, optimistic versions, foreign keys, unique constraints, check constraints where supported, and required indexes. Migrate existing demo rows deterministically.

### Phase 3: Restaurant profile and catalog

Move Restaurant profile, accepting-orders state, hours, menu items, options, availability, and Customer discovery to MySQL-backed services. Remove authoritative Restaurant/catalog local-storage mutations.

### Phase 4: Pricing, checkout, payment, and wallet

Implement server quotes, option validation, promotion and fee application, service-area validation, stable checkout idempotency, wallet ledger, cash payment state, and the provider-neutral card boundary. Remove local wallet and submitted-order writes.

### Phase 5: Cross-role orders

Move Customer history, Restaurant live orders/history, Driver-linked order views, and Admin order views to one order read model. Enforce role-owned transitions and one canonical status history.

### Phase 6: Dispatch, delivery, GPS, and tracking

Implement server availability, offer lifecycle, exclusive assignment, decline/timeout, reassignment, milestones, current location with timestamp, location privacy/retention, Customer tracking, and proof-of-delivery metadata. Remove local dispatch and delivery authority.

### Phase 7: Admin consistency and finance

Make cancel, reassign, refund, payout, COD settlement, suspension, and case resolution atomic across orders, delivery, payment, wallet, ledger, payout, notifications, and audit. Remove unreachable legacy admin operation code.

### Phase 8: Partner onboarding

Add Restaurant and Driver application submission, secure document upload metadata, validation, review, approval, account creation, and activation notification. Approval remains the only path that creates partner accounts.

### Phase 9: Notifications and support

Add a notification outbox/read model, role-specific in-app notifications, server-backed support issue creation, case messages, attachment metadata, and delivery evidence links.

### Phase 10: Commercial rules, analytics, and exports

Connect promotions, fee rules, service areas, and maintenance mode to checkout. Correct analytics definitions and filters. Implement authorized CSV exports and print/PDF output without false controls.

### Phase 11: Security hardening and final legacy removal

Remove default production credentials and demo-login exposure, protect recovery URLs, add rate-limit boundaries, verify output escaping and upload validation, delete obsolete local-state writers and compatibility routers, and run the full release suite.

## Error Contract

All JSON endpoints return `ok`, `message`, optional `data`, optional field `errors`, and a safe `referenceId`.

- `401`: no valid authenticated session
- `403`: wrong role or prohibited ownership/action
- `409`: stale version, duplicate business intent, illegal transition, or assignment conflict
- `419`: missing, invalid, or expired CSRF token
- `422`: field or business validation failure
- `500`: unexpected error after transaction rollback

The UI preserves user input, displays safe errors, and offers an explicit retry. It never converts a server failure into local success.

## Security Design

- Prepared SQL only in repositories.
- Central output escaping for persisted content.
- Role and ownership checks in services, not JavaScript.
- CSRF on every mutation.
- Stable idempotency on financially or operationally significant commands.
- Optimistic version checks for mutable records.
- Session revocation through `session_version` and server session records.
- Randomized upload names, MIME/extension/size checks, and files stored outside executable web paths.
- No raw reset token in logs, audit payloads, screenshots, or general Admin toasts.
- Detailed server errors stay server-side; users receive a safe reference identifier.

## Test Strategy

All implementation uses test-driven development: a behavior test must fail for the expected reason before production code is added.

Test layers:

- Static and markup tests for route, shell, accessibility, and forbidden legacy patterns
- Pure JavaScript tests for non-authoritative cart and UI behavior
- PHP service tests against a dedicated disposable test database
- Migration tests for repeatability, constraints, deterministic seed, and rollback-safe failures
- API security tests for authentication, role, ownership, CSRF, idempotency, version conflict, and escaping
- Cross-role integration tests for Customer -> Restaurant -> Driver -> Admin visibility
- Money tests for quote validation, duplicate retry, wallet debit, refund limits, ledger balance, payout, and COD
- Browser QA for navigation, forms, responsive layout, focus behavior, retry/error states, and role-visible updates

Each phase must pass its focused tests and the complete existing regression suite before legacy code is removed and again after removal.

## Rollback Strategy

- Schema changes use forward migrations with explicit verification queries.
- Destructive column/table removal is deferred until all consumers and rollback windows have passed.
- Domain cutovers are performed on an isolated branch and committed in small, independently testable slices.
- A rollback reverts the application cutover while preserving newly written authoritative records; it must not re-enable a local authoritative writer.
- Financial and audit records are corrected with compensating entries rather than deletion.

## Non-Goals

- Rewriting Savora in a new framework or language
- Changing the approved 35-route top-level information architecture
- Importing arbitrary historical local-storage data from users' browsers
- Building route optimization, OCR, or external identity verification
- Selecting a card provider without a separate provider/credential decision
- Unrelated visual redesign

## Acceptance Criteria

- Normal page reads do not create, migrate, seed, or otherwise mutate business data.
- MySQL is authoritative for every submitted business record.
- No migrated domain has a reachable local authoritative writer.
- Customer, Restaurant, Driver, and Admin observe the same order and delivery state.
- Pricing, payment, wallet, refund, payout, and COD operations are transactional and idempotent.
- Restaurant/Driver approval is backed by submitted applications and verified documents.
- Promotions, fees, service areas, maintenance mode, notifications, analytics, and exports have real server consumers.
- All 35 routes remain available and preserve their existing accessible design language.
- PHP lint, JavaScript tests, PHP service/integration tests, cross-role tests, security tests, migration tests, and browser QA pass.
- Final Git diff contains no unrelated user changes.
