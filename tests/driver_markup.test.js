const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('Driver shell authenticates drivers and exposes exactly five top-level routes', () => {
  for (const file of [
    'components/driver_header.php',
    'components/driver_footer.php',
    'css/driver_style.css',
    'js/driver_ui.js'
  ]) {
    assert.ok(fs.existsSync(path.join(root, file)), `${file} must exist`);
  }

  const header = read('components/driver_header.php');
  const footer = read('components/driver_footer.php');
  const routeNames = [
    'driver_dashboard.php',
    'driver_delivery.php',
    'driver_history.php',
    'driver_earnings.php',
    'driver_profile.php'
  ];

  assert.match(header, /session_status\(\)\s*===\s*PHP_SESSION_NONE/);
  assert.match(header, /\(\$_SESSION\['role'\]\s*\?\?\s*''\)\s*!==\s*'driver'/);
  for (const route of routeNames) assert.match(header, new RegExp(route.replace('.', '\\.')));
  assert.equal((header.match(/=>\s*\[['"](?:Overview|Active Delivery|History|Earnings|Profile)['"]/g) || []).length, 5);
  assert.match(header, /aria-current="page"/);
  assert.match(header, /class="skip-link"/);
  assert.match(header, /assets\/vendor\/fontawesome\/css\/all\.min\.css/);
  assert.match(footer, /js\/customer_state\.js/);
  assert.match(footer, /js\/restaurant_state\.js/);
  assert.match(footer, /js\/driver_state\.js/);
  assert.match(footer, /js\/driver_ui\.js/);
});

test('Driver stylesheet contains the approved palette, responsive navigation and visible focus', () => {
  const css = read('css/driver_style.css').toLowerCase();
  for (const color of [
    '#073b2b',
    '#04291e',
    '#ef634b',
    '#fbf9f3',
    '#e8eddf',
    '#1c2923',
    '#657169',
    '#dfe4da',
    '#1b75d0'
  ]) assert.match(css, new RegExp(color));

  assert.match(css, /:focus-visible/);
  assert.match(css, /@media\s*\(max-width:\s*860px\)/);
  assert.match(css, /grid-template-columns:\s*repeat\(5,\s*1fr\)/);
});

test('Driver UI renders persisted values with DOM nodes and exposes dialog and toast helpers', () => {
  const ui = read('js/driver_ui.js');
  assert.doesNotMatch(ui, /innerHTML\s*=/);
  for (const helper of ['el', 'money', 'formatDate', 'showToast', 'announce', 'openDialog', 'closeDialog']) {
    assert.match(ui, new RegExp(`\\b${helper}\\b`));
  }
  assert.match(ui, /textContent/);
  assert.match(ui, /Escape/);
});
