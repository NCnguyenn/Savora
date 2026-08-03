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

test('hydrated catalog derives categories before the discovery renderer reads them', () => {
  const catalog = require(path.join(root, 'js/customer_catalog.js'));
  const home = read('js/customer_home.js');
  catalog.replaceRecords([{ publicId: 'ramen-1', name: 'Ramen', basePrice: 12, available: true, restaurant: { id: 5, name: 'Noodle House', cuisine: 'Japanese' }, optionGroups: [] }]);

  assert.deepEqual(catalog.categories, [{ id: 'japanese', label: 'Japanese' }]);
  assert.match(home, /filterOptions/);
  assert.match(home, /categoryLabel/);
});

test('menu editor preserves server option groups and choice public ids on an unchanged edit', () => {
  const menu = require(path.join(root, 'js/restaurant_menu.js'));
  const source = [{
    name: 'Choose a size', selectionType: 'single', minimumChoices: 1, maximumChoices: 1, sortOrder: 0,
    optionChoices: [{ publicId: 'size-regular', name: 'Regular', priceDelta: 0, available: true, sortOrder: 0 }]
  }];
  const groups = menu.editorGroupsFromServer(source);
  const payload = menu.serverPayload({ id: 'ramen-1', name: 'Ramen', price: 12, available: true, optionGroups: groups, addOns: [] }, 3);

  assert.deepEqual(groups, [{ name: 'Choose a size', required: true, selectionType: 'single', minimumChoices: 1, maximumChoices: 1, sortOrder: 0, options: [{ publicId: 'size-regular', label: 'Regular', price: 0, available: true, sortOrder: 0 }] }]);
  assert.equal(payload.optionGroups[0].choices[0].publicId, 'size-regular');
  assert.equal(payload.optionGroups[0].choices[0].available, true);
});

test('menu editor preserves multiple add-on semantics when serializing an unchanged edit', () => {
  const menu = require(path.join(root, 'js/restaurant_menu.js'));
  const editor = menu.editorDataFromServer([{
    name: 'Add-ons', selectionType: 'multiple', minimumChoices: 0, maximumChoices: 2, sortOrder: 1,
    optionChoices: [
      { publicId: 'addon-egg', name: 'Egg', priceDelta: 1, available: true, sortOrder: 0 },
      { publicId: 'addon-tofu', name: 'Tofu', priceDelta: 2, available: true, sortOrder: 1 }
    ]
  }]);
  const payload = menu.serverPayload({ id: 'ramen-1', name: 'Ramen', price: 12, available: true, optionGroups: editor.optionGroups, addOns: editor.addOns }, 3);
  const addOns = payload.optionGroups[0];

  assert.equal(addOns.selectionType, 'multiple');
  assert.equal(addOns.minimumChoices, 0);
  assert.equal(addOns.maximumChoices, 2);
  assert.deepEqual(addOns.choices.map(choice => choice.publicId), ['addon-egg', 'addon-tofu']);
});

test('Restaurant shell has no local catalog writer and unsupported storefront fields are absent', () => {
  const shell = read('js/restaurant_ui.js');
  const profile = read('restaurant_profile.php');
  const operations = read('restaurant_operations.php');
  const storefront = read('js/restaurant_storefront.js');

  assert.doesNotMatch(shell, /setOperations|state\.profile|state\.operations/);
  for (const unsupported of ['profile-description', 'profile-image', 'delivery-radius', 'minimum-order', 'profile-prep-minutes', 'prep-minutes', 'capacity', 'delivery-enabled', 'pickup-enabled', 'pickup-instructions']) {
    assert.doesNotMatch(`${profile}\n${operations}`, new RegExp(`name="${unsupported}"`));
  }
  assert.doesNotMatch(storefront, /deliveryRadius: 5|prepMinutes: 20|capacity: 20|deliveryEnabled: true|pickupEnabled: true/);
});

test('catalog intent keys are cleared only after the authoritative refresh succeeds', () => {
  for (const file of ['js/restaurant_menu.js', 'js/restaurant_storefront.js']) {
    const source = read(file);
    const command = source.indexOf('await root.SavoraApi.post');
    const refresh = Math.max(source.indexOf('await loadSnapshot', command), source.indexOf('await hydrate', command));
    const clear = source.indexOf('clearIntentKey(scope)', command);
    assert.ok(command >= 0 && refresh > command && clear > refresh, `${file} must refresh before clearing its intent key`);
  }
});

function deepEqualErrors(value) {
  return value && typeof value === 'object' && Object.keys(value).length === 0;
}
