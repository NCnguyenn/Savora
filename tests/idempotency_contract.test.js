'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const read = (file) => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');

test('platform commands require a stable caller-owned idempotency key', () => {
  const bridge = read('js/platform_bridge.js');
  assert.doesNotMatch(bridge, /Date\.now\(\).*Math\.random/);
  assert.match(bridge, /command\(name,payload,idempotencyKey\)/);
  assert.match(bridge, /'Idempotency-Key':idempotencyKey/);
  assert.match(bridge, /SavoraApi\s*=.*intentKey/);
  assert.match(bridge, /crypto\.randomUUID\(\)/);
});

test('checkout retains its intent key until its order request completes', () => {
  const checkout = read('customer_checkout.php');
  assert.match(checkout, /sessionStorage\.getItem\('savora_checkout_intent'\)/);
  assert.match(checkout, /sessionStorage\.setItem\('savora_checkout_intent'/);
  assert.match(checkout, /sessionStorage\.removeItem\('savora_checkout_intent'\)/);
  assert.match(checkout, /SavoraPlatformBridge\.command\('place_order',\s*result\.order,\s*intentKey\)/);
});

test('admin confirmation actions retain a dialog-owned key rather than minting retry keys', () => {
  const adminUi = read('js/admin_ui.js');
  assert.doesNotMatch(adminUi, /Date\.now\(\).*Math\.random/);
  assert.match(adminUi, /crypto\.randomUUID\(\)/);
  assert.match(adminUi, /pendingAction\.idempotencyKey/);
  assert.match(adminUi, /pendingAction\.onConfirm\(reason\.value\.trim\(\), pendingAction\.idempotencyKey\)/);
});

test('both command endpoints reject a reused key with a conflict response', () => {
  const platformEndpoint = read('api/platform_state.php');
  const adminEndpoint = read('admin_action.php');
  assert.match(platformEndpoint, /catch \(SavoraIdempotencyConflict\)/);
  assert.match(platformEndpoint, /savora_error\(409, 'Idempotency key was already used for a different request\.'/);
  assert.match(adminEndpoint, /catch \(SavoraIdempotencyConflict\)/);
  assert.match(adminEndpoint, /savora_error\(409, 'Idempotency key was already used for a different request\.'/);
});
