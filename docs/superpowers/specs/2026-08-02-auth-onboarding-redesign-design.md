# Savora Authentication and Role Onboarding Redesign

Date: 2026-08-02
Status: Approved design, pending implementation plan

## Goal

Redesign Savora's authentication and onboarding experience from the supplied mockups and complete the server-authoritative implementation behind it. The finished flow must support Customer self-registration, Admin-reviewed Restaurant and Driver applications, internal Admin account creation, role-derived login, server-side logout, secure Restaurant logo upload, and responsive English-only interfaces.

This work extends the completed server-authoritative migration. MySQL remains the only authority for accounts, applications, profiles, approval state, media metadata, sessions, notifications, and audit records.

## Approved Product Decisions

1. All authentication, onboarding, validation, notification, and Admin interface copy is English. No mixed-language labels are permitted.
2. Public onboarding uses separate pages for Customer, Restaurant, and Driver.
3. Admin accounts cannot be self-registered. An authorized Admin creates them from the internal Admin portal.
4. Customer accounts are active immediately after successful registration.
5. Restaurant and Driver submissions create pending applications only. They do not create login accounts until Admin approval.
6. Restaurant and Driver legal documents are not required. Existing document tables remain compatible but no approval rule depends on them.
7. Restaurant description and logo are optional. Driver vehicle color and Customer default delivery notes are optional. All other approved form fields are required.
8. The initial Restaurant opening and closing time applies to all seven weekdays. The approved Restaurant may customize weekly hours later through the existing profile/catalog flow.
9. No real email or SMS provider is part of this scope. In-app notifications and demo-compatible messaging remain supported.
10. No registration or approval authority may exist in `localStorage`.

## Screen Architecture

### Public screens

- `index.php`: redesigned sign-in page using the approved split image/form composition.
- `register.php`: public role selection for Customer, Restaurant, and Driver only.
- `register_customer.php`: Customer registration.
- `register_restaurant.php`: Restaurant owner and Restaurant registration, including optional logo upload.
- `register_driver.php`: Driver and vehicle registration.
- `registration_result.php`: post-registration success or pending-review result with a safe reference code.
- `forgot_password.php` and `reset_password.php`: authentication-shell recovery screens consistent with the redesign.

`auth.php` remains the login command boundary. `logout.php` revokes the server session, clears authentication state, and redirects to `index.php` with a success flash message.

Registration uses a POST/Redirect/GET result flow. The server stores the safe result in the submitter's session and displays it once on `registration_result.php`. There is no unauthenticated application-status lookup endpoint.

### Admin screens

- `admin_accounts.php` receives an internal create-Admin panel available only to a `super_admin` actor.
- Existing Restaurant and Driver review screens display every submitted field, optional Restaurant logo, current status, version, review note, and Approve/Reject actions.
- Public role selection never exposes an Admin registration option.

### Shared presentation components

The public pages share one authentication shell, common form primitives, notices, password controls, field errors, buttons, and responsive styles. PHP renders the accessible baseline. JavaScript progressively enhances password visibility, client-side feedback, logo preview, loading state, and asynchronous submission without owning business decisions.

## Visual and Responsive Design

The implementation follows the supplied mockups:

- dark forest-green branding and navigation;
- coral primary actions;
- ivory page background;
- white form cards with soft borders and shadows;
- sage informational and success notices;
- two-column desktop forms that collapse to one column on narrow screens.

The layout must remain usable at 320, 768, and 1440 pixel viewport widths with no page-level horizontal overflow. All inputs have explicit labels, visible keyboard focus, meaningful autocomplete attributes, accessible error association, and screen-reader-friendly status messages. Icon-only controls require accessible names.

Demo credentials appear only when `SAVORA_DEMO_MODE=1`. Production mode must not expose demo usernames or passwords.

## Field Contracts

### Customer

Required:

- full name;
- username;
- email;
- phone number;
- password;
- password confirmation;
- delivery address;
- acceptance of Terms of Service and Privacy Policy.

Optional:

- default delivery notes.

Successful registration creates an active `users` row with role `customer` and a matching `customer_profiles` row in one transaction. The user may sign in immediately.

### Restaurant

Required account fields:

- owner's full name;
- username;
- email;
- phone number;
- password;
- password confirmation.

Required Restaurant fields:

- Restaurant name;
- cuisine type;
- address;
- city;
- Restaurant phone number;
- opening time;
- closing time;
- acceptance of Restaurant Partner Terms.

Optional:

- Restaurant description;
- Restaurant profile image or logo.

Submission creates a `restaurant_applications` row with status `pending`. It does not create `users`, `restaurants`, or weekly-hours rows before approval.

### Driver

Required personal fields:

- full name;
- username;
- email;
- phone number;
- password;
- password confirmation;
- operating city or service area;
- acceptance of Driver Partner Terms.

Required vehicle fields:

- vehicle type;
- vehicle name or model;
- license plate.

Optional:

- vehicle color.

Submission creates a `driver_applications` row with status `pending`. It does not create `users` or `driver_profiles` before approval.

### Admin

The internal Admin creation form contains:

