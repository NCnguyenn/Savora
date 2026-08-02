# Three-Role GPS Address Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Geoapify-backed, one-shot GPS-to-address saving for Customer, Driver, and Restaurant users, with manual fallback and read-only Admin visibility.

**Architecture:** A focused PHP service owns coordinate validation, Geoapify transport, normalization, and safe errors. The existing authenticated `api/platform_state.php` endpoint exposes role-owned location snapshots plus idempotent GPS/manual save commands. A shared browser module wraps one-shot geolocation and bridge calls; role controllers bind it to the existing forms and synchronize local UI caches with server data.

**Tech Stack:** PHP 8+, MySQLi, vanilla JavaScript, Node test runner, browser Geolocation API, Geoapify Reverse Geocoding API.

## Global Constraints

- GPS is available only to Customer, Driver, and Restaurant; Admin is read-only.
- Read `GEOAPIFY_API_KEY` only on the server. Never render, log, return, or commit it.
- Use `getCurrentPosition` only after an explicit button press; never use `watchPosition`.
- Preserve manual entry and the previous address on every GPS/provider failure.
- A manual save uses `location_method = manual` and clears both coordinates.
- A GPS save requires both coordinates and uses `location_method = gps`.
- Display “Powered by Geoapify” beside GPS-assisted controls.
- Automated tests never make an external Geoapify request.

---

## File Structure

- `lib/location_service.php`: provider boundary and normalization.
- `lib/profile_locations.php`: fixed role mappings and persistence.
- `api/platform_state.php`: snapshots and location commands.
- `js/location_client.js`: shared, testable browser adapter.
- `js/customer_location.js`: Home/Profile/Checkout behavior.
- Existing Driver/Restaurant controllers: role-specific binding and rendering.
- Existing Admin repository/pages: read-only display.

### Task 1: Geoapify Service Boundary

**Files:**
- Create: `lib/location_service.php`
- Create: `tests/location_service_test.php`

**Interfaces:**
- Produces: `savora_validate_coordinates(mixed, mixed): array{latitude: float, longitude: float}`
- Produces: `savora_normalize_geoapify(array): array`
- Produces: `savora_reverse_geocode(float, float, ?callable): array`
- Transport: `callable(string $url, array $options): array{status: int, body: string}`

- [ ] **Step 1: Write the failing provider test**

