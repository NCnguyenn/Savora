# Savora Server-Authoritative Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate Savora's 35 existing PHP routes to one MySQL-backed source of truth for submitted business data while preserving the approved UI and removing each legacy authoritative local-storage path in the same phase that replaces it.

**Architecture:** Keep server-rendered PHP and progressive JavaScript enhancement. Introduce explicit database bootstrap commands, focused repositories and domain services, and domain APIs; cut over one domain at a time with exactly one reachable writer and no MySQL/localStorage dual-write.

**Tech Stack:** PHP 8 with `mysqli`, MySQL/MariaDB from XAMPP, vanilla JavaScript, Node.js built-in test runner, HTML/CSS, PowerShell on Windows.

## Global Constraints

- Source design: `docs/superpowers/specs/2026-08-01-server-authoritative-migration-design.md`.
- Preserve all 35 existing top-level Customer, Restaurant, Driver, and Admin routes.
- Do not introduce a PHP or JavaScript framework, Composer package, npm package, or build system.
- MySQL is the sole authority for submitted orders, menus, profiles, wallets, payments, delivery, finance, cases, notifications, promotions, and audit.
- Local storage may retain only an unsubmitted cart, UI filters, display preferences, and explicitly non-authoritative drafts.
- Never dual-write authoritative data to MySQL and local storage.
- Every mutation requires authentication, role/ownership authorization, CSRF, stable idempotency where operationally significant, legal transition checks, and optimistic version checks where applicable.
- A failed server command must remain failed; no local success fallback is allowed.
- Migration and seed logic must never run from a normal web request.
- Use prepared SQL in repositories; page files and JavaScript must not contain authorization or financial decisions.
- Use TDD for every production behavior: write a focused test, run it and observe the expected failure, implement the minimum behavior, then rerun focused and regression tests.
- Use a dedicated database named `savora_test` for PHP integration tests; never run integration tests against `savora_db`.
- Preserve the five pre-existing untracked files recorded before planning; stage only files named by the current task.
- Each task must end with a clean focused test run and a small commit.
- Each phase gate requires focused tests, full JavaScript regression tests, PHP lint, legacy-writer scan, and review before the next phase begins.

## Planned File Boundaries

### Infrastructure

- `lib/database.php`: environment validation and `mysqli` connection only.
- `lib/http.php`: consistent JSON parsing, success, and error responses.
- `lib/request_security.php`: authenticated request context, CSRF, role, ownership, and idempotency header validation.
- `lib/migrations.php`: ordered migration registry and schema version recording.
- `scripts/migrate.php`: explicit database creation and migration CLI entry point.
- `scripts/seed.php`: explicit development seed CLI entry point.
- `database/migrations/*.php`: one forward migration per schema change.
- `tests/support/test_database.php`: dedicated test-database setup and transaction helpers.

### Domain modules

- `lib/domain/order_status.php`: canonical order, dispatch, and delivery transitions.
- `lib/repositories/*_repository.php`: prepared SQL and row mapping only.
- `lib/services/*_service.php`: validation, transitions, transactions, and cross-table effects.
- `api/catalog.php`, `api/checkout.php`, `api/orders.php`, `api/restaurant.php`, `api/dispatch.php`, `api/notifications.php`, `api/support.php`, `api/partner_applications.php`: focused HTTP boundaries.
- `api/platform_state.php`: temporary compatibility router; delete after all consumers move.
- `lib/admin_actions.php`: temporary Admin compatibility router; delete unreachable legacy functions as services move out.

### Browser modules

- `js/api_client.js`: shared JSON GET/POST, CSRF, stable intent key, and error normalization.
- `js/customer_state.js`: unsubmitted cart and UI-only Customer preferences after cutover.
- `js/restaurant_state.js`: UI-only Restaurant drafts/preferences after cutover.
- `js/driver_state.js`: UI-only Driver preferences after cutover.
- Existing page scripts: render server read models and submit domain commands.

## Phase Gates

| Phase | Gate required before continuing |
|---|---|
| 0 | Isolated workspace, baseline evidence, safe test DB contract |
| 1 | Normal GET bootstrap has no schema/seed/session-touch writes |
| 2 | Canonical contracts, versioned migrations, core constraints, stable idempotency |
| 3 | Restaurant/catalog server-authoritative; local catalog writers removed |
| 4 | Quote/checkout/payment/wallet server-authoritative; submitted-order and wallet local writers removed |
| 5 | All four roles read one order model; local order history/status writers removed |
| 6 | Dispatch/delivery/location server-authoritative; local dispatch writers removed |
| 7 | Admin exception and finance commands are atomic; legacy operations code removed |
| 8 | Partner applications originate from real submissions and secure document metadata |
| 9 | Notifications and support are server-backed and visible to affected roles |
| 10 | Commercial rules affect checkout; analytics and exports use verified definitions |
| 11 | Security hardening, complete legacy cleanup, release suite and audit rerun |

---

## Phase 0: Isolated Workspace and Baseline

### Task 1: Create the execution workspace and preserve baseline evidence

**Files:**
- Verify: `.gitignore`
- Verify: `tests/*.test.js`
- Do not modify production files.

**Interfaces:**
- Consumes: committed design `3c9d5d6` and the five pre-existing untracked files in the main checkout.
- Produces: isolated branch `codex/server-authoritative-migration` and a passing baseline record in task commentary.

- [ ] **Step 1: Detect worktree state and verify the worktree directory is ignored**

Run:

```powershell
git rev-parse --git-dir
git rev-parse --git-common-dir
git branch --show-current
git check-ignore -v .worktrees
git status --short
```

Expected: normal checkout on `main`; `.worktrees/` is ignored; only the five known user files are untracked.

- [ ] **Step 2: Create an isolated worktree using the worktree skill**

Run the `using-git-worktrees` workflow and create:

```text
D:\Xampp\htdocs\Savora\.worktrees\server-authoritative-migration
branch: codex/server-authoritative-migration
```

Expected: the isolated worktree starts at commit `3c9d5d6` or its direct planning-doc successor.

- [ ] **Step 3: Run the safe baseline JavaScript suite**

Run:

```powershell
$tests = Get-ChildItem -LiteralPath '.\tests' -Filter '*.test.js' -File | Sort-Object FullName | Select-Object -ExpandProperty FullName
& 'D:\nodejs\node.exe' --test $tests
```

Expected: 156 tests pass, 0 fail, unless later committed plan-only tests change the count.

- [ ] **Step 4: Run PHP lint without executing application includes**

Run:

```powershell
$php = 'D:\Xampp\php\php.exe'
$failed = Get-ChildItem -LiteralPath '.' -Recurse -Filter '*.php' -File | ForEach-Object {
    & $php -l $_.FullName
    if ($LASTEXITCODE -ne 0) { $_.FullName }
}
if ($failed) { throw "PHP lint failed: $($failed -join ', ')" }
```

Expected: no syntax failures.

- [ ] **Step 5: Record the baseline without committing generated artifacts**

Run:

```powershell
git status --short
git diff --exit-code
```

Expected: clean isolated worktree. No commit is created for this task.

---

## Phase 1: Side-Effect-Free Database Bootstrap

### Task 2: Separate connection, migration, and seed entry points

**Files:**
- Create: `lib/database.php`
- Create: `scripts/migrate.php`
- Create: `scripts/seed.php`
- Modify: `db.php`
- Modify: `lib/platform_schema.php`
- Create: `tests/database_bootstrap.test.js`
- Create: `tests/support/test_database.php`

**Interfaces:**
- Consumes: environment keys `SAVORA_DB_HOST`, `SAVORA_DB_PORT`, `SAVORA_DB_USER`, `SAVORA_DB_PASSWORD`, `SAVORA_DB_NAME`, `SAVORA_ENV`, and `SAVORA_SEED_DEMO`.
- Produces: `savora_database_config(): array`, `savora_database_connect(bool $selectDatabase = true): mysqli`, `savora_test_database(): mysqli`, explicit CLI migration and seed commands, and a connection-only `db.php` compatibility include.

- [ ] **Step 1: Write the failing static bootstrap test**

Add `tests/database_bootstrap.test.js`:

```javascript
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('normal db include opens a configured database without migration or seed writes', () => {
  const db = read('db.php');
  assert.match(db, /savora_database_connect\(\)/);
  assert.doesNotMatch(db, /CREATE DATABASE|platform_migrate|platform_seed/);
});

test('migration and seed are explicit CLI-only entry points', () => {
  const migrate = read('scripts/migrate.php');
  const seed = read('scripts/seed.php');
  assert.match(migrate, /PHP_SAPI !== 'cli'/);
  assert.match(seed, /PHP_SAPI !== 'cli'/);
  assert.match(migrate, /platform_migrate/);
  assert.match(seed, /platform_seed/);
});
```

- [ ] **Step 2: Run the test and verify RED**

Run:

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\database_bootstrap.test.js'
```

Expected: FAIL because `scripts/migrate.php`, `scripts/seed.php`, and `savora_database_connect()` do not exist and `db.php` still migrates/seeds.

- [ ] **Step 3: Add the connection-only implementation**

Create `lib/database.php` with this public contract:

```php
<?php
declare(strict_types=1);

function savora_database_config(): array
{
    $name = (string) (getenv('SAVORA_DB_NAME') ?: 'savora_db');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('Invalid database name.');
    }
    return [
        'host' => (string) (getenv('SAVORA_DB_HOST') ?: '127.0.0.1'),
        'port' => (int) (getenv('SAVORA_DB_PORT') ?: 3306),
        'user' => (string) (getenv('SAVORA_DB_USER') ?: 'root'),
        'password' => (string) (getenv('SAVORA_DB_PASSWORD') ?: ''),
        'name' => $name,
    ];
}

function savora_database_connect(bool $selectDatabase = true): mysqli
{
    $config = savora_database_config();
    $database = $selectDatabase ? $config['name'] : '';
    $conn = new mysqli($config['host'], $config['user'], $config['password'], $database, $config['port']);
    $conn->set_charset('utf8mb4');
    return $conn;
}
```

Replace `db.php` with a strict compatibility include that requires `lib/database.php` and assigns `$conn = savora_database_connect();`. Create CLI-only scripts: `migrate.php` may connect without selecting a database, create the validated database, select it, then call `platform_migrate($conn)`; `seed.php` must connect to the selected database and call `platform_seed($conn)` only when `SAVORA_ENV=development` or `SAVORA_ENV=test`.

Create `tests/support/test_database.php` with a hard safety gate:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/database.php';

function savora_test_database(): mysqli
{
    if (getenv('SAVORA_ENV') !== 'test' || getenv('SAVORA_DB_NAME') !== 'savora_test') {
        throw new RuntimeException('Integration tests require SAVORA_ENV=test and SAVORA_DB_NAME=savora_test.');
    }
    return savora_database_connect();
}

function savora_test_transaction(callable $test): void
{
    $conn = savora_test_database();
    $conn->begin_transaction();
    try {
        $test($conn);
    } finally {
        $conn->rollback();
        $conn->close();
    }
}
```

- [ ] **Step 4: Run focused test and PHP lint**

