const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('platform exposes role-owned location commands', () => {
  const endpoint = read('api/platform_state.php');
  assert.match(endpoint, /save_gps_location/);
  assert.match(endpoint, /save_manual_location/);
  assert.match(endpoint, /savora_profile_location/);
  assert.match(endpoint, /savora_reverse_geocode/);
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
