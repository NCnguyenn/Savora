'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

const read = file => fs.readFileSync(file, 'utf8');

test('customer location confirmation keeps preview and save as separate states', () => {
  const controller = read('js/customer_location.js');
  const footer = read('components/customer_footer.php');
  const home = read('customer_dashboard.php');
  assert.match(footer, /data-customer-location-preview/);
  assert.match(footer, /data-customer-delivery-details/);
  assert.match(footer, /maxlength="300"/);
  assert.match(footer, /data-customer-location-save[^>]*disabled/);
  assert.doesNotMatch(home, /id="customer-location-dialog"/);
  assert.match(controller, /previewGps/);
  assert.match(controller, /confirmGps|confirmManual/);
  assert.match(controller, /pendingPreview/);
  assert.match(controller, /pendingSync/);
  assert.match(controller, /savora:customer-location-changed/);
  assert.doesNotMatch(controller, /watchPosition/);
});

test('location controller writes guest storage only after explicit confirmation', () => {
  const controller = read('js/customer_location.js');
  const previewStart = controller.indexOf('previewGps');
  const saveGuest = controller.indexOf('saveGuestLocation(draft)');
  assert.ok(previewStart >= 0 && saveGuest >= 0);
  assert.ok(saveGuest > previewStart, 'Guest storage must happen after preview handling.');
  const footer = read('components/customer_footer.php');
  assert.match(footer, /Save address/);
  assert.match(footer, /Try GPS again/);
  assert.match(footer, /Enter address manually/);
});
