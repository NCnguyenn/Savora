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

test('dedicated Customer restaurant page exposes brand, offers, hours, and split menu landmarks', () => {
  const page = read('customer_restaurant.php');
  const client = read('js/customer_restaurant.js');
  for (const id of ['storefront-name', 'storefront-slogan', 'storefront-address', 'storefront-hours-list', 'storefront-offers', 'storefront-active-order', 'storefront-food-grid', 'storefront-drink-grid']) {
    assert.match(page, new RegExp(`id="${id}"`));
  }
  assert.match(client, /api\/restaurant_storefront\.php\?restaurant=/);
  assert.match(client, /item\.itemType === 'food'/);
  assert.match(client, /item\.itemType === 'drink'/);
  assert.doesNotMatch(client, /innerHTML\s*=/);
});

test('restaurant entry points use storefront links and no menu modal remains', () => {
  const favorites = read('customer_favorites.php');
  const product = read('product_detail.php');
  const footer = read('components/customer_footer.php');
  const ui = read('js/customer_ui.js');
  assert.match(favorites, /customer_restaurant\.php\?restaurant=/);
  assert.match(product, /customer_restaurant\.php\?restaurant=/);
  assert.doesNotMatch([favorites, footer, ui].join('\n'), /openMenuModal|menu-modal|modal-food-grid/);
});
