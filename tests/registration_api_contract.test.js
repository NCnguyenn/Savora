'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

test('Customer registration API is guarded and delegates to the registration service', () => {
  const endpoint = fs.readFileSync('api/registration.php', 'utf8');
  assert.match(endpoint, /REQUEST_METHOD/);
  assert.match(endpoint, /POST/);
  assert.match(endpoint, /registration_register_customer/);
  assert.match(endpoint, /savora_require_csrf|admin_verify_csrf/);
  assert.match(endpoint, /rate_limit_consume/);
  assert.match(endpoint, /register_customer/);
  assert.match(endpoint, /savora_json/);
  assert.doesNotMatch(endpoint, /role\s*=\s*\$_POST|\$_POST\[['"]role/);
});
