'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const tracking = require('../js/customer_tracking.js');
const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'customer_tracking.js'), 'utf8');

function trackingHarness(order, trackingResponse) {
  const elements = {};
  const element = () => ({
    textContent: '', hidden: false, disabled: false, className: '', children: [], listeners: {},
    classList: { values: new Set(), add(value) { this.values.add(value); } },
    setAttribute() {},
    append(...children) { this.children.push(...children); },
    replaceChildren(...children) { this.children = children; },
    addEventListener(type, listener) { this.listeners[type] = listener; }
  });
  for (const selector of ['[data-live-order-status]', '[data-live-order-progress]', '[data-live-order-note]', '[data-live-driver]', '[data-tracking-map]', '[data-route-progress]', '[data-route-updated]', '[data-confirm-received]', '[data-live-order-feedback]']) elements[selector] = element();
  const card = element();
  card.querySelector = selector => elements[selector] || null;
  const document = {
    visibilityState: 'visible',
    querySelector: selector => selector === '[data-customer-live-order]' ? card : null,
    createElement: element,
    addEventListener() {}
  };
  const schedules = [];
  const window = { clearTimeout() {}, setTimeout(_fn, delay) { schedules.push(delay); return schedules.length; }, addEventListener() {} };
  const calls = [];
  const posts = [];
  const cleared = [];
  const api = {
    async get(url) {
      calls.push(url);
      if (url.startsWith('api/orders.php')) return { orders: [order] };
      if (trackingResponse instanceof Error) throw trackingResponse;
      return trackingResponse || { route: {} };
    },
    async post(url, body, key) { posts.push({ url, body, key }); return {}; },
    intentKey(scope) { return `intent-${scope}`; },
    clearIntentKey(scope) { cleared.push(scope); }
  };
  return { document, window, api, elements, schedules, calls, posts, cleared };
}

test('displayState maps payment and delivery states for the Customer', () => {
  assert.equal(tracking.displayState({ status: 'pending', payment: { method: 'seapay', status: 'pending' } }), 'waiting_payment');
  assert.equal(tracking.displayState({ status: 'confirmed', payment: { status: 'paid' } }), 'preparing');
  assert.equal(tracking.displayState({ status: 'picked_up' }), 'on_the_way');
  assert.equal(tracking.displayState({ status: 'delivered' }), 'waiting_confirmation');
  assert.equal(tracking.displayState({ status: 'completed' }), 'completed');
  assert.equal(tracking.displayState({ status: 'cancelled' }), 'unavailable');
});

test('nextDelay starts at two seconds and backs off no further than fifteen', () => {
  assert.equal(tracking.nextDelay(0), 2000);
  assert.equal(tracking.nextDelay(1), 4000);
  assert.equal(tracking.nextDelay(4), 15000);
  assert.equal(tracking.nextDelay(99), 15000);
});

test('active selection retains a delivered order until the Customer confirms receipt', () => {
  const orders = [
    { id: 'done', status: 'completed' },
    { id: 'cancelled', status: 'cancelled' },
    { id: 'delivered', status: 'delivered' }
  ];
  assert.deepEqual(tracking.selectActiveOrder(orders), orders[2]);
  assert.equal(tracking.selectActiveOrder([{ id: 'refunded', status: 'refunded' }]), null);
  assert.equal(tracking.selectActiveOrder([{ id: 'unknown', status: 'paused' }]), null);
});

test('polling and tracking requests are visibility and status bounded', () => {
  assert.equal(tracking.shouldPoll('visible'), true);
  assert.equal(tracking.shouldPoll('hidden'), false);
  assert.equal(tracking.shouldLoadRoute({ status: 'assigned' }), true);
  assert.equal(tracking.shouldLoadRoute({ status: 'picked_up' }), true);
  assert.equal(tracking.shouldLoadRoute({ status: 'delivered' }), true);
  assert.equal(tracking.shouldLoadRoute({ status: 'preparing' }), false);
});

test('receipt confirmation keeps version and its stable idempotency scope', () => {
  assert.deepEqual(tracking.receiptRequest({ id: '17', referenceCode: 'SAV-17', version: '8' }), {
    scope: 'customer-confirm-received-17',
    body: {
      action: 'confirm_received',
      payload: { referenceCode: 'SAV-17', expectedVersion: 8 }
    }
  });
});

test('mount treats an assigned route 404 as a normal waiting state without backing off', async () => {
  const missingRoute = Object.assign(new Error('Tracking was not found.'), { status: 404 });
  const harness = trackingHarness({ id: 'A-1', referenceCode: 'A-1', status: 'assigned', version: 2, assignment: {} }, missingRoute);
  const controller = tracking.mount({ ...harness, autoStart: false });

  assert.equal(harness.calls.length, 0);
  await controller.refresh();

  assert.deepEqual(harness.calls, ['api/orders.php?pageSize=50', 'api/tracking.php?order=A-1']);
  assert.equal(harness.schedules.at(-1), 2000);
  assert.equal(harness.elements['[data-live-order-feedback]'].textContent, '');
  assert.equal(harness.elements['[data-live-driver]'].hidden, false);
  controller.stop();
});

test('mount posts delivered receipt confirmation with the stable key then clears it after success', async () => {
  const order = { id: 'D-1', referenceCode: 'D-1', status: 'delivered', paymentMethod: 'cash', version: 4, assignment: {} };
  const harness = trackingHarness(order, { route: { progress: 1 } });
  const controller = tracking.mount({ ...harness, autoStart: false });
  await controller.refresh();

  assert.equal(harness.elements['[data-confirm-received]'].textContent, 'Received and paid');
  await harness.elements['[data-confirm-received]'].listeners.click();
  assert.deepEqual(harness.posts, [{
    url: 'api/orders.php',
    body: { action: 'confirm_received', payload: { referenceCode: 'D-1', expectedVersion: 4 } },
    key: 'intent-customer-confirm-received-D-1'
  }]);
  assert.deepEqual(harness.cleared, ['customer-confirm-received-D-1']);
  controller.stop();
});

test('the browser controller uses recursive visible polling, map fallback, and safe DOM rendering', () => {
  assert.match(source, /setTimeout\(refresh, delay\)/);
  assert.doesNotMatch(source, /setInterval\s*\(/);
  assert.match(source, /visibilitychange/);
  assert.match(source, /visibilityState/);
  assert.match(source, /is-map-fallback/);
  assert.match(source, /tileerror/);
  assert.match(source, /L\.polyline/);
  assert.match(source, /L\.tileLayer/);
  assert.doesNotMatch(source, /innerHTML\s*=/);
});
