const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

function page(file, title) {
  assert.ok(fs.existsSync(path.join(root, file)), `${file} must exist`);
  const source = read(file);
  assert.match(source, /components\/admin_header\.php/);
  assert.match(source, /components\/admin_footer\.php/);
  assert.match(source, /<main\b[^>]+id="admin-main"/);
  assert.match(source, new RegExp(`<h1[^>]*>\\s*${title.replace('&', '&(?:amp;)?')}\\s*</h1>`, 'i'));
  assert.doesNotMatch(source, /href=["']#["']|\son[a-z]+=/i);
  return source;
}

test('Accounts & Access exposes summary, filters, security detail and controlled actions', () => {
  const source = page('admin_accounts.php', 'Accounts & Access');
  for (const copy of ['All Accounts', 'Active', 'Suspended', 'Pending', 'Role', 'Status', 'Created date', 'Last login', 'Security History', 'Active Sessions', 'Suspend Account', 'Reactivate Account', 'Block Account', 'Revoke Sessions', 'Reset Password']) {
    assert.match(source, new RegExp(copy.replace('&', '&(?:amp;)?'), 'i'));
  }
  assert.match(source, /admin_page_data\s*\(\s*\$conn\s*,\s*['"]accounts['"]/);
  assert.match(source, /data-admin-table/);
  assert.match(source, /data-admin-confirm-action/);
  assert.match(source, /name="reason"/);
});

test('Super Admin provisioning UI is private and uses the server command boundary', () => {
  const pageSource = read('admin_accounts.php');
  const ui = read('js/admin_ui.js');
  const actions = read('lib/admin_actions.php');
  assert.match(pageSource, /data-admin-create-account/);
  for (const name of ['full_name', 'username', 'email', 'phone', 'password', 'password_confirmation', 'privilege_level']) assert.match(pageSource, new RegExp(`name=["']${name}["']`));
  assert.match(pageSource, /super_admin/);
  assert.match(ui, /create_admin_account/);
  assert.match(actions, /create_admin_account/);
  assert.doesNotMatch(read('register.php') + read('api/registration.php'), /create_admin_account|register_admin/i);
});

test('Customers exposes order/wallet insight without editable balances', () => {
  const source = page('admin_customers.php', 'Customers');
  for (const copy of ['Total Customers', 'Average Order Value', 'Open Cases', 'Wallet Balance', 'Order History', 'Wallet Ledger', 'Support Cases', 'Security', 'Customer Profile', 'Suspend Account']) {
    assert.match(source, new RegExp(copy, 'i'));
  }
  assert.match(source, /admin_page_data\s*\(\s*\$conn\s*,\s*['"]customers['"]/);
  assert.match(source, /Masked for privacy/);
  assert.doesNotMatch(source, /name=["']wallet_balance["']/i);
  assert.doesNotMatch(source, /type=["']number["'][^>]+wallet/i);
});

test('Identity repository and commands use statuses, versions, sessions, history and audit', () => {
  const repository = read('lib/admin_repository.php');
  const actions = read('lib/admin_actions.php') + read('lib/services/admin_account_service.php');
  for (const token of ['customer_profiles', 'wallet_transactions', 'account_status_history', 'user_sessions', 'support_cases']) assert.match(repository + actions, new RegExp(token));
  for (const action of ['suspend_account', 'reactivate_account', 'block_account', 'revoke_sessions', 'reset_password']) assert.match(actions, new RegExp(action));
  assert.match(actions, /FOR UPDATE/i);
  assert.match(actions, /session_version\s*=\s*session_version\s*\+\s*1/i);
  assert.match(actions, /password_reset_tokens/);
  assert.doesNotMatch(actions, /responseData\['recovery_url'\]/);
  assert.match(actions, /INSERT INTO account_status_history/i);
  assert.doesNotMatch(actions, /UPDATE\s+customer_profiles\s+SET\s+wallet_balance/i);
});
