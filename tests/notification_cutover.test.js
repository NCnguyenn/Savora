'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

test('role shells load server-backed notifications', () => {
  for (const file of ['components/customer_footer.php', 'components/driver_footer.php', 'components/restaurant_footer.php', 'components/admin_footer.php']) {
    const source = fs.readFileSync(file, 'utf8');
    assert.match(source, /js\/notifications\.js/);
    assert.match(source, /js\/api_client\.js/);
  }
  assert.match(fs.readFileSync('js/notifications.js', 'utf8'), /api\/notifications\.php/);
  assert.match(fs.readFileSync('js/notifications.js', 'utf8'), /sessionStorage|announcement/);
});
