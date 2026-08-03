# Customer GPS Address Confirmation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give Customers a reviewable GPS address preview, optional saved delivery details, explicit confirmation, guest-to-account synchronization, and safe Checkout note prefill.

**Architecture:** Add a public, rate-limited reverse-geocode preview boundary that never writes Customer data. Keep authenticated confirmation in the existing location API, persist one normalized `deliveryDetails` value to both Customer location records, and use one shared Customer dialog/controller on Home, Profile, and Checkout. Guest confirmation is stored locally and synchronized only after authentication; Checkout treats its delivery note as an independent per-order value after the Customer edits it.

**Tech Stack:** PHP 8 strict-mode endpoints and services, MySQL migrations/integration tests, vanilla JavaScript CommonJS-compatible units, Node's built-in test runner, existing Savora API/UI components, Geoapify through the existing server-side adapter.

## Global Constraints

- All Customer-facing copy is English.
- Do not change Driver or Restaurant location behavior.
- Never persist a GPS result before the Customer presses **Save address**.
- Never trust or persist previewed address text during authenticated GPS confirmation; reverse-geocode the submitted coordinates again on the server.
- Keep `delivery_details` separate from provider `address_line2` and the one-time Checkout `deliveryNote`.
- Render every address/details value through `textContent` or form `.value`, never `innerHTML`.
- Preserve the user's unrelated changes, especially the existing modification in `lib/database.php` and unrelated untracked files.
- Run the focused failing test before implementation and the same test after implementation for every task.
- Make each task's commit only if Git operations are available; if the current Git usage limit remains active, continue the implementation without bypassing it and report the pending commits.
- Use `D:\Xampp\php\php.exe` for PHP lint/tests. Database tests must set `SAVORA_ENV=test` and `SAVORA_DB_NAME=savora_test`.

---

## Task 1: Add the delivery-details schema contract

**Files:**

- Create: `database/migrations/019_customer_gps_confirmation.php`
- Modify: `lib/migrations.php`
- Modify: `tests/migration_registry.test.js`
- Modify: `tests/migration_integrity_test.php`

- [ ] **Step 1: Write the failing migration registry and schema assertions**

Add a registry assertion for `019_customer_gps_confirmation`. Extend the integration test to require:

```php
migration_expect(
    migration_column_matches(migration_column($conn, 'customer_profiles', 'delivery_details'), 'varchar(300)', 'YES'),
    'customer_profiles.delivery_details must be nullable VARCHAR(300).'
);
migration_expect(
    migration_column_matches(migration_column($conn, 'customer_addresses', 'delivery_details'), 'varchar(300)', 'YES'),
    'customer_addresses.delivery_details must be nullable VARCHAR(300).'
);
migration_expect(
    migration_column_matches(migration_column($conn, 'customer_addresses', 'latitude'), 'decimal(10,7)', 'YES'),
    'Manual addresses must be able to clear stale latitude.'
);
migration_expect(
    migration_column_matches(migration_column($conn, 'customer_addresses', 'longitude'), 'decimal(10,7)', 'YES'),
    'Manual addresses must be able to clear stale longitude.'
);
```

- [ ] **Step 2: Run the focused tests and confirm they fail**

Run:

```powershell
node --test tests/migration_registry.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; & 'D:\Xampp\php\php.exe' tests/migration_integrity_test.php
```

Expected: the registry test cannot find migration 019 and the schema test cannot find `delivery_details`/nullable address coordinates.

- [ ] **Step 3: Implement idempotent migration 019**

Follow the `information_schema.COLUMNS` validation pattern from migration 016. The migration must add both nullable text columns and make checkout-address coordinates nullable so a manual save can actually remove stale GPS data:

