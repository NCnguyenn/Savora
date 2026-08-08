const test = require('node:test');
const assert = require('node:assert/strict');
const State = require('../js/customer_state.js');

test('normalizes malformed persisted cart data without copying unsafe fields', () => {
  const state = State.normalize({
    cart: [{ id: '1', quantity: '2', note: '<img src=x onerror=alert(1)>', onerror: 'unsafe' }],
    wallet: { balance: '20.5' }, orders: [{ id: 'legacy-order' }]
  });
  assert.equal(state.cart[0].quantity, 2);
  assert.equal(state.cart[0].note, '<img src=x onerror=alert(1)>');
  assert.equal(Object.hasOwn(state, 'wallet'), false);
  assert.equal(Object.hasOwn(state, 'orders'), false);
  assert.equal(Object.hasOwn(state.cart[0], 'onerror'), false);
});

test('Customer state exposes only draft cart helpers and no authoritative money/order writers', () => {
  assert.equal(typeof State.addCartLine, 'function');
  assert.equal(typeof State.removeCartLine, 'function');
  assert.equal(typeof State.updateCartQuantity, 'function');
  for (const name of ['placeDemoOrder', 'topUpWallet', 'setProfile', 'toggleFavorite']) assert.equal(Object.hasOwn(State, name), false);
});

test('adds compatible cart lines and keeps server prices out of the authority boundary', () => {
  let state = State.addCartLine(State.defaultState(), { id: '1', name: 'Pasta', price: 12.5, restaurantId: 'savora-kitchen' }, 2, []);
  assert.equal(state.cart.length, 1);
  assert.equal(state.cart[0].quantity, 2);
  assert.equal(state.cart[0].id, '1');
  assert.equal(state.cart[0].unitPrice, 12.5);
  assert.throws(() => State.addCartLine(state, { id: '2', name: 'Pizza', price: 10, restaurantId: 'pizza-hut' }, 1), /one restaurant/i);
});

test('legacy cart lines receive deterministic restaurant identity and distinct line ids', () => {
  const state = State.normalize({ cart: [{ id: '1' }, { id: '1' }] });
  assert.equal(state.cart[0].restaurantId, 'savora-kitchen');
  assert.equal(state.cart[0].restaurantName, 'Savora Kitchen');
  assert.notEqual(state.cart[0].lineId, state.cart[1].lineId);
});

test('migrates a legacy portion-prefixed cart option to its choice public id', () => {
  const state = State.normalize({
    cart: [{ id: 'dish', options: [{ id: 'portion-choice-regular', label: 'Regular' }] }]
  });
  assert.equal(state.cart[0].options[0].id, 'choice-regular');
});

test('rejects a cart line without a usable product identifier', () => {
  assert.throws(() => State.addCartLine(State.defaultState(), { name: 'Pasta', price: 12.5 }, 1), /product id/i);
});

test('quantity reduction removes a cart line at zero', () => {
  let state = State.addCartLine(State.defaultState(), { id: '1', name: 'Pasta', price: 12.5 }, 1, []);
  state = State.updateCartQuantity(state, state.cart[0].lineId, -1);
  assert.equal(state.cart.length, 0);
});

test('cart line removal is scoped to a stable line id', () => {
  const state = State.addCartLine(State.defaultState(), { id: '1', name: 'Pasta', price: 12.5 }, 1, []);
  assert.equal(State.removeCartLine(state, state.cart[0].lineId).cart.length, 0);
  assert.equal(State.removeCartLine(state, 'missing-line').cart.length, 1);
});
