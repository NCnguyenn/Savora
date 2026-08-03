# Rich Savora Catalog Implementation Plan

> For agentic workers: REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax.

**Goal:** Make Customer Home display six active restaurants with 48 diverse English-language food and beverage items, complete catalog information, and local high-quality menu images.

**Architecture:** Extend the MySQL catalog contract with bounded rich-content fields, expose them through api/catalog.php, map them into the current Customer discovery/product models, and keep demo data creation explicit and idempotent through the CLI seed path.

**Tech Stack:** PHP 8, MySQL/MariaDB, mysqli, the existing migration registry, vanilla JavaScript, Node built-in tests, local JPEG assets, and the built-in ImageGen tool.

## Global Constraints

- All visible restaurant, dish, category, description, ingredient, allergen, and dietary copy is English-only, with no Vietnamese-language copy or diacritics.
- The catalog has exactly six demo restaurants: four Vietnamese and two international.
- Every demo restaurant has exactly eight menu items: six food items and two beverages.
- All demo restaurants are active and accepting orders; all 48 demo items are available.
- Images are local assets under assets/images/catalog/, with validated paths, no text, no logo, and no watermark.
- Web requests never run migrations or seed data; seeding remains CLI-only with SAVORA_ENV=development|test and SAVORA_SEED_DEMO=1.
- The seed never deletes unrelated users, restaurants, or menu items.
- No frontend framework, external image hosting, or external image URL is introduced.
- Existing catalog ownership, CSRF, idempotency, version, and unavailable-item behavior remain intact.

---

### Task 1: Add the rich catalog schema

**Files**

- Create: database/migrations/017_rich_catalog_content.php
- Modify: lib/migrations.php
- Test: tests/rich_catalog_contract.test.js

**Interfaces**

- Consumes the existing 001-016 migration registry.
- Produces migration 017_rich_catalog_content.

- [ ] Step 1: Add the failing contract test.

~~~javascript
test('rich catalog migration is registered after profile locations', () => {
  const source = read('lib/migrations.php');
  assert.ok(source.indexOf('017_rich_catalog_content') > source.indexOf('016_profile_locations'));
});

test('rich catalog migration names every required field', () => {
  const source = read('database/migrations/017_rich_catalog_content.php');
  for (const field of [
    'description', 'hero_image', 'image_path', 'category',
    'prep_time_minutes', 'calories', 'dietary_tags', 'allergens',
    'ingredients', 'sort_order', 'demo_key'
  ]) assert.match(source, new RegExp(field));
});
~~~

- [ ] Step 2: Run the focused test and verify it fails.

~~~powershell
& 'C:\Users\CHI NGUYEN\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe' --test .\tests\rich_catalog_contract.test.js
~~~

Expected: FAIL because migration 017 does not exist or is not registered.

- [ ] Step 3: Implement the idempotent migration.

Use information_schema.COLUMNS before each ALTER TABLE. Add:

~~~text
restaurants.description VARCHAR(600) NULL
restaurants.hero_image VARCHAR(255) NULL
restaurants.demo_key VARCHAR(80) NULL UNIQUE
menu_items.description VARCHAR(600) NULL
menu_items.image_path VARCHAR(255) NULL
menu_items.category VARCHAR(80) NULL
menu_items.prep_time_minutes SMALLINT UNSIGNED NULL
menu_items.calories SMALLINT UNSIGNED NULL
menu_items.dietary_tags VARCHAR(255) NULL
menu_items.allergens VARCHAR(255) NULL
menu_items.ingredients VARCHAR(600) NULL
menu_items.sort_order INT NOT NULL DEFAULT 0
~~~

Throw RuntimeException when an existing column has an incompatible type or nullability. The migration must not insert catalog rows.

- [ ] Step 4: Register and verify migration 017.

~~~powershell
& 'D:\Xampp\php\php.exe' -l .\database\migrations\017_rich_catalog_content.php
& 'C:\Users\CHI NGUYEN\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe' --test .\tests\rich_catalog_contract.test.js
~~~

Expected: PHP lint and the focused test PASS.

- [ ] Step 5: Commit.

~~~powershell
git add database/migrations/017_rich_catalog_content.php lib/migrations.php tests/rich_catalog_contract.test.js
git commit -m "feat: add rich catalog content fields"
~~~

### Task 2: Return rich catalog data to Customer

**Files**

- Modify: lib/repositories/catalog_repository.php
- Modify: js/customer_catalog.js
- Modify: product_detail.php
- Test: tests/rich_catalog_mapping.test.js

**Interfaces**

- Consumes the columns from Task 1.
- Produces item fields image, description, category, prepTime, calories, dietaryTags, allergens, ingredients, sortOrder and restaurant fields description, heroImage, rating, prepTimeMinutes.