- full name;
- username;
- email;
- internal phone number;
- password;
- password confirmation;
- privilege level: `admin` or `super_admin`.

The selectable privilege levels are `admin` and `super_admin`. Both authenticate with the existing `admin` role and use the Admin portal, while the internal Admin profile controls privileged account-provisioning actions. Creation is active immediately, transactional, idempotent, and audited. Public requests cannot select the Admin role.

## Database Design

The next migration extends existing tables without deleting historical data.

### Customer profile

Add nullable `default_delivery_notes` to `customer_profiles`. Existing `address`, `email`, and `phone` fields remain compatible with the current profile service.

### Restaurant application and profile

Add the missing application fields:

- `description`;
- `restaurant_phone`;
- `opens_at`;
- `closes_at`.

Add the corresponding durable Restaurant profile fields needed after approval, including `description` and a nullable logo-media relationship. Initial hours are written to the existing `restaurant_weekly_hours` table for weekdays 0 through 6.

### Driver application

Add nullable `vehicle_color` to `driver_applications`. Approval copies it into the existing `driver_profiles.vehicle_color` column.

### Media assets

Add a media metadata table with:

- immutable public identifier;
- owner kind and owner identifier;
- purpose;
- randomized relative storage path;
- verified MIME type;
- byte size;
- SHA-256 digest;
- visibility/status;
- timestamps.

Restaurant application logos initially belong to the pending application and are Admin-only. Approval transfers ownership to the created Restaurant and makes the logo publicly readable through the controlled media route. Rejection removes the stored pending logo after the decision is durably recorded.

### Identity uniqueness

Username and email uniqueness must cover active users and pending Restaurant/Driver applications, including concurrent submissions. A database-backed identity-claim registry reserves normalized usernames and emails for either a user or pending application. Existing users are backfilled during migration. Approval transfers the claims to the new user; rejection releases them. The existing `users.username` unique constraint remains, and user email uniqueness is verified before adding or enforcing its unique index.

The migration must stop safely with a descriptive error if existing normalized identifiers collide. It must be repeatable and must not run during ordinary web requests.

### Admin privilege

Add an `admin_profiles` table keyed by `user_id` with `privilege_level`, `created_by`, and timestamps. Allowed levels are `admin` and `super_admin`. Existing Admin demo/account data is deterministically backfilled as `super_admin` so the project retains one account capable of provisioning internal Admin users. The service must never allow an operation that leaves Savora without an active `super_admin`.

## Server Components

### Registration repository and service

A focused registration repository owns prepared SQL for identity claims, Customer account/profile creation, and application persistence. A registration service owns normalization, validation, password hashing, transaction boundaries, duplicate handling, and safe result envelopes.

The page POST fallback and JSON API both call the same service. There is no duplicated business logic in page controllers or JavaScript.

### Partner application service

The existing partner application service is extended for all approved Restaurant and Driver fields. Mandatory legal-document checks are removed. Historical document support remains readable and does not block approval.

Restaurant submissions use multipart form data because the optional logo may be present. Driver submissions may use normal form or JSON input but share the same validation rules.

### Admin approval service

Approval locks the application and verifies pending status, expected version, identity claims, and idempotency before creating data.

Restaurant approval performs one transaction that:

1. creates an active `users` row with role `restaurant`;
2. creates the `restaurants` profile with all approved fields;
3. inserts initial hours for all seven weekdays;
4. transfers optional logo ownership;
5. transfers identity claims to the new user;
6. consumes the pending password hash;
7. updates application status and version;
8. writes audit and in-app notification records.

Driver approval performs the equivalent transaction for `users` and `driver_profiles`, including vehicle color. Repeated approval with the same idempotency key returns the original result and cannot create a second account.

Rejection records the reviewer and reason, consumes the password hash, releases identity claims, finalizes the application, and schedules safe pending-media cleanup. The supported final statuses are `approved` and `rejected`; no public resubmission workflow is introduced in this scope.

### Admin account creation service

Add a dedicated create-Admin command rather than overloading account intervention actions. Only an active `super_admin` may invoke it. The command validates the requested `admin` or `super_admin` level, identity uniqueness, and password; creates the user, Admin profile, and identifier claims; writes audit history; and stores the idempotent response in one transaction.

### Media service

The media service validates upload errors, detected MIME, extension, size, digest, and storage location. Allowed Restaurant logo types are JPEG, PNG, and WebP, with a maximum size of 5 MB. Files use randomized names outside the executable webroot.

The read route authorizes pending media for Admin only and serves approved public Restaurant logos with safe content headers. Stored paths are never accepted directly from request input or exposed as filesystem paths.

## API Contracts

### Customer registration

`POST api/registration.php`

Action: `register_customer`

Success: HTTP 201 with `ok`, a safe message, created user identifier, role, and next route. The response never starts an authenticated session automatically; the Customer signs in through the normal login boundary.

### Partner application

`POST api/partner_applications.php`

Action: `submit_application`

The response contains `ok`, a safe message, application identifier, immutable reference code, and `pending` status. It never returns the password hash or internal storage information.

### Admin creation and review

