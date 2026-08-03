'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const read = (file) => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');

test('platform commands require a stable caller-owned idempotency key', () => {
  const dispatch = read('api/dispatch.php');
  assert.match(dispatch, /savora_require_idempotency_key/);
  assert.match(dispatch, /savora_idempotency_lock/);
  assert.match(dispatch, /Idempotency-Key/);
});

test('checkout retains its intent key until its order request completes', () => {
  const checkout = read('customer_checkout.php');
  assert.match(checkout, /SavoraApi\.post\('api\/checkout\.php'/);
  assert.match(checkout, /SavoraApi\.intentKey\('customer-place-order'\)/);
  assert.match(checkout, /SavoraApi\.clearIntentKey\('customer-place-order'\)/);
  assert.match(checkout, /action: 'place_order'/);
});

test('admin confirmation actions retain a dialog-owned key rather than minting retry keys', () => {
  const adminUi = read('js/admin_ui.js');
  assert.doesNotMatch(adminUi, /Date\.now\(\).*Math\.random/);
  assert.match(adminUi, /crypto\.randomUUID\(\)/);
  assert.match(adminUi, /pendingAction\.idempotencyKey/);
  assert.match(adminUi, /pendingAction\.onConfirm\(reason\.value\.trim\(\), pendingAction\.idempotencyKey\)/);
});

test('both command endpoints reject a reused key with a conflict response', () => {
  const platformEndpoint = read('api/dispatch.php');
  const adminEndpoint = read('admin_action.php');
  assert.match(platformEndpoint, /catch \(SavoraIdempotencyConflict\)/);
  assert.match(platformEndpoint, /savora_error\(409, 'Idempotency key was already used for a different dispatch request\.'/);
  assert.match(adminEndpoint, /catch \(SavoraIdempotencyConflict\)/);
  assert.match(adminEndpoint, /savora_error\(409, 'Idempotency key was already used for a different request\.'/);
});

test('endpoint replay fixtures use the canonical PHP request hash helper', () => {
  const fixture = read('tests/endpoint_compatibility_test.php');
  assert.match(fixture, /require_once __DIR__ \. '\/\.\.\/lib\/idempotency\.php';/);
  assert.match(fixture, /savora_idempotency_hash\(\$replayAction, \[\]\)/);
  assert.doesNotMatch(fixture, /hash\('sha256', \$replayAction \./);
});
