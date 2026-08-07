'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

test('SeaPay webhook endpoint enforces provider authentication and idempotent payment binding', () => {
  const endpoint = fs.readFileSync('api/webhook_seapay.php', 'utf8');
  const config = fs.readFileSync('config/local.php.example', 'utf8');

  assert.match(endpoint, /sepay_webhook_is_authorized\(\$_SERVER/);
  assert.match(endpoint, /provider_reference/);
  assert.match(endpoint, /FOR UPDATE/);
  assert.match(endpoint, /method.*seapay/);
  assert.match(endpoint, /sepay_webhook_amount_matches/);
  assert.doesNotMatch(endpoint, /file_put_contents/);
  assert.match(config, /SEPAY_WEBHOOK_API_KEY/);
});
