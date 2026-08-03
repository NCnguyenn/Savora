'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('customer Home renders a restaurant-first API-backed overview with trusted local catalog images', () => {
  const dashboard = read('customer_dashboard.php');
  const home = read('js/customer_home.js');
  const catalog = read('js/customer_catalog.js');
  const endpoint = read('api/catalog.php');

  assert.match(dashboard, /await catalog\.hydrate\(\)/);
  assert.match(dashboard, /id="featured-restaurants-grid"/);
  assert.match(dashboard, /id="popular-food-grid"/);
  assert.match(dashboard, /id="popular-drink-grid"/);
  assert.doesNotMatch(dashboard, /id="food-products-grid"|id="restaurant-grid"/);
  assert.match(home, /Object\.values\(catalog\.products/);
  assert.match(home, /catalog\.imageFor\(item\)/);
  assert.match(home, /catalog\.logoFor\(restaurant\)/);
  assert.match(home, /customer_restaurant\.php\?restaurant=/);
  assert.match(home, /filterOptions/);
  assert.match(catalog, /source\.imagePath/);
  assert.match(catalog, /source\.description/);
  assert.match(catalog, /heroImage/);
  assert.match(catalog, /assets\\\/images\\\/catalog/);
  assert.match(endpoint, /catalog_for_customer/);
  assert.doesNotMatch(`${dashboard}\n${home}`, /baseProducts|baseRestaurants|seedCatalog/);
});

test('customer catalog keeps remote or unsafe image paths on the placeholder', () => {
  const catalog = require(path.join(root, 'js/customer_catalog.js'));
  assert.equal(catalog.imageFor({ image: 'https://attacker.invalid/menu.jpg' }), catalog.placeholderImage);
  assert.equal(catalog.imageFor({ image: 'assets/images/catalog/../unsafe.jpg' }), catalog.placeholderImage);
  assert.match(catalog.imageFor({ image: 'assets/images/catalog/demo-lotus-kitchen-rare-beef-pho.jpg' }), /demo-lotus-kitchen-rare-beef-pho\.jpg$/);
});
