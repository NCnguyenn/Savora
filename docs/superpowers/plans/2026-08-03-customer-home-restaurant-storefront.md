# Customer Home and Restaurant Storefront Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the undifferentiated Customer Home catalog with a wide brand-led overview and add a dedicated, data-backed storefront page for every active restaurant.

**Architecture:** Extend the existing MySQL catalog contract with stable restaurant public IDs, brand fields, and an explicit menu item type. Keep `api/catalog.php` as the Home catalog boundary and add a read-only `api/restaurant_storefront.php` endpoint that composes one active restaurant, its available items, weekly hours, and applicable promotions. Render Home and the new storefront with small CommonJS/browser-compatible JavaScript modules, page-specific CSS, and existing Customer state/favorites helpers.

**Tech Stack:** PHP 8+, MySQLi/MySQL, vanilla JavaScript with Node's built-in test runner, HTML5, CSS Grid/Flexbox, local SVG assets, existing Savora API/state helpers.

## Global Constraints

- All customer-facing copy, restaurant content, menu content, slogans, addresses, categories, and promotion text must be English-only.
- Home is an overview: show all six restaurant brands, one representative food item per restaurant, and one representative drink per restaurant by default; never render all 48 products as one default grid.
- Customer restaurant URLs use `customer_restaurant.php?restaurant={restaurantPublicId}` and never expose internal numeric restaurant IDs.
- Storefronts expose only active restaurants and available items.
- Food/Drinks behavior uses persisted `item_type`; do not infer type from category names.
- Brand logos and menu imagery are local assets only; no remote image host or frontend dependency may be added.
- Keep unrelated working-tree changes, especially `lib/database.php`, untouched.
- Follow TDD: write each focused test first, confirm the expected failure, implement the minimum behavior, rerun, then commit only that task's files.

---

## File Structure

**Create**

- `database/migrations/018_customer_storefront.php` — idempotent schema additions and restaurant public-ID backfill.
- `scripts/generate_brand_logos.php` — deterministic generator for six brand SVGs plus a safe fallback.
- `assets/images/brands/*.svg` — generated local restaurant logos.
- `api/restaurant_storefront.php` — public read-only storefront endpoint.
- `js/customer_home.js` — Home filtering, curation, and rendering.
- `js/customer_restaurant.js` — storefront loading, menu filtering, offers, hours, and cards.
- `css/customer_home.css` — Home-only wide discovery layout.
- `css/customer_restaurant.css` — storefront-only responsive presentation.
- `customer_restaurant.php` — semantic storefront shell.
- `tests/customer_storefront_contract.test.js` — migration, asset, route, and markup contracts.
- `tests/customer_home_selection.test.js` — pure Home selection/filter tests.
- `tests/customer_restaurant_client.test.js` — pure storefront filtering/promotion-format tests.
- `tests/customer_storefront_service_test.php` — executable repository/service boundary test.

**Modify**

- `lib/migrations.php` — register migration 018 after 017.
- `database/seeds/catalog_demo_data.json` — add public IDs, slogans, logo paths, hours, and normalize `beverage` to `drink`.
- `lib/catalog_demo_seed.php` — persist storefront fields, item type, and weekly hours.
- `lib/repositories/catalog_repository.php` — map new fields and add scoped storefront/promotion queries.
- `lib/services/catalog_service.php` — validate and compose the storefront response.
- `api/catalog.php` — continue returning the enriched Home catalog; no mutation behavior change.
- `js/customer_catalog.js` — fix category labels and map brand/type/storefront fields safely.
- `components/customer_header.php` — allow the public storefront route, title it, and load page CSS safely.
- `components/customer_footer.php` — load page JS safely and remove the obsolete menu modal.
- `customer_dashboard.php` — replace the aggregate grid with overview sections and delegate discovery rendering.
- `css/customer_style.css` — increase the shared desktop content width and align the header/hero grid.
- `customer_favorites.php` — link restaurant favorites to storefront URLs.
- `product_detail.php` — link the owning restaurant back to its storefront.
- `js/customer_ui.js` — remove obsolete restaurant-menu modal functions.
- `tests/catalog_demo_seed_test.php` — assert complete brand/type/hour demo data.
- `tests/rich_catalog_mapping.test.js` — assert correct category labels, public IDs, logos, and item type.
- `tests/rich_catalog_integration_test.php` — verify all 48 seeded records expose storefront metadata.
- `tests/customer_markup.test.js` — include the new public Customer route and title.
- `tests/customer_guest_browser_qa.mjs` — exercise Home-to-storefront navigation and horizontal-overflow breakpoints.

---

### Task 1: Add the Storefront Schema Contract

**Files:**

- Create: `database/migrations/018_customer_storefront.php`
- Create: `tests/customer_storefront_contract.test.js`
- Modify: `lib/migrations.php:5-23`

**Interfaces:**

- Produces: `restaurants.public_id VARCHAR(60) NOT NULL UNIQUE`, `restaurants.slogan VARCHAR(180) NULL`, `restaurants.logo_path VARCHAR(255) NULL`, and `menu_items.item_type VARCHAR(20) NOT NULL DEFAULT 'food'`.
- Produces: migration registry key `018_customer_storefront` after `017_rich_catalog`.

- [ ] **Step 1: Write the failing migration contract test**

Create `tests/customer_storefront_contract.test.js` with this first test:

```js
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('customer storefront migration is registered after the rich catalog migration', () => {
  const registry = read('lib/migrations.php');
  const migrationPath = path.join(root, 'database/migrations/018_customer_storefront.php');
  assert.ok(fs.existsSync(migrationPath));
  const migration = fs.readFileSync(migrationPath, 'utf8');
  assert.ok(registry.indexOf('018_customer_storefront') > registry.indexOf('017_rich_catalog'));
  for (const column of ['public_id', 'slogan', 'logo_path', 'item_type']) {
    assert.match(migration, new RegExp(`['\"]${column}['\"]`));
  }
  assert.match(migration, /uq_restaurants_public_id/);
  assert.match(migration, /CONCAT\('restaurant-',id\)/);
  assert.match(migration, /item_type.*DEFAULT 'food'/s);
});
```

- [ ] **Step 2: Run the test and confirm it fails**

Run:

```powershell
node --test tests/customer_storefront_contract.test.js
```

Expected: FAIL because `database/migrations/018_customer_storefront.php` does not exist.

- [ ] **Step 3: Implement and register migration 018**

Create `database/migrations/018_customer_storefront.php` using the same information-schema checks as migration 017. The migration body must perform these exact operations in order:

```php
<?php
declare(strict_types=1);

