'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('Partner application endpoint accepts an optional logo and enforces public guards', () => {
  const endpoint = read('api/partner_applications.php');
  assert.match(endpoint, /multipart\/form-data/);
  assert.match(endpoint, /\$_FILES\['logo'\]/);
  assert.match(endpoint, /admin_verify_csrf|savora_verify_csrf/);
  assert.match(endpoint, /rate[_a-z]*limit|savora_rate_limit/i);
  assert.doesNotMatch(endpoint, /Required document missing/);
});

test('Partner service uses identity claims and does not require legal documents', () => {
  const service = read('lib/services/partner_application_service.php');
  assert.match(service, /registration_repository_claim/);
  assert.match(service, /media_store_restaurant_logo/);
  assert.match(service, /passwordConfirmation/);
  assert.match(service, /acceptedTerms/);
  assert.doesNotMatch(service, /Required document missing/);
});
