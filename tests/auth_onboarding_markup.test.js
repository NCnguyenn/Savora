'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('shared authentication shell is accessible and English-only', () => {
  const header = read('components/auth_header.php');
  const footer = read('components/auth_footer.php');
  const css = read('css/auth.css');
  const js = read('js/auth_forms.js');
  assert.match(header, /<html lang="en"/);
  assert.match(header, /Skip to main content/);
  assert.match(header, /<main id="main-content"/);
  assert.equal((header.match(/<main\b/g) || []).length, 1);
  assert.match(footer, /js\/auth_forms\.js/);
  for (const token of ['--auth-forest', '--auth-coral', '--auth-ivory', '--auth-sage', '--auth-focus']) assert.match(css, new RegExp(token));
  assert.match(css, /@media\s*\(max-width:\s*768px\)/);
  for (const hook of ['data-auth-form', 'data-password-toggle', 'data-password-strength', 'data-password-confirmation', 'data-submit-label', 'data-form-summary']) {
    assert.match(js, new RegExp(hook));
  }
});

test('public role selection exposes exactly Customer, Restaurant, and Driver', () => {
  const page = read('register.php');
  for (const route of ['register_customer.php', 'register_restaurant.php', 'register_driver.php']) assert.match(page, new RegExp(route.replace('.', '\\.')));
  assert.doesNotMatch(page, /register_admin\.php|Create an Admin account/i);
  assert.match(page, /Sign in/);
});

test('role registration pages expose the approved English field contracts', () => {
  const contracts = {
    'register_customer.php': ['fullName','username','email','phone','password','passwordConfirmation','deliveryAddress','defaultDeliveryNotes','acceptedTerms'],
    'register_restaurant.php': ['ownerName','username','email','phone','password','passwordConfirmation','restaurantName','description','cuisine','address','city','restaurantPhone','opensAt','closesAt','logo','acceptedTerms'],
    'register_driver.php': ['fullName','username','email','phone','password','passwordConfirmation','city','serviceArea','vehicleType','vehicleModel','licensePlate','vehicleColor','acceptedTerms'],
  };
  for (const [file, names] of Object.entries(contracts)) {
    const page = read(file);
    assert.match(page, /components\/auth_header\.php/);
    assert.match(page, /data-auth-form/);
    assert.match(page, /data-form-summary/);
    for (const name of names) assert.match(page, new RegExp(`name=["']${name}["']`), `${file} missing ${name}`);
  }
  assert.match(read('register_restaurant.php'), /multipart\/form-data/);
});

test('registration result is one-time session state and never a lookup endpoint', () => {
  const page = read('registration_result.php');
  assert.match(page, /registration_result/);
  assert.match(page, /unset\(\$_SESSION\['registration_result'\]\)/);
  assert.doesNotMatch(page, /SELECT|reference_code\s*=/i);
  assert.match(page, /Return to sign in/);
});

test('login, recovery, and reset screens share the English authentication shell', () => {
  const login = read('index.php');
  for (const token of ['components/auth_header.php', 'name="username"', 'name="password"', 'data-password-toggle', 'forgot_password.php', 'register.php']) assert.match(login, new RegExp(token.replace(/[./]/g, '\\$&'), 'i'));
  assert.match(login, /savora_demo_mode\(\)/);
  assert.match(read('forgot_password.php'), /components\/auth_header\.php/);
  assert.match(read('forgot_password.php'), /If an active account matches/i);
  const reset = read('reset_password.php');
  assert.match(reset, /components\/auth_header\.php/);
  assert.match(reset, /data-auth-form/);
  assert.match(reset, /data-password-toggle/);
});
