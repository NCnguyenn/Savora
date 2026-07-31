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
  assert.match(header, /data-driver-session-id=/);
  assert.match(read('js/driver_dashboard.js'), /scheduleDispatchReconciliation/);
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

test('Demo dispatch candidates have matching driver accounts', () => {
  const database = read('db.php');
  const schema = read('lib/platform_schema.php');
  const databaseBoundary = `${database}\n${schema}`;
  assert.match(databaseBoundary, /driver-nearby-2/);
  assert.match(databaseBoundary, /driver-nearby-3/);
  assert.match(databaseBoundary, /INSERT IGNORE INTO users/);
});

test('Database connection honors a configurable XAMPP MySQL port', () => {
  const database = read('db.php');
  assert.match(database, /SAVORA_DB_PORT/);
  assert.match(database, /new mysqli\(\$host, \$username, \$password, '', \$dbPort\)/);
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
  assert.match(css, /\[data-driver-page="overview"\]\.has-active-offer \.driver-offer-actions\s*\{\s*position:\s*fixed;/);
  assert.match(css, /grid-template-columns:\s*1\.25fr 0\.9fr;/);
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

test('Driver Overview exposes location, summary and exclusive offer controls', () => {
  const page = read('driver_dashboard.php');
  assert.match(page, /require_once\s+__DIR__\s*\.\s*['"]\/components\/driver_header\.php['"]/);
  assert.match(page, /require_once\s+__DIR__\s*\.\s*['"]\/components\/driver_footer\.php['"]/);
  assert.equal((page.match(/<main\b/gi) || []).length, 1);
  assert.match(page, /<h1[^>]*>[\s\S]*Good afternoon/);
  for (const hook of [
    'data-driver-availability',
    'data-driver-location',
    'data-use-driver-gps',
    'data-enter-driver-address',
    'data-driver-map',
    'data-driver-summary',
    'data-delivery-offer',
    'data-offer-countdown',
    'data-accept-offer',
    'data-decline-offer'
  ]) assert.match(page, new RegExp(hook));
  assert.doesNotMatch(page, /\son[a-z]+\s*=/i);
  assert.doesNotMatch(page, /href\s*=\s*["']#["']/i);
});

test('Active Delivery exposes route, parties, order details and one milestone action', () => {
  const page = read('driver_delivery.php');
  assert.match(page, /require_once\s+__DIR__\s*\.\s*['"]\/components\/driver_header\.php['"]/);
  assert.match(page, /require_once\s+__DIR__\s*\.\s*['"]\/components\/driver_footer\.php['"]/);
  assert.equal((page.match(/<main\b/gi) || []).length, 1);
  assert.match(page, /<h1[^>]*>\s*Active delivery\s*<\/h1>/);
  for (const hook of [
    'data-active-delivery',
    'data-delivery-map',
    'data-delivery-timeline',
    'data-pickup-details',
    'data-customer-details',
    'data-delivery-items',
    'data-delivery-payment',
    'data-delivery-primary-action',
    'data-report-issue'
  ]) assert.match(page, new RegExp(hook));
  assert.doesNotMatch(page, /\son[a-z]+\s*=/i);
  assert.doesNotMatch(page, /href\s*=\s*["']#["']/i);
});

test('Delivery History exposes accessible filters, responsive records and detail drawer', () => {
  const page = read('driver_history.php');
  const controller = read('js/driver_history.js');
  assert.equal((page.match(/<main\b/gi) || []).length, 1);
  assert.match(page, /<h1[^>]*>\s*Delivery history\s*<\/h1>/);
  for (const hook of [
    'data-history-summary',
    'data-history-search',
    'data-history-date',
    'data-history-status',
    'data-history-results',
    'data-history-cards',
    'data-history-export',
    'data-history-drawer',
    'data-history-detail',
    'data-history-close'
  ]) assert.match(page, new RegExp(hook));
  assert.match(page, /<caption[^>]*>Driver delivery records<\/caption>/);
  assert.match(page, /<label[^>]*for="driver-history-search"/);
  assert.match(page, /<label[^>]*for="driver-history-date"/);
  assert.match(page, /<label[^>]*for="driver-history-status"/);
  assert.match(controller, /ui\.openDialog\(drawer, trigger\)/);
  assert.match(controller, /ui\.closeDialog\(drawer\)/);
  assert.doesNotMatch(page, /\son[a-z]+\s*=/i);
});

test('Driver Earnings exposes KPIs, chart, payout, COD and labelled recent records', () => {
  const page = read('driver_earnings.php');
  assert.equal((page.match(/<main\b/gi) || []).length, 1);
  assert.match(page, /<h1[^>]*>\s*Earnings\s*<\/h1>/);
  for (const hook of [
    'data-earnings-kpis',
    'data-earnings-chart',
    'data-next-payout',
    'data-cash-balance',
    'data-earnings-records',
    'data-download-statement'
  ]) assert.match(page, new RegExp(hook));
  assert.match(page, /<caption[^>]*>Recent driver earnings<\/caption>/);
  assert.match(page, /local preview/i);
  assert.doesNotMatch(page, /\son[a-z]+\s*=/i);
});

test('Driver Profile exposes labelled personal, vehicle, document, location and preference controls', () => {
  const page = read('driver_profile.php');
  assert.equal((page.match(/<main\b/gi) || []).length, 1);
  assert.match(page, /<h1[^>]*>\s*Profile &amp; settings\s*<\/h1>/);
  for (const hook of [
    'data-driver-profile-form',
    'data-personal-information',
    'data-driver-vehicle',
    'data-driver-documents',
    'data-driver-location-settings',
    'data-profile-use-gps',
    'data-profile-manual-address',
    'data-driver-preferences',
    'data-profile-save'
  ]) assert.match(page, new RegExp(hook));
  for (const id of [
    'driver-profile-name',
    'driver-profile-phone',
    'driver-profile-email',
    'driver-vehicle-model',
    'driver-license-plate',
    'driver-current-address',
    'driver-service-radius'
  ]) assert.match(page, new RegExp(`<label[^>]*for="${id}"`));
  assert.match(page, /href="logout\.php"/);
  assert.doesNotMatch(page, /\son[a-z]+\s*=/i);
});
