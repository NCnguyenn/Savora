'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('hybrid payment and GPS demo entry points remain wired into every role', () => {
  assert.match(read('customer_checkout.php'), /pay_now|pay_on_receipt/);
  assert.match(read('api/payment_demo.php'), /savora_demo_mode/);
  assert.match(read('api/tracking.php'), /demo_route_snapshot/);
  assert.match(read('js/customer_tracking.js'), /confirm_received/);
  assert.match(read('js/driver_delivery.js'), /demo_start_delivery/);
  assert.match(read('js/driver_dashboard.js'), /demo_start_shift/);
  assert.match(read('js/restaurant_orders.js'), /Food is ready/);
});