return static function (mysqli $conn): void {
    $database = (string) ($conn->query('SELECT DATABASE() AS name')?->fetch_assoc()['name'] ?? '');
    if ($database === '') throw new RuntimeException('A database must be selected before applying the storefront migration.');

    $ensureColumn = static function (string $table, string $column, string $definition) use ($conn, $database): void {
        $lookup = $conn->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
        $lookup->bind_param('sss', $database, $table, $column);
        $lookup->execute();
        $exists = $lookup->get_result()->fetch_assoc() !== null;
        $lookup->close();
        if (!$exists && !$conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}")) {
            throw new RuntimeException("Unable to add storefront column {$table}.{$column}: {$conn->error}");
        }
    };

    $ensureColumn('restaurants', 'public_id', 'VARCHAR(60) NULL');
    $ensureColumn('restaurants', 'slogan', 'VARCHAR(180) NULL');
    $ensureColumn('restaurants', 'logo_path', 'VARCHAR(255) NULL');
    $ensureColumn('menu_items', 'item_type', "VARCHAR(20) NOT NULL DEFAULT 'food'");

    if (!$conn->query("UPDATE restaurants SET public_id=CONCAT('restaurant-',id) WHERE public_id IS NULL OR public_id=''")) {
        throw new RuntimeException('Unable to backfill restaurant public IDs: ' . $conn->error);
    }
    if (!$conn->query('ALTER TABLE restaurants MODIFY public_id VARCHAR(60) NOT NULL')) {
        throw new RuntimeException('Unable to enforce restaurant public IDs: ' . $conn->error);
    }

    $index = $conn->prepare('SELECT COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=\'restaurants\' AND INDEX_NAME=\'uq_restaurants_public_id\' ORDER BY SEQ_IN_INDEX');
    $index->bind_param('s', $database);
    $index->execute();
    $rows = $index->get_result()->fetch_all(MYSQLI_ASSOC);
    $index->close();
    if ($rows === [] && !$conn->query('ALTER TABLE restaurants ADD UNIQUE KEY uq_restaurants_public_id (public_id)')) {
        throw new RuntimeException('Unable to add restaurant public ID index: ' . $conn->error);
    }
    if ($rows !== [] && (count($rows) !== 1 || $rows[0]['COLUMN_NAME'] !== 'public_id' || (int) $rows[0]['NON_UNIQUE'] !== 0)) {
        throw new RuntimeException('Existing restaurant public ID index does not match the migration definition.');
    }
};
```

Register it in `lib/migrations.php`:

```php
'017_rich_catalog' => __DIR__ . '/../database/migrations/017_rich_catalog.php',
'018_customer_storefront' => __DIR__ . '/../database/migrations/018_customer_storefront.php',
```

- [ ] **Step 4: Run focused tests and lint**

Run:

```powershell
php -l database/migrations/018_customer_storefront.php
node --test tests/customer_storefront_contract.test.js tests/migration_registry.test.js tests/rich_catalog_contract.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php scripts/migrate.php
```

Expected: PHP reports no syntax errors; all Node tests PASS; the test database applies migration 018 or reports no pending migrations.

- [ ] **Step 5: Commit the schema contract**

```powershell
git add database/migrations/018_customer_storefront.php lib/migrations.php tests/customer_storefront_contract.test.js
git commit -m "feat: add customer storefront schema"
```

---

### Task 2: Seed Complete Brand Data, Item Types, Hours, and SVG Logos

**Files:**

- Create: `scripts/generate_brand_logos.php`
- Create: `assets/images/brands/restaurant-placeholder.svg`
- Create: `assets/images/brands/lotus-kitchen.svg`
- Create: `assets/images/brands/saigon-ember-grill.svg`
- Create: `assets/images/brands/hoi-an-garden.svg`
- Create: `assets/images/brands/mekong-bowl-tea.svg`
- Create: `assets/images/brands/tokyo-kumo.svg`
- Create: `assets/images/brands/roma-verde.svg`
- Modify: `database/seeds/catalog_demo_data.json`
- Modify: `lib/catalog_demo_seed.php:15-116`
- Modify: `tests/catalog_demo_seed_test.php`

**Interfaces:**

- Consumes: migration 018 columns.
- Produces: six deterministic restaurant public IDs, slogans, safe logo paths, seven weekly-hour rows per restaurant, and exactly six `food` plus two `drink` items per restaurant.
- Produces: local logo paths matching `assets/images/brands/[a-z0-9-]+.svg`.

- [ ] **Step 1: Extend the seed test before changing data**

In `tests/catalog_demo_seed_test.php`, replace the type assertion and add restaurant brand assertions:

```php
$restaurantIds = [];
foreach ($data as $restaurant) {
    foreach (['public_id', 'slogan', 'logo_path', 'opens_at', 'closes_at'] as $field) {
        if (trim((string) ($restaurant[$field] ?? '')) === '') {
            fwrite(STDERR, "Demo restaurant field {$field} is required.\n");
            exit(1);
        }
    }
    if (!preg_match('/^demo-[a-z0-9-]+$/', (string) $restaurant['public_id'])) {
        fwrite(STDERR, "Restaurant public IDs must be stable demo IDs.\n");
        exit(1);
    }
    if (isset($restaurantIds[$restaurant['public_id']])) {
        fwrite(STDERR, "Restaurant public IDs must be unique.\n");
        exit(1);
    }
    $restaurantIds[$restaurant['public_id']] = true;
    if (!preg_match('#^assets/images/brands/[a-z0-9-]+\.svg$#', (string) $restaurant['logo_path'])) {
        fwrite(STDERR, "Restaurant logo paths must be local SVG assets.\n");
        exit(1);
    }
    if (!is_file($root . '/' . $restaurant['logo_path'])) {
        fwrite(STDERR, "Restaurant logo asset is missing.\n");
        exit(1);
    }
    $types = array_count_values(array_column($restaurant['items'], 'type'));
    if (($types['food'] ?? 0) !== 6 || ($types['drink'] ?? 0) !== 2) {
        fwrite(STDERR, "Every demo restaurant must contain six foods and two drinks.\n");
        exit(1);
    }
}
```

- [ ] **Step 2: Run the seed test and confirm the expected failure**

```powershell
php tests/catalog_demo_seed_test.php
```

Expected: FAIL because the brand fields and SVG assets do not exist and item types still use `beverage`.

- [ ] **Step 3: Add exact brand metadata to the JSON seed**

Add these fields to each restaurant object and change every item type `beverage` to `drink`:

```json
[
  {"demo_key":"lotus-kitchen","public_id":"demo-lotus-kitchen","slogan":"Vietnamese comfort, thoughtfully served.","logo_path":"assets/images/brands/lotus-kitchen.svg","opens_at":"09:00:00","closes_at":"22:00:00"},
  {"demo_key":"saigon-ember-grill","public_id":"demo-saigon-ember-grill","slogan":"Fire, fragrance, and Saigon spirit.","logo_path":"assets/images/brands/saigon-ember-grill.svg","opens_at":"10:00:00","closes_at":"23:00:00"},
  {"demo_key":"hoi-an-garden","public_id":"demo-hoi-an-garden","slogan":"Regional flavors in full bloom.","logo_path":"assets/images/brands/hoi-an-garden.svg","opens_at":"09:30:00","closes_at":"21:30:00"},
  {"demo_key":"mekong-bowl-tea","public_id":"demo-mekong-bowl-tea","slogan":"Bright bowls, slow sips.","logo_path":"assets/images/brands/mekong-bowl-tea.svg","opens_at":"08:00:00","closes_at":"21:00:00"},
  {"demo_key":"tokyo-kumo","public_id":"demo-tokyo-kumo","slogan":"Tokyo craft, light as a cloud.","logo_path":"assets/images/brands/tokyo-kumo.svg","opens_at":"11:00:00","closes_at":"22:30:00"},
  {"demo_key":"roma-verde","public_id":"demo-roma-verde","slogan":"Italian warmth, fresh by nature.","logo_path":"assets/images/brands/roma-verde.svg","opens_at":"11:00:00","closes_at":"22:00:00"}
]
```

The block above defines only the added fields; merge them into the matching existing six restaurant objects without removing existing descriptions, addresses, coordinates, images, or item arrays.

- [ ] **Step 4: Create and run the deterministic SVG generator**

Create `scripts/generate_brand_logos.php` with a seven-entry map. Each entry must include a distinct mark, accent color, and accessible title:

```php
<?php
declare(strict_types=1);

$output = dirname(__DIR__) . '/assets/images/brands';
if (!is_dir($output) && !mkdir($output, 0775, true) && !is_dir($output)) {
    throw new RuntimeException('Unable to create brand asset directory.');
}
$brands = [
    'restaurant-placeholder' => ['Restaurant', '#0a4a38', '<circle cx="48" cy="48" r="22"/><path d="M35 50h26M39 41h18M42 59h12"/>'],
    'lotus-kitchen' => ['Lotus Kitchen', '#c9573f', '<path d="M48 65C28 54 27 35 48 22c21 13 20 32 0 43Z"/><path d="M48 65C36 48 39 34 48 22M48 65c12-17 9-31 0-43"/>'],
    'saigon-ember-grill' => ['Saigon Ember Grill', '#e05d3f', '<path d="M50 18c7 17-10 18-2 31 4-8 12-10 14-18 12 17 7 38-14 46-22-8-24-31-8-43 1 10 6 12 10 15-2-10 5-17 0-31Z"/>'],
    'hoi-an-garden' => ['Hoi An Garden', '#d79728', '<path d="M32 34h32l-4 35H36l-4-35Zm5-9h22l5 9H32l5-9Zm11-8v8M38 45h20M39 57h18"/>'],
    'mekong-bowl-tea' => ['Mekong Bowl and Tea', '#178a78', '<path d="M20 39c9-8 19-8 28 0s19 8 28 0M20 52c9-8 19-8 28 0s19 8 28 0M27 66h42"/>'],
    'tokyo-kumo' => ['Tokyo Kumo', '#416b91', '<path d="M27 62h42a13 13 0 0 0 0-26 20 20 0 0 0-37-5A16 16 0 0 0 27 62Z"/><circle cx="65" cy="25" r="7"/>'],
    'roma-verde' => ['Roma Verde', '#5e8f4d', '<path d="M25 68c29 1 43-17 47-45-29 4-47 18-47 45Zm5-5c12-13 23-22 37-33M44 50l-2-14M54 41l11 2"/>'],
];

foreach ($brands as $slug => [$title, $accent, $mark]) {
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 96" role="img" aria-labelledby="title">
  <title id="title">{$safeTitle} logo</title>
  <rect width="320" height="96" rx="24" fill="#fffdf7"/>
  <g fill="none" stroke="{$accent}" stroke-width="5" stroke-linecap="round" stroke-linejoin="round">{$mark}</g>
  <text x="92" y="56" fill="#17342b" font-family="Georgia, serif" font-size="25" font-weight="700">{$safeTitle}</text>
</svg>
SVG;
    if (file_put_contents($output . '/' . $slug . '.svg', $svg . PHP_EOL) === false) {
        throw new RuntimeException("Unable to write {$slug}.svg");
    }
}
```

Run:

```powershell
php scripts/generate_brand_logos.php
```

Expected: seven SVG files exist under `assets/images/brands/`.

- [ ] **Step 5: Persist the new seed fields and seven-day hours**

Update `lib/catalog_demo_seed.php` prepared statements so restaurant insert/update includes `public_id,slogan,logo_path`, menu upsert includes `item_type`, and hours use a seven-row upsert:

```php
$hours = $conn->prepare('INSERT INTO restaurant_weekly_hours(restaurant_id,weekday,opens_at,closes_at,is_closed) VALUES(?,?,?,?,0) ON DUPLICATE KEY UPDATE opens_at=VALUES(opens_at),closes_at=VALUES(closes_at),is_closed=0,version=version+1');

$publicId = (string) $restaurant['public_id'];
$slogan = (string) $restaurant['slogan'];
$logoPath = (string) $restaurant['logo_path'];
$opensAt = (string) $restaurant['opens_at'];
$closesAt = (string) $restaurant['closes_at'];

for ($weekday = 0; $weekday < 7; $weekday++) {
    $hours->bind_param('iiss', $restaurantId, $weekday, $opensAt, $closesAt);
    $hours->execute();
}

$itemType = (string) ($item['type'] ?? 'food');
if (!in_array($itemType, ['food', 'drink'], true)) {
    throw new RuntimeException("Invalid item type for {$publicId}.");
}
```

Use these exact statements and bind orders; keep the existing demo-key lookup and unrelated-record preservation behavior:

```php
$restaurantInsert = $conn->prepare("INSERT INTO restaurants (owner_user_id,demo_key,public_id,name,cuisine,description,slogan,hero_image,logo_path,address,city,phone,rating,cancellation_rate,latitude,longitude,status,accepting_orders) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active',1)");
$restaurantUpdate = $conn->prepare("UPDATE restaurants SET demo_key=?,public_id=?,name=?,cuisine=?,description=?,slogan=?,hero_image=?,logo_path=?,address=?,city=?,phone=?,rating=?,cancellation_rate=?,latitude=?,longitude=?,status='active',accepting_orders=1,version=version+1 WHERE id=?");
$menu = $conn->prepare("INSERT INTO menu_items (public_id,restaurant_id,name,price,description,image_path,category,item_type,prep_time_minutes,calories,dietary_tags,allergens,ingredients,sort_order,is_available) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE restaurant_id=VALUES(restaurant_id),name=VALUES(name),price=VALUES(price),description=VALUES(description),image_path=VALUES(image_path),category=VALUES(category),item_type=VALUES(item_type),prep_time_minutes=VALUES(prep_time_minutes),calories=VALUES(calories),dietary_tags=VALUES(dietary_tags),allergens=VALUES(allergens),ingredients=VALUES(ingredients),sort_order=VALUES(sort_order),is_available=1,version=version+1");

