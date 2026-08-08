'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('order transition service owns role, version, history, dispatch and audit boundaries', () => {
  const service = read('lib/services/order_transition_service.php');
  const repository = read('lib/repositories/order_repository.php');
  assert.match(service, /function order_transition/);
  assert.match(service, /savora_order_can_transition/);
  assert.match(repository, /WHERE id=\? AND version=\?/);
  assert.match(service, /order_repository_insert_history_event/);
  assert.match(service, /order_repository_create_dispatch/);
  assert.match(service, /notification_queue/);
  assert.match(service, /audit_append/);
  assert.match(service, /savora_idempotency_find/);
  assert.doesNotMatch(service, /customerState|localStorage|SavoraState/);
});

test('orders API exposes one authenticated transition command', () => {
  const api = read('api/orders.php');
  assert.match(api, /REQUEST_METHOD.*POST|method !== 'GET'/s);
  assert.match(api, /action.*transition/);
  assert.match(api, /savora_require_csrf/);
  assert.match(api, /savora_require_idempotency_key/);
  assert.match(api, /order_transition/);
  assert.match(api, /savora_idempotency_unlock/);
});

test('order domain includes customer completion and simple Restaurant ready action', () => {
  const source = fs.readFileSync('lib/domain/order_status.php', 'utf8');
  assert.match(source, /'completed'/);
  assert.match(source, /'customer'\s*=>\s*\[\s*'delivered'\s*=>\s*\['completed'\]/s);
  assert.match(source, /'confirmed'\s*=>\s*\['preparing',\s*'ready_for_pickup',\s*'cancelled'\]/);
});
