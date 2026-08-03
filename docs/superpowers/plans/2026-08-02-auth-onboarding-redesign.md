# Savora Authentication and Role Onboarding Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the approved English-only authentication and role-onboarding experience with immediate Customer activation, Admin-approved Restaurant/Driver accounts, internal Admin provisioning, secure Restaurant logos, and server-authoritative MySQL workflows.

**Architecture:** Keep Savora as server-rendered PHP with progressive JavaScript enhancement. Page controllers and JSON endpoints delegate to focused services and repositories; MySQL transactions own identity uniqueness, account/application creation, approval, sessions, media metadata, notifications, and audit history. Public pages share one authentication shell and never treat browser state as authority.

**Tech Stack:** PHP 8.2, mysqli, MariaDB/MySQL on XAMPP port 3307 for tests, HTML5, CSS3, vanilla JavaScript, Node.js built-in test runner, Chrome DevTools Protocol browser QA.

## Global Constraints

- Work only in `D:\Xampp\htdocs\Savora\.worktrees\server-authoritative-migration`.
- Preserve all existing uncommitted Phase changes and `.runtime-backups/`.
- Do not stage, commit, merge, push, or create a pull request unless the user explicitly requests it.
- Use `SAVORA_ENV=test`, `SAVORA_DB_NAME=savora_test`, and `SAVORA_DB_PORT=3307` for every integration test.
- Never run migrations, seeds, or tests against the production database.
- Authentication and onboarding interface copy must be English-only.
- Customer accounts become active immediately; Restaurant and Driver accounts do not exist before Admin approval.
- Legal documents are not required for Restaurant/Driver submission or approval.
- Restaurant description/logo, Driver vehicle color, and Customer default delivery notes are optional; all other approved fields are required.
- Demo credentials render only when `SAVORA_DEMO_MODE=1`.
- MySQL is the only authority; no registration, application, approval, role, session, or media state may be authoritative in `localStorage`.
- Build each behavior test-first and keep the existing top-level role routes operational.

---

## File Responsibility Map

### Schema and identity

- Create `database/migrations/015_auth_onboarding.php`: additive onboarding schema, identity claims, media assets, and Admin profiles.
- Modify `lib/migrations.php`: register migration `015_auth_onboarding` after `014_partner_document_storage`.
- Create `tests/auth_onboarding_migration_test.php`: schema, backfill, uniqueness, and repeatability integration coverage.
- Modify `tests/migration_registry.test.js` and `tests/migration_integrity_test.php`: include the new migration contract.

### Customer registration

- Create `lib/repositories/registration_repository.php`: prepared SQL for identity claims and Customer account/profile creation.
- Create `lib/services/registration_service.php`: normalization, validation, password hashing, transactions, and result envelopes.
- Create `api/registration.php`: guarded public Customer registration endpoint.
- Create `tests/registration_service_test.php`: atomic creation, duplicates, validation, and rollback coverage.
- Create `tests/registration_api_contract.test.js`: endpoint security and response-envelope contract.

### Media

- Create `lib/services/media_service.php`: logo validation, non-webroot storage, metadata, ownership transfer/revocation, and cleanup.
- Create `media.php`: controlled logo read route.
- Create `tests/media_service_test.php`: MIME, extension, size, hash, ownership, visibility, and rollback cleanup coverage.
- Create `tests/media_endpoint_contract.test.js`: route authorization and safe-header static contract.

### Partner applications

- Modify `lib/repositories/partner_application_repository.php`: persist approved Restaurant/Driver fields and identity claims.
- Modify `lib/services/partner_application_service.php`: remove mandatory documents, add full fields, optional logo, and claim transactions.
- Modify `api/partner_applications.php`: accept the new multipart field contract and CSRF/rate-limit guard.
- Modify `tests/partner_application_service_test.php`, `tests/partner_upload_service_test.php`, and `tests/partner_application_markup.test.js`: replace mandatory-document expectations with approved data/logo behavior.

### Public frontend

- Create `components/auth_header.php` and `components/auth_footer.php`: shared public authentication shell.
- Create `css/auth.css`: approved forest/coral/ivory/sage responsive design system.
- Create `js/auth_forms.js`: progressive form enhancement and pure validation helpers.
- Create `register.php`, `register_customer.php`, `register_restaurant.php`, `register_driver.php`, `registration_result.php`, and `forgot_password.php`.
- Modify `index.php` and `reset_password.php`: use the shared shell.
- Create `tests/auth_onboarding_markup.test.js` and `tests/auth_forms.test.js`.

### Admin review and provisioning

- Modify `lib/admin_repository.php`: return all application fields, media, and Admin privilege data.
- Modify `lib/services/admin_partner_service.php`: approve/reject without legal documents and transfer all profile data atomically.
- Create `lib/services/admin_provisioning_service.php`: `super_admin`-only Admin account creation.
- Modify `lib/admin_actions.php`: route `create_admin_account` to the provisioning service.
- Modify `admin_restaurants.php`, `admin_drivers.php`, `admin_accounts.php`, and `js/admin_ui.js`.
- Modify `tests/admin_approvals_test.php`, `tests/admin_partners.test.js`, and `tests/admin_identity_action_test.php`.
- Create `tests/admin_provisioning_service_test.php`.

### Authentication and release verification