Admin mutations use authenticated POST requests with CSRF token, idempotency key, role authorization, and expected version where applicable. Responses follow the existing Savora envelope: `ok`, `message`, optional `data`, optional field `errors`, and `referenceId`.

## Authentication and Session Rules

- Login accepts username and password only.
- The role is always loaded from MySQL and mapped to the existing role dashboard.
- Only active `users` rows can authenticate.
- Pending applications cannot authenticate because no user exists.
- Successful login rotates the PHP session identifier and registers the server session.
- Logout revokes the current server-side session record, clears authentication data, expires the cookie, and redirects with an English success flash message.
- Session revocation and `session_version` checks remain authoritative.

## Validation and Error Handling

Server validation is canonical. Client validation provides earlier feedback but cannot weaken it.

- `409`: duplicate username/email, concurrent approval, or idempotency conflict.
- `419`: missing or invalid CSRF token.
- `422`: field, password, terms, time-range, or logo validation failure.
- `429`: rate limit exceeded.
- `500`: unexpected failure after transaction rollback, returned with a safe reference identifier.

Forms preserve all safe non-password values after failure. Password fields are cleared. Field errors appear next to their inputs and in an accessible summary. Submit controls are disabled only while a request is active, and retry never converts a failed server response into local success.

## Security Requirements

- CSRF protection on every registration and Admin mutation.
- Rate limits on registration, login, password recovery, and Admin account creation.
- Prepared SQL only.
- Password hashes created with `password_hash`; confirmation is never persisted.
- Output escaping for every persisted value rendered into HTML.
- Admin authorization enforced in services, never JavaScript.
- Idempotency and optimistic versions for approval, rejection, and Admin creation.
- MIME/extension/size validation and non-webroot storage for logo uploads.
- There is no public reference-code or pending-application lookup endpoint. Duplicate registration errors use one generic "Username or email is already in use" message and never identify which value matched.
- Demo credentials are environment-gated.
- No raw reset token, password, password hash, or physical upload path appears in logs, audit payloads, HTML, or API output.

## Testing Strategy

Implementation follows test-driven development. Each new behavior begins with a test that fails for the intended reason.

### Static and JavaScript tests

- required routes, labels, fields, form actions, autocomplete, and accessible error hooks;
- English-only authentication/onboarding copy;
- demo-credential gating;
- password visibility, confirmation feedback, logo preview, loading, retry, and duplicate-submit prevention;
- no authoritative registration or approval writes to `localStorage`.

### Migration and PHP service tests

- repeatable migration and exact schema contract;
- identity-claim backfill and collision failure;
- Customer account/profile atomicity;
- pending Restaurant/Driver application creation;
- optional logo storage and rollback cleanup;
- approval field transfer, initial seven-day hours, identity transfer, notification, and audit;
- rejection credential consumption, identity release, and media cleanup;
- duplicate and concurrent registration handling;
- Admin account creation authorization and idempotency.

### HTTP and security tests

- CSRF, rate limiting, validation envelopes, output escaping, upload MIME/size checks, and media authorization;
- pending partner login denial;
- Customer login routing after registration;
- approved Restaurant/Driver login routing;
- server-side logout and session revocation;
- duplicate approval cannot create duplicate users or profiles.

### Browser QA

Chrome QA covers login, role selection, every registration form, logo preview, success/pending result pages, internal Admin creation, approval review, error states, keyboard focus, and overflow at 320, 768, and 1440 pixels.

All integration tests use `SAVORA_ENV=test`, the `savora_test` database, and MySQL port 3307. Production data is never used or mutated.

## Acceptance Criteria

1. Authentication and onboarding UI is English-only and visually consistent with the approved mockups.
2. The public role-selection page offers Customer, Restaurant, and Driver, but not Admin.
3. Customer registration atomically creates an active account/profile and the Customer can sign in normally.
4. Restaurant/Driver registration creates only a pending application and returns a reference code.
5. No legal documents are required to submit or approve a partner application.
6. Restaurant logo upload is optional, secure, and correctly transferred on approval.
7. Pending applications cannot log in.
8. Approval creates exactly one active role account and complete profile; rejection creates none.
9. Internal Admin account creation is restricted to `super_admin`, audited, and unavailable publicly.
10. Login derives role from the database, and logout revokes server session state.
11. All visible form fields are persisted or explicitly identified as non-persisted password confirmation/terms acceptance.
12. Registration and approval have no authoritative browser-local writer.
13. Focused and full PHP, JavaScript, migration, integration, security, browser, lint, and diff checks pass.
14. Existing top-level Customer, Restaurant, Driver, and Admin routes continue to work.

## Out of Scope

- real email or SMS delivery providers;
- legal-document collection or third-party identity verification;
- social login or OAuth;
- automatic login immediately after Customer registration;
- public Admin self-registration;
- a partner application edit/resubmission portal;
- framework or language rewrites;
- unrelated dashboard redesigns.

## Git and Data Safety

Implementation stays in `D:\\Xampp\\htdocs\\Savora\\.worktrees\\server-authoritative-migration`. Existing uncommitted Phase changes and `.runtime-backups/` are preserved. No production database is used. No staging, commit, merge, push, or pull-request action occurs unless the user explicitly requests it.