$restaurantInsert->bind_param('isssssssssssdddd', $ownerId, $demoKey, $publicId, $name, $cuisine, $description, $slogan, $heroImage, $logoPath, $address, $city, $phone, $rating, $cancellationRate, $latitude, $longitude);
$restaurantUpdate->bind_param('sssssssssssddddi', $demoKey, $publicId, $name, $cuisine, $description, $slogan, $heroImage, $logoPath, $address, $city, $phone, $rating, $cancellationRate, $latitude, $longitude, $restaurantId);
$menu->bind_param('sisdssssiisssi', $menuPublicId, $restaurantId, $itemName, $price, $itemDescription, $imagePath, $category, $itemType, $prepTime, $calories, $dietaryTags, $allergens, $ingredients, $sortOrder);
```

Rename the menu item's existing local `$publicId` variable to `$menuPublicId` so the restaurant public ID cannot be overwritten inside the item loop. Close `$hours` with the other prepared statements before commit.

- [ ] **Step 6: Run seed, migration, and asset tests**

```powershell
php -l lib/catalog_demo_seed.php
php -l scripts/generate_brand_logos.php
php tests/catalog_demo_seed_test.php
$env:SAVORA_ENV='development'; $env:SAVORA_DB_NAME='savora_db'; $env:SAVORA_DB_PORT='3307'; php scripts/migrate.php
$env:SAVORA_SEED_DEMO='1'; php scripts/seed.php
```

Expected: lint passes; seed contract passes; migration reports `Applied migration: 018_customer_storefront` on first run or `No migrations to apply.` on a repeated run; seed exits successfully.

- [ ] **Step 7: Commit brand data and assets**

```powershell
git add database/seeds/catalog_demo_data.json lib/catalog_demo_seed.php scripts/generate_brand_logos.php assets/images/brands tests/catalog_demo_seed_test.php
git commit -m "feat: seed Savora restaurant brands"
```

---

### Task 3: Add the Public Storefront Read Boundary

**Files:**

- Create: `api/restaurant_storefront.php`
- Create: `tests/customer_storefront_service_test.php`
- Modify: `lib/repositories/catalog_repository.php:76-181`
- Modify: `lib/services/catalog_service.php:333-364`
- Modify: `tests/customer_storefront_contract.test.js`

**Interfaces:**

- Produces: `catalog_storefront_for_customer(mysqli $conn, string $publicId, ?DateTimeImmutable $at = null): array`.
- Produces success data: `{restaurant, items, weeklyHours, promotions}`.
- Produces endpoint responses: HTTP 200 with `{ok:true,data:{...}}`, HTTP 404 with `{ok:false,message:"Restaurant not found."}`, HTTP 422 with `{ok:false,message:"A valid restaurant identifier is required."}`.

- [ ] **Step 1: Add failing static and executable tests**

Append to `tests/customer_storefront_contract.test.js`:

```js
test('storefront endpoint is a public GET-only catalog read', () => {
  const endpoint = read('api/restaurant_storefront.php');
  const service = read('lib/services/catalog_service.php');
  const repository = read('lib/repositories/catalog_repository.php');
  assert.match(endpoint, /REQUEST_METHOD/);
  assert.match(endpoint, /catalog_storefront_for_customer/);
  assert.doesNotMatch(endpoint, /savora_request_actor/);
  assert.match(service, /function catalog_storefront_for_customer/);
  assert.match(repository, /function catalog_repository_customer_storefront/);
  assert.match(repository, /function catalog_repository_active_promotions/);
  assert.match(repository, /restaurant_weekly_hours/);
  assert.match(repository, /scope='all_restaurants'/);
});
```

Create `tests/customer_storefront_service_test.php` using `tests/support/test_database.php`. Build the fixture with these statements before the assertions:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/support/test_database.php';
require_once __DIR__ . '/../lib/services/catalog_service.php';

function storefront_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$conn = savora_test_database();
$suffix = bin2hex(random_bytes(5));
$userIds = [];
$restaurantIds = [];
$promotionIds = [];
try {
    $user = $conn->prepare("INSERT INTO users(username,password,role,full_name,status) VALUES(?,?,'restaurant',?,'active')");
    $password = password_hash('storefront-test', PASSWORD_DEFAULT);
    foreach (['a', 'b', 'suspended'] as $key) {
        $username = "storefront-{$key}-{$suffix}";
        $ownerName = "Storefront {$key} Owner";
        $user->bind_param('sss', $username, $password, $ownerName);
        $user->execute();
        $userIds[$key] = (int) $conn->insert_id;
    }
    $user->close();

    $restaurant = $conn->prepare('INSERT INTO restaurants(owner_user_id,public_id,name,cuisine,status,accepting_orders) VALUES(?,?,?,?,?,1)');
    foreach ([['a','Storefront A','active'],['b','Storefront B','active'],['suspended','Storefront Suspended','suspended']] as [$key,$name,$status]) {
        $restaurantPublicIdValue = "storefront-{$key}-{$suffix}";
        $cuisine = 'Test Cuisine';
        $restaurant->bind_param('issss', $userIds[$key], $restaurantPublicIdValue, $name, $cuisine, $status);
        $restaurant->execute();
        $restaurantIds[$key] = (int) $conn->insert_id;
    }
    $restaurant->close();
    $restaurantPublicId = "storefront-a-{$suffix}";
    $suspendedPublicId = "storefront-suspended-{$suffix}";

    $item = $conn->prepare('INSERT INTO menu_items(public_id,restaurant_id,name,price,item_type,is_available) VALUES(?,?,?,?,?,?)');
    foreach ([['visible',$restaurantIds['a'],1],['hidden',$restaurantIds['a'],0],['foreign',$restaurantIds['b'],1]] as [$key,$restaurantIdValue,$available]) {
        $itemPublicId = "storefront-item-{$key}-{$suffix}";
        $itemName = "Storefront {$key} item";
        $price = 10.0;
        $itemType = 'food';
        $item->bind_param('sisdsi', $itemPublicId, $restaurantIdValue, $itemName, $price, $itemType, $available);
        $item->execute();
    }
    $item->close();

    $hour = $conn->prepare("INSERT INTO restaurant_weekly_hours(restaurant_id,weekday,opens_at,closes_at,is_closed) VALUES(?,1,'09:00:00','21:00:00',0)");
    $hour->bind_param('i', $restaurantIds['a']);
    $hour->execute();
    $hour->close();

    $promotion = $conn->prepare('INSERT INTO promotions(code,audience,discount_type,discount_value,minimum_order,budget,starts_at,ends_at,status,scope) VALUES(?,\'all_customers\',\'percentage\',10,0,1000,?,?,?,?)');
    foreach ([['STORE10','2026-08-01 00:00:00','2026-08-31 23:59:59','active','restaurant:' . $restaurantIds['a']],['EXPIRED10','2026-07-01 00:00:00','2026-07-31 23:59:59','active','restaurant:' . $restaurantIds['a']],['OTHER10','2026-08-01 00:00:00','2026-08-31 23:59:59','active','restaurant:' . $restaurantIds['b']]] as [$code,$starts,$ends,$status,$scope]) {
        $promotionCode = "{$code}-{$suffix}";
        $promotion->bind_param('sssss', $promotionCode, $starts, $ends, $status, $scope);
        $promotion->execute();
        $promotionIds[] = (int) $conn->insert_id;
    }
    $promotion->close();
    $activeCode = "STORE10-{$suffix}";
```

Use `$activeCode = "STORE10-{$suffix}"` in the promotion assertion, then close the `try` with this exact cleanup:

```php
} finally {
    foreach (array_reverse($promotionIds) as $id) $conn->query('DELETE FROM promotions WHERE id=' . (int) $id);
    foreach (array_reverse($restaurantIds) as $id) {
        $conn->query('DELETE FROM restaurant_weekly_hours WHERE restaurant_id=' . (int) $id);
        $conn->query('DELETE FROM menu_items WHERE restaurant_id=' . (int) $id);
        $conn->query('DELETE FROM restaurants WHERE id=' . (int) $id);
    }
    foreach (array_reverse($userIds) as $id) $conn->query('DELETE FROM users WHERE id=' . (int) $id);
    $conn->close();
}
echo "PASS: customer storefront read boundary is scoped and promotion-safe\n";
```

Between fixture setup and cleanup, assert:

```php
$result = catalog_storefront_for_customer($conn, $restaurantPublicId, new DateTimeImmutable('2026-08-03 12:00:00'));
storefront_expect(($result['ok'] ?? false) === true, 'Active restaurant storefront must resolve.');
storefront_expect(count($result['data']['items'] ?? []) === 1, 'Only available selected-restaurant items may be returned.');
storefront_expect(count($result['data']['weeklyHours'] ?? []) === 1, 'Selected restaurant hours must be returned.');
storefront_expect(array_column($result['data']['promotions'] ?? [], 'code') === [$activeCode], 'Only active applicable promotions may be returned.');
storefront_expect((catalog_storefront_for_customer($conn, 'bad id')['status'] ?? 0) === 422, 'Invalid IDs must return 422.');
storefront_expect((catalog_storefront_for_customer($conn, $suspendedPublicId)['status'] ?? 0) === 404, 'Inactive restaurants must return 404.');
```

Use a `finally` block to delete created promotions, hours, items, restaurants, and users in foreign-key-safe order.

- [ ] **Step 2: Run tests and confirm failures**

```powershell
node --test tests/customer_storefront_contract.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/customer_storefront_service_test.php
```

Expected: static test fails because endpoint/functions are missing; PHP test fails for undefined `catalog_storefront_for_customer` after the test database is migrated.

- [ ] **Step 3: Enrich the shared item mapping**

In every customer/restaurant item SELECT in `lib/repositories/catalog_repository.php`, add:

```sql
m.item_type,
r.public_id AS restaurant_public_id,
r.slogan AS restaurant_slogan,
r.logo_path AS restaurant_logo_path,
r.phone AS restaurant_phone
```

Add these exact mapped keys in `catalog_repository_map_item()`:

```php
'itemType' => in_array((string) ($row['item_type'] ?? 'food'), ['food', 'drink'], true) ? (string) $row['item_type'] : 'food',
'sortOrder' => (int) ($row['sort_order'] ?? 0),
'restaurant' => [
    'id' => (int) $row['restaurant_id'],
    'publicId' => (string) ($row['restaurant_public_id'] ?? ''),
    'name' => (string) $row['restaurant_name'],
    'slogan' => (string) ($row['restaurant_slogan'] ?? ''),
    'logoPath' => (string) ($row['restaurant_logo_path'] ?? ''),
    'phone' => (string) ($row['restaurant_phone'] ?? ''),
],
```

Retain all existing restaurant keys in the same nested array.

- [ ] **Step 4: Implement scoped storefront repository functions**

Add these functions to `lib/repositories/catalog_repository.php`:

