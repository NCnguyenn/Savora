const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('all four role boundaries validate DB-backed session state', () => {
  const files = ['lib/admin_security.php', 'components/customer_header.php', 'components/restaurant_header.php', 'components/driver_header.php', 'lib/request_security.php'];
  for (const file of files) assert.match(read(file), /savora_validate_session/, `${file} must validate the database session`);
  assert.match(read('api/dispatch.php'), /savora_request_actor/, 'api/dispatch.php must use the shared request boundary');
  const security = read('lib/session_security.php');
  for (const token of ['session_version', 'revoked_at', 'account_inactive', 'role_mismatch']) assert.match(security, new RegExp(token));
  assert.match(read('auth.php'), /savora_register_user_session/);
});

test('checkout uses the focused quote and order services instead of platform state', () => {
  const api = read('api/checkout.php');
  const service = read('lib/services/order_service.php');
  assert.match(api, /api\/checkout\.php|order_place_from_quote/);
  assert.match(service, /order_place_from_quote/);
  assert.match(service, /order_repository_menu_available/);
  assert.match(service, /payment_repository_status/);
  assert.doesNotMatch(read('api/dispatch.php'), /place_order/);
});

test('cross-role fulfillment is owned by the focused dispatch boundary', () => {
  const api = read('api/dispatch.php');
  const transition = read('lib/services/order_transition_service.php');
  assert.doesNotMatch(api, /driver_accept_order|driver_milestone|INSERT INTO deliveries/);
  assert.match(transition, /savora_order_can_transition/);
  assert.match(transition, /order_repository_transition_target/);
  assert.doesNotMatch(read('js/driver_dashboard.js'), /driver_accept_order|SavoraPlatformBridge/);
});

test('controlled Admin operations carry and enforce optimistic versions', () => {
  const actions = read('lib/admin_actions.php') + read('lib/services/admin_settings_service.php') + read('lib/services/admin_order_service.php');
  const ui = read('js/admin_ui.js');
  assert.match(actions, /function admin_expected_version/);
  assert.match(actions, /WHERE id=\? AND version=\?/);
  assert.match(ui, /SavoraAdminRecordVersions/);
  assert.match(ui, /version:\s*button\.dataset\.version\s*\|\|\s*versions\[versionKey\]/);
});

test('partner demo accounts require an explicit seed flag and approvals consume reserved identities', () => {
  const schema = read('lib/platform_schema.php');
  const actions = read('lib/admin_actions.php') + read('lib/services/admin_partner_service.php');
  assert.match(schema, /getenv\('SAVORA_SEED_DEMO'\) === '1'/);
  assert.match(actions, /registration_repository_transfer_claims/);
  assert.match(actions, /registration_repository_release_claims/);
  assert.doesNotMatch(actions, /verification_status|required document/i);
});
