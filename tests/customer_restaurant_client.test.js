'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const storefront = require('../js/customer_restaurant.js');

const items = [
  { id: 'pho', name: 'Rare Beef Pho', itemType: 'food', category: 'noodles', categoryLabel: 'Regional Noodles', description: 'A fragrant broth.' },
  { id: 'tea', name: 'Lotus Tea', itemType: 'drink', category: 'tea', categoryLabel: 'Tea and Coffee', description: 'A floral iced tea.' },
  { id: 'rice', name: 'Claypot Rice', itemType: 'food', category: 'rice', categoryLabel: 'Rice Plates', description: 'Smoky and comforting.' }
];

test('storefront filters food, drinks, categories, and search without cross-restaurant leakage', () => {
  assert.deepEqual(storefront.filterItems(items, 'all', '').map(item => item.id), ['pho', 'tea', 'rice']);
  assert.deepEqual(storefront.filterItems(items, 'food', '').map(item => item.id), ['pho', 'rice']);
  assert.deepEqual(storefront.filterItems(items, 'drinks', '').map(item => item.id), ['tea']);
  assert.deepEqual(storefront.filterItems(items, 'tea', '').map(item => item.id), ['tea']);
  assert.deepEqual(storefront.filterItems(items, 'all', 'smoky').map(item => item.id), ['rice']);
});

test('storefront presents safe promotion copy for percentage and fixed discounts', () => {
  assert.equal(storefront.promotionCopy({ code: 'SAVORA10', discountType: 'percentage', discountValue: 10, minimumOrder: 20 }), '10% off orders over $20.00 with code SAVORA10');
  assert.equal(storefront.promotionCopy({ code: 'BOWL5', discountType: 'fixed', discountValue: 5, minimumOrder: 0 }), '$5.00 off with code BOWL5');
});

test('storefront labels open, closed, and unavailable weekly hours', () => {
  const hours = [
    { weekday: 1, opens_at: '09:00:00', closes_at: '22:00:00', is_closed: 0 },
    { weekday: 2, opens_at: '09:00:00', closes_at: '22:00:00', is_closed: 1 }
  ];
  assert.equal(storefront.statusLabel(hours, new Date('2026-08-03T12:00:00')), 'Open today until 10:00 PM');
  assert.equal(storefront.statusLabel(hours, new Date('2026-08-04T12:00:00')), 'Closed today');
  assert.equal(storefront.statusLabel([], new Date('2026-08-03T12:00:00')), 'Hours unavailable');
});