```php
function catalog_repository_customer_storefront(mysqli $conn, string $publicId): array
{
    return catalog_repository_one($conn, "SELECT id,public_id,name,cuisine,description,slogan,hero_image,logo_path,rating,address,city,phone,accepting_orders,latitude,longitude FROM restaurants WHERE public_id=? AND status='active' LIMIT 1", 's', [$publicId]);
}

function catalog_repository_customer_storefront_items(mysqli $conn, int $restaurantId): array
{
    $rows = catalog_repository_rows($conn, "SELECT m.id AS menu_item_id,m.public_id,m.name AS item_name,m.price AS base_price,m.version AS item_version,m.is_available AS item_available,m.description AS item_description,m.image_path,m.category AS item_category,m.item_type,m.prep_time_minutes,m.calories,m.dietary_tags,m.allergens,m.ingredients,m.sort_order,r.id AS restaurant_id,r.public_id AS restaurant_public_id,r.name AS restaurant_name,r.cuisine,r.description AS restaurant_description,r.slogan AS restaurant_slogan,r.hero_image AS restaurant_hero_image,r.logo_path AS restaurant_logo_path,r.rating AS restaurant_rating,r.address,r.city,r.phone AS restaurant_phone,r.latitude,r.longitude,(r.accepting_orders=1) AS operational_available FROM menu_items m JOIN restaurants r ON r.id=m.restaurant_id WHERE r.id=? AND r.status='active' AND m.is_available=1 ORDER BY m.item_type,m.sort_order,m.name,m.id", 'i', [$restaurantId]);
    return array_map(fn (array $row): array => catalog_repository_map_item($conn, $row, true), $rows);
}

function catalog_repository_active_promotions(mysqli $conn, int $restaurantId, DateTimeImmutable $at): array
{
    $timestamp = $at->format('Y-m-d H:i:s');
    $id = (string) $restaurantId;
    $scoped = 'restaurant:' . $restaurantId;
    return catalog_repository_rows($conn, "SELECT code,discount_type,discount_value,maximum_discount,minimum_order,ends_at FROM promotions WHERE status='active' AND starts_at<=? AND ends_at>=? AND (scope='all_restaurants' OR scope='all' OR scope=? OR scope=?) ORDER BY ends_at,code", 'ssss', [$timestamp, $timestamp, $id, $scoped]);
}
```

- [ ] **Step 5: Compose and validate the storefront service**

Add to `lib/services/catalog_service.php`:

```php
function catalog_storefront_for_customer(mysqli $conn, string $publicId, ?DateTimeImmutable $at = null): array
{
    $publicId = trim($publicId);
    if (!preg_match('/^[A-Za-z0-9_-]{1,60}$/', $publicId)) {
        return catalog_error(422, 'A valid restaurant identifier is required.');
    }
    $restaurant = catalog_repository_customer_storefront($conn, $publicId);
    if ($restaurant === []) return catalog_error(404, 'Restaurant not found.');
    $restaurantId = (int) $restaurant['id'];
    return catalog_success([
        'restaurant' => [
            'publicId' => (string) $restaurant['public_id'],
            'name' => (string) $restaurant['name'],
            'cuisine' => (string) $restaurant['cuisine'],
            'description' => (string) ($restaurant['description'] ?? ''),
            'slogan' => (string) ($restaurant['slogan'] ?? ''),
            'heroImage' => (string) ($restaurant['hero_image'] ?? ''),
            'logoPath' => (string) ($restaurant['logo_path'] ?? ''),
            'rating' => (float) ($restaurant['rating'] ?? 0),
            'address' => (string) ($restaurant['address'] ?? ''),
            'city' => (string) ($restaurant['city'] ?? ''),
            'phone' => (string) ($restaurant['phone'] ?? ''),
            'acceptingOrders' => (bool) $restaurant['accepting_orders'],
        ],
        'items' => catalog_repository_customer_storefront_items($conn, $restaurantId),
        'weeklyHours' => catalog_repository_weekly_hours($conn, $restaurantId),
        'promotions' => array_map(static fn (array $promotion): array => [
            'code' => (string) $promotion['code'],
            'discountType' => (string) $promotion['discount_type'],
            'discountValue' => (float) $promotion['discount_value'],
            'maximumDiscount' => $promotion['maximum_discount'] === null ? null : (float) $promotion['maximum_discount'],
            'minimumOrder' => (float) $promotion['minimum_order'],
            'endsAt' => (string) $promotion['ends_at'],
        ], catalog_repository_active_promotions($conn, $restaurantId, $at ?? new DateTimeImmutable('now'))),
    ], 'Restaurant storefront loaded.');
}
```

- [ ] **Step 6: Add the GET-only endpoint**

Create `api/restaurant_storefront.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/services/catalog_service.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    savora_error(405, 'Method not allowed.');
}
$result = catalog_storefront_for_customer($conn, (string) ($_GET['restaurant'] ?? ''));
$status = (int) ($result['status'] ?? 200);
unset($result['status']);
savora_json($result, $status);
```

- [ ] **Step 7: Run focused API/service tests**

```powershell
php -l api/restaurant_storefront.php
php -l lib/repositories/catalog_repository.php
php -l lib/services/catalog_service.php
node --test tests/customer_storefront_contract.test.js tests/catalog_api_contract.test.js tests/rich_catalog_mapping.test.js
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/customer_storefront_service_test.php
```

Expected: all lint/tests PASS; storefront fixture returns one available item and only `STORE10`.

- [ ] **Step 8: Commit the read boundary**

```powershell
git add api/restaurant_storefront.php lib/repositories/catalog_repository.php lib/services/catalog_service.php tests/customer_storefront_contract.test.js tests/customer_storefront_service_test.php
git commit -m "feat: expose customer restaurant storefront"
```

---

### Task 4: Fix Catalog Mapping and Add Safe Brand/Type Fields

**Files:**

- Modify: `js/customer_catalog.js:8-69`
- Modify: `tests/rich_catalog_mapping.test.js`
- Modify: `tests/rich_catalog_browser_contract.test.js`

**Interfaces:**

- Consumes: enriched item records from Task 3.
- Produces: item fields `categoryLabel`, `itemType`, `sortOrder`, `restaurantPublicId`.
- Produces: restaurant fields `publicId`, `slogan`, `logoPath`, `phone`, `address`, `city`, and `productIds`.
- Produces: `SavoraCatalog.logoFor(restaurant)` with a strict local-SVG allowlist.

- [ ] **Step 1: Write the failing mapping regression**

Add a two-item, one-restaurant fixture to `tests/rich_catalog_mapping.test.js`:

```js
test('category labels stay paired with their own IDs and brand paths stay local', () => {
  const catalog = require(path.join(root, 'js/customer_catalog.js'));
  catalog.replaceRecords([
    { publicId: 'food-1', name: 'Noodles', category: 'Regional Noodles', itemType: 'food', basePrice: 10, restaurant: { id: 7, publicId: 'demo-hoi-an-garden', name: 'Hoi An Garden', cuisine: 'Vietnamese', slogan: 'Regional flavors in full bloom.', logoPath: 'assets/images/brands/hoi-an-garden.svg' } },
    { publicId: 'drink-1', name: 'Lotus Tea', category: 'Tea', itemType: 'drink', basePrice: 4, restaurant: { id: 7, publicId: 'demo-hoi-an-garden', name: 'Hoi An Garden', cuisine: 'Vietnamese', slogan: 'Regional flavors in full bloom.', logoPath: 'assets/images/brands/hoi-an-garden.svg' } }
  ]);
  assert.deepEqual(catalog.categories, [
    { id: 'regional-noodles', label: 'Regional Noodles' },
    { id: 'tea', label: 'Tea' }
  ]);
  assert.equal(catalog.products['drink-1'].itemType, 'drink');
  assert.equal(catalog.products['drink-1'].restaurantPublicId, 'demo-hoi-an-garden');
  assert.equal(catalog.restaurants['Hoi An Garden'].publicId, 'demo-hoi-an-garden');
  assert.equal(catalog.logoFor(catalog.restaurants['Hoi An Garden']), 'assets/images/brands/hoi-an-garden.svg');
  assert.match(catalog.logoFor({ logoPath: 'https://invalid.example/logo.svg' }), /restaurant-placeholder\.svg$/);
});
```

- [ ] **Step 2: Run mapping tests and confirm failure**

```powershell
node --test tests/rich_catalog_mapping.test.js tests/rich_catalog_browser_contract.test.js
```

Expected: FAIL because labels repeat and brand/type fields are not mapped.

- [ ] **Step 3: Map each record without first-record lookup**

In `js/customer_catalog.js`, add:

```js
const placeholderLogo = 'assets/images/brands/restaurant-placeholder.svg';
const logoFor = restaurant => restaurant && /^assets\/images\/brands\/[a-z0-9][a-z0-9-]*\.svg$/.test(String(restaurant.logoPath || ''))
  ? restaurant.logoPath
  : placeholderLogo;
```

Return these fields from `itemFromRecord(record)`:

```js
restaurantPublicId: text(source.restaurant && source.restaurant.publicId),
categoryLabel: text(source.category || (source.restaurant && source.restaurant.cuisine) || 'Menu'),
itemType: source.itemType === 'drink' ? 'drink' : 'food',
sortOrder: Number.isFinite(Number(source.sortOrder)) ? Number(source.sortOrder) : 0,
```

Rewrite the `replaceRecords` loop to keep the current record paired with its item:

```js
(Array.isArray(records) ? records : []).forEach(record => {
  const item = itemFromRecord(record);
  if (!item.id) return;
  products[item.id] = item;
  const restaurantSource = record && record.restaurant && typeof record.restaurant === 'object' ? record.restaurant : {};
  const name = item.restaurant || 'Restaurant';
  const existing = restaurants[name] || {
    publicId: item.restaurantPublicId,
    name,
    cuisine: item.cuisine,
    slogan: text(restaurantSource.slogan),
    logoPath: text(restaurantSource.logoPath),
    description: text(restaurantSource.description),
    heroImage: text(restaurantSource.heroImage),
    image: text(restaurantSource.heroImage),
    address: text(restaurantSource.address),
    city: text(restaurantSource.city),
    phone: text(restaurantSource.phone),
    rating: Number.isFinite(Number(restaurantSource.rating)) ? String(Number(restaurantSource.rating)) : 'No rating',
    prepTime: item.prepTime,
    productIds: []
  };
  if (!existing.productIds.includes(item.id)) existing.productIds.push(item.id);
  restaurants[name] = existing;
  if (!categories.some(category => category.id === item.category)) {
    categories.push({ id: item.category, label: item.categoryLabel });
  }
});
```

