'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function loadCheckoutIntent() {
  const source = fs.readFileSync('js/checkout_intent.js', 'utf8');
  const context = { window: {}, sessionStorage: null };
  context.window = context;
  vm.runInNewContext(source, context);
  return context.SavoraCheckoutIntent;
}

function memoryStorage() {
  const values = new Map();
  return {
    getItem: key => values.has(key) ? values.get(key) : null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: key => values.delete(key),
  };
}

test('checkout retries send the exact same payload and clear intent only after success', async () => {
  const intent = loadCheckoutIntent();
  const storage = memoryStorage();
  let builds = 0;
  let calls = 0;
  const payloads = [];
  const intentKeys = [];
  const buildOrder = () => ({
    order: { id: `SVR-${++builds}`, createdAt: `2026-08-01T00:00:0${builds}.000Z` },
    state: { orderNumber: builds },
  });
  const command = async (payload, intentKey) => {
    payloads.push(JSON.stringify(payload));
    intentKeys.push(intentKey);
    calls += 1;
    if (calls === 1) throw new Error('network retry');
    return { ok: true };
  };

  await assert.rejects(() => intent.submit({ storage, randomUUID: () => 'intent-1', buildOrder, command }), /network retry/);
  assert.equal(storage.getItem('savora_checkout_intent'), 'role-intent-1');
  await intent.submit({ storage, randomUUID: () => 'intent-2-must-not-be-used', buildOrder, command });

  assert.equal(builds, 1, 'the local order must be built once per intent');
  assert.equal(payloads[0], payloads[1], 'retry payload must be byte-for-byte stable');
  assert.deepEqual(intentKeys, ['role-intent-1', 'role-intent-1'], 'retry must send the original idempotency intent key even when a new UUID is available');
  assert.equal(storage.getItem('savora_checkout_intent'), null, 'success must clear the checkout intent');
});

test('checkout cancellation clears the intent and draft without invoking the command', () => {
  const intent = loadCheckoutIntent();
  const storage = memoryStorage();
  storage.setItem('savora_checkout_intent', 'intent-cancel');
  storage.setItem('savora_checkout_draft_intent-cancel', '{"order":{}}');
  intent.cancel({ storage });
  assert.equal(storage.getItem('savora_checkout_intent'), null);
  assert.equal(storage.getItem('savora_checkout_draft_intent-cancel'), null);
});

test('checkout offers a cancellable path that clears server-command intents before returning to cart', () => {
  const checkoutPage = fs.readFileSync('customer_checkout.php', 'utf8');
  assert.match(checkoutPage, /id="cancel-checkout-button"[^>]*type="button"/);
  assert.match(checkoutPage, /SavoraApi\.clearIntentKey\('customer-checkout-quote'\)/);
  assert.match(checkoutPage, /SavoraApi\.clearIntentKey\('customer-place-order'\)/);
  assert.match(checkoutPage, /window\.location\.assign\('customer_cart\.php'\)/);
});

test('checkout rotates the place-order intent when its payload changes', () => {
  const checkout = fs.readFileSync('customer_checkout.php', 'utf8');
  assert.match(checkout, /const resetPlaceOrderIntent = \(\) => SavoraApi\.clearIntentKey\('customer-place-order'\)/);
  assert.match(checkout, /input\[name="payment"\][\s\S]*resetPlaceOrderIntent/);
  assert.match(checkout, /note\.addEventListener\('input',[\s\S]*resetPlaceOrderIntent/);
  assert.match(checkout, /error\.status[\s\S]*resetPlaceOrderIntent/);
});
