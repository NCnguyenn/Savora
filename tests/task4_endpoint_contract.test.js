'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

const read = file => fs.readFileSync(file, 'utf8');

test('both action endpoints delegate idempotency header validation to the shared boundary', () => {
  const security = read('lib/request_security.php');
  assert.match(security, /function savora_require_idempotency_key\(array \$headers\): string/);
  assert.match(security, /\[A-Za-z0-9\]/);
  for (const endpoint of ['admin_action.php', 'api/platform_state.php']) {
    const source = read(endpoint);
    assert.match(source, /savora_require_idempotency_key/);
    assert.doesNotMatch(source, /mb_substr\(trim\(\(string\) \(\$_SERVER\['HTTP_IDEMPOTENCY_KEY'\]/);
  }
});

test('endpoint-specific malformed JSON and Admin CSRF responses remain compatible', () => {
  const platform = read('api/platform_state.php');
  const admin = read('admin_action.php');
  assert.match(platform, /catch \(JsonException\)[\s\S]*savora_error\(400, 'Invalid JSON\.'\)/);
  assert.match(admin, /catch \(JsonException\)[\s\S]*savora_error\(400, 'Invalid JSON request\.', \[\], admin_reference_id\(\)\)/);
  assert.match(admin, /catch \(InvalidArgumentException\)[\s\S]*savora_error\(403, 'Your secure session expired\. Refresh and try again\.', \[\], admin_reference_id\(\)\)/);
});
