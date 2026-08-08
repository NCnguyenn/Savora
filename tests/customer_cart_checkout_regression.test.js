'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const Catalog = require('../js/customer_catalog.js');

test('menu items without option groups do not invent a server-invalid regular option', () => {
  const item = Catalog.itemFromRecord({
    publicId: 'egg-coffee',
    name: 'Vietnamese Egg Coffee',
    basePrice: 6,
    restaurant: { id: 4, name: 'Mekong Bowl and Tea' },
    optionGroups: []
  });
  assert.deepEqual(item.portions, []);
});

test('product selections use option public ids and cart lines retain their persisted image before hydration', () => {
  assert.match(read('product_detail.php'), /id:\s*portion\.id/);
  assert.match(read('customer_cart.php'), /imageFor\(catalogProduct \|\| line\)/);
  assert.match(read('js/customer_ui.js'), /productImage\(catalogProduct \|\| line\)/);
});

test('the full cart refreshes from the catalog and reports the one-restaurant checkout boundary', () => {
  const cart = read('customer_cart.php');
  const product = read('product_detail.php');
  assert.match(cart, /SavoraCatalog\.hydrate\(\)\.then\(\(\) => \{[\s\S]*renderFullCart\(\);\s*uiApi\(\)\.refreshChrome\(\);/);
  assert.match(product, /if \(portion\) selected\.push\(\{ id: portion\.id/);
  assert.match(read('customer_checkout.php'), /await catalog\.hydrate\(\);[\s\S]*reconcileCart/);
  assert.match(product, /A cart can contain items from one restaurant only/);
  assert.match(product, /catch \(error\)/);
});

test('shared customer scripts use file-versioned URLs so browser cannot mix JS revisions', () => {
  const footer = read('components/customer_footer.php');
  for (const script of ['js/customer_catalog.js', 'js/customer_state.js', 'js/customer_ui.js']) {
    const escaped = script.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    assert.match(footer, new RegExp(`${escaped}\\?v=<\\?php echo \\$customer_asset_version\\('${escaped}'\\); \\?>`));
  }
});