```php
<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $databaseResult = $conn->query('SELECT DATABASE() AS name');
    $database = (string) ($databaseResult?->fetch_assoc()['name'] ?? '');
    if ($database === '') throw new RuntimeException('A database must be selected before the Customer GPS confirmation migration.');

    $ensureColumn = static function (
        string $table,
        string $name,
        string $definition,
        string $expectedType,
        string $nullable
    ) use ($conn, $database): void {
        $lookup = $conn->prepare(
            'SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?'
        );
        $lookup->bind_param('sss', $database, $table, $name);
        $lookup->execute();
        $existing = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (!$existing) {
            if (!$conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}")) {
                throw new RuntimeException("Unable to add {$table}.{$name}: {$conn->error}");
            }
            return;
        }
        if (strtolower((string) $existing['COLUMN_TYPE']) !== $expectedType || (string) $existing['IS_NULLABLE'] !== $nullable) {
            if (!$conn->query("ALTER TABLE `{$table}` MODIFY COLUMN `{$name}` {$definition}")) {
                throw new RuntimeException("Unable to align {$table}.{$name}: {$conn->error}");
            }
        }
    };

    $ensureColumn('customer_profiles', 'delivery_details', 'VARCHAR(300) NULL', 'varchar(300)', 'YES');
    $ensureColumn('customer_addresses', 'delivery_details', 'VARCHAR(300) NULL', 'varchar(300)', 'YES');
    $ensureColumn('customer_addresses', 'latitude', 'DECIMAL(10,7) NULL', 'decimal(10,7)', 'YES');
    $ensureColumn('customer_addresses', 'longitude', 'DECIMAL(10,7) NULL', 'decimal(10,7)', 'YES');
};
```

Register it after migration 018:

```php
'019_customer_gps_confirmation' => __DIR__ . '/../database/migrations/019_customer_gps_confirmation.php',
```

- [ ] **Step 4: Apply migration 019 to the test database and rerun tests**

Run:

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; & 'D:\Xampp\php\php.exe' scripts/migrate.php
node --test tests/migration_registry.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; & 'D:\Xampp\php\php.exe' tests/migration_integrity_test.php
```

Expected: migration output includes `019_customer_gps_confirmation`; both tests print/pass without schema errors.

- [ ] **Step 5: Commit the schema checkpoint**

```powershell
git add database/migrations/019_customer_gps_confirmation.php lib/migrations.php tests/migration_registry.test.js tests/migration_integrity_test.php
git commit -m "feat: add customer delivery detail schema"
```

---

## Task 2: Build the public GPS preview boundary

**Files:**

- Create: `lib/customer_location_preview.php`
- Create: `api/location_preview.php`
- Create: `tests/customer_location_preview_test.php`
- Modify: `tests/location_api_contract.test.js`

- [ ] **Step 1: Write failing pure service and endpoint-contract tests**

Test the service through injected callables so no real Geoapify request is made:

```php
$resolved = customer_location_preview(
    13.7563,
    100.5018,
    static fn (float $lat, float $lon): array => [
        'address' => 'Bangkok, Thailand',
        'addressLine1' => 'Ratchadamnoen Avenue',
        'addressLine2' => '',
        'city' => 'Bangkok',
        'state' => 'Bangkok',
        'postalCode' => '10200',
        'country' => 'Thailand',
    ]
);
preview_expect($resolved['latitude'] === 13.7563, 'Preview must return submitted latitude.');
preview_expect(!array_key_exists('raw', $resolved), 'Preview must not expose raw provider data.');
```

Also test `customer_location_same_origin($_SERVER)` for:

- `Sec-Fetch-Site: same-origin` accepted.
- `Sec-Fetch-Site: cross-site` rejected.
- matching `Origin` scheme and host accepted.
- mismatched scheme or host rejected.
- both headers absent accepted for CLI/tests.

Add static endpoint assertions for POST-only handling, 4096-byte limit, `application/json`, action `customer_location_preview`, the exact call `rate_limit_consume($conn, $remoteAddress, 'customer_location_preview', 10, 600)`, and the absence of authentication/CSRF/idempotency writes.

- [ ] **Step 2: Run the tests and confirm they fail**

```powershell
& 'D:\Xampp\php\php.exe' tests/customer_location_preview_test.php
node --test tests/location_api_contract.test.js
```

Expected: missing preview service/endpoint assertions fail.

- [ ] **Step 3: Implement the pure preview and same-origin helpers**

Create these boundaries in `lib/customer_location_preview.php`:

```php
function customer_location_request_origin(array $server): string;
function customer_location_same_origin(array $server): bool;
function customer_location_preview(
    mixed $latitude,
    mixed $longitude,
    ?callable $reverseGeocode = null
): array;
```

`customer_location_preview()` must call `savora_validate_coordinates()`, call the injected resolver or `savora_reverse_geocode()`, and return only:

```php
[
    'address' => (string) $resolved['address'],
    'addressLine1' => (string) ($resolved['addressLine1'] ?? ''),
    'addressLine2' => (string) ($resolved['addressLine2'] ?? ''),
    'city' => (string) ($resolved['city'] ?? ''),
    'state' => (string) ($resolved['state'] ?? ''),
    'postalCode' => (string) ($resolved['postalCode'] ?? ''),
    'country' => (string) ($resolved['country'] ?? ''),
    'latitude' => $coordinates['latitude'],
    'longitude' => $coordinates['longitude'],
];
```

Origin comparison must derive HTTPS from `HTTPS`/forwarded-proto only through the application's trusted request convention, normalize host case, and compare exact `scheme://host[:port]` values. `Sec-Fetch-Site` takes precedence when present.

