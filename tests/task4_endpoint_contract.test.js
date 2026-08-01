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

test('endpoint compatibility is owned by actual CGI endpoint coverage, not a duplicated probe', () => {
  const behavioral = read('tests/endpoint_compatibility_test.php');
  const probe = read('tests/support/http_contract_probe.php');
  assert.match(behavioral, /admin_action\.php/);
  assert.match(behavioral, /api\/platform_state\.php/);
  assert.match(behavioral, /endpoint_request/);
  assert.doesNotMatch(probe, /Invalid JSON request\.|Secure session expired\.|platform_malformed|admin_malformed/);
});