Export `placeholderLogo` and `logoFor` in the API object.

- [ ] **Step 4: Run mapping and browser-contract tests**

```powershell
node --test tests/rich_catalog_mapping.test.js tests/rich_catalog_browser_contract.test.js tests/catalog_assets.test.js
```

Expected: all tests PASS; `regional-noodles` and `tea` retain distinct labels.

- [ ] **Step 5: Commit the mapping fix**

```powershell
git add js/customer_catalog.js tests/rich_catalog_mapping.test.js tests/rich_catalog_browser_contract.test.js
git commit -m "fix: preserve customer catalog categories"
```

---

### Task 5: Redesign Customer Home as a Wide Overview

**Files:**

- Create: `js/customer_home.js`
- Create: `css/customer_home.css`
- Create: `tests/customer_home_selection.test.js`
- Modify: `customer_dashboard.php:1-309`
- Modify: `components/customer_header.php:24-51`
- Modify: `components/customer_footer.php:83-92`
- Modify: `css/customer_style.css:39-44,158-190,489-535`
- Modify: `tests/customer_markup.test.js` — add only the header title expectation in this task; add the route file to `customerRoutes` in Task 6 after the page exists.
- Modify: `tests/rich_catalog_browser_contract.test.js`

**Interfaces:**

- Consumes: `SavoraCatalog.products`, `SavoraCatalog.restaurants`, `SavoraCatalog.logoFor`, `SavoraApi` profile reads/writes, and existing Customer favorite records.
- Produces: `SavoraHome.selectOverview(products, restaurants, filter, query)` and DOM renderer `SavoraHome.initialize()`.
- Default output: six restaurants, up to six foods with one per restaurant, and up to six drinks with one per restaurant.

- [ ] **Step 1: Write pure Home-selection tests**

Create `tests/customer_home_selection.test.js`:

```js
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const home = require('../js/customer_home.js');

const products = [
  { id: 'a-food', name: 'Pho', restaurant: 'Lotus', cuisine: 'Vietnamese', itemType: 'food', categoryLabel: 'Noodle Soup', sortOrder: 1 },
  { id: 'a-drink', name: 'Coffee', restaurant: 'Lotus', cuisine: 'Vietnamese', itemType: 'drink', categoryLabel: 'Coffee', sortOrder: 7 },
  { id: 'b-food', name: 'Ramen', restaurant: 'Kumo', cuisine: 'Japanese', itemType: 'food', categoryLabel: 'Ramen', sortOrder: 1 },
  { id: 'b-drink', name: 'Yuzu Soda', restaurant: 'Kumo', cuisine: 'Japanese', itemType: 'drink', categoryLabel: 'Coolers', sortOrder: 8 }
];
const restaurants = [
  { name: 'Lotus', cuisine: 'Vietnamese', slogan: 'Comfort served thoughtfully.', productIds: ['a-food', 'a-drink'] },
  { name: 'Kumo', cuisine: 'Japanese', slogan: 'Light as a cloud.', productIds: ['b-food', 'b-drink'] }
];

test('default Home overview keeps one food and drink per restaurant', () => {
  const result = home.selectOverview(products, restaurants, 'all', '');
  assert.deepEqual(result.restaurants.map(item => item.name), ['Lotus', 'Kumo']);
  assert.deepEqual(result.foods.map(item => item.id), ['a-food', 'b-food']);
  assert.deepEqual(result.drinks.map(item => item.id), ['a-drink', 'b-drink']);
});

test('Home cuisine, type, and search filters share one deterministic selector', () => {
  assert.deepEqual(home.selectOverview(products, restaurants, 'japanese', '').restaurants.map(item => item.name), ['Kumo']);
  assert.equal(home.selectOverview(products, restaurants, 'food', '').drinks.length, 0);
  assert.equal(home.selectOverview(products, restaurants, 'drinks', '').foods.length, 0);
  assert.deepEqual(home.selectOverview(products, restaurants, 'all', 'yuzu').drinks.map(item => item.id), ['b-drink']);
});
```

- [ ] **Step 2: Run the test and confirm the missing-module failure**

```powershell
node --test tests/customer_home_selection.test.js
```

Expected: FAIL because `js/customer_home.js` does not exist.

- [ ] **Step 3: Implement the pure selector and browser module**

Create `js/customer_home.js` as a UMD module. Implement these exact pure functions before DOM rendering:

```js
const normalize = value => String(value || '').trim().toLowerCase();
const matchesQuery = (item, query) => !query || normalize([item.name, item.restaurant, item.cuisine, item.slogan, item.categoryLabel].join(' ')).includes(query);
const cuisineMatches = (item, filter) => ['vietnamese', 'japanese', 'italian'].includes(filter) ? normalize(item.cuisine) === filter : true;
const onePerRestaurant = items => {
  const seen = new Set();
  return items.filter(item => {
    const key = normalize(item.restaurant);
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  }).slice(0, 6);
};

function selectOverview(products, restaurants, selectedFilter = 'all', search = '') {
  const filter = normalize(selectedFilter) || 'all';
  const query = normalize(search);
  const visibleProducts = products.filter(item => cuisineMatches(item, filter) && matchesQuery(item, query));
  const visibleRestaurants = restaurants.filter(item => cuisineMatches(item, filter) && matchesQuery(item, query));
  return {
    restaurants: visibleRestaurants,
    foods: filter === 'drinks' ? [] : onePerRestaurant(visibleProducts.filter(item => item.itemType === 'food')),
    drinks: filter === 'food' ? [] : onePerRestaurant(visibleProducts.filter(item => item.itemType === 'drink'))
  };
}
```

Browser initialization must:

- hydrate the catalog once;
- render six fixed filter buttons: `All`, `Vietnamese`, `Japanese`, `Italian`, `Food`, `Drinks`;
- use links for restaurant/product navigation;
- render logo, slogan, cuisine, rating, and preparation time on restaurant cards;
- render `No matches found` with a working reset button;
- update result counts with `aria-live="polite"`;
- use `encodeURIComponent(restaurant.publicId)` in storefront URLs;
- preserve existing favorite API behavior.

- [ ] **Step 4: Add page-specific asset hooks to Customer chrome**

Before including the header in `customer_dashboard.php`, set:

```php
<?php
$customer_page_styles = ['css/customer_home.css'];
$customer_page_scripts = ['js/customer_home.js'];
include 'components/customer_header.php';
?>
```

In `components/customer_header.php`, add `customer_restaurant.php` to `$public_customer_pages`, add its page title, and load only page-controlled local CSS:

```php
<?php foreach (($customer_page_styles ?? []) as $style): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars((string) $style, ENT_QUOTES, 'UTF-8') ?>?v=<?= time() ?>">
<?php endforeach; ?>
```

In `components/customer_footer.php`, after `js/customer_ui.js`, load page scripts:

```php
<?php foreach (($customer_page_scripts ?? []) as $script): ?>
    <script src="<?= htmlspecialchars((string) $script, ENT_QUOTES, 'UTF-8') ?>?v=<?= time() ?>"></script>
<?php endforeach; ?>
```

Because these arrays are set only in repository-owned PHP files, no request input may be copied into them.

- [ ] **Step 5: Replace Home aggregate markup with overview sections**

Keep the hero search and active-order renderer, but replace item-level category pills and `#food-products-grid` with:

```html
<div id="home-filter-controls" class="home-filter-controls" aria-label="Filter discovery"></div>
<div class="container home-overview-layout">
  <div class="home-overview-feed">
    <section class="home-section home-section--restaurants" aria-labelledby="featured-restaurants-title">
      <div class="section-heading-row"><div><p class="eyebrow">Places to love</p><h2 id="featured-restaurants-title">Featured Restaurants</h2></div><span id="restaurant-result-count" class="result-count" aria-live="polite"></span></div>
      <div id="featured-restaurants-grid" class="home-restaurant-grid"></div>
    </section>
    <section id="popular-food-section" class="home-section" aria-labelledby="popular-food-title">
      <div class="section-heading-row"><div><p class="eyebrow">Across Savora</p><h2 id="popular-food-title">Popular Dishes</h2></div><span id="food-result-count" class="result-count" aria-live="polite"></span></div>
      <div id="popular-food-grid" class="home-product-grid"></div>
    </section>
    <section id="popular-drink-section" class="home-section home-section--tinted" aria-labelledby="popular-drink-title">
      <div class="section-heading-row"><div><p class="eyebrow">Cool and crafted</p><h2 id="popular-drink-title">Refreshing Drinks</h2></div><span id="drink-result-count" class="result-count" aria-live="polite"></span></div>
      <div id="popular-drink-grid" class="home-product-grid"></div>
    </section>
  </div>
  <aside class="discovery-sidebar" aria-label="Order tracking"><section class="tracking-card"><div id="active-order-content"></div></section></aside>
</div>
```

Delete the inline product/restaurant/category rendering functions now owned by `js/customer_home.js`. Keep order-map/tracking logic intact.

- [ ] **Step 6: Implement the wide responsive layout**

In `css/customer_style.css`, introduce one shared width variable and update the container/header/hero calculations:

```css
:root { --customer-content-width: 1500px; }
.customer-shell, .container { width: min(var(--customer-content-width), calc(100% - 3rem)); margin-inline: auto; }
.customer-header { padding-inline: max(1.5rem, calc((100vw - var(--customer-content-width)) / 2)); }
.discovery-hero { padding-inline: max(1.5rem, calc((100vw - var(--customer-content-width)) / 2)); }
```

Create `css/customer_home.css` with these layout contracts:

```css
.home-filter-controls { display:flex; flex-wrap:wrap; gap:.75rem; max-width:1500px; margin:0 auto; padding:1.25rem 1.5rem; overflow:visible; }
.home-overview-layout { display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:2rem; align-items:start; }
.home-overview-feed { min-width:0; display:grid; gap:2.5rem; }
.home-section { padding:2rem; border-radius:1.5rem; background:#fff; }
.home-section--tinted { background:#edf5ec; }
.home-restaurant-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:1.25rem; }
.home-product-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:1.1rem; }
.home-restaurant-logo { width:9rem; height:3rem; object-fit:contain; }
@media (max-width:1279px) { .home-overview-layout { grid-template-columns:1fr; } .discovery-sidebar { position:static; grid-row:1; } .home-product-grid { grid-template-columns:repeat(3,minmax(0,1fr)); } }
@media (max-width:900px) { .home-restaurant-grid,.home-product-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width:600px) { .customer-shell,.container { width:min(100% - 1.25rem,var(--customer-content-width)); } .home-section { padding:1.25rem; } .home-restaurant-grid,.home-product-grid { grid-template-columns:1fr; } }
```

