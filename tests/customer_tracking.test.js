'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const tracking = require('../js/customer_tracking.js');
const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'customer_tracking.js'), 'utf8');

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((nextResolve, nextReject) => { resolve = nextResolve; reject = nextReject; });
  return { promise, resolve, reject };
}

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
    listeners: {},
    querySelector: selector => selector === '[data-customer-live-order]' ? card : null,
    createElement: element,
    addEventListener(type, listener) { this.listeners[type] = listener; }
  };
  const schedules = [];
  const scheduledTasks = [];
  const window = { listeners: {}, clearTimeout() {}, setTimeout(fn, delay) { schedules.push(delay); scheduledTasks.push({ fn, delay }); return schedules.length; }, addEventListener(type, listener) { this.listeners[type] = listener; } };
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
  return { document, window, api, elements, schedules, scheduledTasks, calls, posts, cleared };
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

test('mount ignores an orders response that settles after the tab becomes hidden', async () => {
  const gate = deferred();
  const harness = trackingHarness({ id: 'P-1', referenceCode: 'P-1', status: 'picked_up' });
  harness.api.get = async url => { harness.calls.push(url); return gate.promise; };
  const controller = tracking.mount({ ...harness, autoStart: false });
  const pending = controller.refresh();
  await Promise.resolve();

  harness.document.visibilityState = 'hidden';
  harness.document.listeners.visibilitychange();
  gate.resolve({ orders: [{ id: 'P-1', referenceCode: 'P-1', status: 'picked_up' }] });
  await pending;

  assert.deepEqual(harness.calls, ['api/orders.php?pageSize=50']);
  assert.equal(harness.elements['[data-live-order-status]'].textContent, '');
  controller.stop();
});

test('mount ignores a route response that settles after pagehide', async () => {
  const routeGate = deferred();
  const order = { id: 'P-2', referenceCode: 'P-2', status: 'picked_up', assignment: {} };
  const harness = trackingHarness(order);
  harness.api.get = async url => {
    harness.calls.push(url);
    return url.startsWith('api/orders.php') ? { orders: [order] } : routeGate.promise;
  };
  const controller = tracking.mount({ ...harness, autoStart: false });
  const pending = controller.refresh();
  await Promise.resolve();
  await Promise.resolve();

  harness.window.listeners.pagehide();
  routeGate.resolve({ route: { progress: 1, start: { latitude: 1, longitude: 1 }, current: { latitude: 2, longitude: 2 }, end: { latitude: 3, longitude: 3 } } });
  await pending;

  assert.equal(harness.elements['[data-route-updated]'].textContent, '');
  assert.equal(harness.elements['[data-tracking-map]'].classList.values.size, 0);
});

test('mount starts a fresh request when a hidden tab becomes visible', async () => {
  const harness = trackingHarness({ id: 'P-3', referenceCode: 'P-3', status: 'preparing' });
  harness.document.visibilityState = 'hidden';
  const controller = tracking.mount({ ...harness, autoStart: false });
  await controller.refresh();
  assert.equal(harness.calls.length, 0);

  harness.document.visibilityState = 'visible';
  harness.document.listeners.visibilitychange();
  await Promise.resolve();
  await Promise.resolve();
  assert.deepEqual(harness.calls, ['api/orders.php?pageSize=50']);
  controller.stop();
});

test('delivered route stays visible and retains its vector state through a tracking error', async () => {
  const removed = [];
  const map = { removeLayer(layer) { removed.push(layer); }, fitBounds() {} };
  const savedLeaflet = globalThis.L;
  globalThis.L = {
    map: () => map,
    tileLayer: () => ({ on() { return this; }, addTo() { return this; } }),
    polyline: () => ({ addTo() { return {}; } }),
    circleMarker: () => ({ addTo() { return {}; } }),
    latLngBounds: points => points
  };
  const order = { id: 'D-2', referenceCode: 'D-2', status: 'delivered', assignment: {} };
  const harness = trackingHarness(order);
  let routeReads = 0;
  harness.api.get = async url => {
    harness.calls.push(url);
    if (url.startsWith('api/orders.php')) return { orders: [order] };
    routeReads += 1;
    if (routeReads === 1) return { route: { progress: 1, arrived: true, start: { latitude: 1, longitude: 1 }, current: { latitude: 2, longitude: 2 }, end: { latitude: 3, longitude: 3 } } };
    throw new Error('Tracking is temporarily unavailable.');
  };
  try {
    const controller = tracking.mount({ ...harness, autoStart: false });
    await controller.refresh();
    const arrived = harness.elements['[data-route-updated]'].textContent;
    await controller.refresh();

    assert.equal(harness.elements['[data-live-driver]'].hidden, false);
    assert.equal(harness.elements['[data-tracking-map]'].hidden, false);
    assert.equal(harness.elements['[data-route-updated]'].textContent, arrived);
    assert.equal(removed.length, 0);
    controller.stop();
  } finally {
    if (savedLeaflet === undefined) delete globalThis.L;
    else globalThis.L = savedLeaflet;
  }
});

