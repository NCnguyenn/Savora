'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');

const State = require('../js/customer_location_state.js');

test('confirmed GPS state is the first point that becomes persistable', () => {
  const preview = { address: '100 Server Street', addressLine1: '100 Server Street', city: 'Bangkok', latitude: 13.7563, longitude: 100.5018 };
  assert.equal(State.normalizePreview(preview).pendingSync, undefined);
  assert.deepEqual(State.confirmGps(preview, ' Tower B '), {
    address: '100 Server Street',
    addressLine1: '100 Server Street',
    addressLine2: '',
    city: 'Bangkok',
    state: '',
    postalCode: '',
    country: '',
    latitude: 13.7563,
    longitude: 100.5018,
    deliveryDetails: 'Tower B',
    method: 'gps',
    pendingSync: true
  });
});

test('manual state accepts empty details and preserves the pending sync marker', () => {
  const draft = State.confirmManual('Manual display location', '');
  assert.equal(draft.address, 'Manual display location');
  assert.equal(draft.deliveryDetails, '');
  assert.equal(draft.method, 'manual');
  assert.equal(draft.pendingSync, true);
  assert.deepEqual(State.syncRequest(draft), { address: 'Manual display location', deliveryDetails: '' });
});

test('guest drafts reject invalid coordinates and oversized details', () => {
  assert.throws(() => State.confirmGps({ address: 'Road', latitude: 91, longitude: 1 }, ''), /coordinates/i);
  assert.throws(() => State.normalizeDeliveryDetails('x'.repeat(301)), /300/);
  assert.equal(State.parseGuestDraft('{bad json'), null);
});
