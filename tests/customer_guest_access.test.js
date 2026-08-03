const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('the public entry point routes to Customer Home and login is explicit', () => {
  assert.match(read('index.php'), /customer_dashboard\.php/);
  assert.match(read('login.php'), /name="username"/);
  assert.match(read('login.php'), /name="password"/);
  assert.match(read('auth.php'), /login\.php/);
});

test('customer access helper allows only internal customer routes', () => {
  const helper = read('lib/customer_access.php');
  assert.match(helper, /function customer_safe_return_to/);
  assert.match(helper, /customer_checkout\.php/);
  assert.match(helper, /customer_profile\.php/);
  assert.match(helper, /parse_url/);
  assert.match(helper, /isset\(\$parts\['scheme'\], \$parts\['host'\]/);
});

test('login preserves a validated return route', () => {
  assert.match(read('login.php'), /return_to/);
  assert.match(read('auth.php'), /customer_safe_return_to/);
  assert.match(read('auth.php'), /customer_login_url/);
});

test('the existing customer chrome supports guest and protected routes', () => {
  const header = read('components/customer_header.php');
  assert.match(header, /customer_dashboard\.php/);
  assert.match(header, /customer_cart\.php/);
  assert.match(header, /customer_redirect_to_login/);
  assert.match(header, /SavoraCustomerAuthenticated/);
  assert.match(header, /Sign in/);
  assert.match(header, /Log out/);
});

test('the existing navigation labels remain unchanged', () => {
  const header = read('components/customer_header.php');
  for (const label of ['Discover', 'Orders', 'Favorites', 'Wallet', 'Profile']) {
    assert.match(header, new RegExp(label));
  }
  assert.match(header, /Open cart/);
});

test('public customer renderers make account API calls conditional', () => {
  for (const file of ['customer_dashboard.php', 'product_detail.php', 'js/customer_location.js']) {
    const source = read(file);
    assert.match(source, /SavoraCustomerAuthenticated/);
  }
  assert.match(read('customer_dashboard.php'), /api\/catalog\.php|catalog\.hydrate/);
  assert.match(read('product_detail.php'), /Add to cart/);
});

test('guest cart remains local and checkout has an authentication gate', () => {
  const cart = read('customer_cart.php');
  assert.match(cart, /SavoraState\.load/);
  assert.match(cart, /customer_checkout\.php/);
  assert.match(cart, /customer_login_url|login\.php/);
  assert.match(read('customer_checkout.php'), /api\/checkout\.php/);
});

test('browser QA covers the public Home to checkout login gate', () => {
  const qa = read('tests/customer_guest_browser_qa.mjs');
  for (const token of ['customer_dashboard.php', 'customer_cart.php', 'customer_checkout.php', 'customer_profile.php', 'SavoraCustomerAuthenticated', 'restaurant_dashboard.php', 'driver_dashboard.php', 'admin_dashboard.php']) {
    assert.match(qa, new RegExp(token.replace(/[.]/g, '\\$&')));
  }
});
