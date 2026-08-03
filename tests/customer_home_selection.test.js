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