- [ ] Step 1: Add the failing mapper test.

~~~javascript
test('Customer mapper preserves rich server fields', () => {
  const catalog = require('../js/customer_catalog.js');
  const item = catalog.itemFromRecord({
    publicId: 'demo-lotus-rare-beef-pho',
    name: 'Rare Beef Pho',
    basePrice: 12.5,
    image: 'assets/images/catalog/lotus-rare-beef-pho.jpg',
    description: 'Slow-simmered beef broth with rice noodles and herbs.',
    category: 'Vietnamese Noodles',
    prepTimeMinutes: 18,
    calories: 640,
    dietaryTags: ['Dairy-free'],
    allergens: ['Fish sauce'],
    ingredients: ['Rice noodles', 'Beef broth', 'Rare beef', 'Thai basil'],
    restaurant: {
      id: 11, name: 'Lotus Kitchen', cuisine: 'Vietnamese',
      description: 'Vietnamese comfort food.', rating: 4.8,
      prepTimeMinutes: 32,
      heroImage: 'assets/images/catalog/lotus-rare-beef-pho.jpg'
    },
    optionGroups: []
  });
  assert.equal(item.image, 'assets/images/catalog/lotus-rare-beef-pho.jpg');
  assert.equal(item.description, 'Slow-simmered beef broth with rice noodles and herbs.');
  assert.deepEqual(item.ingredients, ['Rice noodles', 'Beef broth', 'Rare beef', 'Thai basil']);
  assert.equal(item.restaurantDescription, 'Vietnamese comfort food.');
});
~~~

- [ ] Step 2: Run it and verify the current hard-coded empty image/description fails.

~~~powershell
& 'C:\Users\CHI NGUYEN\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe' --test .\tests\rich_catalog_mapping.test.js
~~~

- [ ] Step 3: Extend repository SELECT statements and map comma-separated fields.

Use prepared SQL. Return image only when it matches the local catalog path pattern; otherwise return an empty string so the existing SVG placeholder remains safe. Map dietary_tags, allergens, and ingredients to trimmed arrays.

- [ ] Step 4: Extend itemFromRecord() with safe defaults.

Use Prepared to order when prep_time_minutes is absent, zero for missing calories, empty arrays for missing lists, and the cuisine-derived category only when category is absent. Preserve the existing optionGroups/portions/addOns behavior.

- [ ] Step 5: Render rich details.

Keep the existing Customer layout. Populate product-tags with dietary/allergen tags and product-ingredient-list with DOM li nodes. Use the server restaurant description when available and retain the generic fallback for legacy records.

- [ ] Step 6: Verify and commit.

~~~powershell
& 'C:\Users\CHI NGUYEN\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe' --test .\tests\rich_catalog_mapping.test.js .\tests\catalog_cutover.test.js .\tests\customer_markup.test.js
& 'D:\Xampp\php\php.exe' -l .\lib\repositories\catalog_repository.php
git add lib/repositories/catalog_repository.php js/customer_catalog.js product_detail.php tests/rich_catalog_mapping.test.js
git commit -m "feat: expose rich catalog content to customers"
~~~

### Task 3: Seed six restaurants and 48 English menu items

**Files**

- Create: database/seeds/catalog_demo_data.json
- Create: lib/catalog_demo_seed.php
- Modify: scripts/seed.php
- Modify: lib/platform_schema.php
- Modify: tests/finance_repository_test.php
- Modify: tests/task7_browser_qa.mjs
- Test: tests/catalog_demo_seed_test.php

**Interfaces**

- Consumes the Task 1 schema and existing platform seed.
- Produces catalog_demo_data(): array and catalog_demo_seed(mysqli $conn): void; both read the same JSON source.

- [ ] Step 1: Define the exact English catalog.

Use stable restaurant keys and eight stable item slugs per restaurant:

~~~text
lotus-kitchen:
  rare-beef-pho, grilled-pork-bun-cha, broken-rice-pork-chop, lemongrass-chicken-banh-mi,
  shrimp-summer-rolls, crispy-spring-rolls, vietnamese-iced-coffee, peach-lemongrass-tea
saigon-ember-grill:
  saigon-pork-vermicelli, lemongrass-beef-skewers, caramelized-claypot-fish, turmeric-dill-fish,
  lemongrass-chicken-wings, crispy-shrimp-toast, salted-lime-soda, pandan-coconut-smoothie
hoi-an-garden:
  hoi-an-cao-lau, quang-style-shrimp-noodles, turmeric-fish-rice, lemongrass-tofu-bowl,
  mushroom-banh-xeo, green-papaya-jackfruit-salad, lotus-seed-tea, calamansi-sparkler
