const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

const read = file => fs.readFileSync(file, 'utf8');

test('Admin action router contains no SQL and delegates to focused services', () => {
  const router = read('lib/admin_actions.php');
  assert.doesNotMatch(router, /SELECT |INSERT INTO|UPDATE |DELETE FROM|begin_transaction/);
  for (const service of [
    'admin_account_service.php',
    'admin_partner_service.php',
    'admin_order_service.php',
    'finance_service.php',
    'admin_settings_service.php',
  ]) {
    assert.match(router, new RegExp(service.replace('.', '\\.')));
  }
});

test('Admin service boundaries own their SQL and audit/transaction behavior', () => {
  for (const file of [
    'lib/services/admin_account_service.php',
    'lib/services/admin_partner_service.php',
    'lib/services/admin_order_service.php',
    'lib/services/finance_service.php',
    'lib/services/admin_settings_service.php',
  ]) {
    const source = read(file);
    assert.match(source, /->prepare\(/, `${file} must own prepared SQL`);
    assert.match(source, /begin_transaction|savora_idempotency_store/, `${file} must own command consistency`);
  }
});
