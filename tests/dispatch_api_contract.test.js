'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('dispatch API is an authenticated server-authoritative command boundary', () => {
  const api = read('api/dispatch.php');
  assert.match(api, /savora_request_actor\(\$conn, \['driver', 'admin'\]\)/);
  assert.match(api, /savora_require_csrf/);
  assert.match(api, /savora_require_idempotency_key/);
  assert.match(api, /driver_set_availability/);
  assert.match(api, /dispatch_accept_offer/);
  assert.match(api, /dispatch_decline_offer/);
  assert.match(api, /dispatch_expire_offers/);
  assert.doesNotMatch(api, /localStorage|SavoraState|customerState/);
});
test('dispatch service exposes exclusive offer lifecycle and safe offer fields', () => {
  const service = read('lib/services/dispatch_service.php');
  const repo = read('lib/repositories/dispatch_repository.php');
  assert.match(service, /function driver_set_availability/);
  assert.match(service, /function dispatch_offer_next_driver/);
  assert.match(service, /function dispatch_accept_offer/);
  assert.match(service, /function dispatch_decline_offer/);
  assert.match(service, /function dispatch_expire_offers/);
  assert.match(service, /FOR UPDATE/);
  assert.match(service, /notification_queue/);
  assert.match(service, /audit_append/);
  assert.match(service, /savora_idempotency_find/);
  assert.match(repo, /delivery_offers/);
  assert.match(repo, /driver_locations/);
  assert.match(repo, /distance/);
  assert.doesNotMatch(service, /password|password_hash|customer_phone/);
});