Run:

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\database_bootstrap.test.js'
& 'D:\Xampp\php\php.exe' -l '.\lib\database.php'
& 'D:\Xampp\php\php.exe' -l '.\db.php'
& 'D:\Xampp\php\php.exe' -l '.\scripts\migrate.php'
& 'D:\Xampp\php\php.exe' -l '.\scripts\seed.php'
& 'D:\Xampp\php\php.exe' -l '.\tests\support\test_database.php'
```

Expected: all focused checks pass.

- [ ] **Step 5: Run the full safe regression suite**

Run:

```powershell
$tests = Get-ChildItem '.\tests\*.test.js' | Sort-Object FullName | Select-Object -ExpandProperty FullName
& 'D:\nodejs\node.exe' --test $tests
```

Expected: all JavaScript tests pass.

- [ ] **Step 6: Commit the bootstrap separation**

```powershell
git add db.php lib/database.php lib/platform_schema.php scripts/migrate.php scripts/seed.php tests/database_bootstrap.test.js tests/support/test_database.php
git commit -m "refactor: separate database bootstrap from web requests"
```

### Task 3: Make authenticated page validation read-only

**Files:**
- Modify: `lib/session_security.php`
- Create: `api/session_heartbeat.php`
- Modify: `components/customer_footer.php`
- Modify: `components/restaurant_footer.php`
- Modify: `components/driver_footer.php`
- Modify: `components/admin_footer.php`
- Create: `tests/session_read_boundary.test.js`

**Interfaces:**
- Consumes: `savora_validate_session(mysqli $conn, array $session, string $sessionId, ?string $requiredRole): array`.
- Produces: read-only validation plus explicit `savora_touch_session(mysqli $conn, int $userId, string $sessionId): void` invoked only by `POST api/session_heartbeat.php`.

- [ ] **Step 1: Write the failing read-boundary test**

Create `tests/session_read_boundary.test.js`:

```javascript
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

test('session validation performs no update and heartbeat owns last-seen writes', () => {
  const source = fs.readFileSync('lib/session_security.php', 'utf8');
  const validate = source.slice(source.indexOf('function savora_validate_session'), source.indexOf('function savora_revoke_current_session'));
  assert.doesNotMatch(validate, /UPDATE user_sessions SET last_seen_at/);
  assert.match(source, /function savora_touch_session/);
  assert.match(fs.readFileSync('api/session_heartbeat.php', 'utf8'), /savora_touch_session/);
});
```

- [ ] **Step 2: Run the test and verify RED**

Run:

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\session_read_boundary.test.js'
```

Expected: FAIL because validation still updates `last_seen_at` and no heartbeat endpoint exists.

- [ ] **Step 3: Extract the explicit heartbeat write**

Move the current update statement into this function:

```php
function savora_touch_session(mysqli $conn, int $userId, string $sessionId): void
{
    $hash = savora_session_hash($sessionId);
    $touch = $conn->prepare('UPDATE user_sessions SET last_seen_at = NOW() WHERE user_id = ? AND session_hash = ? AND revoked_at IS NULL');
    $touch->bind_param('is', $userId, $hash);
    $touch->execute();
    $touch->close();
}
```

Create `api/session_heartbeat.php` as a POST-only endpoint that starts the session, requires `db.php`, validates the current role/session, validates the CSRF header, calls `savora_touch_session()`, and returns `{ "ok": true }`. Add a throttled browser call no more than once every five minutes while the document is visible; no page-load GET may call it.

- [ ] **Step 4: Run focused and session-security tests**

Run:

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\session_read_boundary.test.js' '.\tests\admin_security_hardening.test.js'
& 'D:\Xampp\php\php.exe' -l '.\lib\session_security.php'
& 'D:\Xampp\php\php.exe' -l '.\api\session_heartbeat.php'
```

Expected: all focused checks pass.

- [ ] **Step 5: Commit the read-only session boundary**

```powershell
git add lib/session_security.php api/session_heartbeat.php components/customer_footer.php components/restaurant_footer.php components/driver_footer.php components/admin_footer.php tests/session_read_boundary.test.js
git commit -m "fix: keep authenticated page reads side effect free"
```

---

## Phase 2: Canonical Contracts, Migrations, and Idempotency

### Task 4: Add shared HTTP and canonical status contracts

**Files:**
- Create: `lib/http.php`
- Create: `lib/request_security.php`
- Create: `lib/domain/order_status.php`
- Create: `lib/services/audit_service.php`
- Create: `lib/services/notification_service.php`
- Create: `tests/order_contract_test.php`
- Modify: `api/platform_state.php`
- Modify: `admin_action.php`

**Interfaces:**
- Produces: `savora_json(array $body, int $status = 200): never`, `savora_read_json(): array`, `savora_error(int $status, string $message, array $errors = [], ?string $referenceId = null): never`, `savora_request_actor(mysqli $conn, array $roles): array`, `savora_require_csrf(array $headers): void`, `savora_order_can_transition(string $from, string $to, string $role): bool`, `audit_append(...)`, `notification_queue(...)`, and canonical constants.

- [ ] **Step 1: Write the failing pure-PHP contract test**

Create `tests/order_contract_test.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/domain/order_status.php';