- [ ] **Step 4: Implement the public endpoint**

`api/location_preview.php` must execute in this order:

1. Include database, HTTP, rate-limit, location service, and preview service dependencies.
2. Start/resume the normal session without requiring an actor.
3. Require `POST`.
4. Require `application/json` and reject `CONTENT_LENGTH > 4096`.
5. Enforce `customer_location_same_origin($_SERVER)`.
6. Consume `rate_limit_consume($conn, $remoteAddress, 'customer_location_preview', 10, 600)`.
7. Parse JSON and call `customer_location_preview()`.
8. Return `['ok' => true, 'data' => ['location' => $preview]]`.

Map failures to safe responses: 400 malformed JSON, 403 cross-site, 405 method, 413 oversized, 415 content type, 422 invalid coordinates, 429 quota, and 503 address lookup unavailable. Never echo exception/provider details for the 503 path.

- [ ] **Step 5: Rerun focused tests and lint**

```powershell
& 'D:\Xampp\php\php.exe' -l lib/customer_location_preview.php
& 'D:\Xampp\php\php.exe' -l api/location_preview.php
& 'D:\Xampp\php\php.exe' tests/customer_location_preview_test.php
node --test tests/location_api_contract.test.js
```

Expected: all commands pass; no live provider call occurs.

- [ ] **Step 6: Commit the preview checkpoint**

```powershell
git add lib/customer_location_preview.php api/location_preview.php tests/customer_location_preview_test.php tests/location_api_contract.test.js
git commit -m "feat: add public GPS address preview"
```

---

## Task 3: Persist delivery details in the authoritative location models

**Files:**

- Modify: `lib/profile_locations.php`
- Modify: `api/location.php`
- Modify: `lib/repositories/profile_repository.php`
- Modify: `lib/services/profile_service.php`
- Modify: `tests/profile_locations_test.php`
- Modify: `tests/location_api_contract.test.js`

- [ ] **Step 1: Extend failing integration assertions**

Change the GPS fixture call to include details and assert both records agree:

```php
$saved = savora_save_gps_location(
    $conn,
    'customer',
    $customerId,
    $resolved,
    13.7563,
    100.5018,
    'Tower B, floor 12'
);
profile_location_expect($saved['deliveryDetails'] === 'Tower B, floor 12', 'GPS details must be returned.');
```

Query `customer_profiles.delivery_details` and `customer_addresses.delivery_details`. Then perform a manual save with `deliveryDetails => 'Blue gate'` and assert:

- both records contain `Blue gate`;
- both location coordinate pairs are `NULL`;
- the default checkout address now contains the manual address;
- values over 300 characters fail validation;
- an empty value is accepted and returned as `''`.

Extend the profile snapshot assertion to require `addresses[0].deliveryDetails` and nullable coordinate mapping rather than `0.0`.

