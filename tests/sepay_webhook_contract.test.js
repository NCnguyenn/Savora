'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

test('SeaPay webhook keeps provider authentication while delegating confirmation authority', () => {
  assert.equal(fs.existsSync('lib/services/payment_confirmation_service.php'), true, 'shared payment service must exist');
  const endpoint = fs.readFileSync('api/webhook_seapay.php', 'utf8');
  const service = fs.readFileSync('lib/services/payment_confirmation_service.php', 'utf8');
  const config = fs.readFileSync('config/local.php.example', 'utf8');

  assert.match(endpoint, /sepay_webhook_is_authorized\(\$_SERVER/);
  assert.match(endpoint, /payment_confirm_incoming\(\$conn,\s*\$event,\s*'seapay'\)/);
  assert.doesNotMatch(endpoint, /begin_transaction|UPDATE payments|FOR UPDATE/);
  assert.match(service, /payment_repository_target_by_reference/);
  assert.match(service, /payment_repository_by_provider_reference/);
  assert.match(service, /payment_repository_mark_paid/);
  assert.doesNotMatch(endpoint, /file_put_contents/);
  assert.match(config, /SEPAY_WEBHOOK_API_KEY/);
});

test('Customer demo endpoint is POST, CSRF, idempotency, ownership, and production guarded', () => {
  assert.equal(fs.existsSync('api/payment_demo.php'), true, 'Customer demo endpoint must exist');
  const endpoint = fs.readFileSync('api/payment_demo.php', 'utf8');

  assert.match(endpoint, /savora_request_actor\(\$conn,\s*\['customer'\]\)/);
  assert.match(endpoint, /savora_demo_mode\(\)/);
  assert.match(endpoint, /REQUEST_METHOD/);
  assert.match(endpoint, /savora_require_csrf/);
  assert.match(endpoint, /savora_require_idempotency_key/);
  assert.match(endpoint, /simulate_success/);
  assert.match(endpoint, /savora_idempotency_lock/);
  assert.match(endpoint, /savora_idempotency_unlock/);
  assert.match(endpoint, /payment_simulate_customer_success/);
  assert.match(endpoint, /\$response\['status'\]/);
  assert.doesNotMatch(endpoint, /SEPAY_WEBHOOK_API_KEY|SAVORA_APP_SECRET|password/i);
});
