'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('repository selects and maps rich restaurant and menu presentation fields', () => {
  const repository = read('lib/repositories/catalog_repository.php');
  for (const field of ['r.description', 'r.hero_image', 'r.rating', 'm.description', 'm.image_path', 'm.category', 'm.prep_time_minutes', 'm.calories', 'm.dietary_tags', 'm.allergens', 'm.ingredients', 'm.sort_order']) {
    assert.match(repository, new RegExp(field.replace('.', '\\.') + '[\\s,)]'));
  }
  assert.match(repository, /'description'\s*=>/);
  assert.match(repository, /'heroImage'\s*=>/);
  assert.match(repository, /'imagePath'\s*=>/);
  assert.match(repository, /'prepTimeMinutes'\s*=>/);
  assert.match(repository, /'dietaryTags'\s*=>/);
  assert.match(repository, /'ingredients'\s*=>/);
});

test('customer catalog preserves rich metadata and validates local image paths', () => {
  const catalog = require(path.join(root, 'js/customer_catalog.js'));
  const item = catalog.itemFromRecord({
    publicId: 'demo-lotus-kitchen-rare-beef-pho',
    name: 'Rare Beef Pho',
    basePrice: 12.5,
    description: 'A fragrant bowl with slow-simmered beef broth.',
    imagePath: 'assets/images/catalog/demo-lotus-kitchen-rare-beef-pho.jpg',
    category: 'Noodle Soup',
    prepTimeMinutes: 18,
    calories: 540,
    dietaryTags: ['High Protein'],
    allergens: ['Fish Sauce'],
    ingredients: ['Rice noodles', 'Beef broth'],
    restaurant: {
      id: 7,
      name: 'Lotus Kitchen',
      cuisine: 'Vietnamese',
      description: 'A bright Vietnamese kitchen built around comforting bowls.',
      heroImage: 'assets/images/catalog/demo-lotus-kitchen.jpg',
      rating: 4.8
    }
  });

  assert.equal(item.description, 'A fragrant bowl with slow-simmered beef broth.');
  assert.equal(item.image, 'assets/images/catalog/demo-lotus-kitchen-rare-beef-pho.jpg');
  assert.equal(item.category, 'noodle-soup');
  assert.equal(item.prepTimeMinutes, 18);
  assert.equal(item.prepTime, '18 min');
  assert.equal(item.calories, 540);
  assert.deepEqual(item.dietaryTags, ['High Protein']);
  assert.deepEqual(item.allergens, ['Fish Sauce']);
  assert.deepEqual(item.ingredients, ['Rice noodles', 'Beef broth']);
  assert.equal(catalog.imageFor(item), item.image);

  const restaurant = catalog.replaceRecords([{
    publicId: 'demo-lotus-kitchen-rare-beef-pho',
    name: 'Rare Beef Pho',
    basePrice: 12.5,
    category: 'Noodle Soup',
    prepTimeMinutes: 18,
    restaurant: {
      id: 7,
      name: 'Lotus Kitchen',
      cuisine: 'Vietnamese',
      description: 'A bright Vietnamese kitchen built around comforting bowls.',
      heroImage: 'assets/images/catalog/demo-lotus-kitchen.jpg',
      rating: 4.8
    }
  }]).restaurants['Lotus Kitchen'];
  assert.equal(restaurant.description, 'A bright Vietnamese kitchen built around comforting bowls.');
  assert.equal(restaurant.heroImage, 'assets/images/catalog/demo-lotus-kitchen.jpg');
  assert.equal(restaurant.rating, '4.8');
});

test('product details render restaurant description, tags, and ingredient metadata', () => {
  const page = read('product_detail.php');
  assert.match(page, /setText\('restaurant-description',\s*restaurant\.description/);
  assert.match(page, /item\.dietaryTags/);
  assert.match(page, /item\.allergens/);
  assert.match(page, /item\.ingredients/);
});