Add card typography/image rules without fixed card heights; long slogans must wrap.

- [ ] **Step 7: Update markup contracts and run tests**

Add only `'customer_restaurant.php': 'Restaurant | Savora'` to the expected title map in `tests/customer_markup.test.js`; do not add the route to `customerRoutes` until Task 6. Change the rich browser contract to assert:

```js
assert.match(dashboard, /featured-restaurants-grid/);
assert.match(dashboard, /popular-food-grid/);
assert.match(dashboard, /popular-drink-grid/);
assert.doesNotMatch(dashboard, /id="food-products-grid"/);
assert.doesNotMatch(dashboard, /categories\.forEach/);
assert.match(homeModule, /customer_restaurant\.php\?restaurant=/);
```

Run:

```powershell
node --test tests/customer_home_selection.test.js tests/customer_markup.test.js tests/rich_catalog_browser_contract.test.js tests/rich_catalog_mapping.test.js
php -l customer_dashboard.php
php -l components/customer_header.php
php -l components/customer_footer.php
```

Expected: all tests and lint PASS; no old aggregate grid/category-loop assertions remain.

- [ ] **Step 8: Commit the Home redesign**

```powershell
git add customer_dashboard.php components/customer_header.php components/customer_footer.php css/customer_style.css css/customer_home.css js/customer_home.js tests/customer_home_selection.test.js tests/customer_markup.test.js tests/rich_catalog_browser_contract.test.js
git commit -m "feat: redesign customer discovery home"
```

---

### Task 6: Build the Dedicated Restaurant Storefront

**Files:**

- Create: `customer_restaurant.php`
- Create: `js/customer_restaurant.js`
- Create: `css/customer_restaurant.css`
- Create: `tests/customer_restaurant_client.test.js`
- Modify: `tests/customer_storefront_contract.test.js`
- Modify: `tests/customer_markup.test.js`

**Interfaces:**

- Consumes: `GET api/restaurant_storefront.php?restaurant={publicId}`.
- Produces: `SavoraRestaurant.filterItems(items, filter, query)`, `SavoraRestaurant.promotionCopy(promotion)`, `SavoraRestaurant.statusLabel(restaurant, weeklyHours, now)`, and browser initializer.
- Produces semantic sections for brand hero, About, conditional Special Offers, Food, Drinks, and menu filters.

- [ ] **Step 1: Write pure storefront client tests**

Create `tests/customer_restaurant_client.test.js`:

```js
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const storefront = require('../js/customer_restaurant.js');

const items = [
  { id:'food', name:'Rare Beef Pho', itemType:'food', category:'noodle-soup', categoryLabel:'Noodle Soup' },
  { id:'drink', name:'Peach Tea', itemType:'drink', category:'tea', categoryLabel:'Tea' }
];

test('storefront menu filters by type, category, and query', () => {
  assert.deepEqual(storefront.filterItems(items, 'food', '').map(item => item.id), ['food']);
  assert.deepEqual(storefront.filterItems(items, 'drinks', '').map(item => item.id), ['drink']);
  assert.deepEqual(storefront.filterItems(items, 'tea', '').map(item => item.id), ['drink']);
  assert.deepEqual(storefront.filterItems(items, 'all', 'beef').map(item => item.id), ['food']);
});

test('promotion copy is English and includes checkout conditions', () => {
  assert.equal(storefront.promotionCopy({ code:'STORE10', discountType:'percentage', discountValue:10, minimumOrder:25 }), 'Save 10% on orders of $25.00 or more with code STORE10.');
  assert.equal(storefront.promotionCopy({ code:'SAVE5', discountType:'fixed', discountValue:5, minimumOrder:0 }), 'Save $5.00 with code SAVE5.');
});

test('storefront status combines accepting-orders state with weekly hours', () => {
  const noonMonday = { getDay: () => 1, getHours: () => 12, getMinutes: () => 0 };
  const hours = [{ weekday:1, opens_at:'09:00:00', closes_at:'21:00:00', is_closed:0 }];
  assert.equal(storefront.statusLabel({ acceptingOrders:true }, hours, noonMonday), 'Open now');
  assert.equal(storefront.statusLabel({ acceptingOrders:false }, hours, noonMonday), 'Not accepting orders');
});
```

- [ ] **Step 2: Run tests and confirm the missing-module failure**

```powershell
node --test tests/customer_restaurant_client.test.js
```

Expected: FAIL because `js/customer_restaurant.js` does not exist.

- [ ] **Step 3: Create the semantic storefront shell**

Create `customer_restaurant.php` with page assets set before the shared header and this complete landmark structure:

```php
<?php
$customer_page_styles = ['css/customer_restaurant.css'];
$customer_page_scripts = ['js/customer_restaurant.js'];
include 'components/customer_header.php';
?>
<main id="restaurant-storefront" class="restaurant-storefront" data-restaurant-public-id="<?= htmlspecialchars((string) ($_GET['restaurant'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
  <nav class="storefront-breadcrumb" aria-label="Breadcrumb"><a href="customer_dashboard.php">Discover</a><span aria-hidden="true">/</span><span id="storefront-breadcrumb-name">Restaurant</span></nav>
  <section id="storefront-loading" class="storefront-state" role="status">Loading restaurant...</section>
  <section id="storefront-error" class="storefront-state" role="alert" hidden><h1>Restaurant unavailable</h1><p id="storefront-error-copy">This restaurant could not be loaded.</p><a class="primary-action" href="customer_dashboard.php">Back to Discover</a></section>
  <div id="storefront-content" hidden>
    <section class="storefront-hero" aria-labelledby="storefront-name">
      <img id="storefront-cover" class="storefront-cover" src="assets/images/food-placeholder.svg" alt="">
      <div class="storefront-hero-overlay"><img id="storefront-logo" class="storefront-logo" src="assets/images/brands/restaurant-placeholder.svg" alt=""><div><p id="storefront-cuisine" class="eyebrow"></p><h1 id="storefront-name"></h1><p id="storefront-slogan"></p><div id="storefront-meta" class="storefront-meta"></div></div><button id="storefront-favorite" class="favorite-button" type="button" aria-pressed="false"></button></div>
    </section>
    <div class="storefront-info-grid">
      <section class="surface-card storefront-about" aria-labelledby="storefront-about-title"><h2 id="storefront-about-title">About</h2><p id="storefront-description"></p><dl><div><dt>Address</dt><dd id="storefront-address"></dd></div><div><dt>Phone</dt><dd id="storefront-phone"></dd></div></dl></section>
      <section class="surface-card storefront-hours" aria-labelledby="storefront-hours-title"><h2 id="storefront-hours-title">Opening Hours</h2><dl id="storefront-hours-list"></dl></section>
    </div>
    <section id="storefront-offers" class="storefront-offers" aria-labelledby="storefront-offers-title" hidden><div class="section-heading-row"><div><p class="eyebrow">A little extra</p><h2 id="storefront-offers-title">Special Offers</h2></div></div><div id="storefront-offers-grid"></div></section>
    <section id="storefront-active-order" class="surface-card storefront-active-order" aria-labelledby="storefront-active-order-title" hidden><div><p class="eyebrow">On the way</p><h2 id="storefront-active-order-title">Your Active Order</h2><p id="storefront-active-order-copy"></p></div><a class="primary-action" href="customer_history.php">Track Order</a></section>
    <section class="storefront-menu" aria-labelledby="storefront-menu-title"><div class="section-heading-row"><div><p class="eyebrow">Made to order</p><h2 id="storefront-menu-title">Full Menu</h2></div><span id="storefront-result-count" class="result-count" aria-live="polite"></span></div><form id="storefront-menu-search" role="search"><label for="storefront-search">Search this menu</label><input id="storefront-search" type="search" autocomplete="off" placeholder="Search dishes or drinks"></form><div id="storefront-menu-filters" class="storefront-menu-filters" aria-label="Menu filters"></div><section id="storefront-food-section" aria-labelledby="storefront-food-title"><h3 id="storefront-food-title">Food</h3><div id="storefront-food-grid" class="storefront-product-grid"></div></section><section id="storefront-drink-section" aria-labelledby="storefront-drink-title"><h3 id="storefront-drink-title">Drinks</h3><div id="storefront-drink-grid" class="storefront-product-grid"></div></section><div id="storefront-menu-empty" class="empty-state" hidden><p>No menu items match these filters.</p><button id="storefront-reset" class="secondary-action" type="button">Clear filters</button></div></section>
  </div>
</main>
<?php include 'components/customer_footer.php'; ?>
```

Add `customer_restaurant.php` to `customerRoutes` in `tests/customer_markup.test.js` now that the route exists. The title expectation was added in Task 5.

- [ ] **Step 4: Implement storefront data loading and pure helpers**

Create `js/customer_restaurant.js` as a UMD module. Implement:

```js
const money = value => `$${Number(value || 0).toFixed(2)}`;
const normalize = value => String(value || '').trim().toLowerCase();
function filterItems(items, selectedFilter = 'all', query = '') {
  const filter = normalize(selectedFilter) || 'all';
  const search = normalize(query);
  return items.filter(item => {
    const typeMatch = filter === 'all' || (filter === 'food' && item.itemType === 'food') || (filter === 'drinks' && item.itemType === 'drink') || item.category === filter;
    const queryMatch = !search || normalize([item.name, item.description, item.categoryLabel].join(' ')).includes(search);
    return typeMatch && queryMatch;
  });
}
function promotionCopy(promotion) {
  const saving = promotion.discountType === 'percentage' ? `${Number(promotion.discountValue)}%` : money(promotion.discountValue);
  const minimum = Number(promotion.minimumOrder || 0) > 0 ? ` on orders of ${money(promotion.minimumOrder)} or more` : '';
  return `Save ${saving}${minimum} with code ${promotion.code}.`;
}
function statusLabel(restaurant, weeklyHours, now = new Date()) {
  if (!restaurant.acceptingOrders) return 'Not accepting orders';
  const today = weeklyHours.find(entry => Number(entry.weekday) === Number(now.getDay()));
  if (!today || Boolean(Number(today.is_closed))) return 'Closed now';
  const minutes = value => {
    const [hour, minute] = String(value || '').split(':').map(Number);
    return Number.isFinite(hour) && Number.isFinite(minute) ? hour * 60 + minute : -1;
  };
  const current = now.getHours() * 60 + now.getMinutes();
  const opens = minutes(today.opens_at);
  const closes = minutes(today.closes_at);
  return opens >= 0 && closes >= 0 && current >= opens && current < closes ? 'Open now' : 'Closed now';
}
```

