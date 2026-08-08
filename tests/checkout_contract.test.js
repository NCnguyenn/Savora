'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('server pricing and checkout contracts exist behind one authenticated endpoint', () => {
  for (const file of [
    'database/migrations/005_checkout_quotes.php',
    'lib/repositories/pricing_repository.php',
    'lib/services/pricing_service.php',
    'api/checkout.php',
    'tests/pricing_service_test.php'
  ]) assert.ok(fs.existsSync(path.join(root, file)), `${file} must exist`);

  const pricing = read('lib/services/pricing_service.php');
  const checkout = read('api/checkout.php');
  assert.match(pricing, /function pricing_create_quote/);
  assert.match(pricing, /unitPrice|basePrice/);
  assert.match(pricing, /optionPublicIds/);
  assert.match(pricing, /commercial_active_rules/);
  assert.match(pricing, /promotion/);
  assert.match(checkout, /savora_request_actor[\s\S]*customer/);
  assert.match(checkout, /action.*quote|quote.*action/);
  assert.match(checkout, /savora_require_csrf/);
  assert.match(checkout, /place_order/);
  assert.match(checkout, /savora_idempotency_lock/);
  assert.match(checkout, /savora_idempotency_unlock/);
  assert.doesNotMatch(checkout, /placeDemoOrder|topUpWallet/);
});

test('quote input ignores client totals and uses a bounded customer address', () => {
  const pricing = read('lib/services/pricing_service.php');
  const repository = read('lib/repositories/pricing_repository.php');
  const checkout = read('api/checkout.php');
  assert.match(pricing, /addressPublicId/);
  assert.match(repository, /customer_addresses/);
  assert.match(repository, /cart_hash|cartHash/);
  assert.match(pricing, /subtotal/);
  assert.match(pricing, /deliveryFee/);
  assert.match(pricing, /total/);
  assert.doesNotMatch(pricing, /\$cart[^;]*\['(subtotal|total|deliveryFee|discount)'\]/);
  assert.doesNotMatch(checkout, /\$_POST/);
});

test('quote mutation is not an order idempotency command', () => {
  const checkout = read('api/checkout.php');
  const quoteSection = checkout.slice(0, checkout.indexOf("'place_order'"));
  assert.match(quoteSection, /quote/);
  assert.doesNotMatch(quoteSection, /savora_idempotency_store/);
});

test('checkout groups stored payment methods by when payment is collected', () => {
  const checkout = read('customer_checkout.php');

  assert.match(checkout, /data-payment-timing="pay_now"/);
  assert.match(checkout, /data-payment-timing="pay_on_receipt"/);
  assert.match(checkout, /value="seapay"/);
  assert.match(checkout, /value="cash"/);
});

test('SePay checkout handoff carries only the server order reference after placement', () => {
  const checkout = read('customer_checkout.php');
  const placement = checkout.slice(checkout.indexOf("form.addEventListener('submit'"));
  assert.match(placement, /await SavoraApi\.post\('api\/checkout\.php'[\s\S]*action:\s*'place_order'/);
  assert.match(placement, /stateApi\.persist\(\{\s*cart:\s*\[\]\s*\}\)/);
  assert.ok(
    placement.indexOf('stateApi.persist({ cart: [] })') > placement.indexOf("const result = await SavoraApi.post('api/checkout.php'"),
    'Cart clearing must occur only after the server placement request resolves.'
  );
  assert.match(placement, /payment\.value === 'seapay'[\s\S]*seapay_checkout\.php\?order=' \+ result\.referenceCode/);
  const redirect = placement.match(/seapay_checkout\.php\?order=[^;]+/)?.[0] || '';
  assert.doesNotMatch(redirect, /amount|total|paid/i);
});
