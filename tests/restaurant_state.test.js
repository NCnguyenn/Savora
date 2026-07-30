const test = require('node:test');
const assert = require('node:assert/strict');
const RestaurantState = require('../js/restaurant_state.js');

test('accepts only valid order transitions and records an audit event', () => {
  const customer = { orders: [{ id: 'SVR-1', status: 'pending', items: [], total: 20 }] };
  const next = RestaurantState.updateOrderStatus(customer, 'SVR-1', 'confirmed', { prepMinutes: 20 });
  assert.equal(next.orders[0].status, 'confirmed');
  assert.equal(next.orders[0].prepMinutes, 20);
  assert.equal(next.orders[0].statusHistory.at(-1).status, 'confirmed');
  assert.throws(() => RestaurantState.updateOrderStatus(next, 'SVR-1', 'completed'), /transition/i);
});

test('menu and storefront changes normalize to safe customer overrides', () => {
  let state = RestaurantState.setItemAvailability(RestaurantState.defaultState(), '1', false);
  state = RestaurantState.setProfile(state, { name: 'Savora Kitchen', address: '<img onerror=alert(1)>' });
  assert.equal(state.menuItems.find(item => item.id === '1').available, false);
  assert.equal(state.profile.address, '<img onerror=alert(1)>');
  assert.equal(Object.hasOwn(state.profile, 'onerror'), false);
});
