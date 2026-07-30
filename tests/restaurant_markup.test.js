const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('Restaurant Overview uses the shared authenticated shell and semantic data hooks', () => {
  for (const file of [
    'components/restaurant_header.php',
    'components/restaurant_footer.php',
    'css/restaurant_style.css',
    'js/restaurant_ui.js'
  ]) {
    assert.ok(fs.existsSync(path.join(root, file)), `${file} must exist`);
  }

  const page = read('restaurant_dashboard.php');
  const header = read('components/restaurant_header.php');
  const footer = read('components/restaurant_footer.php');

  assert.match(page, /require_once\s+__DIR__\s*\.\s*['\"]\/components\/restaurant_header\.php['\"]/);
  assert.match(page, /require_once\s+__DIR__\s*\.\s*['\"]\/components\/restaurant_footer\.php['\"]/);
  assert.match(header, /session_status\(\)\s*===\s*PHP_SESSION_NONE/);
  assert.match(header, /\(\$_SESSION\['role'\]\s*\?\?\s*''\)\s*!==\s*'restaurant'/);
  assert.equal((page.match(/<main\b/gi) || []).length, 1, 'Overview has one main landmark');
  assert.match(page, /<h1[^>]*>\s*Restaurant Overview\s*<\/h1>/);
  assert.match(page, /data-overview-kpis/);
  assert.match(page, /data-overview-chart/);
  assert.match(page, /data-live-queue/);
  assert.match(page, /data-top-items/);
  assert.match(page, /data-low-stock/);
  assert.match(page, /SavoraRestaurantState\.load\(\)/);
  assert.match(page, /SavoraState\.load\(\)/);
  assert.match(page, /SavoraRestaurantUI\.el/);
  assert.doesNotMatch(`${page}\n${header}\n${footer}`, /href\s*=\s*["']#["']/i);
  assert.doesNotMatch(`${page}\n${header}\n${footer}`, /\son[a-z]+\s*=/i);
});

test('Restaurant shell has local assets, accessible navigation, and named UI helpers', () => {
  const header = read('components/restaurant_header.php');
  const footer = read('components/restaurant_footer.php');
  const css = read('css/restaurant_style.css');
  const ui = read('js/restaurant_ui.js');

  assert.match(header, /assets\/vendor\/fontawesome\/css\/all\.min\.css/);
  assert.match(header, /css\/restaurant_style\.css/);
  assert.match(footer, /js\/restaurant_state\.js/);
  assert.match(footer, /js\/customer_state\.js/);
  assert.match(footer, /js\/restaurant_ui\.js/);
  assert.match(header, /class="skip-link"/);
  assert.match(header, /role="dialog"/);
  assert.match(header, /aria-current="page"/);
  assert.match(header, /for="restaurant-search"/);
  assert.match(header, /data-accepting-orders/);
  assert.match(header, /data-owner-menu/);
  assert.match(footer, /aria-live="polite"/);
  assert.match(ui, /function el\(/);
  assert.match(ui, /function showToast\(/);
  assert.match(ui, /function formatMoney\(/);
  assert.match(ui, /function statusChip\(/);
  assert.match(ui, /function refreshShell\(/);
  assert.match(ui, /function openDialog\(/);
  assert.match(ui, /function closeDialog\(/);
  assert.doesNotMatch(ui, /innerHTML\s*=/);
  assert.match(css, /--restaurant-primary:\s*#073B2B/);
  assert.match(css, /--restaurant-focus:\s*#1B75D0/);
  assert.match(css, /:focus-visible/);
  assert.match(css, /prefers-reduced-motion/);
  for (const breakpoint of ['1024px', '768px', '480px']) {
    assert.match(css, new RegExp(`@media \\(max-width: ${breakpoint.replace('.', '\\.')}\\)`));
  }
});
