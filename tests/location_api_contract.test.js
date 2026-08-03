const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('focused location API exposes role-owned location commands', () => {
  const endpoint = read('api/location.php');
  assert.match(endpoint, /savora_request_actor\(\$conn, \['customer', 'driver', 'restaurant'\]\)/);
  assert.match(endpoint, /savora_require_csrf/);
  assert.match(endpoint, /savora_require_idempotency_key/);
  assert.match(endpoint, /save_gps_location/);
  assert.match(endpoint, /save_manual_location/);
  assert.match(endpoint, /savora_profile_location/);
  assert.match(endpoint, /savora_reverse_geocode/);
  assert.equal(fs.existsSync(path.join(root, 'api/platform_state.php')), false);
});

test('manual persistence clears stale coordinates', () => {
  const repository = read('lib/profile_locations.php');
  for (const role of ['customer', 'driver', 'restaurant']) {
    assert.match(repository, new RegExp("'" + role + "'"));
  }
  assert.match(repository, /latitude\s*=\s*NULL/);
  assert.match(repository, /longitude\s*=\s*NULL/);
  assert.doesNotMatch(repository, /\$_(?:GET|POST|REQUEST).*user/i);
});

test('public GPS preview is a bounded, rate-limited, non-mutating JSON boundary', () => {
  const endpoint = read('api/location_preview.php');
  assert.match(endpoint, /savora_start_session/);
  assert.match(endpoint, /REQUEST_METHOD.*POST/);
  assert.match(endpoint, /application\/json/);
  assert.match(endpoint, /CONTENT_LENGTH.*4096/);
  assert.match(endpoint, /strlen\(\$raw\).*4096/);
  assert.match(endpoint, /customer_location_same_origin/);
  assert.match(endpoint, /rate_limit_consume\(\$conn, \$remoteAddress, 'customer_location_preview', 10, 600\)/);
  assert.match(endpoint, /customer_location_preview/);
  assert.doesNotMatch(endpoint, /savora_require_idempotency_key/);
  assert.doesNotMatch(endpoint, /savora_request_actor/);
  assert.match(read('lib/customer_location_preview.php'), /customer_location_same_origin/);
});
