'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const menu = require('../js/restaurant_menu.js');

test('restaurant menu maps the server catalog fields used by customer cards', () => {
  const item = menu.mapServerItem({
    publicId: 'demo-lotus-pho',
    name: 'Rare Beef Pho',
    basePrice: 12.5,
    available: true,
    version: 3,
    description: 'Slow-simmered beef broth with rice noodles.',
    imagePath: 'assets/images/catalog/demo-lotus-pho.jpg',
    category: 'Noodle Soup',
    itemType: 'food',
    prepTimeMinutes: 18,
    calories: 540,
    dietaryTags: ['High Protein'],
    allergens: ['Fish Sauce'],
    ingredients: ['Rice noodles', 'Beef broth'],
    optionGroups: []
  }, { cuisine: 'Vietnamese' });

  assert.equal(item.id, 'demo-lotus-pho');
  assert.equal(item.description, 'Slow-simmered beef broth with rice noodles.');
  assert.equal(item.image, 'assets/images/catalog/demo-lotus-pho.jpg');
  assert.equal(item.category, 'Noodle Soup');
  assert.equal(item.itemType, 'food');
  assert.equal(item.prepTimeMinutes, 18);
  assert.equal(item.prepTime, '18 min');
  assert.deepEqual(item.dietaryTags, ['High Protein']);
  assert.deepEqual(item.ingredients, ['Rice noodles', 'Beef broth']);
});

test('restaurant menu falls back safely for missing or unsafe catalog metadata', () => {
  const item = menu.mapServerItem({
    publicId: 'demo-item', name: 'Menu item', basePrice: 4, available: false, version: 1,
    imagePath: 'https://attacker.invalid/menu.jpg', optionGroups: []
  }, {});

  assert.equal(item.image, menu.PLACEHOLDER_IMAGE);
  assert.equal(item.category, 'Menu');
  assert.equal(item.itemType, 'food');
  assert.equal(item.prepTime, 'Prepared to order');
  assert.deepEqual(item.dietaryTags, []);
});