- [ ] **Step 2: Run the integration and contract tests and confirm failure**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; & 'D:\Xampp\php\php.exe' tests/profile_locations_test.php
node --test tests/location_api_contract.test.js
```

Expected: current save signatures/read models omit details and manual checkout coordinates remain stale.

- [ ] **Step 3: Extend the shared location read/write contract**

In `lib/profile_locations.php`:

- Add `'deliveryDetails' => ''` to `savora_location_empty()`.
- Select `delivery_details` for Customer profile reads.
- Map nullable coordinates without converting `NULL` to `0.0`.
- Add a strict normalizer:

```php
function savora_delivery_details(mixed $value): string
{
    if (!is_string($value)) throw new InvalidArgumentException('Delivery details must be text.');
    $value = trim($value);
    if (mb_strlen($value) > 300) throw new InvalidArgumentException('Delivery details must be 300 characters or fewer.');
    return $value;
}
```

- Extend the GPS save signature with an optional final string so existing Driver/Restaurant callers remain compatible:

```php
function savora_save_gps_location(
    mysqli $conn,
    string $role,
    int $userId,
    array $resolved,
    float $latitude,
    float $longitude,
    string $deliveryDetails = ''
): array;
```

- For Customers, atomically update `customer_profiles` and the default/most-recent `customer_addresses` row with the same details.
- For Customer manual saves, update/create the default address, set both coordinate pairs to `NULL`, and preserve provider-derived `address_line2` as a separate field.
- Driver and Restaurant branches must ignore `deliveryDetails` and retain current SQL/return behavior.

Use one internal Customer synchronization helper for both modes:

```php
function savora_sync_customer_checkout_location(
    mysqli $conn,
    int $userId,
    array $address,
    ?float $latitude,
    ?float $longitude,
    string $deliveryDetails
): void;
```

- [ ] **Step 4: Pass details through the authenticated API**

In `api/location.php`, normalize `deliveryDetails` before the transaction and pass it to GPS saves. Manual saves keep using the payload but are normalized inside `savora_save_manual_location()`:

```php
$deliveryDetails = savora_delivery_details($payload['deliveryDetails'] ?? '');
savora_save_gps_location(
    $conn,
    $role,
    $userId,
    $resolved ?? [],
    (float) $coordinates['latitude'],
    (float) $coordinates['longitude'],
    $role === 'customer' ? $deliveryDetails : ''
);
```

Do not alter CSRF, ownership, idempotency locking, re-geocoding, or transaction boundaries.

- [ ] **Step 5: Expose details in Profile snapshots and address saves**

Update `profile_repository_addresses()` to select `delivery_details`. Map:

```php
'deliveryDetails' => (string) ($row['delivery_details'] ?? ''),
'latitude' => $row['latitude'] === null ? null : (float) $row['latitude'],
'longitude' => $row['longitude'] === null ? null : (float) $row['longitude'],
```

Update `profile_save_address_mutation()` to accept normalized optional `deliveryDetails`, write it on insert/update, and allow coordinates to be either both valid numbers or both `NULL`. Reject one-sided coordinates. This keeps direct Profile address saves consistent with the shared location record.

- [ ] **Step 6: Rerun focused integration tests and lint**

```powershell
& 'D:\Xampp\php\php.exe' -l lib/profile_locations.php
& 'D:\Xampp\php\php.exe' -l api/location.php
& 'D:\Xampp\php\php.exe' -l lib/repositories/profile_repository.php
& 'D:\Xampp\php\php.exe' -l lib/services/profile_service.php
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; & 'D:\Xampp\php\php.exe' tests/profile_locations_test.php
node --test tests/location_api_contract.test.js
```

Expected: details persist to both records; manual mode returns/keeps `NULL` coordinates; all checks pass.

- [ ] **Step 7: Commit the persistence checkpoint**

```powershell
git add lib/profile_locations.php api/location.php lib/repositories/profile_repository.php lib/services/profile_service.php tests/profile_locations_test.php tests/location_api_contract.test.js
git commit -m "feat: persist customer delivery details"
```

---

## Task 4: Add public-preview and confirmed-draft client primitives

**Files:**

- Modify: `js/api_client.js`
- Modify: `js/location_client.js`
- Create: `js/customer_location_state.js`
- Create: `tests/api_client_public_post.test.js`
- Modify: `tests/location_client.test.js`
- Create: `tests/customer_location_state.test.js`

- [ ] **Step 1: Write failing client tests**

Require these behaviors:

```js
await Client.previewGps(api, { latitude: 13.7563, longitude: 100.5018 });
assert.deepEqual(calls[0], {
  method: 'POST_PUBLIC',
  url: 'api/location_preview.php',
  body: { latitude: 13.7563, longitude: 100.5018 }
});
```

Test that `SavoraApi.postPublic()` sends JSON with `credentials: 'same-origin'` but no CSRF or idempotency headers. Test pure guest-state helpers for:

- rejecting malformed coordinate pairs;
- trimming details and enforcing 300 characters;
- creating `pendingSync: true` only from an explicit confirmation call;
- preserving normalized address fields;
- serializing GPS sync payload separately from manual sync payload;
- parsing corrupt local storage as no draft.

- [ ] **Step 2: Run focused Node tests and confirm failure**

```powershell
node --test tests/api_client_public_post.test.js tests/location_client.test.js tests/customer_location_state.test.js
```

Expected: `postPublic`, `previewGps`, and guest-state helpers do not exist.

- [ ] **Step 3: Add the non-mutating API client method**

Add:

```js
async function postPublic(url, body) {
  let response;
  try {
    response = await root.fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    });
  } catch (error) {
    throw requestError({ status: 0 }, { message: error.message });
  }
  const payload = await decode(response);
  if (!response.ok || !payload.ok) throw requestError(response, payload);
  return payload.data;
}
```

Export it beside `get`/`post`. Do not weaken the stable intent-key requirement on mutating `post()`.

- [ ] **Step 4: Extend the location client**

Add `PREVIEW_ENDPOINT = 'api/location_preview.php'` and:

```js
async function previewGps(api, coordinates) {
  const client = requireApi(api);
  const normalized = coordinatesOf(coordinates);
  const data = await client.postPublic(PREVIEW_ENDPOINT, normalized);
  return data && data.location ? data.location : null;
}
```

Extend `saveGps`/`saveManual` payload tests to include `deliveryDetails` without changing existing coordinate validation or geolocation error messages.

- [ ] **Step 5: Implement pure confirmed-draft state**

Create a UMD/CommonJS-compatible `SavoraCustomerLocationState` module exposing:

```js
normalizeDeliveryDetails(value)
normalizePreview(value)
confirmGps(preview, deliveryDetails)
confirmManual(address, deliveryDetails)
parseGuestDraft(raw)
syncRequest(draft)
```

`confirmGps`/`confirmManual` are the only functions that add `pendingSync: true`. Preview normalization alone must never produce a persistable draft.

- [ ] **Step 6: Rerun focused tests**

```powershell
node --test tests/api_client_public_post.test.js tests/location_client.test.js tests/customer_location_state.test.js
```

Expected: all client primitive tests pass.

- [ ] **Step 7: Commit the client-primitives checkpoint**

```powershell
git add js/api_client.js js/location_client.js js/customer_location_state.js tests/api_client_public_post.test.js tests/location_client.test.js tests/customer_location_state.test.js
git commit -m "feat: add GPS preview client primitives"
```

---

## Task 5: Replace immediate GPS save with the shared confirmation dialog

**Files:**

- Modify: `components/customer_footer.php`
- Modify: `customer_dashboard.php`
- Modify: `js/customer_location.js`
- Modify: `css/customer_style.css`
- Modify: `tests/customer_markup.test.js`
- Create: `tests/customer_location_confirmation.test.js`

- [ ] **Step 1: Write failing markup/controller contract tests**

Require one shared dialog in the footer with:

```html
id="customer-location-dialog"
data-customer-location-form
data-customer-location-preview
data-customer-location-preview-address
data-customer-location-input
data-customer-delivery-details
data-customer-use-gps
data-customer-location-manual
data-customer-location-save
data-customer-location-status
```

Assert that Home no longer owns a duplicate dialog, all relevant controls point to the shared dialog, details have `maxlength="300"`, the save button starts disabled, and the controller calls `previewGps` before `saveGps`. Assert no `watchPosition` and no local-storage write occurs in the GPS-preview function.

- [ ] **Step 2: Run the tests and confirm failure**

```powershell
node --test tests/customer_markup.test.js tests/customer_location_confirmation.test.js
```

Expected: preview/details/shared-dialog contracts are missing and current GPS logic saves immediately.

- [ ] **Step 3: Move and expand the dialog into shared Customer chrome**

Move the dialog from `customer_dashboard.php` to `components/customer_footer.php`, before shared scripts. Its visible English states must include:

- **Detected address** preview region.
- **Delivery details (optional)** with examples such as apartment, floor, gate, or landmark.
- **Use current location** / **Try GPS again**.
- **Enter address manually**.
- **Save address**, disabled until valid GPS preview or non-empty manual address.
- **Skip** and close controls.
- **Powered by Geoapify** attribution.

Load `js/customer_location_state.js` before `js/customer_location.js`.

- [ ] **Step 4: Refactor the controller into explicit preview/confirm states**

Keep state local to the controller:

```js
let pendingPreview = null;
let mode = 'manual';
let activeTrigger = null;
let saving = false;
```

Implement this flow:

1. `useCurrentLocation()` sets button busy, announces **Requesting your location…**, obtains one position, then announces **Finding your address…**.
2. Call `LocationClient.previewGps(SavoraApi, coordinates)`.
3. Store only `pendingPreview` in memory, render **Address found. Please review it before saving.**, and enable Save.
4. Submit creates a confirmed draft from the current mode/details.
5. Guest: write the confirmed draft to `savora_guest_location_v1`, render it, and announce **Saved on this device. Sign in to sync it with your account.**
6. Authenticated: call `saveGps` or `saveManual`, reload `api/location.php`, render the authoritative location, and announce **Address saved.**
7. Closing/skipping resets `pendingPreview` and form-only edits without changing the saved location.

Keep the saved display address and unsaved preview as distinct variables. Reset the idempotency key after each successful authenticated save so a later explicit choice receives a fresh key.

- [ ] **Step 5: Implement authenticated guest synchronization**

Before normal authenticated rendering:

1. Parse local storage through `SavoraCustomerLocationState.parseGuestDraft()`.
2. If `pendingSync`, call the corresponding authenticated save with a dedicated stable intent scope.
3. Reload `api/location.php`.
4. Remove local storage only after both save and reload succeed.
5. Dispatch `savora:customer-location-changed` with the authoritative location.
6. On failure, retain the draft and announce a retry-safe message without replacing account data in the UI.

The guest's latest explicitly confirmed draft intentionally becomes the account default after successful synchronization.

- [ ] **Step 6: Add responsive and focus behavior**

In `css/customer_style.css`, style the preview card, mode actions, details textarea, busy/disabled states, and 390 px stacking. In the controller:

- focus the first relevant control after open;
- return focus to `activeTrigger` after close;
- toggle `aria-expanded` on the trigger;
- set GPS button `aria-busy` while locating/resolving;
- keep status `aria-live="polite"` and `aria-atomic="true"`.

- [ ] **Step 7: Rerun focused tests**

```powershell
node --test tests/customer_location_state.test.js tests/location_client.test.js tests/customer_markup.test.js tests/customer_location_confirmation.test.js
```

Expected: GPS only previews; persistence appears only in submit/confirmation branches; shared markup and guest sync contracts pass.

- [ ] **Step 8: Commit the shared-dialog checkpoint**

```powershell
git add components/customer_footer.php customer_dashboard.php js/customer_location.js css/customer_style.css tests/customer_markup.test.js tests/customer_location_confirmation.test.js
git commit -m "feat: confirm customer GPS addresses before saving"
```

---

## Task 6: Integrate saved delivery details with Customer Profile

**Files:**

- Modify: `customer_profile.php`
- Modify: `tests/customer_markup.test.js`
- Modify: `tests/profile_locations_test.php`

- [ ] **Step 1: Write failing Profile assertions**

Require:

- a labelled `profile-delivery-details` textarea with `maxlength="300"`;
- the GPS button opens the shared dialog instead of immediately saving;
- `renderSnapshot()` hydrates `address.deliveryDetails`;
- Profile submit sends `deliveryDetails` in `save_address` and manual-location payloads;
- empty coordinates serialize as `null`, never `Number('') === 0`;
- a location-changed event refreshes Profile from the server.

- [ ] **Step 2: Run focused tests and confirm failure**

```powershell
node --test tests/customer_markup.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; & 'D:\Xampp\php\php.exe' tests/profile_locations_test.php
```

Expected: Profile details field/payload are missing.

- [ ] **Step 3: Update Profile markup and hydration**

Add the field beside Delivery address:

```html
<label for="profile-delivery-details">Delivery details (optional)</label>
<textarea id="profile-delivery-details" name="deliveryDetails" rows="2" maxlength="300"
  placeholder="Apartment, floor, gate, or landmark"></textarea>