The browser initializer must:

- read and validate `data-restaurant-public-id` with `/^[A-Za-z0-9_-]{1,60}$/`;
- call `SavoraApi.get('api/restaurant_storefront.php?restaurant=' + encodeURIComponent(publicId))`;
- call `SavoraCatalog.replaceRecords(data.items)`;
- populate every hero/about/hour field using `textContent` or safe DOM creation;
- render `statusLabel(data.restaurant, data.weeklyHours)` in `#storefront-meta`;
- show offers only when `data.promotions.length > 0`;
- when authenticated, fetch `api/orders.php`, select the first order whose status is one of `pending`, `confirmed`, `preparing`, `ready_for_pickup`, `assigned`, `picked_up`, or `on_the_way`, set `#storefront-active-order-copy` to `Order {publicId or id} · {status label} · ${total}`, and unhide `#storefront-active-order`; keep it hidden for guests, failures, or no active order;
- build filters `All`, `Food`, `Drinks`, then unique item categories;
- split filtered cards into Food and Drinks grids;
- use product links `product_detail.php?id={publicId}`;
- use existing profile favorite APIs and `aria-pressed` behavior;
- show the unavailable state for invalid IDs, 404, empty payload, or request failure;
- never assign `innerHTML`.

Use this exact favorite request sequence inside initialization, with the return URL preserving the current storefront:

```js
let profileSnapshot = { favorites: [] };
if (root.SavoraCustomerAuthenticated) {
  try { profileSnapshot = await root.SavoraApi.get('api/profile.php'); } catch (_) { profileSnapshot = { favorites: [] }; }
}
const favoriteButton = documentRef.getElementById('storefront-favorite');
const renderFavorite = () => {
  const saved = (profileSnapshot.favorites || []).some(item => item.type === 'restaurant' && item.publicId === data.restaurant.publicId);
  favoriteButton.setAttribute('aria-pressed', String(saved));
  favoriteButton.setAttribute('aria-label', `${saved ? 'Remove' : 'Add'} ${data.restaurant.name} ${saved ? 'from' : 'to'} favorites`);
  favoriteButton.replaceChildren(documentRef.createTextNode(saved ? 'Saved' : 'Save restaurant'));
};
favoriteButton.addEventListener('click', async () => {
  if (!root.SavoraCustomerAuthenticated) {
    root.location.assign(`login.php?return_to=${encodeURIComponent(`customer_restaurant.php?restaurant=${data.restaurant.publicId}`)}`);
    return;
  }
  const saved = (profileSnapshot.favorites || []).some(item => item.type === 'restaurant' && item.publicId === data.restaurant.publicId);
  const scope = `customer-favorite-restaurant-${data.restaurant.publicId}`;
  favoriteButton.disabled = true;
  try {
    await root.SavoraApi.post('api/profile.php', { action:'set_favorite', payload:{ type:'restaurant', publicId:data.restaurant.publicId, active:!saved, version:0 } }, root.SavoraApi.intentKey(scope));
    profileSnapshot = await root.SavoraApi.get('api/profile.php');
    root.SavoraApi.clearIntentKey(scope);
    renderFavorite();
  } finally {
    favoriteButton.disabled = false;
  }
});
renderFavorite();
```

- [ ] **Step 5: Add responsive storefront CSS**

Create `css/customer_restaurant.css` with these complete structural and component rules:

```css
.restaurant-storefront { width:min(1500px,calc(100% - 3rem)); margin:0 auto; padding:2rem 0 4rem; }
.storefront-hero { position:relative; min-height:30rem; overflow:hidden; border-radius:2rem; background:#17342b; }
.storefront-cover { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
.storefront-hero::after { content:""; position:absolute; inset:0; background:linear-gradient(90deg,rgba(8,35,27,.94),rgba(8,35,27,.54) 55%,rgba(8,35,27,.12)); }
.storefront-hero-overlay { position:relative; z-index:1; min-height:30rem; display:flex; align-items:flex-end; gap:1.5rem; padding:3rem; color:#fff; }
.storefront-logo { width:min(15rem,35vw); max-height:5.5rem; object-fit:contain; background:#fff; border-radius:1rem; }
.storefront-meta { display:flex; flex-wrap:wrap; gap:.65rem; margin-top:1rem; }
.storefront-meta span { padding:.45rem .75rem; border:1px solid rgba(255,255,255,.35); border-radius:999px; background:rgba(0,0,0,.18); font-weight:700; }
.storefront-info-grid { display:grid; grid-template-columns:1.3fr .7fr; gap:1.5rem; margin:1.5rem 0 2.5rem; }
.storefront-about,.storefront-hours { padding:1.75rem; }
.storefront-about dl,.storefront-hours dl { display:grid; gap:.8rem; margin-top:1.25rem; }
.storefront-about dl div,.storefront-hours dl div { display:flex; justify-content:space-between; gap:1rem; padding-bottom:.7rem; border-bottom:1px solid #dbe6dc; }
.storefront-about dt,.storefront-hours dt { color:#64746d; font-weight:700; }
.storefront-about dd,.storefront-hours dd { margin:0; text-align:right; }
.storefront-offers { margin:0 0 2.5rem; padding:2rem; border-radius:1.5rem; background:#e9f3e8; }
#storefront-offers-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:1rem; }
.storefront-offer-card { padding:1.25rem; border:1px solid #cfe0ce; border-radius:1rem; background:#fff; }
.storefront-offer-code { display:inline-block; margin-top:.75rem; padding:.4rem .65rem; border-radius:.5rem; background:#17342b; color:#fff; font-weight:800; letter-spacing:.04em; }
.storefront-active-order { display:flex; align-items:center; justify-content:space-between; gap:1.5rem; margin:0 0 2.5rem; padding:1.5rem 1.75rem; }
.storefront-active-order[hidden] { display:none; }
.storefront-menu { padding:2rem; border-radius:1.5rem; background:#fff; }
#storefront-menu-search { display:grid; grid-template-columns:auto minmax(16rem,28rem); align-items:center; gap:1rem; margin-top:1.25rem; }
#storefront-search { min-height:3rem; padding:.7rem .9rem; border:1px solid #cbd8cf; border-radius:.8rem; }
.storefront-product-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:1.1rem; }
.storefront-menu-filters { display:flex; flex-wrap:wrap; gap:.65rem; margin:1rem 0 2rem; }
.storefront-menu-filters button { min-height:2.75rem; padding:.55rem .9rem; border:1px solid #cbd8cf; border-radius:999px; background:#fff; color:#17342b; font-weight:800; }
.storefront-menu-filters button[aria-pressed="true"] { border-color:#0a4a38; background:#0a4a38; color:#fff; }
.storefront-menu-filters button:focus-visible,#storefront-search:focus-visible,.storefront-product-grid a:focus-visible { outline:3px solid #e08c67; outline-offset:3px; }
#storefront-food-section + #storefront-drink-section { margin-top:2.5rem; }
.storefront-state { width:min(42rem,100%); margin:4rem auto; padding:2rem; border-radius:1rem; background:#fff; text-align:center; }
@media (max-width:1100px) { .storefront-product-grid { grid-template-columns:repeat(3,minmax(0,1fr)); } }
@media (max-width:850px) { .storefront-info-grid { grid-template-columns:1fr; } #storefront-offers-grid { grid-template-columns:1fr; } .storefront-product-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width:600px) { .restaurant-storefront { width:calc(100% - 1.25rem); } .storefront-hero,.storefront-hero-overlay { min-height:26rem; } .storefront-hero-overlay { align-items:flex-start; flex-direction:column; justify-content:flex-end; padding:1.25rem; } .storefront-menu { padding:1.25rem; } #storefront-menu-search { grid-template-columns:1fr; } .storefront-product-grid { grid-template-columns:1fr; } }
```

- [ ] **Step 6: Add route/markup contracts**

Append to `tests/customer_storefront_contract.test.js`:

```js
test('dedicated Customer restaurant page exposes brand, offers, hours, and split menu landmarks', () => {
  const page = read('customer_restaurant.php');
  const client = read('js/customer_restaurant.js');
  for (const id of ['storefront-name','storefront-slogan','storefront-address','storefront-hours-list','storefront-offers','storefront-active-order','storefront-food-grid','storefront-drink-grid']) {
    assert.match(page, new RegExp(`id="${id}"`));
  }
  assert.match(client, /api\/restaurant_storefront\.php\?restaurant=/);
  assert.match(client, /item\.itemType === 'food'/);
  assert.match(client, /item\.itemType === 'drink'/);
  assert.doesNotMatch(client, /innerHTML\s*=/);
});
```

- [ ] **Step 7: Run storefront tests and lint**

```powershell
node --test tests/customer_restaurant_client.test.js tests/customer_storefront_contract.test.js tests/customer_markup.test.js
php -l customer_restaurant.php
```

Expected: all tests and lint PASS.

- [ ] **Step 8: Commit the storefront UI**

```powershell
git add customer_restaurant.php js/customer_restaurant.js css/customer_restaurant.css tests/customer_restaurant_client.test.js tests/customer_storefront_contract.test.js
git commit -m "feat: add customer restaurant storefront page"
```

---

### Task 7: Route Favorites and Product Details to Storefronts, Then Remove the Menu Modal

**Files:**

- Modify: `customer_favorites.php:56-62`
- Modify: `product_detail.php:29-54,227-241`
- Modify: `components/customer_footer.php:62-68`
- Modify: `js/customer_ui.js:230-258,342-350`
- Modify: `tests/customer_markup.test.js`
- Modify: `tests/customer_storefront_contract.test.js`

**Interfaces:**

- Consumes: `restaurant.publicId` from Task 4.
- Produces: all Customer restaurant navigation uses semantic storefront links.
- Removes: `SavoraUI.openMenuModal`, `closeMenuModal`, `#menu-modal`, `#modal-food-grid`, and `#modal-rest-name`.

- [ ] **Step 1: Add a failing navigation cleanup contract**

