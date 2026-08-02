const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('Admin shell exposes exactly eleven approved routes with local assets', () => {
  for (const file of [
    'components/admin_header.php',
    'components/admin_footer.php',
    'css/admin_style.css',
    'js/admin_ui.js'
  ]) assert.ok(fs.existsSync(path.join(root, file)), `${file} must exist`);

  const header = read('components/admin_header.php');
  const routes = [
    ['admin_dashboard.php', 'Overview'],
    ['admin_accounts.php', 'Accounts'],
    ['admin_customers.php', 'Customers'],
    ['admin_restaurants.php', 'Restaurants'],
    ['admin_drivers.php', 'Drivers'],
    ['admin_orders.php', 'Orders'],
    ['admin_cases.php', 'Cases & Refunds'],
    ['admin_finance.php', 'Finance'],
    ['admin_promotions.php', 'Promotions & Fees'],
    ['admin_analytics.php', 'Analytics'],
    ['admin_settings.php', 'Settings & Audit']
  ];

  for (const [route, label] of routes) {
    assert.match(header, new RegExp(route.replace('.', '\\.')));
    assert.match(header, new RegExp(label.replace('&', '&amp;|&')));
  }
  assert.equal((header.match(/'admin_[a-z_]+\.php'\s*=>/g) || []).length, 11);
  assert.match(header, /admin_require_role\s*\(\)/);
  assert.match(header, /assets\/vendor\/fontawesome\/css\/all\.min\.css/);
  assert.match(header, /css\/admin_style\.css/);
  assert.match(header, /class="skip-link"/);
  assert.match(header, /aria-label="Admin navigation"/);
  assert.match(header, /aria-current="page"/);
  assert.match(header, /aria-label="Global search"/);
  assert.match(header, /Admin Mode/);
  assert.match(header, /role="dialog"/);
  assert.match(header, /data-admin-mobile-navigation/);
});

test('Admin footer provides shared accessible overlay and feedback roots', () => {
  const footer = read('components/admin_footer.php');
  assert.match(footer, /data-admin-drawer/);
  assert.match(footer, /data-admin-confirmation/);
  assert.match(footer, /role="dialog"/);
  assert.match(footer, /aria-modal="true"/);
  assert.match(footer, /aria-live="polite"/);
  assert.match(footer, /js\/admin_ui\.js/);
  assert.doesNotMatch(footer, /\son[a-z]+\s*=/i);
});

test('Admin Overview adopts the shared shell and one main landmark', () => {
  const page = read('admin_dashboard.php');
  assert.match(page, /require_once\s+__DIR__\s*\.\s*['"]\/components\/admin_header\.php['"]/);
  assert.match(page, /require_once\s+__DIR__\s*\.\s*['"]\/components\/admin_footer\.php['"]/);
  assert.equal((page.match(/<main\b/gi) || []).length, 1);
  assert.match(page, /id="admin-main"/);
  assert.match(page, /<h1[^>]*>\s*System Overview\s*<\/h1>/);
  assert.doesNotMatch(page, /cdnjs|href\s*=\s*["']#["']/i);
  assert.doesNotMatch(page, /\son[a-z]+\s*=/i);
});

test('Admin stylesheet defines the approved palette and responsive behavior', () => {
  const css = read('css/admin_style.css');
  for (const declaration of [
    '--admin-primary: #073B2B',
    '--admin-primary-deep: #04291E',
    '--admin-coral: #EF634B',
    '--admin-ivory: #FBF9F3',
    '--admin-sage: #E8EDDF',
    '--admin-text: #1C2923',
    '--admin-muted: #657169',
    '--admin-border: #DFE4DA',
    '--admin-focus: #1B75D0'
  ]) assert.match(css, new RegExp(declaration.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i'));
  assert.match(css, /:focus-visible/);
  assert.match(css, /prefers-reduced-motion/);
  for (const breakpoint of ['1200px', '900px', '768px', '480px']) {
    assert.match(css, new RegExp(`@media \\(max-width: ${breakpoint}\\)`));
  }
});

test('Admin can read role GPS addresses without receiving GPS controls', () => {
  const repository = read('lib/admin_repository.php');
  const customer = read('admin_customers.php');
  const driver = read('admin_drivers.php');
  const restaurant = read('admin_restaurants.php');
  for (const column of ['latitude', 'longitude', 'location_method', 'location_updated_at']) assert.match(repository, new RegExp(column));
  for (const page of [customer, driver, restaurant]) {
    assert.match(page, /GPS address/);
    assert.match(page, /location_address/);
    assert.doesNotMatch(page, /data-use-current-location|data-profile-use-gps|data-use-driver-gps/);
  }
});