- Modify `auth.php`: preserve DB-derived role routing and return English flash errors.
- Modify `logout.php`: revoke the current server session and create a safe logout flash.
- Modify `tests/session_security_test.php`, `tests/production_security.test.js`, and `tests/task29_browser_qa.mjs`.
- Create `tests/auth_onboarding_http_test.php`: cross-role registration, approval, login, and logout HTTP flow.

---

### Task 1: Add the Onboarding Schema Contract

**Files:**
- Create: `database/migrations/015_auth_onboarding.php`
- Modify: `lib/migrations.php`
- Create: `tests/auth_onboarding_migration_test.php`
- Modify: `tests/migration_registry.test.js`
- Modify: `tests/migration_integrity_test.php`

**Interfaces:**
- Produces: tables `identity_claims`, `media_assets`, `admin_profiles`; approved new columns; migration key `015_auth_onboarding`.
- Consumes: existing `users`, `customer_profiles`, `restaurant_applications`, `driver_applications`, `restaurants`, `driver_profiles`, and `restaurant_weekly_hours` tables.

- [ ] **Step 1: Write the failing migration registry and schema tests**

Add this registry assertion to `tests/migration_registry.test.js`:

```js
test('auth onboarding migration is registered after partner storage', () => {
  const source = read('lib/migrations.php');
  const previous = source.indexOf("'014_partner_document_storage'");
  const onboarding = source.indexOf("'015_auth_onboarding'");
  assert.ok(previous >= 0 && onboarding > previous);
  assert.match(source, /database\/migrations\/015_auth_onboarding\.php/);
});
```

Create `tests/auth_onboarding_migration_test.php` with test-environment guards and assertions for this exact contract:

```php
$required = [
    'customer_profiles' => ['default_delivery_notes'],
    'restaurant_applications' => ['description','restaurant_phone','opens_at','closes_at'],
    'driver_applications' => ['vehicle_color'],
    'restaurants' => ['description','logo_media_id'],
    'identity_claims' => ['identifier_type','normalized_value','owner_kind','owner_id'],
    'media_assets' => ['public_id','owner_kind','owner_id','purpose','stored_path','mime_type','file_size','sha256','visibility','status'],
    'admin_profiles' => ['user_id','privilege_level','created_by'],
];
```

The test must call the migration twice, assert one identity claim per non-empty existing username/email, assert one `admin_profiles` row for every existing Admin, and assert the unique key on `(identifier_type, normalized_value)`.

- [ ] **Step 2: Run the focused tests and confirm the expected failure**

Run:

```powershell
node --test tests/migration_registry.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/auth_onboarding_migration_test.php
```

Expected: the Node test fails because migration `015_auth_onboarding` is absent; the PHP test fails because its migration file/tables are absent.

- [ ] **Step 3: Implement migration 015 and register it**

Implement idempotent `ensureColumn`, `ensureUniqueIndex`, and schema-verification helpers in `015_auth_onboarding.php`. Create these definitions:

```sql
CREATE TABLE IF NOT EXISTS identity_claims (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  identifier_type VARCHAR(20) NOT NULL,
  normalized_value VARCHAR(190) NOT NULL,
  owner_kind VARCHAR(40) NOT NULL,
  owner_id BIGINT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_identity_claim (identifier_type, normalized_value),
  KEY idx_identity_owner (owner_kind, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_assets (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  public_id VARCHAR(60) NOT NULL,
  owner_kind VARCHAR(40) NOT NULL,
  owner_id BIGINT NOT NULL,
  purpose VARCHAR(40) NOT NULL,
  stored_path VARCHAR(500) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size INT NOT NULL,
  sha256 CHAR(64) NOT NULL,
  visibility VARCHAR(20) NOT NULL DEFAULT 'private',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_media_public_id (public_id),
  KEY idx_media_owner (owner_kind, owner_id, purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_profiles (
  user_id INT NOT NULL PRIMARY KEY,
  privilege_level VARCHAR(30) NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_admin_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_admin_profile_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Add columns using these exact definitions:

```php
$columns = [
    ['customer_profiles','default_delivery_notes','VARCHAR(500) NULL'],
    ['restaurant_applications','description','VARCHAR(1000) NULL'],
    ['restaurant_applications','restaurant_phone','VARCHAR(40) NULL'],
    ['restaurant_applications','opens_at','TIME NULL'],
    ['restaurant_applications','closes_at','TIME NULL'],
    ['driver_applications','vehicle_color','VARCHAR(80) NULL'],
    ['restaurants','description','VARCHAR(1000) NULL'],
    ['restaurants','logo_media_id','BIGINT NULL'],
];
```

After `media_assets` exists, add foreign key `fk_restaurant_logo_media` from `restaurants.logo_media_id` to `media_assets.id` with `ON DELETE SET NULL`, and verify the exact relationship through `information_schema`.

Before backfill, query normalized duplicate usernames/emails and throw `RuntimeException('Existing identity values collide after normalization.')` when any count exceeds one. Backfill claims with `LOWER(TRIM(...))`, backfill every role `admin` as `super_admin`, and add `uq_users_email` only after verifying non-empty email uniqueness. Register the migration in `savora_migrations()`.

- [ ] **Step 4: Run migration tests until green**

Run the commands from Step 2 plus:

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/migration_integrity_test.php
```

Expected: all three commands exit 0 and the PHP tests print PASS messages for the new contract.

- [ ] **Step 5: Review checkpoint**

Run `git diff --check -- database/migrations/015_auth_onboarding.php lib/migrations.php tests/auth_onboarding_migration_test.php tests/migration_registry.test.js tests/migration_integrity_test.php`. Expected: exit 0. Do not stage or commit.

