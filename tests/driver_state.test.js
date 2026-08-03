const test = require('node:test');
const assert = require('node:assert/strict');
const DriverState = require('../js/driver_state.js');

test('Driver state retains only safe preferences and never normalizes operational data', () => {
  const state = DriverState.normalize({
    profile: { id: 'driver-7', fullName: 'Daniel Morgan', password: 'must-not-survive' },
    location: { method: 'gps', address: '21 Oak Avenue', latitude: 91, longitude: 106.7 },
    serviceRadiusKm: 500,
    preferences: { newOffers: false, soundAlerts: true, cashOnDelivery: true, avoidHighways: true },
    currentOffer: { orderId: 'legacy-order' },
    deliveries: [{ orderId: 'legacy-order' }]
  });
  assert.equal(state.preferences.newOffers, false);
  assert.equal(Object.hasOwn(state, 'currentOffer'), false);
  assert.equal(Object.hasOwn(state, 'deliveries'), false);
  assert.equal(Object.hasOwn(state, 'profile'), false);
  assert.equal(Object.hasOwn(state, 'location'), false);
  assert.equal(Object.hasOwn(state, 'online'), false);
});

test('Driver preference setter cannot create server-owned fields', () => {
  let state = DriverState.defaultState();
  state = DriverState.setPreferences(state, { cashOnDelivery: false, avoidHighways: true });
  assert.equal(state.preferences.cashOnDelivery, false);
  assert.equal(state.preferences.avoidHighways, true);
  assert.equal(Object.hasOwn(state, 'profile'), false);
  assert.equal(Object.hasOwn(state, 'location'), false);
  assert.equal(Object.hasOwn(state, 'online'), false);
  for (const method of ['createOffer', 'acceptOffer', 'updateMilestone', 'deriveHistory', 'deriveEarnings']) {
    assert.equal(Object.hasOwn(DriverState, method), false, `${method} must remain a Phase 6 server concern`);
  }
});