```

Change the GPS control to `data-customer-location-trigger` with `aria-controls="customer-location-dialog"`. Hydrate details from the default address and listen for the authoritative location-changed event before refreshing the Profile snapshot.

- [ ] **Step 4: Make Profile's manual save consistent**

Build nullable coordinates explicitly:

```js
const latitude = latitudeInput.value.trim() === '' ? null : Number(latitudeInput.value);
const longitude = longitudeInput.value.trim() === '' ? null : Number(longitudeInput.value);
```

Send `deliveryDetails` with both the profile `save_address` action and `SavoraLocationClient.saveManual()`. Keep the existing server-backed profile/name/email save sequence and refresh the snapshot after all writes succeed.

Because the shared dialog performs its own confirmed save, its success event must refresh Profile fields; merely opening/previewing the dialog must not mutate the form or server.

- [ ] **Step 5: Rerun focused tests and lint**

```powershell
& 'D:\Xampp\php\php.exe' -l customer_profile.php
node --test tests/customer_markup.test.js tests/customer_location_confirmation.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; & 'D:\Xampp\php\php.exe' tests/profile_locations_test.php
```

Expected: Profile restores/saves the same details as the location service and accepts a manual address without fake `0,0` coordinates.

- [ ] **Step 6: Commit the Profile checkpoint**

```powershell
git add customer_profile.php tests/customer_markup.test.js tests/profile_locations_test.php
git commit -m "feat: add profile delivery details"
```

---

## Task 7: Prefill Checkout notes without overwriting Customer edits

**Files:**

- Create: `js/customer_checkout_note.js`
- Create: `tests/customer_checkout_note.test.js`
- Modify: `components/customer_footer.php`
- Modify: `customer_checkout.php`
- Modify: `tests/customer_markup.test.js`

- [ ] **Step 1: Write failing pure state tests**

Test a small note-state unit rather than only static page text:

```js
const state = NoteState.create();
assert.deepEqual(NoteState.applyAddressDetails(state, 'Tower B'), { value: 'Tower B', dirty: false });
const edited = NoteState.edit(state, 'Call on arrival');
assert.deepEqual(NoteState.applyAddressDetails(edited, 'New gate'), edited);
```

Also test empty details, 300-character preservation, and resetting only on a fresh Checkout page state. Markup tests must require a 300-character UI limit/count and `selectedAddress.deliveryDetails` as the source.

- [ ] **Step 2: Run tests and confirm failure**

```powershell
node --test tests/customer_checkout_note.test.js tests/customer_markup.test.js
```

Expected: the note-state unit is missing and Checkout never pre-fills saved details.

- [ ] **Step 3: Implement the note-state helper**

Create a UMD/CommonJS-compatible helper exposing:

```js
create()
applyAddressDetails(state, deliveryDetails)
edit(state, nextValue)
```

Normalize values to strings capped at 300 characters. `applyAddressDetails` must be a no-op once `dirty === true`.

- [ ] **Step 4: Integrate it into Checkout**

Load the helper in the shared footer before page scripts. Change Checkout note `maxlength` and counter to 300. Initialize one note state and update `renderSelectedAddress()`:

```js
const next = SavoraCustomerCheckoutNote.applyAddressDetails(
    noteState,
    selectedAddress ? selectedAddress.deliveryDetails : ''
);
noteState = next;
note.value = next.value;
renderNoteCount();
```

On input, call `edit()` and update the count. Location refresh and quote refresh may call `renderSelectedAddress()`, but the helper must preserve a Customer-edited note. Order submit continues sending `note.value.trim()` only; it must not update saved delivery details.

Change Checkout's GPS button to open the shared confirmation dialog. After a confirmed save, reuse the existing authoritative Profile reload and quote refresh.

- [ ] **Step 5: Rerun focused tests and lint**

```powershell
& 'D:\Xampp\php\php.exe' -l customer_checkout.php
node --test tests/customer_checkout_note.test.js tests/customer_markup.test.js tests/customer_location_confirmation.test.js
```

Expected: untouched notes follow saved details; edited notes survive location/quote refreshes; order submission remains unchanged.

- [ ] **Step 6: Commit the Checkout checkpoint**

```powershell
git add js/customer_checkout_note.js tests/customer_checkout_note.test.js components/customer_footer.php customer_checkout.php tests/customer_markup.test.js
git commit -m "feat: prefill checkout delivery notes safely"
```

---

## Task 8: Run complete regression, security, and browser verification

**Files:**

- Modify if needed: `tests/customer_guest_browser_qa.mjs`
- Modify if needed: `tests/task29_browser_qa.mjs`
- Modify if needed: `docs/superpowers/specs/2026-08-03-customer-gps-address-confirmation-design.md` only for implementation-discovered factual corrections

- [ ] **Step 1: Run PHP lint for every changed PHP file**

```powershell
$files = @(
  'database/migrations/019_customer_gps_confirmation.php',
  'lib/customer_location_preview.php',
  'api/location_preview.php',
  'lib/profile_locations.php',
  'api/location.php',
  'lib/repositories/profile_repository.php',
  'lib/services/profile_service.php',
  'components/customer_footer.php',
  'customer_dashboard.php',
  'customer_profile.php',
  'customer_checkout.php'
)
foreach ($file in $files) { & 'D:\Xampp\php\php.exe' -l $file; if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE } }
```

Expected: `No syntax errors detected` for every file.

- [ ] **Step 2: Run the complete Node suite**

```powershell
node --test tests/*.test.js
```

Expected: zero failures. If PowerShell does not expand the pattern correctly, use `Get-ChildItem tests -Filter '*.test.js' | ForEach-Object { node --test $_.FullName }` and stop on the first non-zero exit.

- [ ] **Step 3: Run focused PHP/database regressions**

```powershell
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' scripts/migrate.php
& 'D:\Xampp\php\php.exe' tests/migration_integrity_test.php
& 'D:\Xampp\php\php.exe' tests/location_service_test.php
& 'D:\Xampp\php\php.exe' tests/customer_location_preview_test.php
& 'D:\Xampp\php\php.exe' tests/profile_locations_test.php
& 'D:\Xampp\php\php.exe' tests/checkout_order_service_test.php
```

Expected: every script prints its PASS line and exits 0.

- [ ] **Step 4: Smoke-test the public endpoint over HTTP**

With Apache/MySQL running and a test-safe Geoapify configuration, verify:

```powershell
curl.exe -i -X GET http://localhost/Savora/api/location_preview.php
curl.exe -i -X POST -H "Content-Type: application/json" --data "{\"latitude\":999,\"longitude\":100.5018}" http://localhost/Savora/api/location_preview.php
```

Expected: 405 for GET and 422 for invalid coordinates. Then send one valid coordinate pair and confirm the JSON contains only normalized address fields/coordinates—no provider key, URL, or raw payload.

- [ ] **Step 5: Run browser QA at desktop and 390 px**

Verify these scenarios manually or extend the existing Playwright scripts where credentials/environment permit:

1. Guest opens Home, GPS preview appears, closing does not alter **Deliver to**.
2. Guest confirms with details, reload preserves the local address/details.
3. Guest signs in, sync succeeds, local draft clears only after authoritative reload.
4. Denied/timeout/429/provider failure leaves manual entry available and old saved location unchanged.
5. Authenticated Customer confirms GPS; Home and Profile show the same data.
6. Manual Profile save clears both coordinate pairs and persists details.
7. Checkout pre-fills details into an untouched note.
8. Customer edits the note, then location/quote refresh does not overwrite it.
9. Focus enters/returns from the dialog, busy state is announced, and no horizontal scroll appears at 390 px.

- [ ] **Step 6: Inspect the final diff for scope and secrets**

```powershell
git status --short
git diff --check
git diff -- api/location_preview.php lib/customer_location_preview.php js/customer_location.js customer_profile.php customer_checkout.php
```

Confirm there are no API keys, raw provider payload logging, accidental Driver/Restaurant UI changes, `watchPosition`, unsafe HTML rendering, or unrelated-file edits.

- [ ] **Step 7: Create the final implementation commit if Git is available**

```powershell
git add database/migrations/019_customer_gps_confirmation.php lib/migrations.php lib/customer_location_preview.php api/location_preview.php lib/profile_locations.php api/location.php lib/repositories/profile_repository.php lib/services/profile_service.php js/api_client.js js/location_client.js js/customer_location_state.js js/customer_location.js js/customer_checkout_note.js components/customer_footer.php customer_dashboard.php customer_profile.php customer_checkout.php css/customer_style.css tests/migration_registry.test.js tests/migration_integrity_test.php tests/customer_location_preview_test.php tests/location_api_contract.test.js tests/profile_locations_test.php tests/api_client_public_post.test.js tests/location_client.test.js tests/customer_location_state.test.js tests/customer_markup.test.js tests/customer_location_confirmation.test.js tests/customer_checkout_note.test.js
git commit -m "feat: add customer GPS address confirmation"
```

Inspect `git status --short` before this command. Do not stage `lib/database.php` or unrelated untracked artifacts.

---

## Completion Criteria

- A GPS click produces only a readable preview; **Save address** is the sole confirmation action.
- Guests and authenticated Customers use the same review UI.
- Pending guest data survives failures and clears only after authenticated save plus reload.
- Address and `deliveryDetails` agree between Customer profile/location and default checkout address records.
- Manual saves remove stale coordinates without substituting `0,0`.
- Checkout prefill follows saved details until the Customer edits the per-order note.
- Cross-site, malformed, oversized, invalid, and rate-limited preview requests fail safely.
- Geoapify credentials/raw payloads never reach Customer HTML/JavaScript/JSON.
- PHP lint, Node tests, database integration tests, endpoint smoke checks, and responsive browser QA all pass.
