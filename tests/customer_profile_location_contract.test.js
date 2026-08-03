'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

const profile = fs.readFileSync('customer_profile.php', 'utf8');

test('Profile exposes saved delivery details and sends nullable coordinates safely', () => {
  assert.match(profile, /id="profile-delivery-details"/);
  assert.match(profile, /name="deliveryDetails"/);
  assert.match(profile, /maxlength="300"/);
  assert.match(profile, /data-customer-location-trigger/);
  assert.match(profile, /deliveryDetails:/);
  assert.match(profile, /=== '' \? null : Number/);
});