Create `tests/location_service_test.php`. It must check valid and invalid coordinate boundaries, Vietnamese structured fields, empty-result rejection, missing-key rejection, and fake-transport success without a network request.

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/location_service.php';
function check(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}
$pair = savora_validate_coordinates('13.7563', '100.5018');
check($pair === ['latitude' => 13.7563, 'longitude' => 100.5018], 'coordinates normalize');
foreach ([[91, 0], [0, 181], ['NaN', 0], [null, 0]] as $invalid) {
    try {
        savora_validate_coordinates($invalid[0], $invalid[1]);
        throw new RuntimeException('invalid coordinates accepted');
    } catch (InvalidArgumentException) {
    }
}
$result = savora_normalize_geoapify(['features' => [['properties' => [
    'formatted' => '12 Đường Lê Lợi, Quận 1, Hồ Chí Minh, Việt Nam',
    'housenumber' => '12', 'street' => 'Đường Lê Lợi', 'suburb' => 'Phường Bến Nghé',
    'city' => 'Hồ Chí Minh', 'state' => 'Hồ Chí Minh', 'postcode' => '700000',
    'country' => 'Việt Nam'
]]]]);
check($result['addressLine1'] === '12 Đường Lê Lợi', 'street normalized');
check($result['city'] === 'Hồ Chí Minh', 'city normalized');
putenv('GEOAPIFY_API_KEY=test-secret-key');
$transport = static function (string $url, array $options): array {
    check(str_contains($url, 'lang=vi'), 'Vietnamese requested');
    check(($options['timeout'] ?? 0) <= 8, 'timeout bounded');
    return ['status' => 200, 'body' => json_encode(['features' => [['properties' => [
        'formatted' => 'Bangkok, Thailand', 'city' => 'Bangkok', 'country' => 'Thailand'
    ]]]], JSON_THROW_ON_ERROR)];
};
check(savora_reverse_geocode(13.7563, 100.5018, $transport)['address'] === 'Bangkok, Thailand', 'transport normalized');
echo "location_service_test: ok\n";
```

- [ ] **Step 2: Verify RED**

Run: `php tests/location_service_test.php`

Expected: FAIL because `lib/location_service.php` does not exist.

- [ ] **Step 3: Implement the minimal provider service**

Validate finite values and ranges `-90..90` / `-180..180`. Build the Geoapify URL with `lang=vi`, `format=geojson`, `limit=1`, and the environment key. The default cURL transport uses HTTPS verification, 3-second connection timeout, 8-second total timeout, and a 256 KiB response limit. Parse with `JSON_THROW_ON_ERROR` and return only normalized fields:

```php
[
    'address' => $formatted,
    'addressLine1' => $line1,
    'addressLine2' => $line2,
    'city' => $city,
    'state' => $state,
    'postalCode' => $postcode,
    'country' => $country,
]
```

Throw only sanitized messages: “Automatic address lookup is not configured,” “temporarily unavailable,” or “No readable address was found.”

- [ ] **Step 4: Verify GREEN**

Run: `php tests/location_service_test.php`

Expected: `location_service_test: ok`.

- [ ] **Step 5: Commit**

```bash
git add lib/location_service.php tests/location_service_test.php
git commit -m "feat: add Geoapify location service"
```

### Task 2: Server-Authoritative Persistence

**Files:**
- Modify: `lib/platform_schema.php`
- Create: `lib/profile_locations.php`
- Modify: `api/platform_state.php`
- Modify: `tests/admin_schema_test.php`
- Create: `tests/location_api_contract.test.js`

**Interfaces:**
- Produces: `savora_profile_location(mysqli, string $role, int $userId): array`
- Produces: `savora_save_gps_location(mysqli, string, int, array, float, float): array`
- Produces: `savora_save_manual_location(mysqli, string, int, array): array`
- Commands: `save_gps_location` and `save_manual_location`.

- [ ] **Step 1: Write failing schema and contract tests**

Extend `tests/admin_schema_test.php` to require `latitude`, `longitude`, `location_method`, and `location_updated_at` on all three profile tables; Driver `address`; and Restaurant `address_line1`, `address_line2`, `state`, `postal_code`, and `country`.

Create `tests/location_api_contract.test.js`:

```js
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
test('platform exposes role-owned location commands', () => {
  const endpoint = read('api/platform_state.php');
  assert.match(endpoint, /save_gps_location/);
  assert.match(endpoint, /save_manual_location/);
  assert.match(endpoint, /savora_profile_location/);
  assert.match(endpoint, /savora_reverse_geocode/);
});
test('manual persistence clears stale coordinates', () => {
  const repository = read('lib/profile_locations.php');
  for (const role of ['customer', 'driver', 'restaurant']) assert.match(repository, new RegExp("'" + role + "'"));
  assert.match(repository, /latitude\s*=\s*NULL/);
  assert.match(repository, /longitude\s*=\s*NULL/);
  assert.doesNotMatch(repository, /\$_(?:GET|POST|REQUEST).*user/i);
});
```

- [ ] **Step 2: Verify RED**

Run: `node --test tests/location_api_contract.test.js`

Expected: FAIL because persistence and commands do not exist.

Run: `php tests/admin_schema_test.php`

Expected: FAIL on missing columns.

- [ ] **Step 3: Add idempotent schema columns**

Use `platform_add_column`:

```php
foreach (['customer_profiles', 'restaurants', 'driver_profiles'] as $table) {
    platform_add_column($conn, $table, 'latitude', 'DECIMAL(10,7) NULL');
    platform_add_column($conn, $table, 'longitude', 'DECIMAL(10,7) NULL');
    platform_add_column($conn, $table, 'location_method', "VARCHAR(10) NOT NULL DEFAULT 'manual'");
    platform_add_column($conn, $table, 'location_updated_at', 'DATETIME NULL');
}
platform_add_column($conn, 'driver_profiles', 'address', 'VARCHAR(500) NULL');
platform_add_column($conn, 'restaurants', 'address_line1', 'VARCHAR(150) NULL');
platform_add_column($conn, 'restaurants', 'address_line2', 'VARCHAR(150) NULL');
platform_add_column($conn, 'restaurants', 'state', 'VARCHAR(100) NULL');
platform_add_column($conn, 'restaurants', 'postal_code', 'VARCHAR(30) NULL');
platform_add_column($conn, 'restaurants', 'country', 'VARCHAR(100) NULL');
```

- [ ] **Step 4: Implement fixed role mappings**

In `lib/profile_locations.php` use `match ($role)` with fixed SQL:

- Customer: `customer_profiles.user_id`.
- Driver: `driver_profiles.user_id`.
- Restaurant: `restaurants.owner_user_id`.

GPS writes resolved fields, both coordinates, `gps`, and `NOW()`. Manual writes bounded address fields, `manual`, `NOW()`, and `NULL` coordinates. Never accept user ID or role from payload.

- [ ] **Step 5: Add snapshots and commands**

Require the two location libraries from `api/platform_state.php`. Include `location => savora_profile_location($conn, $role, $userId)` in GET for allowed roles. Add:

```php
} elseif ($command === 'save_gps_location' && $requiredRole !== null) {
    $coordinates = savora_validate_coordinates($payload['latitude'] ?? null, $payload['longitude'] ?? null);
    $resolved = savora_reverse_geocode($coordinates['latitude'], $coordinates['longitude']);
    $result = savora_save_gps_location($conn, $requiredRole, $userId, $resolved, $coordinates['latitude'], $coordinates['longitude']);
} elseif ($command === 'save_manual_location' && $requiredRole !== null) {
    $result = savora_save_manual_location($conn, $requiredRole, $userId, $payload);
```

Retain existing session, CSRF, idempotency, transaction, and rollback handling.

- [ ] **Step 6: Verify GREEN**

Run: `php tests/admin_schema_test.php`

Run: `node --test tests/location_api_contract.test.js`

Expected: all focused tests PASS.

- [ ] **Step 7: Commit**

```bash
git add lib/platform_schema.php lib/profile_locations.php api/platform_state.php tests/admin_schema_test.php tests/location_api_contract.test.js
git commit -m "feat: persist role-owned profile locations"
```

### Task 3: Shared Browser Client

**Files:**
- Create: `js/location_client.js`
- Create: `tests/location_client.test.js`
- Modify: `components/customer_footer.php`
- Modify: `components/driver_footer.php`
- Modify: `components/restaurant_footer.php`

**Interfaces:**
- Produces: `getPosition(geolocation): Promise<{latitude, longitude}>`
- Produces: `saveGps(bridge, coordinates): Promise<Location>`
- Produces: `saveManual(bridge, payload): Promise<Location>`
- Produces: `messageForGeolocationError(error): string`

- [ ] **Step 1: Write failing client tests**

```js
const test = require('node:test');
const assert = require('node:assert/strict');
const Client = require('../js/location_client.js');
test('gets one high-accuracy position', async () => {
  let calls = 0;
  const geo = { getCurrentPosition(ok, fail, options) {
    calls += 1;
    assert.deepEqual(options, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
    ok({ coords: { latitude: 13.7563, longitude: 100.5018 } });
  }};
  assert.deepEqual(await Client.getPosition(geo), { latitude: 13.7563, longitude: 100.5018 });
  assert.equal(calls, 1);
});
test('delegates GPS save to platform bridge', async () => {
  const bridge = { command: async (name, payload) => {
    assert.equal(name, 'save_gps_location');
    return { data: { address: 'Bangkok', locationMethod: 'gps', payload } };
  }};
  assert.equal((await Client.saveGps(bridge, { latitude: 13.7563, longitude: 100.5018 })).address, 'Bangkok');
});
test('maps denied and timeout errors distinctly', () => {
  assert.match(Client.messageForGeolocationError({ code: 1 }), /permission/i);
  assert.match(Client.messageForGeolocationError({ code: 3 }), /timed out/i);
});
```

- [ ] **Step 2: Verify RED**

Run: `node --test tests/location_client.test.js`

Expected: FAIL because the module does not exist.

- [ ] **Step 3: Implement the UMD-style client**

Export through `module.exports` and `window.SavoraLocationClient`. Validate coordinate pairs, reject unsupported geolocation, use exactly one callback request, and unwrap `response.data` from bridge commands.

- [ ] **Step 4: Load it before role controllers**

Add `<script defer src="js/location_client.js"></script>` to all three role footers before dependent controllers. Do not load it for Admin.

- [ ] **Step 5: Verify GREEN**

Run: `node --test tests/location_client.test.js tests/customer_markup.test.js tests/driver_markup.test.js tests/restaurant_markup.test.js`

Expected: all tests PASS.

- [ ] **Step 6: Commit**

```bash
git add js/location_client.js tests/location_client.test.js components/customer_footer.php components/driver_footer.php components/restaurant_footer.php
git commit -m "feat: add shared browser location client"
```

### Task 4: Customer Home, Profile, and Checkout

**Files:**
- Create: `js/customer_location.js`
- Modify: `js/customer_state.js`
- Modify: `customer_dashboard.php`
- Modify: `customer_profile.php`
- Modify: `customer_checkout.php`
- Modify: `components/customer_footer.php`
- Modify: `css/customer_style.css`
- Modify: `tests/customer_state.test.js`
- Modify: `tests/customer_markup.test.js`

**Interfaces:**
- Produces: `SavoraCustomerLocation.saveManualAddress(address): Promise<Location>`.
- Customer profile gains `latitude`, `longitude`, `locationMethod`, `locationUpdatedAt`.

- [ ] **Step 1: Write failing Customer tests**

Add state tests for complete GPS pairs and manual clearing. Add markup tests for Home `data-customer-location-trigger`, no sample `123 Tech Park, Block C`, accessible dialog, Skip/Close, manual Save, GPS button, live status, attribution, Profile/Checkout GPS buttons, and one `customer_location.js` include.

- [ ] **Step 2: Verify RED**

Run: `node --test tests/customer_state.test.js tests/customer_markup.test.js`

Expected: FAIL on new state and hooks.

- [ ] **Step 3: Extend Customer state**

Preserve coordinates only when both are finite and `locationMethod === 'gps'`. Otherwise store null coordinates and `manual` without changing existing profile/cart behavior.

- [ ] **Step 4: Build the non-blocking Home selector**

Replace the sample address element with a real button. Add an accessible dialog with manual field, “Use current location,” Save, and Skip/Close. Closing must restore trigger focus, not call geolocation, and not block discovery.

- [ ] **Step 5: Bind all Customer surfaces**

In `js/customer_location.js`:

- Hydrate from `savora:platform-state`.
- Render saved address on Home/Profile/Checkout.
- Bind GPS buttons through one busy-state helper.
- Save Home/Profile manual text through `saveManual`.
- Expose `saveManualAddress` for Checkout.
- Keep previous text and announce safe errors on failure.

- [ ] **Step 6: Synchronize Checkout**

Before placing an order, await `saveManualAddress(address)` only when the textarea differs from the saved address. A GPS-filled saved address must not be downgraded to manual by a duplicate save.

- [ ] **Step 7: Add responsive styles**

Use existing Customer tokens/focus rules. Stack actions on narrow screens. Do not use inline handlers or `innerHTML`.

- [ ] **Step 8: Verify GREEN**

Run: `node --test tests/customer_state.test.js tests/customer_markup.test.js tests/location_client.test.js`

Expected: all tests PASS.

- [ ] **Step 9: Commit**

```bash
git add js/customer_location.js js/customer_state.js customer_dashboard.php customer_profile.php customer_checkout.php components/customer_footer.php css/customer_style.css tests/customer_state.test.js tests/customer_markup.test.js
git commit -m "feat: add Customer GPS address selection"
```

### Task 5: Driver Resolved Addresses

**Files:**
- Modify: `js/driver_dashboard.js`
- Modify: `js/driver_profile.js`
- Modify: `js/driver_state.js`
- Modify: `driver_dashboard.php`
- Modify: `driver_profile.php`
- Modify: `tests/driver_state.test.js`
- Modify: `tests/driver_markup.test.js`

**Interfaces:**
- Consumes the shared location client.
- Driver location gains `updatedAt` while retaining `method`, `address`, and coordinates.

- [ ] **Step 1: Write failing Driver tests**

Prove resolved server address replaces `Current GPS location`, manual save clears coordinates, both controllers use the shared client, server snapshots hydrate state, attribution appears, and coordinate-only fallback success text is absent.

- [ ] **Step 2: Verify RED**

Run: `node --test tests/driver_state.test.js tests/driver_markup.test.js`

Expected: FAIL on old coordinate-only handlers.

- [ ] **Step 3: Replace Dashboard handlers**

Await one position and `saveGps`, persist the returned address/coordinates, and rerender. Manual dialog submission awaits `saveManual` before local persistence and close. Restore buttons in `finally`.

- [ ] **Step 4: Replace Profile handlers**

GPS success fills and saves `currentAddress` immediately. Profile submit sends manual location only if the editable address differs from the saved GPS address.

- [ ] **Step 5: Hydrate from server**

On `savora:platform-state`, merge `event.detail.location` into Driver state and rerender without requesting GPS.

- [ ] **Step 6: Verify GREEN**

Run: `node --test tests/driver_state.test.js tests/driver_markup.test.js tests/location_client.test.js`

Expected: all tests PASS.

- [ ] **Step 7: Commit**

```bash
git add js/driver_dashboard.js js/driver_profile.js js/driver_state.js driver_dashboard.php driver_profile.php tests/driver_state.test.js tests/driver_markup.test.js
git commit -m "feat: resolve and persist Driver GPS addresses"
```

### Task 6: Restaurant Structured GPS Address

**Files:**
- Modify: `js/restaurant_storefront.js`
- Modify: `js/restaurant_state.js`
- Modify: `restaurant_profile.php`
- Modify: `tests/restaurant_state.test.js`
- Modify: `tests/restaurant_markup.test.js`

**Interfaces:**
- Consumes normalized `addressLine1`, `addressLine2`, `city`, `state`, `postalCode`, `country`, and coordinates.

- [ ] **Step 1: Write failing Restaurant tests**

Prove GPS fills all structured fields, manual save clears coordinates, the shared client replaces direct provider logic, server snapshots hydrate fields, attribution is shown, and coordinate-only success text is absent.

- [ ] **Step 2: Verify RED**

Run: `node --test tests/restaurant_state.test.js tests/restaurant_markup.test.js`

Expected: FAIL because current code stores only coordinates.

- [ ] **Step 3: Apply structured GPS results**

Use the shared client, assign every normalized component to its named input, map server `gps` to the UI state’s existing `current` method, persist, and rerender the Leaflet preview.

- [ ] **Step 4: Persist manual structured fields**

Send all structured values through `save_manual_location`. After server success, persist `manual` with null coordinates and retain existing validation/preview behavior.

- [ ] **Step 5: Hydrate saved server data**

Populate fields and map from `savora:platform-state` on every later load without another location request.

- [ ] **Step 6: Verify GREEN**

Run: `node --test tests/restaurant_state.test.js tests/restaurant_markup.test.js tests/location_client.test.js`

Expected: all tests PASS.

- [ ] **Step 7: Commit**

```bash
git add js/restaurant_storefront.js js/restaurant_state.js restaurant_profile.php tests/restaurant_state.test.js tests/restaurant_markup.test.js
git commit -m "feat: resolve Restaurant GPS into structured address"
```

### Task 7: Admin Read-Only Visibility

**Files:**
- Modify: `lib/admin_repository.php`
- Modify: `admin_customers.php`
- Modify: `admin_drivers.php`
- Modify: `admin_restaurants.php`
- Modify: `css/admin_style.css`
- Modify: `tests/admin_partners.test.js`
- Modify: `tests/admin_markup.test.js`
- Modify: `tests/admin_security_hardening.test.js`

**Interfaces:**
- Consumes server profile location columns only.

- [ ] **Step 1: Write failing Admin tests**

Require queries and pages to show escaped address, GPS/Manual source, conditional coordinates, and update time. Assert no Admin geolocation calls, GPS buttons, editable coordinates, or key exposure.

- [ ] **Step 2: Verify RED**

Run: `node --test tests/admin_partners.test.js tests/admin_markup.test.js tests/admin_security_hardening.test.js`

Expected: FAIL on missing location metadata.

- [ ] **Step 3: Extend fixed Admin queries**

Select `address`, `latitude`, `longitude`, `location_method`, and `location_updated_at` for Customer, Driver, and Restaurant active profiles while preserving prepared filters and authorization.

- [ ] **Step 4: Render read-only summaries**

Use existing table/detail patterns and `admin_escape`:

```php
<strong><?= admin_escape($row['address'] ?: 'Not provided') ?></strong>
<small class="admin-cell-note">
  <?= admin_escape(($row['location_method'] ?? 'manual') === 'gps' ? 'GPS' : 'Manual') ?>
  <?php if (($row['location_method'] ?? '') === 'gps' && $row['latitude'] !== null && $row['longitude'] !== null): ?>
    · <?= admin_escape($row['latitude']) ?>, <?= admin_escape($row['longitude']) ?>
  <?php endif; ?>
</small>
```

Add escaped update time and retain responsive tables.

- [ ] **Step 5: Verify GREEN**

Run: `node --test tests/admin_partners.test.js tests/admin_markup.test.js tests/admin_security_hardening.test.js`

Expected: all tests PASS.

- [ ] **Step 6: Commit**

```bash
git add lib/admin_repository.php admin_customers.php admin_drivers.php admin_restaurants.php css/admin_style.css tests/admin_partners.test.js tests/admin_markup.test.js tests/admin_security_hardening.test.js
git commit -m "feat: show saved role locations to Admin"
```

### Task 8: Configuration and Final Verification

**Files:**
- Create: `docs/GEOAPIFY_CONFIGURATION.md`
- Create: `tests/location_browser_qa.mjs`

- [ ] **Step 1: Document configuration**

Explain how Apache/XAMPP and deployment environments expose `GEOAPIFY_API_KEY` to PHP, restart requirements, key restriction/rotation, required attribution, and manual fallback when configuration is absent. Never include a real key.

- [ ] **Step 2: Add browser QA with stubs**

Stub `getCurrentPosition` and bridge responses. Verify Customer skip/GPS/synchronization, Driver resolved address, Restaurant structured fields/map, denied/provider failures preserving text, buttons re-enabled, persistence after reload, and zero geolocation calls on page load.

- [ ] **Step 3: Run lint and focused tests**

```powershell
php -l lib/location_service.php
php -l lib/profile_locations.php
php -l api/platform_state.php
php -l lib/platform_schema.php
node --check js/location_client.js
node --check js/customer_location.js
node --check js/driver_dashboard.js
node --check js/driver_profile.js
node --check js/restaurant_storefront.js
php tests/location_service_test.php
node --test tests/location_client.test.js tests/location_api_contract.test.js
```

Expected: all exit 0 and tests PASS.

- [ ] **Step 4: Run affected suites**

```powershell
node --test tests/customer_state.test.js tests/customer_markup.test.js
node --test tests/driver_state.test.js tests/driver_markup.test.js
node --test tests/restaurant_state.test.js tests/restaurant_markup.test.js
node --test tests/admin_partners.test.js tests/admin_markup.test.js tests/admin_security_hardening.test.js
php tests/admin_schema_test.php
```

Expected: all PASS with no external requests.

- [ ] **Step 5: Run browser QA**

With XAMPP Apache serving `http://localhost/Savora`, launch a dedicated Chrome CDP profile and run the QA script:

```powershell
$chrome = "$env:ProgramFiles\Google\Chrome\Application\chrome.exe"
Start-Process $chrome -ArgumentList '--headless=new','--remote-debugging-port=9227','--user-data-dir=C:\tmp\savora-location-qa' -WindowStyle Hidden
$env:SAVORA_BASE_URL = 'http://localhost/Savora'
$env:SAVORA_CDP_PORT = '9227'
node tests/location_browser_qa.mjs
```

Expected: all role, failure, persistence, and no-auto-prompt scenarios PASS using stubs.

- [ ] **Step 6: Inspect the final diff**

Run: `git diff --check`

Expected: no whitespace errors.

Run: `git status --short`

Expected: only intended GPS files plus pre-existing unrelated user files.

- [ ] **Step 7: Commit**

```bash
git add docs/GEOAPIFY_CONFIGURATION.md tests/location_browser_qa.mjs
git commit -m "test: verify three-role GPS address flow"
```

## Completion Criteria

- Customer Home/Profile/Checkout share one saved server address.
- Driver and Restaurant GPS actions resolve and persist readable addresses.
- Saved values load automatically until their owner changes them.
- Manual saves remain available and clear stale coordinates.
- Admin sees read-only location source, coordinates, and update time.
- No page requests GPS on load or tracks continuously.
- Geoapify credentials remain server-only.
- Focused browser QA, lint, syntax checks, and all affected tests pass.
