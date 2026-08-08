'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('product selections use option public ids and cart lines retain their persisted image before hydration', () => {
  assert.match(read('product_detail.php'), /id:\s*portion\.id/);
  assert.match(read('customer_cart.php'), /imageFor\(catalogProduct \|\| line\)/);
  assert.match(read('js/customer_ui.js'), /productImage\(catalogProduct \|\| line\)/);
});

test('the full cart refreshes from the catalog and reports the one-restaurant checkout boundary', () => {
  const cart = read('customer_cart.php');
  const product = read('product_detail.php');
  assert.match(cart, /SavoraCatalog\.hydrate\(\)\.then\(renderFullCart\)/);
  assert.match(product, /A cart can contain items from one restaurant only/);
  assert.match(product, /catch \(error\)/);
});