---

### Task 2: Implement Atomic Customer Registration

**Files:**
- Create: `lib/repositories/registration_repository.php`
- Create: `lib/services/registration_service.php`
- Create: `api/registration.php`
- Create: `tests/registration_service_test.php`
- Create: `tests/registration_api_contract.test.js`

**Interfaces:**
- Produces: `registration_normalize_identifier(string): string`, `registration_register_customer(mysqli,array): array`, and `POST api/registration.php` action `register_customer`.
- Consumes: Task 1 identity claims and Customer profile schema; existing HTTP, CSRF, and rate-limit helpers.

- [ ] **Step 1: Write failing Customer service tests**

Create unique test input and assert this exact behavior:

```php
$payload = [
    'fullName' => 'Onboarding Customer',
    'username' => $username,
    'email' => $email,
    'phone' => '+1 555 010 2200',
    'password' => 'Strong-Customer-123!',
    'passwordConfirmation' => 'Strong-Customer-123!',
    'deliveryAddress' => '220 Test Avenue, Central City',
    'defaultDeliveryNotes' => 'Leave at reception',
    'acceptedTerms' => true,
];
$created = registration_register_customer($conn, $payload);
registration_expect($created['ok'] === true && $created['status'] === 201, 'Customer registration must succeed.');
registration_expect($created['data']['role'] === 'customer', 'Created role must be customer.');
```

Assert one active `users` row, one `customer_profiles` row, two identity claims, a verifiable password hash, duplicate response status 409, mismatch response 422, and no partial rows after a forced profile insert failure.

- [ ] **Step 2: Run the service test and confirm it fails**

Run:

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/registration_service_test.php
```

Expected: FAIL because `registration_register_customer()` is undefined.

- [ ] **Step 3: Implement repository and service interfaces**

Implement these repository functions with prepared SQL:

```php
function registration_repository_claim(mysqli $conn, string $type, string $value, string $ownerKind, int $ownerId): void;
function registration_repository_transfer_claims(mysqli $conn, string $fromKind, int $fromId, string $toKind, int $toId): void;
function registration_repository_release_claims(mysqli $conn, string $ownerKind, int $ownerId): void;
function registration_repository_create_user(mysqli $conn, array $data): int;
function registration_repository_create_customer_profile(mysqli $conn, int $userId, array $data): void;
```

Implement canonical service normalization and payload validation:

```php
function registration_normalize_identifier(string $value): string
{
    return mb_strtolower(trim($value));
}

function registration_text(mixed $value, int $maximum, string $field, bool $required = true): string
{
    $text = trim((string) $value);
    if (($required && $text === '') || mb_strlen($text) > $maximum) {
        throw new InvalidArgumentException($field . ' is invalid.');
    }
    return $text;
}