test('receipt confirmation does not render a stale success message after pagehide', async () => {
  const postGate = deferred();
  const order = { id: 'D-3', referenceCode: 'D-3', status: 'delivered', paymentMethod: 'cash', version: 1, assignment: {} };
  const harness = trackingHarness(order, { route: {} });
  harness.api.post = async () => postGate.promise;
  const controller = tracking.mount({ ...harness, autoStart: false });
  await controller.refresh();

  const confirming = harness.elements['[data-confirm-received]'].listeners.click();
  harness.window.listeners.pagehide();
  postGate.resolve({});
  await confirming;

  assert.notEqual(harness.elements['[data-live-order-feedback]'].textContent, 'Receipt confirmed. Thank you.');
  assert.deepEqual(harness.cleared, ['customer-confirm-received-D-3']);
  controller.stop();
});

test('receipt confirmation refreshes authoritatively after a background poll starts during its POST', async () => {
  const postGate = deferred();
  const order = { id: 'D-4', referenceCode: 'D-4', status: 'delivered', paymentMethod: 'cash', version: 2, assignment: {} };
  const harness = trackingHarness(order, { route: {} });
  let orderReads = 0;
  harness.api.get = async url => {
    harness.calls.push(url);
    if (url.startsWith('api/orders.php')) { orderReads += 1; return { orders: [order] }; }
    return { route: {} };
  };
  harness.api.post = async () => postGate.promise;
  const controller = tracking.mount({ ...harness, autoStart: false });
  await controller.refresh();

  const confirming = harness.elements['[data-confirm-received]'].listeners.click();
  await harness.scheduledTasks[0].fn();
  postGate.resolve({});
  await confirming;

  assert.equal(orderReads, 3);
  assert.equal(harness.elements['[data-confirm-received]'].disabled, false);
  assert.deepEqual(harness.cleared, ['customer-confirm-received-D-4']);
  controller.stop();
});

test('receipt confirmation retains its stable key and re-enables the button on failure', async () => {
  const order = { id: 'D-5', referenceCode: 'D-5', status: 'delivered', paymentMethod: 'cash', version: 3, assignment: {} };
  const harness = trackingHarness(order, { route: {} });
  harness.api.post = async () => { throw new Error('Confirmation failed.'); };
  const controller = tracking.mount({ ...harness, autoStart: false });
  await controller.refresh();

  await harness.elements['[data-confirm-received]'].listeners.click();

  assert.equal(harness.elements['[data-confirm-received]'].disabled, false);
  assert.deepEqual(harness.cleared, []);
  assert.equal(harness.elements['[data-live-order-feedback]'].textContent, 'Confirmation failed.');
  controller.stop();
});

test('hidden receipt failure rehydrates an enabled retry without clearing the stable key', async () => {
  const postGate = deferred();
  const order = { id: 'D-6', referenceCode: 'D-6', status: 'delivered', paymentMethod: 'cash', version: 4, assignment: {} };
  const harness = trackingHarness(order, { route: {} });
  let postedKey = '';
  harness.api.post = async (_url, _body, key) => { postedKey = key; return postGate.promise; };
  const controller = tracking.mount({ ...harness, autoStart: false });
  await controller.refresh();

  const confirming = harness.elements['[data-confirm-received]'].listeners.click();
  harness.document.visibilityState = 'hidden';
  harness.document.listeners.visibilitychange();
  postGate.reject(new Error('Confirmation failed.'));
  await confirming;
  const hiddenFeedback = harness.elements['[data-live-order-feedback]'].textContent;

  harness.document.visibilityState = 'visible';
  harness.document.listeners.visibilitychange();
  for (let step = 0; step < 5; step += 1) await Promise.resolve();

  assert.equal(harness.elements['[data-confirm-received]'].disabled, false);
  assert.equal(hiddenFeedback, 'Confirming receipt…');
  assert.equal(postedKey, 'intent-customer-confirm-received-D-6');
  assert.deepEqual(harness.cleared, []);
  controller.stop();
});

test('receipt confirmation ignores a second click while its POST is in flight', async () => {
  const postGate = deferred();
  const order = { id: 'D-7', referenceCode: 'D-7', status: 'delivered', paymentMethod: 'cash', version: 5, assignment: {} };
  const harness = trackingHarness(order, { route: {} });
  let postCount = 0;
  harness.api.post = async () => { postCount += 1; return postGate.promise; };
  const controller = tracking.mount({ ...harness, autoStart: false });
  await controller.refresh();

  const first = harness.elements['[data-confirm-received]'].listeners.click();
  const second = harness.elements['[data-confirm-received]'].listeners.click();
  assert.equal(postCount, 1);
  postGate.resolve({});
  await Promise.all([first, second]);
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
