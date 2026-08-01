'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('migrated catalog pages hydrate from the catalog API with no authoritative local catalog fixtures or writers', () => {
  const catalog = read('js/customer_catalog.js');
  const menu = read('js/restaurant_menu.js');
  const storefront = read('js/restaurant_storefront.js');
  const restaurantState = read('js/restaurant_state.js');
  const endpoint = read('api/catalog.php');

  assert.match(catalog, /api\/catalog\.php/);
  assert.match(menu, /SavoraApi\.get\('api\/catalog\.php\?scope=restaurant'\)/);
  assert.match(menu, /SavoraApi\.post\('api\/catalog\.php'/);
  assert.match(storefront, /SavoraApi\.post\('api\/catalog\.php'/);
  assert.match(endpoint, /catalog_for_restaurant/);
  assert.doesNotMatch(catalog, /baseProducts|baseRestaurants|applyRestaurantOverrides/);
  assert.doesNotMatch(restaurantState, /setMenuItem|setItemAvailability|setProfile|setOperations/);

  for (const page of [
    'customer_dashboard.php', 'product_detail.php', 'restaurant_menu.php',
    'restaurant_menu_item.php', 'restaurant_profile.php', 'restaurant_operations.php'
  ]) assert.match(read(page), /js\/api_client\.js/);
});

test('API client requires a caller-owned stable key and preserves it across retries', async () => {
  const clientPath = path.join(root, 'js/api_client.js');
  assert.ok(fs.existsSync(clientPath), 'Task 8 must provide the shared API client.');
  if (!fs.existsSync(clientPath)) return;

  const client = require(clientPath);
  const calls = [];
  global.fetch = async (url, options) => {
    calls.push({ url, options });
    return { ok: true, status: 200, json: async () => ({ ok: true, data: { saved: true } }) };
  };
  global.SavoraCsrfToken = 'csrf-test-token';

  await assert.rejects(client.post('api/catalog.php', {}, ''), /stable intent key/i);
  await client.post('api/catalog.php', { action: 'save_item' }, 'intent-menu-42');
  await client.post('api/catalog.php', { action: 'save_item' }, 'intent-menu-42');

  assert.equal(calls.length, 2);
  for (const call of calls) {
    assert.equal(call.options.credentials, 'same-origin');
    assert.equal(call.options.headers['Content-Type'], 'application/json');
    assert.equal(call.options.headers['X-CSRF-Token'], 'csrf-test-token');
    assert.equal(call.options.headers['Idempotency-Key'], 'intent-menu-42');
  }
});

test('API client normalizes a rejected server command without producing local success data', async () => {
  const clientPath = path.join(root, 'js/api_client.js');
  assert.ok(fs.existsSync(clientPath), 'Task 8 must provide the shared API client.');
  if (!fs.existsSync(clientPath)) return;

  const client = require(clientPath);
  global.SavoraCsrfToken = 'csrf-test-token';
  global.fetch = async () => ({
    ok: false,
    status: 409,
    json: async () => ({ ok: false, message: 'Stale record.', errors: { version: 'refresh' }, referenceId: 'ref-42' })
  });

  await assert.rejects(
    client.post('api/catalog.php', { action: 'save_operations' }, 'intent-operations-42'),
    error => error.status === 409 && error.errors.version === 'refresh' && error.referenceId === 'ref-42'
  );
});

test('API client normalizes a network rejection without producing local success data', async () => {
  const client = require(path.join(root, 'js/api_client.js'));
  global.SavoraCsrfToken = 'csrf-test-token';
  global.fetch = async () => { throw new TypeError('Network unavailable'); };

  await assert.rejects(
    client.post('api/catalog.php', { action: 'save_profile' }, 'intent-profile-42'),
    error => error.status === 0 && deepEqualErrors(error.errors) && error.referenceId === ''
  );
});

function deepEqualErrors(value) {
  return value && typeof value === 'object' && Object.keys(value).length === 0;
}
