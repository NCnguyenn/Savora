'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('payment status endpoint is Customer-owned and read-only', () => {
  const endpointPath = path.join(root, 'api/payment_status.php');
  assert.ok(fs.existsSync(endpointPath), 'api/payment_status.php must exist');
  const endpoint = read('api/payment_status.php');
  assert.match(endpoint, /savora_request_actor\(\$conn,\s*\['customer'\]\)/);
  assert.match(endpoint, /sepay_checkout_snapshot/);
  assert.doesNotMatch(endpoint, /\b(?:UPDATE|INSERT|DELETE)\b/i);
  assert.doesNotMatch(endpoint, /SEPAY_WEBHOOK_API_KEY/);
});

test('SePay page has stable pending and receipt states without client-side order mutation', () => {
  const page = read('seapay_checkout.php');
  for (const hook of [
    'data-seapay-pending',
    'data-seapay-qr',
    'data-seapay-amount',
    'data-seapay-reference',
    'data-seapay-status',
    'data-seapay-receipt',
    'data-seapay-receipt-ok'
  ]) assert.match(page, new RegExp(hook), `${hook} must exist`);
  assert.match(page, /js\/sepay_checkout\.js/);
  assert.match(page, /sepay_checkout_snapshot/);
  assert.doesNotMatch(page, /style="/);
  assert.doesNotMatch(page, /(?:include|require_once).*auth\.php/);
  assert.doesNotMatch(page, /api\/orders\.php|confirm_received|\btransition\b/);
  assert.doesNotMatch(page, /SEPAY_WEBHOOK_API_KEY|0366564953|NGUYEN CHI NGUYEN/);
});
