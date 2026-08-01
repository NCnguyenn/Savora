const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('all four role boundaries validate DB-backed session state', () => {
  const files = ['lib/admin_security.php', 'components/customer_header.php', 'components/restaurant_header.php', 'components/driver_header.php', 'lib/request_security.php'];
  for (const file of files) assert.match(read(file), /savora_validate_session/, `${file} must validate the database session`);
  assert.match(read('api/platform_state.php'), /savora_request_actor/, 'api/platform_state.php must use the shared request boundary');
  const security = read('lib/session_security.php');
  for (const token of ['session_version', 'revoked_at', 'account_inactive', 'role_mismatch']) assert.match(security, new RegExp(token));
  assert.match(read('auth.php'), /savora_register_user_session/);
});

test('checkout uses authoritative menu prices and non-wallet payments remain pending', () => {
  const api = read('api/platform_state.php');
  assert.match(api, /JOIN restaurants r ON r\.id=m\.restaurant_id/);
  assert.match(api, /\$unitPrice\s*=\s*round\(\(float\)\s*\$menuItem\['price'\]/);
  assert.doesNotMatch(api, /\$subtotal\s*=\s*round\(\(float\)\s*\(\$payload\['subtotal'\]/);
  assert.match(api, /\$payment === 'wallet' \? 'paid' : 'pending'/);
  assert.match(api, /One order can only contain items from one Restaurant/);
});

test('cross-role fulfillment materializes deliveries and enforces milestone order', () => {
  const api = read('api/platform_state.php');
  const actions = read('lib/admin_actions.php');
  assert.match(api, /driver_accept_order/);
  assert.match(api, /INSERT INTO deliveries/);
  assert.match(actions, /INSERT INTO deliveries/);
  assert.match(api, /'assigned' => 'arrived', 'arrived' => 'picked_up', 'picked_up' => 'delivered'/);
  assert.match(read('js/driver_dashboard.js'), /driver_accept_order/);
});

test('controlled Admin operations carry and enforce optimistic versions', () => {
  const actions = read('lib/admin_actions.php');
  const ui = read('js/admin_ui.js');
  assert.match(actions, /function admin_expected_version/);
  assert.match(actions, /WHERE id=\? AND version=\?/);
  assert.match(ui, /SavoraAdminRecordVersions/);
  assert.match(ui, /version:\s*button\.dataset\.version\s*\|\|\s*versions\[versionKey\]/);
});

test('partner demo accounts require an explicit seed flag and approvals require named documents', () => {
  const schema = read('lib/platform_schema.php');
  const actions = read('lib/admin_actions.php');
  assert.match(schema, /getenv\('SAVORA_SEED_DEMO'\) === '1'/);
  for (const type of ['business_registration', 'food_safety_certificate', 'owner_identity', 'driver_license', 'vehicle_registration', 'background_check']) assert.match(actions, new RegExp(type));
});
