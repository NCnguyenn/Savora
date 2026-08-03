'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('orders API exposes role-derived reads and one authenticated transition boundary', () => {
  const api = read('api/orders.php');
  assert.match(api, /REQUEST_METHOD/);
  assert.match(api, /savora_request_actor\(\$conn, \['customer', 'restaurant', 'driver', 'admin'\]\)/);
  assert.match(api, /orders_for_customer/);
  assert.match(api, /orders_for_restaurant/);
  assert.match(api, /orders_for_driver/);
  assert.match(api, /order_for_admin/);
  assert.doesNotMatch(api, /\$_GET\[['"](?:role|userId)['"]\]/);
  assert.match(api, /'orders'\s*=>/);
  assert.match(api, /'pagination'\s*=>/);
});

test('order read model exposes server snapshots and no secret fields', () => {
  const repo = read('lib/repositories/order_repository.php');
  const service = read('lib/services/order_query_service.php');
  assert.match(service, /function orders_for_customer/);
  assert.match(service, /function orders_for_restaurant/);
  assert.match(service, /function orders_for_driver/);
  assert.match(service, /function order_for_admin/);
  assert.match(repo, /o\.customer_user_id=\?/);
  assert.match(repo, /r\.owner_user_id=\?/);
  assert.match(repo, /d\.driver_user_id=\?/);
  assert.match(repo, /statusHistory/);
  assert.match(repo, /milestones/);
  assert.doesNotMatch(repo, /password|reset_token|password_hash/);
});