function registration_customer_payload(array $input): array
{
    $username = registration_normalize_identifier((string) ($input['username'] ?? ''));
    $email = registration_normalize_identifier((string) ($input['email'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $confirmation = (string) ($input['passwordConfirmation'] ?? '');
    if (!preg_match('/^[a-z0-9_-]{3,50}$/', $username)) throw new InvalidArgumentException('Username must contain 3-50 letters, numbers, underscores, or hyphens.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    if (strlen($password) < 10 || $password !== $confirmation) throw new InvalidArgumentException('Passwords must match and contain at least 10 characters.');
    $accepted = filter_var($input['acceptedTerms'] ?? false, FILTER_VALIDATE_BOOL);
    if (!$accepted) throw new InvalidArgumentException('Accept the Terms of Service and Privacy Policy.');
    return [
        'fullName' => registration_text($input['fullName'] ?? '', 120, 'Full name'),
        'username' => $username,
        'email' => $email,
        'phone' => registration_text($input['phone'] ?? '', 40, 'Phone number'),
        'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
        'address' => registration_text($input['deliveryAddress'] ?? '', 500, 'Delivery address'),
        'notes' => registration_text($input['defaultDeliveryNotes'] ?? '', 500, 'Default delivery notes', false),
    ];
}
```

`registration_register_customer()` must begin a transaction, create the user, claim username/email, create the profile, commit, and return status 201. Catch duplicate-key failures as one generic status 409 response and rollback every other failure.

- [ ] **Step 4: Add endpoint contract and endpoint**

The static contract must require POST, `savora_require_csrf`, rate-limit scope `registration`, JSON/form input support, and this success envelope:

```php
[
    'ok' => true,
    'message' => 'Your Customer account is ready. Sign in to continue.',
    'data' => ['userId' => $userId, 'role' => 'customer', 'next' => 'index.php'],
]
```

`api/registration.php` delegates only action `register_customer`; unsupported actions return 422.

- [ ] **Step 5: Verify Task 2**

Run:

```powershell
node --test tests/registration_api_contract.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/registration_service_test.php
git diff --check -- lib/repositories/registration_repository.php lib/services/registration_service.php api/registration.php tests/registration_service_test.php tests/registration_api_contract.test.js
```

Expected: all commands exit 0. Do not stage or commit.

---

### Task 3: Add Secure Restaurant Logo Storage and Serving

**Files:**
- Create: `lib/services/media_service.php`
- Create: `media.php`
- Create: `tests/media_service_test.php`
- Create: `tests/media_endpoint_contract.test.js`

**Interfaces:**
- Produces: `media_store_restaurant_logo(mysqli,array,string,int): array`, `media_transfer(mysqli,int,string,int,string): void`, `media_revoke(mysqli,int): ?string`, and `media_find_public(mysqli,string): array`.
- Consumes: Task 1 `media_assets`; existing `SAVORA_UPLOAD_ROOT`, session validation, and Admin authorization.

- [ ] **Step 1: Write failing media tests**

Use a temporary upload root and a real 1x1 PNG fixture. Assert:

```php
$stored = media_store_restaurant_logo($conn, $pngFile, 'restaurant_application', $applicationId);
media_expect($stored['mimeType'] === 'image/png', 'Detected MIME must be PNG.');
media_expect($stored['visibility'] === 'private', 'Pending logo must be private.');
media_expect(is_file($uploadRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $stored['storedPath'])), 'Logo file must exist.');
```

Also assert rejection of a renamed text file, SVG, file over 5 MB, missing upload, and a storage root inside the project. Assert transfer changes owner/visibility without exposing `stored_path`; revocation makes the endpoint unreadable and returns a cleanup path.

- [ ] **Step 2: Run and confirm the expected failure**

Run:

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/media_service_test.php
```

Expected: FAIL because the media service does not exist.

- [ ] **Step 3: Implement the media service**

Use this exact allowlist and limit:

```php
const SAVORA_RESTAURANT_LOGO_MAX_BYTES = 5 * 1024 * 1024;
const SAVORA_RESTAURANT_LOGO_MIMES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];
```

Generate public IDs as `MED-` plus 24 uppercase hex characters and relative paths as `restaurant-logos/YYYY/MM/<36-random-hex>.<extension>`. Validate upload error, source file, size, detected MIME, matching extension, SHA-256, resolved non-webroot storage, and prepared metadata insertion. Return public metadata only; keep physical paths local to cleanup code.

- [ ] **Step 4: Implement the controlled read route**

`media.php` accepts `GET ?asset=<public-id>`, loads metadata, and applies these rules:

```php
$public = $asset['status'] === 'active' && $asset['visibility'] === 'public';
$admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin' && savora_validate_session($conn, $_SESSION, session_id(), 'admin')['ok'];
if (!$public && !$admin) {
    http_response_code(404);
    exit;
}
```

Resolve the stored relative path beneath `SAVORA_UPLOAD_ROOT`, reject traversal, send `Content-Type`, `Content-Length`, `X-Content-Type-Options: nosniff`, and a bounded cache policy. Never print the physical path.

- [ ] **Step 5: Verify Task 3**

Run:

```powershell
node --test tests/media_endpoint_contract.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/media_service_test.php
git diff --check -- lib/services/media_service.php media.php tests/media_service_test.php tests/media_endpoint_contract.test.js
```

Expected: all commands exit 0. Do not stage or commit.

---

### Task 4: Replace Document-Gated Partner Applications

**Files:**
- Modify: `lib/repositories/partner_application_repository.php`
- Modify: `lib/services/partner_application_service.php`
- Modify: `api/partner_applications.php`
- Modify: `tests/partner_application_service_test.php`
- Modify: `tests/partner_upload_service_test.php`
- Modify: `tests/partner_application_markup.test.js`

**Interfaces:**
- Produces: `partner_submit_application(mysqli,string,array,array): array` with no legal-document requirement and optional `logo` file for Restaurant.
- Consumes: Task 1 identity claims, Task 2 registration claim repository, and Task 3 media service.

- [ ] **Step 1: Rewrite focused tests to the approved contract**

Driver submission must succeed with an empty file array and this payload:

```php
[
    'username' => $username,
    'password' => 'Strong-Driver-123!',
    'passwordConfirmation' => 'Strong-Driver-123!',
    'fullName' => 'Pending Driver',
    'email' => $email,
    'phone' => '+1 555 010 3300',
    'city' => 'Central City',
    'serviceArea' => 'Central District',
    'vehicleType' => 'Motorcycle',
    'vehicleModel' => 'Savora Test Bike',
    'licensePlate' => 'TEST-3300',
    'vehicleColor' => 'Forest Green',
    'acceptedTerms' => true,
]
```

Restaurant submission must persist owner fields, description, Restaurant phone, cuisine, address, city, `09:00:00`, `22:00:00`, and an optional valid logo. Assert status pending, zero users created, two identity claims created, and no legal-document rows required.

- [ ] **Step 2: Run tests and confirm old document requirements fail**

Run:

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/partner_application_service_test.php
node --test tests/partner_application_markup.test.js
```

Expected: failures reference missing required documents or old `index.php?apply=` markup.

- [ ] **Step 3: Implement the new partner payloads and transaction**

Restaurant service payload keys are exactly:

```php
[
    'reference','username','passwordHash','ownerName','ownerEmail','ownerPhone',
    'restaurantName','description','cuisine','address','city','restaurantPhone',
    'opensAt','closesAt'
]
```

Driver service payload keys are exactly:

```php
[
    'reference','username','passwordHash','fullName','email','phone','city',
    'serviceArea','vehicleType','vehicleModel','licensePlate','vehicleColor'
]
```

Validate matching 10-character passwords, accepted terms with `filter_var($value, FILTER_VALIDATE_BOOL)`, required strings, normalized email/username, and `opensAt < closesAt`. Start one transaction, create the application, claim username/email as `restaurant_application` or `driver_application`, store an optional Restaurant logo, and commit. On failure rollback DB writes and unlink newly stored files.

Remove mandatory calls to `partner_required_document_types()` from submission and approval paths. Keep document metadata functions available for historical data compatibility.

- [ ] **Step 4: Harden the API boundary**

Start the session, issue/verify public CSRF, consume rate-limit scope `partner_registration`, and accept `$_FILES['logo']` for Restaurant. Preserve the existing response envelope while never returning file paths or password material.

- [ ] **Step 5: Verify Task 4**

Run:

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/partner_application_service_test.php
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/partner_upload_service_test.php
node --test tests/partner_application_markup.test.js
git diff --check -- lib/repositories/partner_application_repository.php lib/services/partner_application_service.php api/partner_applications.php tests/partner_application_service_test.php tests/partner_upload_service_test.php tests/partner_application_markup.test.js
```

Expected: all commands exit 0. Do not stage or commit.

---

### Task 5: Build the Shared Authentication Frontend System

**Files:**
- Create: `components/auth_header.php`
- Create: `components/auth_footer.php`
- Create: `css/auth.css`
- Create: `js/auth_forms.js`
- Create: `tests/auth_forms.test.js`
- Create: `tests/auth_onboarding_markup.test.js`

**Interfaces:**
- Produces: shared `$authPageTitle`, `$authPageClass`, `SavoraAuth.validatePassword`, `SavoraAuth.passwordsMatch`, and data attributes used by all public onboarding pages.
- Consumes: existing Savora assets and environment-gated demo mode.

- [ ] **Step 1: Write failing frontend contracts**

The markup test must require shared shell imports, one `<main>`, skip-link target, English labels, and these data hooks:

```js
for (const hook of [
  'data-auth-form', 'data-password-toggle', 'data-password-strength',
  'data-password-confirmation', 'data-submit-label', 'data-form-summary'
]) assert.match(read('js/auth_forms.js') + pages, new RegExp(hook));
```

The JavaScript test must require the module and assert:

```js
assert.equal(auth.validatePassword('short').ok, false);
assert.equal(auth.validatePassword('Strong-Pass-123!').ok, true);
assert.equal(auth.passwordsMatch('Strong-Pass-123!', 'Strong-Pass-123!'), true);
```

- [ ] **Step 2: Run and confirm missing-file failures**

Run `node --test tests/auth_forms.test.js tests/auth_onboarding_markup.test.js`.

Expected: FAIL because the shared files and pages do not exist.

- [ ] **Step 3: Implement the shared PHP shell and CSS tokens**

Define these CSS custom properties and responsive contract:

```css
:root {
  --auth-forest: #063b2c;
  --auth-forest-dark: #03291f;
  --auth-coral: #ff4438;
  --auth-coral-dark: #dc352d;
  --auth-ivory: #fbfaf5;
  --auth-sage: #eef1e6;
  --auth-ink: #13251e;
  --auth-muted: #66716c;
  --auth-border: #dfe4dc;
  --auth-focus: #1769e0;
}
@media (max-width: 768px) {
  .auth-grid, .auth-form-grid { grid-template-columns: 1fr; }
  .auth-brand-panel { min-height: 220px; }
}
```

`auth_header.php` starts the document, renders the brand/navigation and opens `<main id="main-content">`. `auth_footer.php` closes it, includes `js/auth_forms.js`, and closes the document. Both escape variables with `htmlspecialchars`.

- [ ] **Step 4: Implement progressive JavaScript**

Export pure helpers in Node and bind browser behavior in an IIFE:

```js
const api = {
  validatePassword(value) {
    const ok = typeof value === 'string' && value.length >= 10;
    return { ok, message: ok ? '' : 'Use at least 10 characters.' };
  },
  passwordsMatch(password, confirmation) {
    return password.length > 0 && password === confirmation;
  }
};
if (typeof module !== 'undefined' && module.exports) module.exports = api;
```

Browser binding toggles password visibility, updates strength/match messages, previews a selected logo with `URL.createObjectURL`, revokes the previous object URL, disables only the active submit button, renders field errors, and prevents duplicate in-flight submission. It must not write to `localStorage`.

- [ ] **Step 5: Verify Task 5**

Run:

```powershell
node --test tests/auth_forms.test.js tests/auth_onboarding_markup.test.js
git diff --check -- components/auth_header.php components/auth_footer.php css/auth.css js/auth_forms.js tests/auth_forms.test.js tests/auth_onboarding_markup.test.js
```

Expected: all commands exit 0. Do not stage or commit.

---

### Task 6: Build Public Role Selection and Registration Pages

**Files:**
- Create: `register.php`
- Create: `register_customer.php`
- Create: `register_restaurant.php`
- Create: `register_driver.php`
- Create: `registration_result.php`
- Modify: `tests/auth_onboarding_markup.test.js`

**Interfaces:**
- Produces: public GET/POST page routes and one-time session result key `registration_result`.
- Consumes: Tasks 2, 4, and 5 services/shell; public CSRF token stored in session.

- [ ] **Step 1: Add failing page-field assertions**

Require these exact form names:

```js
const customer = ['fullName','username','email','phone','password','passwordConfirmation','deliveryAddress','defaultDeliveryNotes','acceptedTerms'];
const restaurant = ['ownerName','username','email','phone','password','passwordConfirmation','restaurantName','description','cuisine','address','city','restaurantPhone','opensAt','closesAt','logo','acceptedTerms'];
const driver = ['fullName','username','email','phone','password','passwordConfirmation','city','serviceArea','vehicleType','vehicleModel','licensePlate','vehicleColor','acceptedTerms'];
```

Assert `register.php` links only Customer, Restaurant, and Driver; it must not contain a public Admin registration link.

- [ ] **Step 2: Run markup test and confirm missing-page failures**

Run `node --test tests/auth_onboarding_markup.test.js`.

Expected: FAIL naming the first absent page or field.

- [ ] **Step 3: Implement role selection and Customer page**

`register.php` renders three role cards and a `Sign in` link. `register_customer.php` uses POST/Redirect/GET: validate CSRF, call `registration_register_customer()`, set this result, then redirect:

```php
$_SESSION['registration_result'] = [
    'kind' => 'customer_active',
    'title' => 'Your account is ready',
    'message' => 'Sign in to start ordering with Savora.',
    'referenceCode' => '',
];
header('Location: registration_result.php');
exit;
```

On 409/422, render safe field errors and all non-password submitted values.

- [ ] **Step 4: Implement Restaurant, Driver, and result pages**

Restaurant uses `multipart/form-data` and delegates to `partner_submit_application($conn, 'restaurant', $_POST, ['logo' => $_FILES['logo'] ?? []])`. Driver delegates with an empty file array. On success, store:

```php
$_SESSION['registration_result'] = [
    'kind' => 'partner_pending',
    'title' => 'Application submitted',
    'message' => 'Your application is waiting for Admin approval.',
    'referenceCode' => (string) $result['data']['referenceCode'],
];
```

`registration_result.php` reads and unsets the session value. Direct access without a result redirects to `register.php`. It offers `Return to sign in`; it does not query by reference code.

- [ ] **Step 5: Verify Task 6**

Run:

```powershell
node --test tests/auth_onboarding_markup.test.js tests/auth_forms.test.js
php -l register.php
php -l register_customer.php
php -l register_restaurant.php
php -l register_driver.php
php -l registration_result.php
git diff --check -- register.php register_customer.php register_restaurant.php register_driver.php registration_result.php tests/auth_onboarding_markup.test.js
```

Expected: all commands exit 0. Do not stage or commit.

---

### Task 7: Cut Admin Partner Approval Over to the New Application Data

**Files:**
- Modify: `lib/admin_repository.php`
- Modify: `lib/services/admin_partner_service.php`
- Modify: `admin_restaurants.php`
- Modify: `admin_drivers.php`
- Modify: `js/admin_ui.js`
- Modify: `tests/admin_approvals_test.php`
- Modify: `tests/admin_partners.test.js`

**Interfaces:**
- Produces: document-independent `approve_restaurant`, `reject_restaurant`, `approve_driver`, and `reject_driver` behavior.
- Consumes: Task 1 identity/media schema, Task 2 claim repository, Task 3 media transfer/revoke, and Task 4 application rows.

- [ ] **Step 1: Rewrite failing approval integration tests**

Create pending applications without document rows. Restaurant assertions must verify one user, one Restaurant profile, seven weekly-hour rows, transferred description/phone/logo, transferred claims, consumed password hash, notification, and audit row. Driver assertions must verify vehicle model, plate, color, service area, and claims. Retry approval with the same idempotency key and assert byte-for-byte equal response and one created account.

Add rejection assertions:

```php
admin_approval_expect($rejected['ok'] === true, 'Rejection must succeed.');
admin_approval_expect($application['status'] === 'rejected' && $application['password_hash'] === null, 'Rejection must finalize credentials.');
admin_approval_expect($claimCount === 0, 'Rejection must release identity claims.');
admin_approval_expect($userCount === 0, 'Rejection must not create a user.');
```

- [ ] **Step 2: Run and confirm document-gate failures**

Run:

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/admin_approvals_test.php
node --test tests/admin_partners.test.js
```

Expected: PHP fails because the current service requires verified documents; markup tests fail on document-review copy.

- [ ] **Step 3: Implement transactional approval/rejection**

Remove required-document queries and `changes_requested` from reviewable/final transitions. Restaurant approval inserts:

```sql
INSERT INTO restaurants
  (owner_user_id,name,description,cuisine,address,city,phone,status,accepting_orders)
VALUES (?,?,?,?,?,?,?,'active',0)
```

Then insert weekdays 0 through 6 with application `opens_at` and `closes_at`, transfer identity claims from `restaurant_application` to `user`, transfer logo media to `restaurant` with public visibility, consume credentials, update version, queue notification, append audit, and store the idempotent result before commit.

Driver approval inserts all approved vehicle/profile fields, including `vehicle_color`, and performs the same claim/credential/audit sequence. Rejection releases claims, marks media revoked/private, consumes credentials, commits the decision, then attempts physical cleanup outside the transaction.

- [ ] **Step 4: Replace Admin document UI with information review UI**

Restaurant detail displays owner name/email/phone, username, Restaurant name/description/cuisine/address/city/phone/hours, optional controlled logo, status, reference, and version. Driver detail displays personal contact, username, city/service area, vehicle type/model/plate/color, status, reference, and version. Remove `Request Changes`, `Document Review`, document-alert language, and expiry warnings. Keep Approve and Reject with reviewer note required for rejection.

- [ ] **Step 5: Verify Task 7**

Run:

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/admin_approvals_test.php
node --test tests/admin_partners.test.js
git diff --check -- lib/admin_repository.php lib/services/admin_partner_service.php admin_restaurants.php admin_drivers.php js/admin_ui.js tests/admin_approvals_test.php tests/admin_partners.test.js
```

Expected: all commands exit 0. Do not stage or commit.

---

### Task 8: Add Internal Admin and Super Admin Provisioning

**Files:**
- Create: `lib/services/admin_provisioning_service.php`
- Modify: `lib/admin_actions.php`
- Modify: `lib/admin_repository.php`
- Modify: `admin_accounts.php`
- Modify: `js/admin_ui.js`
- Create: `tests/admin_provisioning_service_test.php`
- Modify: `tests/admin_identity_action_test.php`
- Modify: `tests/admin_identity.test.js`

**Interfaces:**
- Produces: `admin_provision_account(mysqli,array,int,string): array` and action `create_admin_account`.
- Consumes: Task 1 `admin_profiles` and identity claims; existing idempotency, audit, CSRF, and Admin action dispatch.

- [ ] **Step 1: Write failing authorization and idempotency tests**

Test payload:

```php
$payload = [
    'full_name' => 'Operations Admin',
    'username' => $username,
    'email' => $email,
    'phone' => '+1 555 010 4400',
    'password' => 'Strong-Admin-123!',
    'password_confirmation' => 'Strong-Admin-123!',
    'privilege_level' => 'admin',
];
```

Assert a normal `admin` actor receives 403, a `super_admin` creates exactly one active role `admin` user/profile, repeated idempotency returns the same response, invalid privilege receives 422, duplicates receive 409, and public registration endpoints cannot select role Admin.

- [ ] **Step 2: Run and confirm the missing-service failure**

Run:

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/admin_provisioning_service_test.php
```

Expected: FAIL because `admin_provision_account()` is undefined.

- [ ] **Step 3: Implement provisioning and dispatch**

Authorize with this query and invariant:

```sql
SELECT u.status, ap.privilege_level
FROM users u
JOIN admin_profiles ap ON ap.user_id=u.id
WHERE u.id=? AND u.role='admin'
```

Only `status='active'` and `privilege_level='super_admin'` may continue. Validate names, normalized identity, matching 10-character passwords, and privilege in `['admin','super_admin']`. In one transaction create role `admin` user, profile, claims, audit row, and idempotent response. Return 403/409/422 with the standard envelope.

Add `create_admin_account` to `admin_execute_action()` routing and require an idempotency key through the existing `admin_action.php` boundary. Provisioning responses include an internal `status` integer; `admin_action.php` removes it before JSON encoding and uses it as the HTTP status so forbidden, duplicate, and validation results remain 403, 409, and 422 rather than collapsing to one status.

- [ ] **Step 4: Implement Admin portal creation UI**

Render the create panel only when the current actor's Admin profile is `super_admin`. Use the approved fields, privilege segmented control, password confirmation, immediate-activation notice, and `data-admin-create-account`. Extend `js/admin_ui.js` to POST:

```js
{
  action: 'create_admin_account',
  payload: {
    full_name, username, email, phone,
    password, password_confirmation, privilege_level
  }
}
```

Use existing CSRF and generated idempotency headers. Clear passwords on every response and refresh the account list only on success.

- [ ] **Step 5: Verify Task 8**

Run:

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/admin_provisioning_service_test.php
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/admin_identity_action_test.php
node --test tests/admin_identity.test.js
git diff --check -- lib/services/admin_provisioning_service.php lib/admin_actions.php lib/admin_repository.php admin_accounts.php js/admin_ui.js tests/admin_provisioning_service_test.php tests/admin_identity_action_test.php tests/admin_identity.test.js
```

Expected: all commands exit 0. Do not stage or commit.

---

### Task 9: Redesign Login, Logout, and Password Recovery

**Files:**
- Modify: `index.php`
- Modify: `auth.php`
- Modify: `logout.php`
- Create: `forgot_password.php`
- Modify: `reset_password.php`
- Modify: `tests/session_security_test.php`
- Modify: `tests/production_security.test.js`
- Modify: `tests/auth_onboarding_markup.test.js`

**Interfaces:**
- Produces: DB-derived login routing, logout flash `auth_notice`, consistent recovery screens, and demo-mode credential gating.
- Consumes: Task 5 authentication shell and existing session security/rate-limit services.

- [ ] **Step 1: Add failing authentication markup/session tests**

Assert `index.php` includes the shared shell, username/password, password toggle, forgot-password link, create-account link, and demo output inside `savora_demo_mode()`. Assert `auth.php` never accepts `$_POST['role']`. Extend session test to create a registered session, call logout behavior, and assert the matching `user_sessions.revoked_at` is non-null.

- [ ] **Step 2: Run and confirm old login/logout UI failures**

Run:

```powershell
node --test tests/production_security.test.js tests/auth_onboarding_markup.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/session_security_test.php
```

Expected: markup fails against the old login page or logout success state.

- [ ] **Step 3: Redesign login and preserve role authority**

Render the approved split image/form login. Keep the current prepared lookup, `password_verify`, active-status check, session rotation, server session registration, and destination map:

```php
$destinations = [
    'customer' => 'customer_dashboard.php',
    'restaurant' => 'restaurant_dashboard.php',
    'driver' => 'driver_dashboard.php',
    'admin' => 'admin_dashboard.php',
];
```

All flash errors are English and escaped. Demo accounts appear only inside `if (savora_demo_mode())`.

- [ ] **Step 4: Complete logout and recovery presentation**

After revocation/end-session, start a fresh safe session only to store:

```php
$_SESSION['auth_notice'] = 'You have signed out successfully.';
```

Redirect to `index.php`, render/unset the escaped notice, and ensure the old session cookie is expired by `savora_end_session()`. Build `forgot_password.php` as an English informational/demo-compatible page with a generic response that does not reveal account existence. Refactor `reset_password.php` into the shared shell without changing its one-time token, rate-limit, password update, session-version increment, and token-consumption rules.

- [ ] **Step 5: Verify Task 9**

Run:

```powershell
node --test tests/production_security.test.js tests/auth_onboarding_markup.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/session_security_test.php
php -l index.php
php -l auth.php
php -l logout.php
php -l forgot_password.php
php -l reset_password.php
git diff --check -- index.php auth.php logout.php forgot_password.php reset_password.php tests/session_security_test.php tests/production_security.test.js tests/auth_onboarding_markup.test.js
```

Expected: all commands exit 0. Do not stage or commit.

---

### Task 10: Run Cross-Role HTTP, Browser, and Full Release Verification

**Files:**
- Create: `tests/auth_onboarding_http_test.php`
- Modify: `tests/task29_browser_qa.mjs`
- Modify: any focused test discovered by the full regression suite only when the old expectation conflicts with the approved spec.

**Interfaces:**
- Produces: executable evidence that the complete approved flow works on XAMPP/MySQL and responsive Chrome.
- Consumes: Tasks 1-9 and the existing release test infrastructure.

- [ ] **Step 1: Write the failing end-to-end HTTP scenario**

The HTTP test must run only against `savora_test` and cover this sequence with unique identities:

```text
Customer submit -> active user/profile -> login -> Customer dashboard -> logout -> session revoked
Restaurant submit with PNG logo -> pending/no user -> Admin approve -> one user/profile/seven hours/public logo -> Restaurant login
Driver submit without files -> pending/no user -> Admin approve -> one user/profile -> Driver login
Second approval with same idempotency key -> original response/no duplicate rows
Rejected partner -> no user/claims released/password consumed/private logo unavailable
Super Admin creates Admin -> new Admin login -> normal Admin cannot create another Admin
```

- [ ] **Step 2: Run the HTTP test and resolve only observed integration failures**

Run:

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; $env:SAVORA_DEMO_MODE='1'; php tests/auth_onboarding_http_test.php
```

Expected before final integration: FAIL at the first unconnected page/API boundary. Fix the actual boundary while preserving service rules, then rerun until PASS.

- [ ] **Step 3: Extend browser QA**

Add routes and interactions for `index.php`, `register.php`, all three registration pages, `registration_result.php`, `forgot_password.php`, `reset_password.php`, Admin account creation, Restaurant approval, and Driver approval. For widths 320, 768, and 1440 assert:

```js
assert.equal(metrics.rootOverflow, 0);
assert.ok(metrics.visibleFocusCount > 0);
assert.equal(metrics.emptyLabels, 0);
assert.equal(metrics.mixedLanguageMarkers, 0);
```

Exercise password toggles, mismatch errors, logo preview/remove, duplicate submit lock, valid submission, pending result, Admin reject/approve, and logout success.

- [ ] **Step 4: Run focused browser QA**

Start the existing test PHP server and Chrome/CDP flow using the test environment, then run:

```powershell
node tests/task29_browser_qa.mjs
```

Expected: all route/viewport checks, interactions, multipart form checks, and overflow checks pass. Stop temporary PHP and Chrome processes after the run.

- [ ] **Step 5: Run the complete regression suite**

Run JavaScript tests:

```powershell
node --test tests
```

Run every PHP integration test safely:

```powershell
$env:SAVORA_ENV='test'
$env:SAVORA_DB_NAME='savora_test'
$env:SAVORA_DB_PORT='3307'
Get-ChildItem -LiteralPath 'tests' -Filter '*_test.php' | Sort-Object Name | ForEach-Object { & php $_.FullName; if ($LASTEXITCODE -ne 0) { throw "PHP test failed: $($_.Name)" } }
```

Run PHP lint:

```powershell
Get-ChildItem -LiteralPath '.' -Recurse -File -Filter '*.php' | Where-Object { $_.FullName -notmatch '[\\/]\.runtime-backups[\\/]' } | ForEach-Object { & php -l $_.FullName; if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $($_.FullName)" } }
```

Run final integrity checks:

```powershell
git diff --check
git status --short --branch
```

Expected: all JavaScript tests, all PHP tests, every PHP lint target, browser QA, and `git diff --check` pass. Git status shows only the preserved migration work plus this approved onboarding implementation; `.runtime-backups/` remains untouched.

- [ ] **Step 6: Produce the completion report**

Report the exact test counts, browser widths/routes/interactions, migration name, test database/port, created/modified file groups, remaining external limitations (real email/SMS provider only), and confirmation that no staging/commit/merge or production-data mutation occurred.

---

## Review and Execution Checkpoints

- Checkpoint A after Task 2: migration and Customer registration are independently usable.
- Checkpoint B after Task 4: Restaurant/Driver applications are server-authoritative without legal documents.
- Checkpoint C after Task 6: all public mockup-derived pages and states exist and are responsive.
- Checkpoint D after Task 8: Admin approval and internal provisioning are complete.
- Checkpoint E after Task 10: full release evidence is captured.

At every checkpoint, preserve the dirty worktree, run `git diff --check`, and do not stage or commit.