Append to `tests/customer_storefront_contract.test.js`:

```js
test('restaurant entry points use storefront links and no menu modal remains', () => {
  const favorites = read('customer_favorites.php');
  const product = read('product_detail.php');
  const footer = read('components/customer_footer.php');
  const ui = read('js/customer_ui.js');
  assert.match(favorites, /customer_restaurant\.php\?restaurant=/);
  assert.match(product, /customer_restaurant\.php\?restaurant=/);
  assert.doesNotMatch([favorites, footer, ui].join('\n'), /openMenuModal|menu-modal|modal-food-grid/);
});
```

- [ ] **Step 2: Run the contract and confirm failure**

```powershell
node --test tests/customer_storefront_contract.test.js
```

Expected: FAIL because favorites still call `openMenuModal` and the shared modal remains.

- [ ] **Step 3: Replace favorite restaurant buttons with links**

In `customer_favorites.php`, replace the menu button/click handler with:

```js
const open = ui.el('a', {
  className: 'favorite-card-navigation',
  href: `customer_restaurant.php?restaurant=${encodeURIComponent(restaurant.publicId)}`,
  'aria-label': `View ${restaurant.name}`
}, [
  ui.el('img', { src: catalog.logoFor(restaurant), alt: `${restaurant.name} logo` }),
  ui.el('span', { className: 'favorite-card-copy' }, [
    ui.el('strong', {}, restaurant.name),
    ui.el('span', {}, restaurant.slogan || restaurant.cuisine || 'Restaurant')
  ])
]);
```

- [ ] **Step 4: Link product detail to the owning storefront**

Change the restaurant heading in `product_detail.php` to a link with ID `restaurant-detail-link`, then set:

```js
const restaurantLink = document.getElementById('restaurant-detail-link');
restaurantLink.href = `customer_restaurant.php?restaurant=${encodeURIComponent(restaurant.publicId)}`;
restaurantLink.setAttribute('aria-label', `View ${restaurant.name}`);
```

Also ensure `product_detail.php` fallback restaurant object defines `publicId: item.restaurantPublicId`.

- [ ] **Step 5: Remove the obsolete modal and functions**

Delete the complete `#menu-modal` section from `components/customer_footer.php`. Delete `openMenuModal()` from `js/customer_ui.js`, remove `openMenuModal`/`closeMenuModal` from exported APIs and `Object.assign(root, ...)`, and leave cart/customization/top-up dialogs unchanged.

- [ ] **Step 6: Run regression tests**

```powershell
node --test tests/customer_storefront_contract.test.js tests/customer_markup.test.js tests/customer_state.test.js tests/rich_catalog_mapping.test.js
php -l customer_favorites.php
php -l product_detail.php
php -l components/customer_footer.php
```

Expected: all tests and lint PASS; repository search outside archival `.superpowers`/`.worktrees` returns no runtime `openMenuModal` reference.

- [ ] **Step 7: Commit navigation cleanup**

```powershell
git add customer_favorites.php product_detail.php components/customer_footer.php js/customer_ui.js tests/customer_markup.test.js tests/customer_storefront_contract.test.js
git commit -m "refactor: route customers to restaurant storefronts"
```

---

### Task 8: Verify Seeded Data, Responsive Layout, and End-to-End Navigation

**Files:**

- Modify: `tests/rich_catalog_integration_test.php`
- Modify: `tests/customer_guest_browser_qa.mjs`
- Modify: `tests/catalog_assets.test.js`

**Interfaces:**

- Consumes: all previous task outputs.
- Produces: executable evidence that six storefronts, 48 items, logos, Food/Drinks, offers, routes, and overflow behavior work together.

- [ ] **Step 1: Strengthen the integration assertions before final verification**

In `tests/rich_catalog_integration_test.php`, assert each item includes `itemType`, each restaurant includes brand fields, and each restaurant has six food plus two drink records:

```php
$restaurantTypes = [];
foreach ($items as $item) {
    $restaurant = $item['restaurant'];
    foreach (['publicId', 'slogan', 'logoPath', 'phone', 'address', 'city'] as $field) {
        if (trim((string) ($restaurant[$field] ?? '')) === '') throw new RuntimeException("Customer restaurant is missing {$field}.");
    }
    if (!in_array($item['itemType'] ?? '', ['food', 'drink'], true)) throw new RuntimeException('Customer item type is invalid.');
    $restaurantTypes[$restaurant['publicId']][$item['itemType']] = ($restaurantTypes[$restaurant['publicId']][$item['itemType']] ?? 0) + 1;
}
foreach ($restaurantTypes as $counts) {
    if (($counts['food'] ?? 0) !== 6 || ($counts['drink'] ?? 0) !== 2) throw new RuntimeException('Every storefront must expose six foods and two drinks.');
}
```

Extend `tests/catalog_assets.test.js` to check all seven SVG files and reject logo paths outside `assets/images/brands/`.

- [ ] **Step 2: Update browser QA for overview, storefront, and overflow**

In `tests/customer_guest_browser_qa.mjs`:

- replace the `#food-products-grid` readiness selector with `#featured-restaurants-grid`;
- assert six restaurant cards, six or fewer food cards, and six or fewer drink cards by default;
- click the first storefront link and wait for `#storefront-content:not([hidden])`;
- assert slogan, address, opening hours, and eight total product cards are visible;
- assert Food returns six and Drinks returns two for the demo storefront;
- use CDP `Emulation.setDeviceMetricsOverride` for widths `1920`, `1440`, `768`, and `390` and run this expression at Home and storefront:

```js
document.documentElement.scrollWidth <= window.innerWidth + 1
```

- assert logo image sources end with `.svg` and menu image sources remain local catalog JPGs;
- preserve the existing guest cart, login gate, and role redirect checks.

- [ ] **Step 3: Run the complete focused automated suite**

```powershell
node --test tests/customer_storefront_contract.test.js tests/customer_home_selection.test.js tests/customer_restaurant_client.test.js tests/rich_catalog_mapping.test.js tests/rich_catalog_browser_contract.test.js tests/catalog_assets.test.js tests/customer_markup.test.js tests/customer_state.test.js tests/catalog_api_contract.test.js
$env:SAVORA_ENV='development'; $env:SAVORA_DB_NAME='savora_db'; $env:SAVORA_DB_PORT='3307'; php tests/rich_catalog_integration_test.php
$env:SAVORA_ENV='test'; $env:SAVORA_DB_NAME='savora_test'; $env:SAVORA_DB_PORT='3307'; php tests/customer_storefront_service_test.php
```

Expected: all Node tests PASS; integration reports 48 items across six restaurants with a 6/2 type split; storefront service test PASS.

- [ ] **Step 4: Run lint for every changed PHP file**

```powershell
$phpFiles = @('database/migrations/018_customer_storefront.php','scripts/generate_brand_logos.php','lib/catalog_demo_seed.php','lib/repositories/catalog_repository.php','lib/services/catalog_service.php','api/restaurant_storefront.php','components/customer_header.php','components/customer_footer.php','customer_dashboard.php','customer_restaurant.php','customer_favorites.php','product_detail.php','tests/customer_storefront_service_test.php','tests/rich_catalog_integration_test.php'); foreach ($file in $phpFiles) { php -l $file; if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE } }
```

Expected: every file reports `No syntax errors detected`.

- [ ] **Step 5: Apply development migration/seed and smoke the HTTP endpoints**

```powershell
$env:SAVORA_ENV='development'; $env:SAVORA_DB_NAME='savora_db'; $env:SAVORA_DB_PORT='3307'; php scripts/migrate.php
$env:SAVORA_SEED_DEMO='1'; php scripts/seed.php
$home = Invoke-WebRequest -UseBasicParsing 'http://127.0.0.1:8085/Savora/customer_dashboard.php'; if ($home.StatusCode -ne 200) { throw 'Customer Home failed' }
$store = Invoke-WebRequest -UseBasicParsing 'http://127.0.0.1:8085/Savora/customer_restaurant.php?restaurant=demo-lotus-kitchen'; if ($store.StatusCode -ne 200) { throw 'Storefront page failed' }
$api = Invoke-RestMethod 'http://127.0.0.1:8085/Savora/api/restaurant_storefront.php?restaurant=demo-lotus-kitchen'; if (-not $api.ok -or $api.data.items.Count -ne 8) { throw 'Storefront API failed' }
```

Expected: both pages return HTTP 200; API returns `ok=true` and eight Lotus Kitchen items.

- [ ] **Step 6: Run browser QA when Chrome CDP is available**

```powershell
$env:SAVORA_BASE_URL='http://127.0.0.1:8085/Savora'; $env:SAVORA_CDP_PORT='9227'; node tests/customer_guest_browser_qa.mjs
```

Expected: PASS for public Home, Home-to-storefront navigation, 1920/1440/768/390 overflow checks, guest cart/login gates, and role redirects. If Chrome is not already running with CDP on port 9227, report this single check as BLOCKED; do not replace it with a success claim.

- [ ] **Step 7: Review the final diff and commit verification changes**

```powershell
git diff --check
git status --short
git diff -- tests/rich_catalog_integration_test.php tests/customer_guest_browser_qa.mjs tests/catalog_assets.test.js
git add tests/rich_catalog_integration_test.php tests/customer_guest_browser_qa.mjs tests/catalog_assets.test.js
git commit -m "test: verify customer restaurant storefronts"
```

Expected: no whitespace errors; only this feature's files are committed; pre-existing unrelated files remain unstaged.

---

## Final Acceptance Checklist

- [ ] Home shows six distinct restaurant brands with unique local SVG logos and English slogans.
- [ ] Home default product sections contain representative items rather than all 48 products.
- [ ] Filter labels are correct and no repeated `Regional Noodles` bug remains.
- [ ] No horizontal category scrollbar or page-level horizontal overflow appears at 1920, 1440, 768, or 390 pixels.
- [ ] Every restaurant card and restaurant favorite opens a dedicated public-ID storefront URL.
- [ ] Each demo storefront shows description, address, phone, opening hours, status, and all eight available products.
- [ ] Each demo storefront splits exactly six Food and two Drinks items using persisted `item_type`.
- [ ] Active applicable promotions appear; expired or differently scoped promotions do not.
- [ ] Product details link back to the owning restaurant storefront.
- [ ] Invalid and inactive restaurants return safe unavailable/404 behavior.
- [ ] Existing cart, favorites, guest access, login gates, and role redirects still pass.
- [ ] All visible Customer copy remains English-only.