mekong-bowl-tea:
  grilled-pork-rice-bowl, lemongrass-beef-rice-bowl, vermicelli-chicken-bowl, five-spice-tofu-bowl,
  green-papaya-salad, crispy-shrimp-toast, vietnamese-egg-coffee, passionfruit-iced-tea
tokyo-kumo:
  salmon-sushi-set, tonkotsu-ramen, chicken-katsu-curry, chicken-karaage,
  vegetable-gyoza, edamame, matcha-latte, yuzu-sparkling-soda
roma-verde:
  truffle-mushroom-pasta, margherita-pizza, chicken-pesto-penne, beef-lasagna,
  burrata-tomato-salad, tiramisu, classic-italian-soda, iced-espresso
~~~

Store the complete records in database/seeds/catalog_demo_data.json so PHP seeding and Node asset tests share one source. Every record must have English name, description, USD price, category, prep time, calories, ingredients, allergens, dietary tags, local image path, availability, and sort order. Every restaurant must have an English description, fictional Central City address/phone, rating, active status, accepting orders, and hero image. Use public IDs in the form demo-restaurant-key-item-slug.

- [ ] Step 2: Add the failing integration test.

Run the seed twice against only SAVORA_ENV=test and SAVORA_DB_NAME=savora_test. Assert six rows with demo_key, 48 demo items, eight items per demo restaurant, all active/available, non-empty English descriptions, and unchanged unrelated fixture rows.

~~~powershell
$env:SAVORA_ENV='test'
$env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' .\scripts\migrate.php
& 'D:\Xampp\php\php.exe' .\tests\catalog_demo_seed_test.php
Remove-Item Env:SAVORA_ENV,Env:SAVORA_DB_NAME -ErrorAction SilentlyContinue
~~~

Expected: FAIL because catalog_demo_seed() does not exist.

- [ ] Step 3: Implement the repeatable seed transaction.

Require database/seeds/catalog_demo_data.json from lib/catalog_demo_seed.php, validate that it contains six restaurants and eight items per restaurant, then create or reuse six demo owner users. Use prepared INSERT ... ON DUPLICATE KEY UPDATE statements keyed by demo_key/public_id, create weekly hours for every day, and update only rows carrying the demo marker. Store lists as comma-separated UTF-8 values. Keep all seed copy English-only and use filenames from the exact slug list.

Require lib/catalog_demo_seed.php before platform_seed_operations(), call catalog_demo_seed($conn) at the beginning of platform_seed_operations() after the base users are inserted, and then resolve the first rich demo restaurant for existing order/ledger/payout fixtures. Remove the old hard-coded Savora Burger/four-item catalog block. Do not delete unrelated rows.

- [ ] Step 4: Update obsolete fixture assumptions.

Change finance_repository_test.php to resolve the demo restaurant by demo_key rather than the name Savora Burger. Update task7_browser_qa.mjs to use stable rich item IDs and English names rather than the old four-item names.

- [ ] Step 5: Verify and commit.

~~~powershell
$env:SAVORA_ENV='test'
$env:SAVORA_DB_NAME='savora_test'
$env:SAVORA_SEED_DEMO='1'
& 'D:\Xampp\php\php.exe' .\tests\catalog_demo_seed_test.php
& 'D:\Xampp\php\php.exe' .\tests\catalog_service_test.php
& 'D:\Xampp\php\php.exe' .\tests\finance_repository_test.php
Remove-Item Env:SAVORA_ENV,Env:SAVORA_DB_NAME,Env:SAVORA_SEED_DEMO -ErrorAction SilentlyContinue
git add database/seeds/catalog_demo_data.json lib/catalog_demo_seed.php scripts/seed.php lib/platform_schema.php tests/catalog_demo_seed_test.php tests/finance_repository_test.php tests/task7_browser_qa.mjs
git commit -m "feat: seed diverse English Savora catalog"
~~~

### Task 4: Generate and validate 48 local menu images

**Files**

- Create: 48 .jpg files under assets/images/catalog/
- Test: tests/catalog_assets.test.js

**Interfaces**

- Consumes the exact image paths in catalog_demo_data().
- Produces local food-photography assets for every menu item.

- [ ] Step 1: Add an asset contract test.

The test must load database/seeds/catalog_demo_data.json, assert 48 items, require paths matching assets/images/catalog/[a-z0-9-]+.jpg, and verify every path exists and is non-empty.

- [ ] Step 2: Generate assets with the built-in ImageGen tool.

Use one generation call per distinct item. Prompt for premium editorial food photography, appetizing natural light, crop-safe composition, no text, logo, watermark, people, or unrelated objects. Save the selected final image under the exact slug filename in assets/images/catalog/.

- [ ] Step 3: Inspect representative outputs.

