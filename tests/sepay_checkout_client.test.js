'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const SePay = require('../js/sepay_checkout.js');

const flush = () => new Promise(resolve => setImmediate(resolve));

function fakeClock() {
  let nextId = 1;
  const timers = new Map();
  return {
    setTimeout(callback, delay) {
      const id = nextId++;
      timers.set(id, { callback, delay });
      return id;
    },
    clearTimeout(id) { timers.delete(id); },
    count() { return timers.size; },
    delays() { return [...timers.values()].map(timer => timer.delay); },
    async runNext() {
      const entry = timers.entries().next().value;
      if (!entry) return;
      const [id, timer] = entry;
      timers.delete(id);
      timer.callback();
      await flush();
    }
  };
}

function controllerHarness(overrides = {}) {
  const clock = fakeClock();
  const calls = { get: [], post: [], clear: [], receipts: [], pending: [], status: [], navigation: [], busy: [] };
  const visibility = { state: 'visible', listener: null };
  const snapshots = overrides.snapshots ? [...overrides.snapshots] : [];
  const api = {
    async get(url) {
      calls.get.push(url);
      if (overrides.getError) throw new Error(overrides.getError);
      return snapshots.shift() || overrides.snapshot || {
        referenceCode: 'SVR-ABC-123', paymentMethod: 'seapay', amountVnd: 125000,
        paymentStatus: 'pending', paidAt: null, orderStatus: 'pending'
      };
    },
    async post(url, body, key) {
      calls.post.push({ url, body, key });
      return { referenceCode: 'SVR-ABC-123', paymentStatus: 'paid' };
    },
    intentKey(scope) { return `intent:${scope}`; },
    clearIntentKey(scope) { calls.clear.push(scope); }
  };
  const controller = SePay.createController({
    api,
    referenceCode: 'SVR-ABC-123',
    demoMode: overrides.demoMode ?? true,
    initialSnapshot: overrides.initialSnapshot || null,
    isVisible: () => visibility.state === 'visible',
    onVisibilityChange(listener) { visibility.listener = listener; return () => { visibility.listener = null; }; },
    setTimeout: clock.setTimeout,
    clearTimeout: clock.clearTimeout,
    renderPending(snapshot) { calls.pending.push(snapshot); },
    renderReceipt(model) { calls.receipts.push(model); },
    renderStatus(message, isError) { calls.status.push({ message, isError }); },
    setDemoBusy(value) { calls.busy.push(value); },
    navigate(url) { calls.navigation.push(url); }
  });
  return { controller, clock, calls, visibility };
}

test('formats integer VND and maps a paid snapshot to a pending-order receipt', () => {
  assert.equal(SePay.formatVnd(125000), '125.000 ₫');
  const receipt = SePay.receiptModel({
    referenceCode: 'SVR-ABC-123', amountVnd: 125000, paymentMethod: 'seapay',
    paymentStatus: 'paid', paidAt: '2026-08-08 12:34:56', orderStatus: 'pending'
  });
  assert.equal(receipt.amount, '125.000 ₫');
  assert.equal(receipt.paymentLabel, 'Đã thanh toán');
  assert.equal(receipt.orderLabel, 'Chờ nhà hàng xác nhận');
});

test('polls every three seconds while visible and stops after the server reports paid', async () => {
  const pending = {
    referenceCode: 'SVR-ABC-123', amountVnd: 125000, paymentMethod: 'seapay',
    paymentStatus: 'pending', paidAt: null, orderStatus: 'pending'
  };
  const paid = { ...pending, paymentStatus: 'paid', paidAt: '2026-08-08 12:34:56' };
  const { controller, clock, calls } = controllerHarness({ snapshots: [pending, paid] });
  await controller.start();
  assert.deepEqual(calls.get, ['api/payment_status.php?order=SVR-ABC-123']);
  assert.deepEqual(clock.delays(), [3000]);
  await clock.runNext();
  assert.equal(calls.receipts.length, 1);
  assert.equal(clock.count(), 0);
});

test('pauses polling while hidden and refreshes immediately when visible again', async () => {
  const { controller, clock, calls, visibility } = controllerHarness();
  await controller.start();
  assert.equal(clock.count(), 1);
  visibility.state = 'hidden';
  visibility.listener();
  assert.equal(clock.count(), 0);
  visibility.state = 'visible';
  visibility.listener();
  await flush();
  assert.equal(calls.get.length, 2);
  assert.equal(clock.count(), 1);
});

test('demo confirmation reuses the protected endpoint and refreshes the same paid receipt', async () => {
  const paid = {
    referenceCode: 'SVR-ABC-123', amountVnd: 125000, paymentMethod: 'seapay',
    paymentStatus: 'paid', paidAt: '2026-08-08 12:34:56', orderStatus: 'pending'
  };
  const { controller, calls } = controllerHarness({ snapshots: [paid] });
  await controller.simulatePayment();
  assert.deepEqual(calls.post, [{
    url: 'api/payment_demo.php',
    body: { action: 'simulate_success', payload: { referenceCode: 'SVR-ABC-123' } },
    key: 'intent:customer-seapay-payment-SVR-ABC-123'
  }]);
  assert.deepEqual(calls.clear, ['customer-seapay-payment-SVR-ABC-123']);
  assert.equal(calls.receipts.length, 1);
  assert.deepEqual(calls.busy, [true, false]);
});

test('transient status failure preserves pending state and exposes a retry message', async () => {
  const { controller, calls, clock } = controllerHarness({ getError: 'Network unavailable' });
  await controller.start();
  assert.equal(calls.receipts.length, 0);
  assert.equal(calls.status.at(-1).isError, true);
  assert.match(calls.status.at(-1).message, /Network unavailable/);
  assert.equal(clock.count(), 1);
});

test('receipt acknowledgement only navigates to Customer history', () => {
  const { controller, calls } = controllerHarness();
  controller.acknowledge();
  assert.deepEqual(calls.navigation, ['customer_history.php?order=SVR-ABC-123']);
  assert.equal(calls.post.length, 0);
});