function expect_true(bool $value, string $message): void
{
    if (!$value) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

expect_true(savora_order_can_transition('pending', 'confirmed', 'restaurant'), 'Restaurant must confirm pending orders.');
expect_true(!savora_order_can_transition('pending', 'delivered', 'restaurant'), 'Restaurant must not deliver orders.');
expect_true(savora_order_can_transition('assigned', 'picked_up', 'driver'), 'Driver must pick up assigned orders.');
expect_true(savora_order_can_transition('picked_up', 'delivered', 'driver'), 'Driver must deliver picked-up orders.');
expect_true(savora_order_can_transition('preparing', 'cancelled', 'admin'), 'Admin exception cancellation must be explicit.');
echo "order contract ok\n";
```

- [ ] **Step 2: Run the test and verify RED**

Run:

```powershell
& 'D:\Xampp\php\php.exe' '.\tests\order_contract_test.php'
```

Expected: fatal include/function failure because the contract file does not exist.

- [ ] **Step 3: Implement the canonical transition map**

Create `lib/domain/order_status.php` with exact role-owned transitions:

```php
<?php
declare(strict_types=1);

const SAVORA_ORDER_STATUSES = ['pending', 'confirmed', 'preparing', 'ready_for_pickup', 'assigned', 'picked_up', 'delivered', 'cancelled', 'refunded'];

function savora_order_transitions(): array
{
    return [
        'restaurant' => [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['preparing', 'cancelled'],
            'preparing' => ['ready_for_pickup', 'cancelled'],
        ],
        'driver' => [
            'assigned' => ['picked_up'],
            'picked_up' => ['delivered'],
        ],
        'admin' => [
            'pending' => ['cancelled'],
            'confirmed' => ['cancelled'],
            'preparing' => ['cancelled'],
            'ready_for_pickup' => ['cancelled', 'assigned'],
            'assigned' => ['cancelled', 'assigned'],
        ],
    ];
}

function savora_order_can_transition(string $from, string $to, string $role): bool
{
    return in_array($to, savora_order_transitions()[$role][$from] ?? [], true);
}
```

Create `lib/http.php` with the named JSON helpers and update both action endpoints to use the same response envelope. Create `lib/request_security.php` by extracting shared authenticated actor, allowed-role, CSRF, and request-header validation from the current endpoint-specific guards. Add focused append helpers so later services do not insert audit/notification rows directly:

```php
function notification_queue(mysqli $conn, int $userId, string $eventType, string $title, string $message, ?string $entityType, ?int $entityId): int
{
    $stmt = $conn->prepare('INSERT INTO notifications(user_id,event_type,title,message,entity_type,entity_id) VALUES(?,?,?,?,?,?)');
    $stmt->bind_param('issssi', $userId, $eventType, $title, $message, $entityType, $entityId);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}
```

`audit_append()` accepts actor/action/entity/before/after/reason/reference and contains the sole reusable `audit_logs` insert. Do not change domain behavior in this task.

- [ ] **Step 4: Run focused tests and endpoint lint**

```powershell
& 'D:\Xampp\php\php.exe' '.\tests\order_contract_test.php'
& 'D:\Xampp\php\php.exe' -l '.\lib\http.php'
& 'D:\Xampp\php\php.exe' -l '.\lib\request_security.php'
& 'D:\Xampp\php\php.exe' -l '.\lib\services\audit_service.php'
& 'D:\Xampp\php\php.exe' -l '.\lib\services\notification_service.php'
& 'D:\Xampp\php\php.exe' -l '.\api\platform_state.php'
& 'D:\Xampp\php\php.exe' -l '.\admin_action.php'
```

Expected: `order contract ok` and no lint errors.

- [ ] **Step 5: Commit the shared contracts**

```powershell
git add lib/http.php lib/request_security.php lib/domain/order_status.php lib/services/audit_service.php lib/services/notification_service.php api/platform_state.php admin_action.php tests/order_contract_test.php
git commit -m "feat: define canonical HTTP and order contracts"
```

### Task 5: Introduce versioned migrations and core integrity constraints

**Files:**
- Create: `lib/migrations.php`
- Create: `database/migrations/001_existing_schema.php`
- Create: `database/migrations/002_core_integrity.php`
- Modify: `scripts/migrate.php`
- Create: `tests/migration_registry.test.js`
- Create: `tests/migration_integrity_test.php`

**Interfaces:**
- Produces: `savora_migrations(): array`, `savora_apply_migrations(mysqli $conn): array`, and `schema_migrations(version VARCHAR(100) PRIMARY KEY, applied_at TIMESTAMP)`.

- [ ] **Step 1: Write failing migration registry tests**

Add static assertions that the registry contains ordered keys `001_existing_schema` and `002_core_integrity`, that `scripts/migrate.php` calls `savora_apply_migrations`, and that `002_core_integrity.php` contains named foreign keys for orders, items, histories, payments, deliveries, sessions, applications, notifications, refunds, payouts, and cases.

Use this executable test shape in `tests/migration_registry.test.js`:

```javascript
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
test('migration registry is explicit and core relationships are constrained', () => {
  const registry = fs.readFileSync('lib/migrations.php', 'utf8');
  const integrity = fs.readFileSync('database/migrations/002_core_integrity.php', 'utf8');
  assert.match(registry, /001_existing_schema/);
  assert.match(registry, /002_core_integrity/);
  for (const name of ['fk_orders_customer', 'fk_orders_restaurant', 'fk_order_items_order', 'fk_payments_order', 'fk_deliveries_order', 'fk_notifications_user']) {
    assert.match(integrity, new RegExp(name));
  }
});
```

- [ ] **Step 2: Run the registry test and verify RED**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\migration_registry.test.js'
```

Expected: FAIL because the migration registry and files do not exist.

- [ ] **Step 3: Implement the registry and integrity preflight**

`savora_apply_migrations()` must create `schema_migrations`, lock each version with a transaction, invoke each migration once, insert its version, and commit. `002_core_integrity.php` must first run orphan-count queries and throw a descriptive exception when any count is non-zero; only then add the named foreign keys and indexes. Use `ON DELETE RESTRICT` for financial/history records and `ON DELETE CASCADE` only for owned option/document child rows.

The migration must add at least these relationships:

```sql
ALTER TABLE orders ADD CONSTRAINT fk_orders_customer FOREIGN KEY (customer_user_id) REFERENCES users(id) ON DELETE RESTRICT;
ALTER TABLE orders ADD CONSTRAINT fk_orders_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE RESTRICT;
ALTER TABLE order_items ADD CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT;
ALTER TABLE order_status_history ADD CONSTRAINT fk_order_history_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT;
ALTER TABLE payments ADD CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT;
ALTER TABLE deliveries ADD CONSTRAINT fk_deliveries_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT;
ALTER TABLE notifications ADD CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT;
```

- [ ] **Step 4: Run static tests, then run integration only against `savora_test`**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\migration_registry.test.js'
$env:SAVORA_ENV='test'
$env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\scripts\migrate.php'
& 'D:\Xampp\php\php.exe' '.\tests\migration_integrity_test.php'
Remove-Item Env:SAVORA_ENV
Remove-Item Env:SAVORA_DB_NAME
```

Expected: repeat migration reports no new versions on the second run; the integration test confirms named constraints in `information_schema`.

- [ ] **Step 5: Commit versioned migrations**

```powershell
git add lib/migrations.php database/migrations/001_existing_schema.php database/migrations/002_core_integrity.php scripts/migrate.php tests/migration_registry.test.js tests/migration_integrity_test.php
git commit -m "feat: add versioned schema migrations and integrity constraints"
```

### Task 6: Make idempotency stable and payload-aware

**Files:**
- Create: `lib/idempotency.php`
- Create: `database/migrations/003_idempotency_request_hash.php`
- Modify: `js/platform_bridge.js`
- Modify: `js/admin_ui.js`
- Modify: `api/platform_state.php`
- Modify: `lib/admin_actions.php`
- Create: `tests/idempotency_contract.test.js`
- Create: `tests/idempotency_service_test.php`

**Interfaces:**
- Produces: `savora_idempotency_hash(string $action, array $payload): string`, `savora_idempotency_find(mysqli $conn, int $actorId, string $key, string $action, string $requestHash): ?array`, `savora_idempotency_store(...)`, and browser `SavoraApi.intentKey(string $scope): string`.

- [ ] **Step 1: Write failing stable-key tests**

Test that `platform_bridge.js` no longer builds keys with `Date.now()` or `Math.random()`, that command accepts an explicit `idempotencyKey`, and that the PHP service rejects reuse of one key with a different action or payload hash.

```javascript
test('platform commands require a stable caller-owned idempotency key', () => {
  const bridge = fs.readFileSync('js/platform_bridge.js', 'utf8');
  assert.doesNotMatch(bridge, /Date\.now\(\).*Math\.random/);
  assert.match(bridge, /command\(name,payload,idempotencyKey\)/);
  assert.match(bridge, /Idempotency-Key':idempotencyKey/);
});
```

- [ ] **Step 2: Run tests and verify RED**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\idempotency_contract.test.js'
```

Expected: FAIL because the bridge generates a new random key for each call.

- [ ] **Step 3: Implement payload-aware idempotency**

Add `request_hash CHAR(64) NOT NULL` to `idempotency_keys`. Canonicalize payloads recursively by sorting associative keys before `json_encode`, then hash `action + "\n" + canonical-json` with SHA-256. Return the stored response only when actor, key, action, and hash all match; return `409` for key reuse with a different request.

Change browser callers to create an intent key once with `crypto.randomUUID()`, retain it until a final response, and reuse it for retries. Checkout intent keys use `sessionStorage['savora_checkout_intent']`; Admin confirmation dialogs keep the key in dialog state until success or cancellation.

- [ ] **Step 4: Run focused JavaScript and PHP tests**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\idempotency_contract.test.js' '.\tests\admin_ui.test.js'
$env:SAVORA_ENV='test'
$env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\idempotency_service_test.php'
Remove-Item Env:SAVORA_ENV
Remove-Item Env:SAVORA_DB_NAME
```

Expected: same-key/same-payload returns the original response; same-key/different-payload returns conflict; focused browser contracts pass.

- [ ] **Step 5: Commit stable idempotency**

```powershell
git add lib/idempotency.php database/migrations/003_idempotency_request_hash.php js/platform_bridge.js js/admin_ui.js api/platform_state.php lib/admin_actions.php tests/idempotency_contract.test.js tests/idempotency_service_test.php
git commit -m "fix: make command idempotency stable and payload aware"
```

### Phase 2 Exit Gate

- [ ] Run all `*.test.js`, PHP lint, `order_contract_test.php`, migration repeatability, and idempotency integration tests.
- [ ] Confirm `db.php` contains no `CREATE`, `ALTER`, `INSERT`, `UPDATE`, `DELETE`, `platform_migrate`, or `platform_seed` call.
- [ ] Confirm `savora_validate_session()` contains no SQL mutation.
- [ ] Review schema orphan preflight results before applying constraints outside `savora_test`.

---

## Phase 3: Server-Authoritative Restaurant Profile and Catalog

### Task 7: Add catalog schema, repository, and read service

**Files:**
- Create: `database/migrations/004_catalog_contract.php`
- Create: `lib/repositories/catalog_repository.php`
- Create: `lib/services/catalog_service.php`
- Create: `api/catalog.php`
- Create: `tests/catalog_service_test.php`
- Create: `tests/catalog_api_contract.test.js`

**Interfaces:**
- Produces: `catalog_for_customer(mysqli $conn, array $filters): array`, `catalog_for_restaurant(mysqli $conn, int $ownerUserId): array`, `catalog_save_item(mysqli $conn, int $ownerUserId, array $input, int $expectedVersion): array`, and `GET api/catalog.php`.

- [ ] **Step 1: Write failing catalog contract tests**

The API contract test must require Customer results to include Restaurant identity, operational availability, item public ID, base price, version, option groups, option choices, and availability. The PHP service test must prove one Restaurant cannot update another Restaurant's item.

```php
$result = catalog_save_item($conn, $ownerA, [
    'publicId' => $restaurantBItem,
    'name' => 'Forbidden update',
    'price' => 4.50,
    'available' => true,
], 1);
expect_true($result['ok'] === false && $result['status'] === 403, 'Cross-Restaurant menu update must be denied.');
```

- [ ] **Step 2: Run tests and verify RED**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\catalog_api_contract.test.js'
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\catalog_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: missing repository/service/API failures.

- [ ] **Step 3: Add the catalog data contract**

Migration `004_catalog_contract.php` must add Restaurant latitude/longitude, weekly and special hours tables, and normalized option tables:

```sql
CREATE TABLE menu_option_groups (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    menu_item_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    selection_type VARCHAR(20) NOT NULL,
    minimum_choices INT NOT NULL DEFAULT 0,
    maximum_choices INT NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    version INT NOT NULL DEFAULT 1,
    CONSTRAINT fk_option_group_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE menu_option_choices (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    option_group_id BIGINT NOT NULL,
    public_id VARCHAR(60) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    price_delta DECIMAL(12,2) NOT NULL DEFAULT 0,
    available TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    version INT NOT NULL DEFAULT 1,
    CONSTRAINT fk_option_choice_group FOREIGN KEY (option_group_id) REFERENCES menu_option_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Repository queries must join active Restaurants and available items for Customer reads. Service writes must resolve the Restaurant by `owner_user_id`, validate price/selection limits, enforce expected version, and return `409` on stale updates.

- [ ] **Step 4: Implement the focused catalog endpoint**

`GET api/catalog.php` accepts optional bounded `q` and `restaurant` filters. Restaurant mutations use `POST` JSON actions `save_item`, `set_item_availability`, `save_profile`, and `save_operations`; each action validates role, CSRF, explicit idempotency key, and record version before calling the service.

- [ ] **Step 5: Run focused tests and migration repeatability**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\catalog_api_contract.test.js'
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\scripts\migrate.php'
& 'D:\Xampp\php\php.exe' '.\tests\catalog_service_test.php'
& 'D:\Xampp\php\php.exe' '.\scripts\migrate.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: catalog tests pass and the second migration run applies zero migrations.

- [ ] **Step 6: Commit the catalog backend**

```powershell
git add database/migrations/004_catalog_contract.php lib/repositories/catalog_repository.php lib/services/catalog_service.php api/catalog.php tests/catalog_service_test.php tests/catalog_api_contract.test.js
git commit -m "feat: add authoritative Restaurant catalog service"
```

### Task 8: Cut Customer discovery and Restaurant management over to the catalog service

**Files:**
- Create: `js/api_client.js`
- Modify: `js/customer_catalog.js`
- Modify: `js/restaurant_menu.js`
- Modify: `js/restaurant_storefront.js`
- Modify: `customer_dashboard.php`
- Modify: `product_detail.php`
- Modify: `restaurant_menu.php`
- Modify: `restaurant_menu_item.php`
- Modify: `restaurant_profile.php`
- Modify: `restaurant_operations.php`
- Modify: `js/restaurant_state.js`
- Create: `tests/catalog_cutover.test.js`

**Interfaces:**
- Consumes: `GET/POST api/catalog.php` from Task 7.
- Produces: `SavoraApi.get(url)`, `SavoraApi.post(url, body, intentKey)`, catalog hydration from server, and UI-only Restaurant draft state.

- [ ] **Step 1: Write failing cutover guards**

```javascript
test('migrated catalog pages use the catalog API and have no authoritative local writes', () => {
  const catalog = fs.readFileSync('js/customer_catalog.js', 'utf8');
  const menu = fs.readFileSync('js/restaurant_menu.js', 'utf8');
  const restaurantState = fs.readFileSync('js/restaurant_state.js', 'utf8');
  assert.match(catalog, /api\/catalog\.php/);
  assert.match(menu, /api\/catalog\.php/);
  assert.doesNotMatch(restaurantState, /setItemAvailability|setProfile|setOperations/);
  assert.doesNotMatch(catalog, /baseRestaurants|baseProducts/);
});
```

- [ ] **Step 2: Run the guard and verify RED**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\catalog_cutover.test.js'
```

Expected: FAIL because discovery is hard-coded and Restaurant mutations persist locally.

- [ ] **Step 3: Implement the shared API client**

`SavoraApi.post()` must require a non-empty intent key, send `Content-Type`, CSRF, and `Idempotency-Key`, parse the shared response envelope, and throw an `Error` carrying `status`, `errors`, and `referenceId`. It must never mutate local state on failure.

```javascript
async function post(url, body, intentKey) {
  if (!intentKey) throw new Error('A stable intent key is required.');
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken(),
      'Idempotency-Key': intentKey,
    },
    body: JSON.stringify(body || {}),
  });
  const payload = await response.json();
  if (!response.ok || !payload.ok) {
    const error = new Error(payload.message || 'Request failed.');
    error.status = response.status;
    error.errors = payload.errors || {};
    error.referenceId = payload.referenceId || '';
    throw error;
  }
  return payload.data;
}
```

- [ ] **Step 4: Switch pages and remove legacy writers**

Customer discovery/product pages fetch and render server catalog records. Restaurant menu/profile/operations forms submit server commands and refresh from the returned server record. Remove base Restaurant/product arrays and remove `setMenuItem`, `setItemAvailability`, `setProfile`, and `setOperations` from authoritative local state; retain only bounded unsaved form draft helpers where the UI needs them.

- [ ] **Step 5: Run catalog, markup, and state regressions**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\catalog_cutover.test.js' '.\tests\customer_markup.test.js' '.\tests\restaurant_markup.test.js' '.\tests\restaurant_state.test.js'
```

Expected: server hydration and no-authoritative-local-write guards pass; existing accessible markup tests remain green.

- [ ] **Step 6: Commit the catalog cutover**

```powershell
git add js/api_client.js js/customer_catalog.js js/restaurant_menu.js js/restaurant_storefront.js js/restaurant_state.js customer_dashboard.php product_detail.php restaurant_menu.php restaurant_menu_item.php restaurant_profile.php restaurant_operations.php tests/catalog_cutover.test.js tests/customer_markup.test.js tests/restaurant_markup.test.js tests/restaurant_state.test.js
git commit -m "refactor: cut catalog and Restaurant operations over to MySQL"
```

### Task 8A: Move Customer profile, addresses, favorites, and Restaurant reviews to server authority

**Files:**
- Create: `database/migrations/004a_profiles_reviews.php`
- Create: `lib/repositories/profile_repository.php`
- Create: `lib/repositories/review_repository.php`
- Create: `lib/services/profile_service.php`
- Create: `lib/services/review_service.php`
- Create: `api/profile.php`
- Create: `api/reviews.php`
- Modify: `customer_profile.php`
- Modify: `customer_favorites.php`
- Modify: `customer_history.php`
- Modify: `restaurant_reviews.php`
- Modify: `js/restaurant_insights.js`
- Create: `tests/profile_review_service_test.php`
- Create: `tests/profile_review_cutover.test.js`

**Interfaces:**
- Produces: server-owned Customer profile, saved addresses, favorites, verified order reviews, and Restaurant replies.
- Public methods: `profile_for_user(...)`, `profile_update_customer(...)`, `profile_save_address(...)`, `favorite_set(...)`, `review_create_for_order(...)`, and `review_reply_as_restaurant(...)`.

- [ ] **Step 1: Write failing profile/review tests**

Create fixtures for two Customers and two Restaurants. Assert users can update only their own profile/address/favorite rows; an address has a server public ID, label, bounded text, latitude/longitude, and one default per Customer; only a delivered owned order can be reviewed once; only the owning Restaurant can reply; persisted text is returned as plain data for escaped rendering.

```php
$review = review_create_for_order($conn, $customerA, $deliveredOrderA, 5, 'Accurate review');
expect_true($review['ok'] === true, 'Delivered Customer order should be reviewable.');
$duplicate = review_create_for_order($conn, $customerA, $deliveredOrderA, 4, 'Second review');
expect_true($duplicate['status'] === 409, 'One order may create only one Customer review.');
$forbidden = review_reply_as_restaurant($conn, $restaurantOwnerB, $review['data']['publicId'], 'Not my review', 1);
expect_true($forbidden['status'] === 403, 'Only the owning Restaurant may reply.');
```

- [ ] **Step 2: Run tests and verify RED**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\profile_review_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: missing profile/review services.

- [ ] **Step 3: Add profile, address, favorite, and review schema**

Migration `004a_profiles_reviews.php` creates `customer_addresses`, `customer_favorites`, and `restaurant_reviews`; adds unique constraints `(customer_user_id, address_public_id)`, `(customer_user_id, favorite_type, entity_public_id)`, and `restaurant_reviews.order_id`; and adds review reply text/status/version fields. Address coordinates are required for delivery addresses used by checkout.

- [ ] **Step 4: Implement focused APIs and cut over pages**

`api/profile.php` handles profile/address/favorite reads and versioned mutations. `api/reviews.php` handles delivered-order review creation and Restaurant replies. Switch Customer profile/favorites and Restaurant reviews to these APIs, remove their authoritative local mutations, and keep only unsaved form input in memory.

- [ ] **Step 5: Run focused and markup tests**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\profile_review_cutover.test.js' '.\tests\customer_markup.test.js' '.\tests\restaurant_markup.test.js'
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\profile_review_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: ownership, one-review-per-order, and no-local-authority guards pass.

- [ ] **Step 6: Commit profile and review cutover**

```powershell
git add database/migrations/004a_profiles_reviews.php lib/repositories/profile_repository.php lib/repositories/review_repository.php lib/services/profile_service.php lib/services/review_service.php api/profile.php api/reviews.php customer_profile.php customer_favorites.php customer_history.php restaurant_reviews.php js/restaurant_insights.js tests/profile_review_service_test.php tests/profile_review_cutover.test.js
git commit -m "feat: move Customer profiles favorites and reviews to MySQL"
```

### Phase 3 Exit Gate

- [ ] Customer discovery and Restaurant menu/profile/hours show the same MySQL records in separate sessions.
- [ ] No migrated catalog/profile/operations function writes authoritative local storage.
- [ ] Cross-Restaurant menu updates, stale versions, invalid options, and unavailable items are rejected server-side.
- [ ] Customer profile/addresses/favorites and Restaurant reviews/replies are server-backed and ownership-scoped.

---

## Phase 4: Pricing, Checkout, Payment, and Wallet

### Task 9: Build server quotes and authoritative pricing

**Files:**
- Create: `database/migrations/005_checkout_quotes.php`
- Create: `lib/repositories/pricing_repository.php`
- Create: `lib/services/pricing_service.php`
- Create: `api/checkout.php`
- Create: `tests/pricing_service_test.php`
- Create: `tests/checkout_contract.test.js`

**Interfaces:**
- Produces: `pricing_create_quote(mysqli $conn, int $customerUserId, array $cart, string $addressPublicId, ?string $promotionCode): array` and `POST api/checkout.php` action `quote`.
- Quote response: `quoteId`, `currency`, `restaurant`, normalized `items`, `subtotal`, `discount`, `deliveryFee`, `total`, `expiresAt`, and `version`.

- [ ] **Step 1: Write failing price-tampering tests**

The service test must submit a fake client item price and assert the quote uses the menu and option rows from MySQL. It must also reject unavailable options, mixed-Restaurant carts, inactive service areas, non-accepting Restaurants, expired promotions, and maintenance mode.

```php
$quote = pricing_create_quote($conn, $customerId, [[
    'itemPublicId' => 'ITEM-1',
    'quantity' => 2,
    'optionPublicIds' => ['OPT-CHEESE'],
    'unitPrice' => 0.01,
]], 'ADDR-CUSTOMER-A', null);
expect_same(15.00, $quote['subtotal'], 'Server prices must ignore the client unit price.');
```

- [ ] **Step 2: Run tests and verify RED**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\pricing_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: missing pricing service failure.

- [ ] **Step 3: Add immutable expiring quotes**

Create `checkout_quotes` with server-generated public ID, customer/Restaurant IDs, selected `customer_addresses` ID, canonical cart hash, item snapshot JSON, subtotal, discount, delivery fee, total, currency `USD`, expiry, consumed timestamp, and version. Add `discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0` and applied quote/rule references to `orders`. Quote creation must lock/read the owned saved address and active catalog rows, validate option cardinality, apply one eligible promotion, select the effective fee rule, check service area and maintenance mode, then persist a 15-minute quote.

- [ ] **Step 4: Implement quote API and response contract**

The `quote` action must require Customer role and CSRF but not consume checkout idempotency. It accepts only item public IDs, quantities, selected option public IDs, an owned saved-address public ID, and optional promotion code. Ignore and do not copy any client subtotal, fee, discount, or total field.

- [ ] **Step 5: Run focused service/API tests**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\checkout_contract.test.js'
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\pricing_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: tampered prices are ignored and invalid business conditions return `422` or `409` as specified.

- [ ] **Step 6: Commit server pricing**

```powershell
git add database/migrations/005_checkout_quotes.php lib/repositories/pricing_repository.php lib/services/pricing_service.php api/checkout.php tests/pricing_service_test.php tests/checkout_contract.test.js
git commit -m "feat: add authoritative checkout quotes and pricing"
```

### Task 10: Place orders transactionally from quotes

**Files:**
- Create: `lib/repositories/order_repository.php`
- Create: `lib/repositories/payment_repository.php`
- Create: `lib/services/order_service.php`
- Create: `lib/services/payment_service.php`
- Modify: `api/checkout.php`
- Create: `tests/checkout_order_service_test.php`

**Interfaces:**
- Produces: `order_place_from_quote(mysqli $conn, int $customerUserId, string $quoteId, string $paymentMethod, string $idempotencyKey): array`.
- Supported initial methods: `cash`, `wallet`; `card` returns `422` unless a configured payment provider adapter reports availability.

- [ ] **Step 1: Write failing transaction and retry tests**

Test wallet success, insufficient wallet rollback, expired quote rejection, consumed quote rejection, same-key retry returning the original order, and same-key/different-quote conflict. Assert one order, one payment, one history row, one promotion redemption when used, and exactly one wallet debit.

- [ ] **Step 2: Run the service test and verify RED**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\checkout_order_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: missing order/payment services.

- [ ] **Step 3: Implement the transaction boundary**

Within one transaction: resolve idempotency, lock the quote, verify owner/expiry/unconsumed/version, revalidate Restaurant/item availability, lock wallet ledger balance for wallet payments, insert server-generated order reference, item snapshots, pending status history, payment, wallet debit, promotion redemption, notifications, audit, mark quote consumed, store idempotent response, and commit. Any exception rolls back all rows.

The public order response must contain only server values:

```php
return [
    'referenceCode' => (string) $order['reference_code'],
    'status' => (string) $order['status'],
    'paymentMethod' => (string) $order['payment_method'],
    'paymentStatus' => (string) $payment['status'],
    'subtotal' => (float) $order['subtotal'],
    'discount' => (float) $order['discount_amount'],
    'deliveryFee' => (float) $order['delivery_fee'],
    'total' => (float) $order['total'],
    'version' => (int) $order['version'],
];
```

- [ ] **Step 4: Run focused transaction tests twice**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\checkout_order_service_test.php'
& 'D:\Xampp\php\php.exe' '.\tests\checkout_order_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: both runs pass with deterministic cleanup and no duplicate business rows.

- [ ] **Step 5: Commit transactional order placement**

```powershell
git add lib/repositories/order_repository.php lib/repositories/payment_repository.php lib/services/order_service.php lib/services/payment_service.php api/checkout.php tests/checkout_order_service_test.php
git commit -m "feat: place idempotent orders from server quotes"
```

### Task 11: Cut checkout and wallet UI over and remove local authority

**Files:**
- Modify: `customer_cart.php`
- Modify: `customer_checkout.php`
- Modify: `customer_wallet.php`
- Modify: `js/customer_state.js`
- Modify: `js/platform_bridge.js`
- Create: `tests/checkout_cutover.test.js`
- Modify: `tests/customer_state.test.js`
- Modify: `tests/customer_markup.test.js`

**Interfaces:**
- Consumes: `POST api/checkout.php` actions `quote` and `place_order`.
- Produces: local cart-only state, server wallet read model, and stable checkout intent retry.

- [ ] **Step 1: Write failing local-authority guards**

```javascript
test('Customer state keeps a draft cart but cannot create orders or wallet money', () => {
  const state = fs.readFileSync('js/customer_state.js', 'utf8');
  assert.doesNotMatch(state, /placeDemoOrder|topUpWallet|walletBalance|walletTransactions/);
  assert.match(state, /addCartLine/);
  assert.match(state, /removeCartLine/);
});

test('checkout quotes and places through the focused server endpoint', () => {
  const page = fs.readFileSync('customer_checkout.php', 'utf8');
  assert.match(page, /api\/checkout\.php/);
  assert.match(page, /savora_checkout_intent/);
  assert.doesNotMatch(page, /placeDemoOrder/);
});
```

- [ ] **Step 2: Run guard tests and verify RED**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\checkout_cutover.test.js'
```

Expected: FAIL because local order creation and wallet top-up remain.

- [ ] **Step 3: Implement server quote and placement UI**

Cart remains a bounded local draft. Checkout requests a quote whenever cart/address/promotion changes, renders only quote totals, then submits `quoteId`, payment method, and the stable session intent key. Clear cart and intent key only after a confirmed server order response. Wallet page fetches server balance/transactions and removes arbitrary local top-up; show a disabled explanatory top-up control until a configured provider flow exists.

- [ ] **Step 4: Remove obsolete platform place-order bridge and local money/order methods**

Delete `placeDemoOrder`, `topUpWallet`, local submitted order persistence, local wallet fields, and the old `place_order` branch from the compatibility bridge after all callers use `api/checkout.php`.

- [ ] **Step 5: Run Customer tests and full JavaScript regression**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\checkout_cutover.test.js' '.\tests\customer_state.test.js' '.\tests\customer_markup.test.js'
$tests = Get-ChildItem '.\tests\*.test.js' | Sort-Object FullName | Select-Object -ExpandProperty FullName
& 'D:\nodejs\node.exe' --test $tests
```

Expected: cart behavior remains green; no local order/wallet authority; all regressions pass.

- [ ] **Step 6: Commit checkout cutover**

```powershell
git add customer_cart.php customer_checkout.php customer_wallet.php js/customer_state.js js/platform_bridge.js tests/checkout_cutover.test.js tests/customer_state.test.js tests/customer_markup.test.js
git commit -m "refactor: cut checkout and wallet over to server authority"
```

### Phase 4 Exit Gate

- [ ] Tampered item/option prices cannot alter the server quote or order total.
- [ ] Retrying one checkout intent produces one order and one debit.
- [ ] Customer local storage contains an unsubmitted cart but no submitted order, wallet balance, or wallet transaction.
- [ ] Card confirmation is disabled unless a configured provider adapter is present.

---

## Phase 5: One Cross-Role Order Model

### Task 12: Add role-scoped order queries and one order endpoint

**Files:**
- Extend: `lib/repositories/order_repository.php`
- Create: `lib/services/order_query_service.php`
- Create: `api/orders.php`
- Create: `tests/order_query_service_test.php`
- Create: `tests/order_api_contract.test.js`

**Interfaces:**
- Produces: `orders_for_customer(mysqli $conn, int $userId, array $filters): array`, `orders_for_restaurant(mysqli $conn, int $ownerUserId, array $filters): array`, `orders_for_driver(mysqli $conn, int $driverUserId, array $filters): array`, and `order_for_admin(mysqli $conn, int $orderId): array`.

- [ ] **Step 1: Write failing role-isolation tests**

Create fixtures for two Customers, Restaurants, and Drivers. Assert each non-Admin role sees only owned/assigned records, while Admin can select by internal ID. Assert response items include canonical status, version, item snapshots, payment summary, status history, assignment, and delivery milestones without exposing password or reset-token fields.

- [ ] **Step 2: Run and verify RED**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\order_query_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: missing query service failure.

- [ ] **Step 3: Implement prepared role-scoped queries**

Use explicit ownership predicates: `orders.customer_user_id` for Customer, `restaurants.owner_user_id` for Restaurant, and `deliveries.driver_user_id` for Driver. Bound status/date/page filters and use stable `placed_at DESC, id DESC` pagination.

- [ ] **Step 4: Implement `GET api/orders.php` and focused tests**

The endpoint derives role from the validated session; it never accepts a role or user ID from query input. It returns `{ orders, pagination }` and one CSRF token for subsequent commands.

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\order_api_contract.test.js'
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\order_query_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: role isolation and response contract pass.

- [ ] **Step 5: Commit the order read model**

```powershell
git add lib/repositories/order_repository.php lib/services/order_query_service.php api/orders.php tests/order_query_service_test.php tests/order_api_contract.test.js
git commit -m "feat: add one role-scoped order read model"
```

### Task 13: Enforce role-owned order transitions

**Files:**
- Create: `lib/services/order_transition_service.php`
- Modify: `api/orders.php`
- Modify: `api/platform_state.php`
- Create: `tests/order_transition_service_test.php`

**Interfaces:**
- Produces: `order_transition(mysqli $conn, array $actor, string $referenceCode, string $nextStatus, int $expectedVersion, string $idempotencyKey, string $reason = ''): array`.

- [ ] **Step 1: Write failing transition tests**

Test every allowed edge in `savora_order_transitions()` and representative denied edges, ownership denial, stale version conflict, terminal-state denial, duplicate retry, status-history attribution, ready-for-pickup dispatch creation, and cancellation notification.

- [ ] **Step 2: Run and verify RED**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\order_transition_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: missing transition service.

- [ ] **Step 3: Implement one transaction-owned transition service**

Lock the order and related Restaurant/Delivery ownership row, call `savora_order_can_transition`, update with `WHERE version=?`, append history with actual actor role/user, create dispatch only on `ready_for_pickup`, append notifications/audit, store idempotent response, and commit. Return `409` on stale version or illegal transition.

- [ ] **Step 4: Route legacy commands to the service and remove duplicate transition SQL**

`api/orders.php` owns new commands. Temporary `platform_state.php` branches call the service without embedding SQL; delete those branches when Task 14 switches the final caller.

- [ ] **Step 5: Run transition, security, and idempotency tests**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\order_transition_service_test.php'
& 'D:\Xampp\php\php.exe' '.\tests\idempotency_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
& 'D:\nodejs\node.exe' --test '.\tests\admin_security_hardening.test.js'
```

Expected: all role and stale-state tests pass.

- [ ] **Step 6: Commit role-owned transitions**

```powershell
git add lib/services/order_transition_service.php api/orders.php api/platform_state.php tests/order_transition_service_test.php
git commit -m "feat: centralize role-owned order transitions"
```

### Task 14: Cut every order page over and remove local order authority

**Files:**
- Modify: `customer_history.php`
- Modify: `customer_dashboard.php`
- Modify: `restaurant_orders.php`
- Modify: `restaurant_order_history.php`
- Modify: `js/restaurant_orders.js`
- Modify: `driver_dashboard.php`
- Modify: `driver_delivery.php`
- Modify: `js/customer_state.js`
- Modify: `js/restaurant_state.js`
- Modify: `js/driver_state.js`
- Modify: `api/platform_state.php`
- Create: `tests/order_cutover.test.js`

**Interfaces:**
- Consumes: `GET/POST api/orders.php`.
- Produces: server-hydrated active/history views and UI state without submitted order mutation methods.

- [ ] **Step 1: Write failing cross-role cutover guards**

Assert each order page/script references `api/orders.php`; assert Customer state has no `orders` persistence, Restaurant state has no `updateOrderStatus`, Driver state has no Customer-order mutation, and `platform_state.php` no longer implements `place_order` or Restaurant transition SQL.

- [ ] **Step 2: Run and verify RED**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\order_cutover.test.js'
```

Expected: FAIL on existing local order/history/transition code.

- [ ] **Step 3: Switch all role pages to the shared order read model**

Use server responses for active order, history, Restaurant live queue, Restaurant history, Driver-linked order details, and dashboard status. Reorder copies item public IDs/options into a new local draft cart only; it never clones price or order status.

- [ ] **Step 4: Remove legacy order writers and compatibility branches**

Delete local submitted-order arrays, status-history mutation, Restaurant `updateOrderStatus`, Driver mutation of Customer order objects, and the corresponding `platform_state.php` branches. Preserve cart normalization and non-authoritative UI preferences.

- [ ] **Step 5: Run all role markup/state and cutover tests**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\order_cutover.test.js' '.\tests\customer_state.test.js' '.\tests\restaurant_state.test.js' '.\tests\driver_state.test.js' '.\tests\customer_markup.test.js' '.\tests\restaurant_markup.test.js' '.\tests\driver_markup.test.js'
```

Expected: one server order contract is consumed by all portals and local authority guards pass.

- [ ] **Step 6: Commit the cross-role cutover**

```powershell
git add customer_history.php customer_dashboard.php restaurant_orders.php restaurant_order_history.php js/restaurant_orders.js driver_dashboard.php driver_delivery.php js/customer_state.js js/restaurant_state.js js/driver_state.js api/platform_state.php tests/order_cutover.test.js tests/customer_state.test.js tests/restaurant_state.test.js tests/driver_state.test.js
git commit -m "refactor: cut all order views over to the server read model"
```

### Phase 5 Exit Gate

- [ ] Place one test order and verify the same reference/status/total appears for Customer, Restaurant, Driver assignment view, and Admin.
- [ ] Verify only Restaurant owns confirmation/preparation/ready transitions and only Driver owns pickup/delivery transitions.
- [ ] Confirm no submitted order or order history is persisted in local storage.

---

## Phase 6: Dispatch, Delivery, GPS, and Tracking

### Task 15: Implement Driver availability and exclusive offers

**Files:**
- Create: `database/migrations/006_dispatch_location.php`
- Create: `lib/repositories/dispatch_repository.php`
- Create: `lib/services/dispatch_service.php`
- Create: `api/dispatch.php`
- Create: `tests/dispatch_service_test.php`
- Create: `tests/dispatch_api_contract.test.js`

**Interfaces:**
- Produces: `driver_set_availability(...)`, `dispatch_offer_next_driver(...)`, `dispatch_accept_offer(...)`, `dispatch_decline_offer(...)`, and `dispatch_expire_offers(...)`.
- Offer contract: public order reference, offer reference, pickup summary, distance estimate, payment method, expiry, dispatch version; no Customer PII beyond delivery-safe fields after acceptance.

- [ ] **Step 1: Write failing exclusive-offer tests**

Test offline/ineligible/busy Driver rejection, nearest eligible selection, one active offer per dispatch, 30-second expiry, decline advancing to the next Driver, accept creating exactly one delivery, simultaneous accept conflict, and same-key retry.

- [ ] **Step 2: Run and verify RED**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\dispatch_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: missing dispatch service.

- [ ] **Step 3: Add dispatch/location schema**

Migration `006_dispatch_location.php` must add unique active-offer protection, declined/expired event metadata, assignment supersession, and current Driver location:

```sql
CREATE TABLE driver_locations (
    driver_user_id INT PRIMARY KEY,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    accuracy_meters DECIMAL(10,2) NULL,
    recorded_at DATETIME NOT NULL,
    version INT NOT NULL DEFAULT 1,
    CONSTRAINT fk_driver_location_user FOREIGN KEY (driver_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Add `superseded_at` and `superseded_by_delivery_id` to deliveries, plus indexes for eligibility/availability and offer expiry scans.

- [ ] **Step 4: Implement lock-based offer lifecycle**

Use `SELECT ... FOR UPDATE` on dispatch, active offers, Driver profile, and active delivery. Accept must update offer, dispatch, delivery, order status/history, notification, and audit in one transaction. Decline/expiry records the outcome and creates the next offer without changing the Customer-visible order status.

- [ ] **Step 5: Run focused dispatch tests**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\dispatch_api_contract.test.js'
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\dispatch_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: concurrency and eligibility cases pass.

- [ ] **Step 6: Commit dispatch service**

```powershell
git add database/migrations/006_dispatch_location.php lib/repositories/dispatch_repository.php lib/services/dispatch_service.php api/dispatch.php tests/dispatch_service_test.php tests/dispatch_api_contract.test.js
git commit -m "feat: add authoritative Driver availability and dispatch offers"
```

### Task 16: Implement location, milestones, and proof-of-delivery metadata

**Files:**
- Create: `lib/repositories/delivery_repository.php`
- Create: `lib/services/delivery_service.php`
- Extend: `api/dispatch.php`
- Create: `database/migrations/007_delivery_evidence.php`
- Create: `tests/delivery_service_test.php`

**Interfaces:**
- Produces: `driver_update_location(...)`, `delivery_record_arrival(...)`, `delivery_record_pickup(...)`, `delivery_record_completion(...)`, and `delivery_fail(...)`.
- Location writes require authenticated assigned/online Driver, coordinates in valid ranges, recorded time not older than five minutes, and server receipt time.

- [ ] **Step 1: Write failing delivery tests**

Test location ownership/range/staleness, assigned-to-arrived-to-picked-up-to-delivered ordering, stale version conflict, non-owner denial, cancelled order denial, cash payment completion, milestone timestamps, and proof metadata required for configured deliveries.

- [ ] **Step 2: Run and verify RED**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\delivery_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: missing delivery service.

- [ ] **Step 3: Add evidence metadata and implement milestones**

Create `delivery_evidence` with delivery ID, evidence type, stored path, MIME, size, SHA-256, captured time, uploader, and created time. Files are not stored in the database. Completion updates delivery/order/payment/COD/ledger/milestones/notifications/audit in one transaction; failure records a terminal delivery event and triggers redispatch or Admin incident according to order state.

- [ ] **Step 4: Run focused delivery and order transition tests**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\delivery_service_test.php'
& 'D:\Xampp\php\php.exe' '.\tests\order_transition_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: role-owned milestones and money side effects pass.

- [ ] **Step 5: Commit delivery services**

```powershell
git add lib/repositories/delivery_repository.php lib/services/delivery_service.php api/dispatch.php database/migrations/007_delivery_evidence.php tests/delivery_service_test.php
git commit -m "feat: add authoritative delivery milestones and location"
```

### Task 17: Cut Driver and Customer tracking UI over and remove local dispatch authority

**Files:**
- Modify: `driver_dashboard.php`
- Modify: `driver_delivery.php`
- Modify: `driver_history.php`
- Modify: `driver_earnings.php`
- Modify: `js/driver_dashboard.js`
- Modify: `js/driver_delivery.js`
- Modify: `js/driver_history.js`
- Modify: `js/driver_earnings.js`
- Modify: `js/driver_state.js`
- Modify: `customer_dashboard.php`
- Create: `tests/dispatch_cutover.test.js`

**Interfaces:**
- Consumes: `GET/POST api/dispatch.php` and order read model.
- Produces: server-backed online state, offers, delivery, history, earnings, location, and Customer tracking.

- [ ] **Step 1: Write failing legacy guards**

Assert Driver scripts use `api/dispatch.php`; `driver_state.js` has no `createOffer`, `acceptOffer`, `declineOffer`, `updateMilestone`, `deriveEarnings`, authoritative `setAvailability`, or authoritative `setLocation`; Customer tracking reads server location and renders stale/unavailable states.

- [ ] **Step 2: Run and verify RED**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\dispatch_cutover.test.js'
```

Expected: FAIL on local dispatch/delivery behavior.

- [ ] **Step 3: Switch Driver portal to server commands**

Online toggle, offer accept/decline, milestone actions, issue reporting, GPS updates, history, earnings, and COD summaries call focused APIs. GPS sends at most one update per configured interval and stops when offline or the page is hidden. Retry uses the same intent key.

- [ ] **Step 4: Switch Customer tracking and remove fixed live-location claims**

Render server location only when timestamp is recent enough; otherwise show “Location temporarily unavailable.” Keep the existing map tile fallback, but do not display a fixed coordinate as if it were live Driver data.

- [ ] **Step 5: Delete authoritative Driver local state and run regressions**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\dispatch_cutover.test.js' '.\tests\driver_state.test.js' '.\tests\driver_markup.test.js' '.\tests\customer_markup.test.js'
```

Expected: UI preferences remain local; operational state is server-only.

- [ ] **Step 6: Commit dispatch UI cutover**

```powershell
git add driver_dashboard.php driver_delivery.php driver_history.php driver_earnings.php js/driver_dashboard.js js/driver_delivery.js js/driver_history.js js/driver_earnings.js js/driver_state.js customer_dashboard.php tests/dispatch_cutover.test.js tests/driver_state.test.js tests/driver_markup.test.js tests/customer_markup.test.js
git commit -m "refactor: cut Driver dispatch and tracking over to MySQL"
```

### Task 17A: Move Driver profile, vehicle, documents, and preferences to server authority

**Files:**
- Extend: `lib/repositories/profile_repository.php`
- Extend: `lib/services/profile_service.php`
- Extend: `api/profile.php`
- Modify: `driver_profile.php`
- Modify: `js/driver_state.js`
- Create: `tests/driver_profile_service_test.php`
- Create: `tests/driver_profile_cutover.test.js`

**Interfaces:**
- Produces: `profile_for_driver(...)`, `profile_update_driver_contact(...)`, `profile_update_driver_vehicle_request(...)`, and `profile_update_driver_preferences(...)`.
- Identity/document verification fields remain Admin-reviewed and are read-only to the Driver; contact/preferences are directly versioned, while vehicle/document changes create a pending review request.

- [ ] **Step 1: Write failing Driver profile authorization tests**

```php
$contact = profile_update_driver_contact($conn, $driverA, ['phone' => '+10000000000'], 1);
expect_true($contact['ok'] === true, 'Driver should update owned contact data.');
$identity = profile_update_driver_contact($conn, $driverA, ['eligibilityStatus' => 'eligible'], 2);
expect_true($identity['status'] === 422, 'Driver must not self-approve eligibility.');
$vehicle = profile_update_driver_vehicle_request($conn, $driverA, ['licensePlate' => 'NEW-123'], 2);
expect_true($vehicle['data']['reviewStatus'] === 'pending', 'Vehicle change must require review.');
```

- [ ] **Step 2: Run tests and verify RED**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\driver_profile_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: missing Driver profile service methods.

- [ ] **Step 3: Implement versioned profile policies**

Extend profile schema with a `driver_profile_change_requests` table containing public ID, Driver, change type, requested JSON, status, reviewer, reason, timestamps, and version. Allow direct bounded contact/preference updates; reject direct writes to eligibility, rating, completion, document verification, COD, earnings, and active delivery fields.

- [ ] **Step 4: Cut over Driver profile UI and remove authoritative profile fields from local state**

Load profile/vehicle/documents/preferences from `api/profile.php`. Submit direct or review-required changes according to field policy. `js/driver_state.js` may retain display preferences only and must not persist profile, vehicle, document, eligibility, availability, location, delivery, earning, or COD authority.

- [ ] **Step 5: Run service, cutover, and Driver regression tests**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\driver_profile_cutover.test.js' '.\tests\driver_markup.test.js' '.\tests\driver_state.test.js'
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\driver_profile_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: protected fields cannot be self-edited and no authoritative Driver profile remains local.

- [ ] **Step 6: Commit Driver profile cutover**

```powershell
git add lib/repositories/profile_repository.php lib/services/profile_service.php api/profile.php driver_profile.php js/driver_state.js tests/driver_profile_service_test.php tests/driver_profile_cutover.test.js
git commit -m "feat: move Driver profile and change requests to MySQL"
```

### Phase 6 Exit Gate

- [ ] Two Drivers cannot accept the same offer.
- [ ] Reassignment supersedes the prior delivery and prevents the old Driver from posting milestones.
- [ ] Customer tracking never presents fixed coordinates as live GPS.
- [ ] Driver local storage contains preferences only.
- [ ] Driver eligibility/documents cannot be self-approved; vehicle changes enter a review workflow.

---

## Phase 7: Atomic Admin Operations and Finance

### Task 18: Implement atomic cancellation and reassignment services

**Files:**
- Create: `lib/services/admin_order_service.php`
- Modify: `admin_action.php`
- Modify: `lib/admin_actions.php`
- Modify: `admin_orders.php`
- Modify: `tests/admin_operations_test.php`
- Create: `tests/admin_order_service_test.php`

**Interfaces:**
- Produces: `admin_cancel_order(...)` and `admin_reassign_driver(...)`, both requiring Admin actor, reason, expected order version, and stable idempotency key.

- [ ] **Step 1: Add failing consistency tests**

Cancellation tests must assert order/history, dispatch cancellation, offer expiry, delivery cancellation/supersession, payment action, wallet compensation when applicable, payout exclusion, notifications, audit, and one transaction. Reassignment tests must assert prior assignment closure, new eligible Driver, one active delivery, notification of old/new Drivers, and stale-version conflict.

- [ ] **Step 2: Run and verify RED**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\admin_order_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: current Admin operation SQL leaves related aggregates inconsistent.

- [ ] **Step 3: Implement domain services and route Admin commands**

Services lock order, payment, dispatch, active offer/delivery, and affected ledger/payout rows in a fixed order to reduce deadlocks. Cancellation policy rejects delivered/refunded orders; paid wallet orders receive a compensating wallet/ledger entry, card payments enter `refund_pending` through the payment boundary, and cash payments become cancelled without a fake refund.

- [ ] **Step 4: Remove duplicate operation SQL from `lib/admin_actions.php`**

Delete legacy `admin_operations_action()` and replace the `cancel_order`/`reassign_driver` branches in `admin_operations_action_v2()` with calls to `admin_order_service.php`. Keep compatibility routing only until the Admin UI posts directly to the focused service action.

- [ ] **Step 5: Run Admin operation and security regressions**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\admin_order_service_test.php'
& 'D:\Xampp\php\php.exe' '.\tests\admin_operations_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
& 'D:\nodejs\node.exe' --test '.\tests\admin_operations.test.js' '.\tests\admin_security_hardening.test.js'
```

Expected: atomic side effects and existing Admin contracts pass.

- [ ] **Step 6: Commit Admin order consistency**

```powershell
git add lib/services/admin_order_service.php admin_action.php lib/admin_actions.php admin_orders.php tests/admin_operations_test.php tests/admin_order_service_test.php
git commit -m "fix: make Admin cancellation and reassignment atomic"
```

### Task 19: Implement refunds, wallet compensation, payouts, and COD reconciliation

**Files:**
- Create: `lib/services/finance_service.php`
- Create: `lib/repositories/finance_repository.php`
- Modify: `lib/admin_actions.php`
- Modify: `admin_cases.php`
- Modify: `admin_finance.php`
- Modify: `restaurant_finance.php`
- Modify: `driver_earnings.php`
- Create: `tests/finance_service_test.php`

**Interfaces:**
- Produces: `finance_issue_refund(...)`, `finance_hold_payout(...)`, `finance_release_payout(...)`, `finance_settle_cod(...)`, and role-scoped ledger summaries.

- [ ] **Step 1: Write failing money-invariant tests**

Test refund cannot exceed remaining paid amount, full/partial status handling, wallet destination credit, card `refund_pending`, immutable compensating ledger, payout exclusion/hold, COD over-settlement rejection, stale versions, duplicate retry, and balanced totals by reference.

- [ ] **Step 2: Run and verify RED**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\finance_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: current refund path fails wallet/provider consistency assertions.

- [ ] **Step 3: Implement append-only finance transactions**

Never overwrite balances. Refund creates refund row, payment transition, wallet/provider action, negative ledger entries, payout adjustments, case/order update, notifications, audit, and idempotent response in one transaction. COD settlement appends a settlement ledger entry and uses `WHERE version=?`.

- [ ] **Step 4: Switch finance views to server repositories**

Restaurant finance and Driver earnings read role-scoped ledger/payout/COD data. Remove local derived finance and fake payout actions. Admin cases/finance use the same service and read models.

- [ ] **Step 5: Run finance and role-view tests**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\finance_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
& 'D:\nodejs\node.exe' --test '.\tests\admin_operations.test.js' '.\tests\restaurant_markup.test.js' '.\tests\driver_markup.test.js'
```

Expected: money invariants and portal contracts pass.

- [ ] **Step 6: Commit finance consistency**

```powershell
git add lib/services/finance_service.php lib/repositories/finance_repository.php lib/admin_actions.php admin_cases.php admin_finance.php restaurant_finance.php driver_earnings.php tests/finance_service_test.php
git commit -m "feat: centralize refunds payouts wallet and COD ledger"
```

### Task 20: Finish Admin action decomposition

**Files:**
- Create: `lib/services/admin_account_service.php`
- Create: `lib/services/admin_partner_service.php`
- Create: `lib/services/admin_settings_service.php`
- Modify: `lib/admin_actions.php`
- Modify: `admin_action.php`
- Create: `tests/admin_action_decomposition.test.js`

**Interfaces:**
- Consumes: focused services from Tasks 18–19 and existing account/partner/settings behavior.
- Produces: a small action-name-to-service router; no SQL or transaction implementation remains in `lib/admin_actions.php`.

- [ ] **Step 1: Write the failing decomposition guard**

```javascript
test('Admin action router contains no SQL and delegates to focused services', () => {
  const router = fs.readFileSync('lib/admin_actions.php', 'utf8');
  assert.doesNotMatch(router, /SELECT |INSERT INTO|UPDATE |DELETE FROM|begin_transaction/);
  for (const service of ['admin_account_service.php', 'admin_partner_service.php', 'admin_order_service.php', 'finance_service.php', 'admin_settings_service.php']) {
    assert.match(router, new RegExp(service.replace('.', '\\.')));
  }
});
```

- [ ] **Step 2: Run and verify RED**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\admin_action_decomposition.test.js'
```

Expected: FAIL because `lib/admin_actions.php` contains all Admin SQL.

- [ ] **Step 3: Move behavior without changing contracts**

Move account, partner, and settings functions into their named services. Preserve public action names and response envelopes. Keep `admin_execute_action()` as a routing table only, then remove `admin_operations_action_v2()` after every action has a focused destination.

- [ ] **Step 4: Run all Admin tests**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\admin_action_decomposition.test.js' '.\tests\admin_identity.test.js' '.\tests\admin_operations.test.js' '.\tests\admin_partners.test.js' '.\tests\admin_insights.test.js' '.\tests\admin_security_hardening.test.js'
```

Expected: decomposition guard and existing Admin contracts pass.

- [ ] **Step 5: Commit Admin decomposition**

```powershell
git add lib/services/admin_account_service.php lib/services/admin_partner_service.php lib/services/admin_settings_service.php lib/admin_actions.php admin_action.php tests/admin_action_decomposition.test.js
git commit -m "refactor: decompose Admin actions into domain services"
```

### Phase 7 Exit Gate

- [ ] Cancellation, reassignment, refund, payout, and COD tests prove all required side effects are atomic.
- [ ] Restaurant and Driver finance pages no longer derive authoritative amounts from browser orders.
- [ ] `lib/admin_actions.php` contains routing only and no unreachable legacy operation implementation.

---

## Phase 8: Partner Onboarding and Documents

### Task 21: Add partner application submission and document metadata

**Files:**
- Create: `lib/repositories/partner_application_repository.php`
- Create: `lib/services/partner_application_service.php`
- Create: `api/partner_applications.php`
- Modify: `index.php`
- Create: `js/partner_application.js`
- Create: `tests/partner_application_service_test.php`
- Create: `tests/partner_application_markup.test.js`

**Interfaces:**
- Produces: `partner_submit_application(...)`, `partner_add_document_metadata(...)`, and `index.php?apply=restaurant|driver` application views without adding a new top-level portal route.

- [ ] **Step 1: Write failing application tests**

Test bounded identity fields, unique username/email, password hash only, status `pending`, required document types, application ownership, no user/profile before approval, MIME/extension/size allowlist, randomized stored name, SHA-256 metadata, and upload root outside the document root.

- [ ] **Step 2: Run and verify RED**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_UPLOAD_ROOT="$env:TEMP\savora-test-uploads"
& 'D:\Xampp\php\php.exe' '.\tests\partner_application_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME; Remove-Item Env:SAVORA_UPLOAD_ROOT
```

Expected: missing submission service.

- [ ] **Step 3: Implement submission and safe upload boundary**

Require explicit `SAVORA_UPLOAD_ROOT`; reject roots inside `D:\Xampp\htdocs\Savora`. Allow PDF/JPEG/PNG up to the configured size, compare detected MIME with extension, generate a random filename, store a content hash, and persist only relative metadata. The service transaction creates application and document rows but no `users`, `restaurants`, or `driver_profiles` row.

- [ ] **Step 4: Add accessible application views to the login page**

Use `index.php?apply=restaurant` and `index.php?apply=driver` with labelled forms, password confirmation, required-document checklist, upload status, validation summary, and success reference. Keep normal login unchanged.

- [ ] **Step 5: Run service, markup, and security tests**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\partner_application_markup.test.js' '.\tests\admin_security_hardening.test.js'
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_UPLOAD_ROOT="$env:TEMP\savora-test-uploads"
& 'D:\Xampp\php\php.exe' '.\tests\partner_application_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME; Remove-Item Env:SAVORA_UPLOAD_ROOT
```

Expected: valid applications are pending; unsafe files and paths are rejected.

- [ ] **Step 6: Commit partner submission**

```powershell
git add lib/repositories/partner_application_repository.php lib/services/partner_application_service.php api/partner_applications.php index.php js/partner_application.js tests/partner_application_service_test.php tests/partner_application_markup.test.js
git commit -m "feat: add Restaurant and Driver application submission"
```

### Task 22: Connect approval to submitted applications

**Files:**
- Modify: `lib/services/admin_partner_service.php`
- Modify: `admin_restaurants.php`
- Modify: `admin_drivers.php`
- Modify: `tests/admin_approvals_test.php`
- Modify: `tests/admin_partners.test.js`

**Interfaces:**
- Consumes: submitted application/document records from Task 21.
- Produces: idempotent approval that creates exactly one active user and one partner profile, consumes the application password hash, and queues activation notification.

- [ ] **Step 1: Extend failing approval tests**

Add cases for missing/expired/unverified documents, stale application version, duplicate credentials, repeated approval key, consumed password hash, account/profile count, decision history, activation notification, and no account creation on changes/rejection.

- [ ] **Step 2: Run and verify RED**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\admin_approvals_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: at least the real-submission provenance and notification assertions fail.

- [ ] **Step 3: Enforce application provenance and approval transaction**

Approval locks the application/documents, validates canonical required types and expiry, creates account/profile, nulls `password_hash`, updates status/reviewer/version, appends notification/audit, stores idempotency response, and commits. No Admin action may bypass the application table to create a Restaurant or Driver.

- [ ] **Step 4: Run approval and partner UI tests**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\admin_approvals_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
& 'D:\nodejs\node.exe' --test '.\tests\admin_partners.test.js'
```

Expected: all approval invariants pass.

- [ ] **Step 5: Commit onboarding approval integration**

```powershell
git add lib/services/admin_partner_service.php admin_restaurants.php admin_drivers.php tests/admin_approvals_test.php tests/admin_partners.test.js
git commit -m "feat: approve only submitted verified partner applications"
```

### Phase 8 Exit Gate

- [ ] Restaurant and Driver applications can be submitted without creating accounts.
- [ ] Only approval creates partner accounts/profiles, exactly once.
- [ ] Upload configuration rejects executable-web-root storage and unsafe file types.

---

## Phase 9: Notifications and Support

### Task 23: Add notification outbox and role read model

**Files:**
- Create: `database/migrations/008_notification_outbox.php`
- Create: `lib/repositories/notification_repository.php`
- Extend: `lib/services/notification_service.php`
- Create: `api/notifications.php`
- Modify: `components/customer_header.php`
- Modify: `components/restaurant_header.php`
- Modify: `components/driver_header.php`
- Modify: `components/admin_header.php`
- Create: `tests/notification_service_test.php`
- Create: `tests/notification_markup.test.js`

**Interfaces:**
- Extends: `notification_queue(...)` from Task 4 so it also creates outbox rows.
- Produces: `notifications_for_user(...)`, `notification_mark_read(...)`, and a provider-neutral outbox status `pending|processing|sent|failed`.

- [ ] **Step 1: Write failing notification tests**

Test recipient ownership, transaction rollback, one event per idempotent business command, template rendering with bounded variables, unread count, mark-read ownership, outbox retry count, and absence of password/reset-token values in payloads.

- [ ] **Step 2: Run and verify RED**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\notification_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: missing notification service/outbox.

- [ ] **Step 3: Add outbox and in-app service**

Create `notification_outbox` with notification ID, channel, payload JSON, status, attempt count, next attempt, last error reference, timestamps, and a foreign key to notifications. Extend the existing `notification_queue()` so the in-app notification and pending outbox row are appended inside the caller's existing transaction; no external delivery occurs in the transaction.

- [ ] **Step 4: Add shared notification UI**

Each role header reads unread count and opens an accessible notification panel backed by `GET api/notifications.php`. Mark-read uses CSRF, version/idempotency where needed, and ownership checks.

- [ ] **Step 5: Run service and markup tests**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\notification_markup.test.js' '.\tests\customer_markup.test.js' '.\tests\restaurant_markup.test.js' '.\tests\driver_markup.test.js' '.\tests\admin_markup.test.js'
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\notification_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: role UI and ownership tests pass.

- [ ] **Step 6: Commit notifications**

```powershell
git add database/migrations/008_notification_outbox.php lib/repositories/notification_repository.php lib/services/notification_service.php api/notifications.php components/customer_header.php components/restaurant_header.php components/driver_header.php components/admin_header.php tests/notification_service_test.php tests/notification_markup.test.js
git commit -m "feat: add transactional in-app notifications and outbox"
```

### Task 24: Add server-backed support cases and delivery issues

**Files:**
- Create: `lib/repositories/support_repository.php`
- Create: `lib/services/support_service.php`
- Create: `api/support.php`
- Modify: `customer_history.php`
- Modify: `driver_delivery.php`
- Modify: `js/driver_delivery.js`
- Modify: `admin_cases.php`
- Create: `tests/support_service_test.php`
- Create: `tests/support_cutover.test.js`

**Interfaces:**
- Produces: `support_open_case(...)`, `support_add_message(...)`, `support_resolve_case(...)`, and role-scoped case reads.

- [ ] **Step 1: Write failing support ownership tests**

Test Customer can report owned order, Driver can report assigned delivery, Restaurant can report owned order, unrelated users are denied, message visibility respects `internal_only`, duplicate issue key returns one case, attachment metadata follows the upload policy, and resolution notifies affected roles.

- [ ] **Step 2: Run and verify RED**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\support_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: missing support service.

- [ ] **Step 3: Implement support service and API**

Open-case locks/validates linked order/delivery ownership, creates case/reference/message/attachment metadata/notifications/audit in one transaction, and returns the server case reference. Message and resolution use optimistic case version.

- [ ] **Step 4: Replace local issue toasts with real case submission**

Customer order follow-up and Driver issue forms post to `api/support.php`, preserve entered text on validation failure, and show the returned case reference on success. Admin cases continue through the same repository/service.

- [ ] **Step 5: Run support and UI cutover tests**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\support_cutover.test.js' '.\tests\customer_markup.test.js' '.\tests\driver_markup.test.js' '.\tests\admin_operations.test.js'
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\support_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: no local-only issue reporting remains.

- [ ] **Step 6: Commit support cutover**

```powershell
git add lib/repositories/support_repository.php lib/services/support_service.php api/support.php customer_history.php driver_delivery.php js/driver_delivery.js admin_cases.php tests/support_service_test.php tests/support_cutover.test.js
git commit -m "feat: add server-backed cross-role support cases"
```

### Phase 9 Exit Gate

- [ ] Application, order, assignment, cancellation, refund, payout, and support events create affected-role notifications.
- [ ] Customer and Driver issue forms return a real case reference.
- [ ] No reset token, password hash, or unmasked sensitive payload appears in notifications/outbox.

---

## Phase 10: Commercial Rules, Analytics, and Exports

### Task 25: Connect commercial rules and maintenance mode to checkout

**Files:**
- Create: `lib/repositories/commercial_repository.php`
- Create: `lib/services/commercial_service.php`
- Modify: `lib/services/pricing_service.php`
- Modify: `lib/services/admin_settings_service.php`
- Modify: `admin_promotions.php`
- Modify: `admin_settings.php`
- Create: `tests/commercial_rules_test.php`

**Interfaces:**
- Produces: `commercial_active_rules(mysqli $conn, int $restaurantId, int $customerId, DateTimeImmutable $at): array` and versioned Admin commands for promotion, fee, service area, and maintenance settings.

- [ ] **Step 1: Write failing rule-consumption tests**

Test active schedule boundaries, paused/expired promotion rejection, minimum order, maximum discount, usage cap, budget cap, one redemption per order, future fee activation, service-area pause, and maintenance mode rejecting new quotes/orders while preserving reads.

- [ ] **Step 2: Run and verify RED**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\commercial_rules_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: Admin rules exist but pricing does not consume all of them.

- [ ] **Step 3: Centralize rule resolution**

Pricing calls `commercial_active_rules()` using one server clock value. Promotion discount is rounded once at currency precision; fee rule selection is deterministic by `effective_at DESC, id DESC`; maintenance mode returns `503` with a safe message; quote stores applied rule IDs/versions for audit.

- [ ] **Step 4: Update Admin forms and rule previews**

Expose all enforced fields with version/reason, display the exact server preview, and remove claims that are not backed by the pricing service.

- [ ] **Step 5: Run pricing and commercial regressions**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\commercial_rules_test.php'
& 'D:\Xampp\php\php.exe' '.\tests\pricing_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
& 'D:\nodejs\node.exe' --test '.\tests\admin_operations.test.js' '.\tests\customer_markup.test.js'
```

Expected: Admin rule preview and checkout totals share one implementation.

- [ ] **Step 6: Commit commercial rule integration**

```powershell
git add lib/repositories/commercial_repository.php lib/services/commercial_service.php lib/services/pricing_service.php lib/services/admin_settings_service.php admin_promotions.php admin_settings.php tests/commercial_rules_test.php
git commit -m "feat: enforce promotions fees service areas and maintenance"
```

### Task 26: Correct analytics definitions and implement exports

**Files:**
- Create: `lib/repositories/analytics_repository.php`
- Create: `lib/services/export_service.php`
- Modify: `lib/admin_repository.php`
- Modify: `admin_accounts.php`
- Modify: `admin_analytics.php`
- Modify: `js/admin_ui.js`
- Modify: `restaurant_analytics.php`
- Modify: `restaurant_invoices.php`
- Create: `tests/analytics_repository_test.php`
- Create: `tests/admin_export.test.js`

**Interfaces:**
- Produces: bounded analytics filters, GMV excluding cancelled/refunded value as defined, delivery duration from milestone timestamps, authorized streaming CSV, and print/PDF views generated from server data.

- [ ] **Step 1: Write failing analytics and export tests**

Test date/order-type/Restaurant/Driver filters, GMV and net revenue definitions, completion/cancellation rates, delivered duration from milestones, CSV headers/escaping/content type/authorization, account export handler, and no state change during exports.

- [ ] **Step 2: Run and verify RED**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\analytics_repository_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
& 'D:\nodejs\node.exe' --test '.\tests\admin_export.test.js'
```

Expected: ignored filters and unreachable exports fail.

- [ ] **Step 3: Implement query definitions and streaming export**

Analytics repository applies the same validated filters to KPIs/tables. Export service accepts a generator of escaped rows, writes UTF-8 CSV with formula-injection protection by prefixing cells beginning `=`, `+`, `-`, or `@`, and sends attachment headers only after authorization.

- [ ] **Step 4: Wire all export controls and remove local preview claims**

`admin_accounts.php` and `admin_analytics.php?export=csv` call real endpoints; PDF controls use a server-backed print view. Restaurant analytics/invoices read repository data and provide server-backed printable documents.

- [ ] **Step 5: Run analytics, export, and Admin tests**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\analytics_repository_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
& 'D:\nodejs\node.exe' --test '.\tests\admin_export.test.js' '.\tests\admin_insights.test.js' '.\tests\admin_ui.test.js' '.\tests\restaurant_markup.test.js'
```

Expected: filters affect every metric/table and export controls have reachable handlers.

- [ ] **Step 6: Commit analytics and exports**

```powershell
git add lib/repositories/analytics_repository.php lib/services/export_service.php lib/admin_repository.php admin_accounts.php admin_analytics.php js/admin_ui.js restaurant_analytics.php restaurant_invoices.php tests/analytics_repository_test.php tests/admin_export.test.js
git commit -m "feat: correct analytics and implement authorized exports"
```

### Phase 10 Exit Gate

- [ ] Checkout quote records the exact promotion/fee/service-area versions applied.
- [ ] Maintenance mode rejects writes but permits authenticated reads and Admin access.
- [ ] Every visible export control has a tested authorized execution path.

---

## Phase 11: Security Hardening, Legacy Removal, and Release Verification

### Task 27: Harden environment, demo access, reset recovery, and rate limits

**Files:**
- Create: `lib/environment.php`
- Create: `database/migrations/009_rate_limits.php`
- Create: `lib/services/rate_limit_service.php`
- Modify: `lib/database.php`
- Modify: `index.php`
- Modify: `auth.php`
- Modify: `reset_password.php`
- Modify: `lib/services/admin_account_service.php`
- Modify: `js/admin_ui.js`
- Create: `tests/production_security.test.js`
- Create: `tests/rate_limit_service_test.php`

**Interfaces:**
- Produces: `savora_environment(): string`, `savora_demo_mode(): bool`, `rate_limit_consume(...)`, and reset delivery through notification/outbox without exposing the raw token in Admin JSON/toast.

- [ ] **Step 1: Write failing production-security tests**

Test production rejects missing DB credentials, demo credentials are hidden unless `SAVORA_DEMO_MODE=1`, login/reset/application endpoints enforce bounded rate limits, raw reset token is absent from Admin response and logs, session version revokes existing sessions, and login errors are escaped.

- [ ] **Step 2: Run and verify RED**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\production_security.test.js'
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\rate_limit_service_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: default credentials/demo exposure/token response/rate-limit gaps fail.

- [ ] **Step 3: Implement fail-fast environment rules**

`SAVORA_ENV` must be `development`, `test`, or `production`. Production requires explicit non-empty DB host/user/name and configured secrets; development may retain XAMPP defaults. Demo credentials render only when demo mode is true. Rate-limit keys use hashed actor/IP plus action and fixed windows; successful authentication clears only the relevant bucket.

- [ ] **Step 4: Move reset delivery to notification/outbox**

Admin reset returns a generic success/reference. The one-time URL is queued to the account's configured secure delivery channel and is never copied into the general Admin toast. Test mode captures the token through a dedicated test adapter, not the production response.

- [ ] **Step 5: Run all identity and security tests**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\production_security.test.js' '.\tests\admin_security.test.js' '.\tests\admin_security_hardening.test.js' '.\tests\admin_identity.test.js'
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\tests\rate_limit_service_test.php'
& 'D:\Xampp\php\php.exe' '.\tests\session_security_test.php'
Remove-Item Env:SAVORA_ENV; Remove-Item Env:SAVORA_DB_NAME
```

Expected: all production and session controls pass.

- [ ] **Step 6: Commit security hardening**

```powershell
git add lib/environment.php database/migrations/009_rate_limits.php lib/services/rate_limit_service.php lib/database.php index.php auth.php reset_password.php lib/services/admin_account_service.php js/admin_ui.js tests/production_security.test.js tests/rate_limit_service_test.php
git commit -m "fix: harden production configuration recovery and abuse controls"
```

### Task 28: Remove final compatibility routers and authoritative local writers

**Files:**
- Delete when unused: `api/platform_state.php`
- Delete when unused: `js/platform_bridge.js`
- Reduce or delete when unused: `lib/admin_actions.php`
- Modify: `components/customer_footer.php`
- Modify: `components/restaurant_footer.php`
- Modify: `components/driver_footer.php`
- Modify: all callers found by the guard test
- Create: `tests/legacy_authority_guard.test.js`

**Interfaces:**
- Consumes: all focused APIs/services from prior phases.
- Produces: no reachable legacy authoritative path.

- [ ] **Step 1: Write the final failing legacy guard**

The test recursively scans production PHP/JS, excluding tests/docs, and fails on:

```javascript
const forbidden = [
  'savora_customer_state_v2.orders',
  'placeDemoOrder',
  'topUpWallet',
  'savora_restaurant_state_v1',
  'savora_driver_state_v1',
  "command('place_order'",
  "command('restaurant_order_status'",
  "command('driver_accept_order'",
  "command('driver_milestone'",
  'admin_operations_action_v2',
];
```

Allow the local-storage keys only if their normalized/persisted shape is proven to contain permitted draft/preferences fields and no authoritative fields.

- [ ] **Step 2: Run the guard and verify RED**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\legacy_authority_guard.test.js'
```

Expected: FAIL listing every remaining compatibility caller or authoritative local writer.

- [ ] **Step 3: Remove each listed caller and router branch**

Update footers/pages to load `js/api_client.js` and focused page modules. Delete `api/platform_state.php` and `js/platform_bridge.js` only after the scan finds zero callers. Reduce `lib/admin_actions.php` to a stable router or delete it after `admin_action.php` routes directly to service maps.

- [ ] **Step 4: Run the legacy guard and full JavaScript suite**

```powershell
& 'D:\nodejs\node.exe' --test '.\tests\legacy_authority_guard.test.js'
$tests = Get-ChildItem '.\tests\*.test.js' | Sort-Object FullName | Select-Object -ExpandProperty FullName
& 'D:\nodejs\node.exe' --test $tests
```

Expected: zero forbidden production patterns and all tests pass.

- [ ] **Step 5: Commit final legacy removal**

```powershell
git add -A api/platform_state.php js/platform_bridge.js lib/admin_actions.php components/customer_footer.php components/restaurant_footer.php components/driver_footer.php tests/legacy_authority_guard.test.js
git commit -m "refactor: remove final browser-local authority paths"
```

### Task 29: Run the release verification suite and repeat the audit

**Files:**
- Modify only tests or documentation if verification reveals a proven defect and a new RED/GREEN cycle is completed.
- Do not create generated reports in the repository.

**Interfaces:**
- Consumes: completed Tasks 1–28.
- Produces: fresh evidence for syntax, unit, integration, cross-role, browser, schema, security, and Git integrity.

- [ ] **Step 1: Run all JavaScript tests**

```powershell
$tests = Get-ChildItem '.\tests\*.test.js' | Sort-Object FullName | Select-Object -ExpandProperty FullName
& 'D:\nodejs\node.exe' --test $tests
```

Expected: all tests pass, 0 fail.

- [ ] **Step 2: Run PHP lint over every PHP file**

```powershell
$php = 'D:\Xampp\php\php.exe'
$failures = @()
Get-ChildItem -LiteralPath '.' -Recurse -Filter '*.php' -File | ForEach-Object {
    & $php -l $_.FullName
    if ($LASTEXITCODE -ne 0) { $failures += $_.FullName }
}
if ($failures.Count -gt 0) { throw "PHP lint failures: $($failures -join ', ')" }
```

Expected: zero lint failures.

- [ ] **Step 3: Rebuild and test only the dedicated test database**

```powershell
$env:SAVORA_ENV='test'
$env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' '.\scripts\migrate.php'
& 'D:\Xampp\php\php.exe' '.\scripts\seed.php'
Get-ChildItem '.\tests\*_test.php' | Sort-Object FullName | ForEach-Object {
    & 'D:\Xampp\php\php.exe' $_.FullName
    if ($LASTEXITCODE -ne 0) { throw "PHP integration test failed: $($_.Name)" }
}
Remove-Item Env:SAVORA_ENV
Remove-Item Env:SAVORA_DB_NAME
```

Expected: migrations are repeatable and all PHP service/integration tests pass against `savora_test`.

- [ ] **Step 4: Run browser QA with artifacts outside the repository**

Configure browser QA output under a temporary directory, then run Customer, Restaurant, Driver, and Admin scenarios in separate browser contexts. Verify responsive widths 1440, 768, and 320; focus/dialog behavior; one cross-role order lifecycle; retry; cancellation; refund; GPS stale state; onboarding; notifications; analytics; and exports.

Expected: all browser scenarios pass and no artifact appears in `git status`.

- [ ] **Step 5: Repeat the original read-only audit checks**

Confirm:

- Page GET paths do not migrate, seed, touch session activity, or mutate business data.
- All 35 routes exist and enforce their role.
- MySQL is authoritative for every submitted domain.
- No false export/payment/GPS claims remain.
- Foreign keys, versions, idempotency, notifications, audit, and money invariants match the approved spec.

- [ ] **Step 6: Verify Git scope and create the final verification commit only if needed**

```powershell
git status --short
git diff --check
git diff --stat main...HEAD
git log --oneline --decorate main..HEAD
```

Expected: no generated artifacts, no unrelated user files, and only planned migration changes. If verification required no code/doc change, do not create an empty commit.

### Phase 11 Exit Gate

- [ ] All automated suites have fresh passing evidence.
- [ ] Browser QA covers separate role sessions and writes artifacts outside the repository.
- [ ] The final read-only audit no longer reports request-side effects, split-brain state, unstable idempotency, pricing mismatch, fake GPS, inconsistent Admin money operations, missing partner submission, or unreachable exports.

---

## Spec Coverage Map

| Approved design requirement | Implementation tasks |
|---|---|
| Side-effect-free web requests | Tasks 2–3 |
| Explicit migrations/seeds and schema integrity | Tasks 2, 5 |
| Canonical statuses and HTTP contract | Task 4 |
| Stable payload-aware idempotency | Task 6 |
| Restaurant profile/catalog authority | Tasks 7–8 |
| Customer profile/addresses/favorites and Restaurant reviews | Task 8A |
| Pricing/checkout/payment/wallet authority | Tasks 9–11 |
| One cross-role order model | Tasks 12–14 |
| Dispatch/delivery/GPS/POD | Tasks 15–17 |
| Driver profile/vehicle/document authority | Task 17A |
| Atomic Admin intervention and finance | Tasks 18–20 |
| Partner submission/documents/approval | Tasks 21–22 |
| Notifications and support | Tasks 23–24 |
| Promotions/fees/service areas/settings | Task 25 |
| Analytics and exports | Task 26 |
| Production security and recovery | Task 27 |
| Final legacy removal | Task 28 |
| Full verification and repeat audit | Task 29 |

## Execution Rule

Execute tasks strictly in order. A later phase may depend on interfaces created earlier, but no later task may reintroduce a local authoritative writer, bypass a domain service with direct SQL, or weaken a completed phase gate. If a RED test exposes an undocumented design decision, stop that task and update the approved spec and this plan before implementing the behavior.