Use view_image on Vietnamese food, Vietnamese beverages, Japanese food, Italian food, and a restaurant signature image. Reject outputs with wrong dishes, visible text/watermarks, unrelated objects, or poor card cropping.

- [ ] Step 4: Run asset and existing Customer tests.

~~~powershell
& 'C:\Users\CHI NGUYEN\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe' --test .\tests\catalog_assets.test.js .\tests\customer_markup.test.js
~~~

- [ ] Step 5: Commit the assets.

~~~powershell
git add assets/images/catalog tests/catalog_assets.test.js
git commit -m "feat: add Savora menu photography assets"
~~~

### Task 5: Verify Customer Home and apply the development seed

**Files**

- Modify only if required: customer_dashboard.php, product_detail.php, css/customer_style.css
- Test: tests/rich_catalog_browser_contract.test.js

**Interfaces**

- Consumes the enriched API records and local assets from Tasks 2-4.
- Produces non-empty Customer restaurant/dish grids, beverage categories, valid images, and rich product detail.

- [ ] Step 1: Add the failing browser/source contract.

~~~javascript
test('Customer discovery renders server rich content', () => {
  const dashboard = read('customer_dashboard.php');
  const detail = read('product_detail.php');
  assert.match(dashboard, /await catalog\.hydrate\(\)/);
  assert.match(dashboard, /SavoraCatalog\.imageFor/);
  assert.match(detail, /product-ingredient-list/);
  assert.match(detail, /product-tags/);
  assert.doesNotMatch(dashboard + detail, /innerHTML\s*=/);
});
~~~

- [ ] Step 2: Keep the existing card layout and add only missing presentation.

Use the first item image as the restaurant card fallback, display rating/cuisine/preparation metadata, and populate product tags/ingredients with DOM nodes. Do not add a local catalog fallback or change the Customer API boundary.

- [ ] Step 3: Run the focused and complete JavaScript suites.

~~~powershell
& 'C:\Users\CHI NGUYEN\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe' --test .\tests\rich_catalog_browser_contract.test.js .\tests\customer_markup.test.js .\tests\catalog_cutover.test.js
$tests = Get-ChildItem .\tests\*.test.js | Sort-Object FullName | Select-Object -ExpandProperty FullName
& 'C:\Users\CHI NGUYEN\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe' --test $tests
~~~

- [ ] Step 4: Populate savora_db.

~~~powershell
$env:SAVORA_ENV='development'
$env:SAVORA_SEED_DEMO='1'
& 'D:\Xampp\php\php.exe' .\scripts\migrate.php
& 'D:\Xampp\php\php.exe' .\scripts\seed.php
~~~

- [ ] Step 5: Verify exact development counts.

~~~powershell
& 'D:\Xampp\mysql\bin\mysql.exe' -h 127.0.0.1 -P 3307 -u root savora_db -e "SELECT COUNT(*) AS demo_restaurants FROM restaurants WHERE demo_key IS NOT NULL; SELECT r.name,COUNT(m.id) AS menu_items FROM restaurants r LEFT JOIN menu_items m ON m.restaurant_id=r.id WHERE r.demo_key IS NOT NULL GROUP BY r.id,r.name ORDER BY r.name; SELECT COUNT(*) AS demo_items FROM menu_items WHERE public_id LIKE 'demo-%' AND is_available=1;"
~~~

Expected: six demo restaurants, eight items for every restaurant, and 48 available items.

- [ ] Step 6: Run PHP lint and catalog integration checks.

~~~powershell
$files = @('lib/catalog_demo_seed.php','database/migrations/017_rich_catalog_content.php','lib/repositories/catalog_repository.php','scripts/seed.php','customer_dashboard.php','product_detail.php')
foreach ($file in $files) { & 'D:\Xampp\php\php.exe' -l $file; if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $file" } }
$env:SAVORA_ENV='test'
$env:SAVORA_DB_NAME='savora_test'
& 'D:\Xampp\php\php.exe' .\tests\catalog_demo_seed_test.php
& 'D:\Xampp\php\php.exe' .\tests\catalog_service_test.php
& 'D:\Xampp\php\php.exe' .\tests\catalog_api_endpoint_test.php
Remove-Item Env:SAVORA_ENV,Env:SAVORA_DB_NAME,Env:SAVORA_SEED_DEMO -ErrorAction SilentlyContinue
~~~

- [ ] Step 7: Smoke test Customer Home.

Open customer_dashboard.php and verify six restaurant cards, 48 dish cards, Vietnamese/Japanese/Italian and beverage categories, local images, search, product detail, ingredient/tags/calories, and add-to-cart.

- [ ] Step 8: Final repository check.

~~~powershell
git status --short
git diff --check
~~~

Do not stage .sessions, screenshots, logs, or unrelated user files.
