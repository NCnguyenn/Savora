'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('Driver operational pages use the authoritative dispatch API', () => {
  for (const file of ['js/driver_dashboard.js', 'js/driver_delivery.js', 'js/driver_history.js', 'js/driver_earnings.js']) {
    assert.match(read(file), /api\/dispatch\.php/);
  }
  const state = read('js/driver_state.js');
  for (const forbidden of ['createOffer', 'acceptOffer', 'declineOffer', 'updateMilestone', 'deriveEarnings', 'setAvailability', 'setLocation']) {
    assert.doesNotMatch(state, new RegExp(forbidden));
  }
});
test('Driver delivery actions are server commands and Customer tracking handles stale location', () => {
  const delivery = read('js/driver_delivery.js');
  assert.match(delivery, /api\/dispatch\.php/);
  assert.doesNotMatch(delivery, /Delivery actions available in Phase 6/);
  const customer = read('customer_dashboard.php');
  assert.match(customer, /recordedAt|recorded_at|location/i);
  assert.match(customer, /temporarily unavailable|unavailable/i);
});
