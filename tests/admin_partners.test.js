'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

function contract(file, title) {
  const source = read(file);
  assert.match(source, /components\/admin_header\.php/);
  assert.match(source, /components\/admin_footer\.php/);
  assert.match(source, new RegExp(`<h1[^>]*>${title}</h1>`, 'i'));
  assert.match(source, /admin_page_data/);
  assert.doesNotMatch(source, /href=["']#["']|\son[a-z]+=/i);
  return source;
}

test('Restaurant review workspace shows approved profile data and two final decisions', () => {
  const source = contract('admin_restaurants.php', 'Restaurants &amp; Approvals');
  for (const text of ['Owner', 'Restaurant name', 'Description', 'Cuisine', 'Address', 'City', 'Restaurant phone', 'Opening hours', 'Approve Restaurant', 'Reject Application']) assert.match(source, new RegExp(text, 'i'));
  assert.doesNotMatch(source, /Business Registration|Food Safety Certificate|Owner Identity|Request Changes/i);
});

test('Driver review workspace shows approved vehicle data and two final decisions', () => {
  const source = contract('admin_drivers.php', 'Drivers &amp; Verification');
  for (const text of ['Full name', 'Email', 'Phone', 'Operating area', 'Vehicle type', 'Vehicle model', 'License plate', 'Vehicle color', 'Approve Driver', 'Reject Application']) assert.match(source, new RegExp(text, 'i'));
  assert.doesNotMatch(source, /Driver License|Vehicle Registration|Background Check|Document expiry|Request Changes/i);
});

test('Partner commands transfer claims and media without a legal-document gate', () => {
  const actions = read('lib/admin_actions.php') + read('lib/services/admin_partner_service.php');
  for (const action of ['approve_restaurant', 'reject_restaurant', 'approve_driver', 'reject_driver']) assert.match(actions, new RegExp(action));
  assert.match(actions, /FOR UPDATE/i);
  assert.match(actions, /registration_repository_transfer_claims/);
  assert.match(actions, /registration_repository_release_claims/);
  assert.match(actions, /media_transfer/);
  assert.match(actions, /media_revoke/);
  assert.match(actions, /media_delete_file/);
  assert.ok(actions.indexOf('media_delete_file') > actions.indexOf('$conn->commit()'), 'revoked files must be deleted only after the database decision commits');
  assert.match(actions, /restaurant_weekly_hours/);
  assert.doesNotMatch(actions, /required document|verification_status|changes_requested/i);
});
