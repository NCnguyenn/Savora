# Task 6 report: stable, payload-aware idempotency

## Delivered

- Added migration `003_idempotency_request_hash`, registered after migrations 001 and 002. It adds `idempotency_keys.request_hash CHAR(64) NOT NULL` and validates an existing column definition for safe retries.
- Added `lib/idempotency.php` as the sole response storage/replay path. It recursively sorts associative arrays, preserves list order, uses throwing JSON encoding, hashes `action + "\n" + canonical JSON` with SHA-256, and rejects actor/key reuse with a different action or request hash via `SavoraIdempotencyConflict`.
- Routed both `api/platform_state.php` and `admin_action.php` through the service. A conflicting key now returns HTTP 409 without replaying or executing the mutation.
- Replaced per-attempt browser key generation with caller-owned intents: persistent checkout `sessionStorage['savora_checkout_intent']`, dialog-owned Admin keys, and scoped platform intents for Restaurant and Driver commands. Keys clear only after a successful command or dialog cancellation.
- Retired duplicate platform response logic; `lib/platform_response.php` delegates to the idempotency service.
- Added/updated contract, pure hash, service, migration, endpoint-fixture, and response-envelope coverage.

## Verification

- RED observed before implementation:
  - `tests/idempotency_contract.test.js` failed because bridge/Admin used `Date.now()` and `Math.random()`, checkout lacked an intent, and dialogs had no stable key.
  - `tests/idempotency_service_test.php` failed because `lib/idempotency.php` did not exist.
- Focused/static JS: 13 passing tests across idempotency, Admin UI, migration registry, endpoint security, and session-read contracts.
- Full safe JS: 168 passing tests, 0 failures.
- Pure PHP hash contract: passed.
- PHP lint: passed for 89 PHP files.
- `db.php` contains no schema/seed/DML calls. `savora_validate_session()` contains only a SELECT; session writes remain in its explicit session lifecycle helpers.

## Database verification blocker

All database commands used only `SAVORA_ENV=test`, `SAVORA_DB_NAME=savora_test` on the configured test connection. The migration integration test cannot apply migration 003 because Task 5's required preflight stops migration 002 first:

`Orphan rows prevent 002_core_integrity: order_status_history.order_id -> orders.id (11 orphans).`

Consequently the test database has no `request_hash` column, and the DB-backed idempotency, endpoint, response, and Admin integration tests correctly stop at that schema boundary. No test data was changed and no constraint was weakened. After the 11 test-database orphan rows are repaired under the controlled migration process, rerun the migration integration test followed by the blocked DB-backed tests.

## Self-review

- Verified every production idempotency lookup/store now uses actor, key, action, and request hash; no old Admin or platform response lookup/store implementation remains.
- Verified canonical hashing covers nested associative ordering and retains array-list ordering.
- Verified conflict mapping is HTTP 409 in both endpoints and happens before transactions/mutations.
- Verified all browser bridge callers pass an explicit stable key and no retry key uses `Date.now()` or `Math.random()`.
