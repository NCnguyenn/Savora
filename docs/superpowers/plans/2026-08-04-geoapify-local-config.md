# Geoapify Local Configuration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make Savora read a user-supplied Geoapify API key from an ignored local PHP config file while retaining the existing environment-variable path.

**Architecture:** Add one key-resolution helper in `lib/location_service.php`. It checks `GEOAPIFY_API_KEY` first, then an optional `config/local.php` array, and returns an empty key when neither is usable. Track only a safe example file and ignore the real local file.

**Tech Stack:** PHP 8.2, existing standalone PHP tests, Apache/XAMPP, Git ignore rules.

## Global Constraints

- Never commit or print the real Geoapify key.
- Preserve the existing public preview response and generic provider failure message.
- Do not change coordinate validation, rate limiting, or reverse-geocode normalization.

---

### Task 1: Prove the local key contract with a failing test

**Files:**
- Modify: `tests/location_service_test.php`

**Interfaces:**
- Consumes: `savora_geoapify_api_key(?string $localPath = null)` from `lib/location_service.php`.
- Produces: coverage for environment precedence, local config fallback, and missing config.

- [ ] **Step 1: Add assertions for the desired key resolution behavior**

Use a temporary PHP config file path passed to the optional loader argument, then assert:

```php
putenv('GEOAPIFY_API_KEY=environment-key');
location_test_assert(savora_geoapify_api_key($tempConfig) === 'environment-key', 'environment key wins');
putenv('GEOAPIFY_API_KEY=');
location_test_assert(savora_geoapify_api_key($tempConfig) === 'local-key', 'local key is used as fallback');
location_test_assert(savora_geoapify_api_key($missingConfig) === '', 'missing key is empty');
```

- [ ] **Step 2: Run the focused test and confirm the expected failure**

Run:

```powershell
& 'D:\Xampp\php\php.exe' tests/location_service_test.php
```

Expected: FAIL because `savora_geoapify_api_key()` does not exist yet.

### Task 2: Add the ignored local config and minimal loader

**Files:**
- Create: `config/local.php.example`
- Create: `config/local.php` with a placeholder only for the user to edit locally
- Modify: `.gitignore`
- Modify: `lib/location_service.php`

**Interfaces:**
- Consumes: `GEOAPIFY_API_KEY` and optional `config/local.php`.
- Produces: `savora_geoapify_api_key(?string $localPath = null): string`.

- [ ] **Step 1: Add the local config ignore rule and safe example**

Add this rule to `.gitignore`:

```gitignore
/config/local.php
```

Create `config/local.php.example` with:

```php
<?php
return [
    'GEOAPIFY_API_KEY' => 'PASTE_YOUR_GEOAPIFY_API_KEY_HERE',
];
```

Create the ignored `config/local.php` with the same placeholder so the user can copy/paste the real key locally.

- [ ] **Step 2: Implement the loader**

Add this helper before `savora_reverse_geocode()`:

```php
function savora_geoapify_api_key(?string $localPath = null): string
{
    $environmentKey = trim((string) getenv('GEOAPIFY_API_KEY'));
    if ($environmentKey !== '') return $environmentKey;

    $localPath ??= __DIR__ . '/../config/local.php';
    if (!is_file($localPath)) return '';
    $local = require $localPath;
    if (!is_array($local)) return '';
    $key = trim((string) ($local['GEOAPIFY_API_KEY'] ?? ''));
    return preg_match('/^PASTE_YOUR_|^YOUR_API_KEY|^<.*>$/', $key) ? '' : $key;
}
```

Change `savora_reverse_geocode()` to call `savora_geoapify_api_key()` instead of reading `getenv()` directly.

- [ ] **Step 3: Run the focused test and confirm it passes**

Run:

```powershell
& 'D:\Xampp\php\php.exe' tests/location_service_test.php
```

Expected: `location_service_test: ok`.

### Task 3: Verify Apache/XAMPP integration and repository safety

**Files:**
- Test: `config/local.php`
- Test: `api/location_preview.php`

- [ ] **Step 1: Fill the local key only when the user is ready**

Replace the placeholder in `D:\Xampp\htdocs\Savora\config\local.php`. Do not paste the key into chat or commit it.

- [ ] **Step 2: Verify the actual XAMPP endpoint**

Run a POST to `http://localhost:8085/api/location_preview.php` with coordinates and confirm HTTP 200 with a normalized `data.location.address`.

- [ ] **Step 3: Verify the key is ignored and no secret is staged**

Run:

```powershell
git check-ignore -v config/local.php
git status --short
```

Expected: `config/local.php` is ignored and only the example/config loader changes are visible.

- [ ] **Step 4: Run regression checks**

Run:

```powershell
node --test tests/*.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; & 'D:\Xampp\php\php.exe' tests/location_service_test.php
```

Expected: all Node tests pass and the location service test reports `ok`.
