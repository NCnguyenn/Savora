'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const actions = require('../js/driver_delivery.js');

test('Driver primary action selects demo and real delivery commands', () => {
  assert.deepEqual(actions.primaryAction?.({ status: 'assigned' }, true), ['demo_start_delivery', 'Picked up - start delivery']);
  assert.deepEqual(actions.primaryAction?.({ status: 'picked_up' }, true), ['record_completion', 'Delivered to Customer']);
  assert.deepEqual(actions.primaryAction?.({ status: 'assigned' }, false), ['record_arrival', 'Mark arrived at pickup']);
  assert.deepEqual(actions.primaryAction?.({ status: 'arrived' }, false), ['record_pickup', 'Confirm pickup']);
  assert.deepEqual(actions.primaryAction?.({ status: 'picked_up' }, false), ['record_completion', 'Delivered to Customer']);
  assert.equal(actions.primaryAction?.({ status: 'delivered' }, true), null);
  assert.equal(actions.primaryAction?.(null, true), null);
});

test('Driver proof state reset clears a selected file and stale status', () => {
  const input = { value: 'C:\\fakepath\\proof.png' };
  const status = { textContent: 'Proof verified by the server.' };
  assert.equal(typeof actions.resetProofState, 'function');
  actions.resetProofState(input, status);
  assert.equal(input.value, '');
  assert.equal(status.textContent, '');
});

test('Driver demo delivery polls authoritative tracking and gates completion on arrival', () => {
  const controller = read('js/driver_delivery.js');
  assert.match(controller, /api\/tracking\.php\?order=/);
  assert.match(controller, /document\.visibilityState|doc\.visibilityState/);
  assert.match(controller, /setTimeout/);
  assert.match(controller, /\b2000\b/);
  assert.match(controller, /route\.arrived/);
  assert.match(controller, /proofRequired\s*===\s*true/);
  assert.match(controller, /if \(!delivery\) \{[^}]*activeDelivery\s*=\s*null;/s);
  assert.doesNotMatch(controller, /watchPosition/);
});

test('Driver dashboard uses recursive visible polling with bounded backoff', () => {
  const controller = read('js/driver_dashboard.js');
  assert.match(controller, /demo_start_shift/);
  assert.match(controller, /document\.visibilityState|doc\.visibilityState/);
  assert.match(controller, /setTimeout/);
  assert.match(controller, /Math\.min\([^\n]*15000/);
  assert.match(controller, /\b2000\b/);
  assert.doesNotMatch(controller, /setInterval|watchPosition/);
});
