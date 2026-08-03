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
