'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('Driver profile reads and writes through the server profile API', () => {
  const controller = read('js/driver_profile.js');
  assert.match(controller, /api\/profile\.php/);
  assert.match(controller, /update_driver_contact/);
  assert.match(controller, /request_driver_vehicle_change/);
  assert.match(controller, /update_driver_preferences/);
  assert.doesNotMatch(controller, /setProfile|setLocation|serviceRadiusKm.*persist/);
});

test('Driver profile service exposes review-only operational identity fields', () => {
  const service = read('lib/services/profile_service.php');
  const api = read('api/profile.php');
  assert.match(service, /profile_for_driver/);
  assert.match(service, /Driver identity and operational fields are Admin-controlled/);
  assert.match(service, /driver_change_requests/);
  assert.match(api, /['"]driver['"]/);
});
