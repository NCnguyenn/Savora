'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

test('session validation performs no update and heartbeat owns last-seen writes', () => {
  const source = fs.readFileSync('lib/session_security.php', 'utf8');
  const validate = source.slice(source.indexOf('function savora_validate_session'), source.indexOf('function savora_revoke_current_session'));
  assert.doesNotMatch(validate, /UPDATE user_sessions SET last_seen_at/);
  assert.match(source, /function savora_touch_session/);
  assert.match(fs.readFileSync('api/session_heartbeat.php', 'utf8'), /savora_touch_session/);
});

test('normal authenticated GET bootstrap reads but does not issue CSRF tokens', () => {
  const security = fs.readFileSync('lib/admin_security.php', 'utf8');
  const csrfGetter = security.slice(security.indexOf('function admin_csrf_token'), security.indexOf('function admin_issue_csrf_token'));
  assert.doesNotMatch(csrfGetter, /\$_SESSION\['admin_csrf'\]\s*=/);
  assert.match(security, /function admin_issue_csrf_token/);
  assert.match(fs.readFileSync('auth.php', 'utf8'), /admin_issue_csrf_token\(\)/);

  for (const footer of ['components/customer_footer.php', 'components/restaurant_footer.php', 'components/driver_footer.php', 'components/admin_footer.php']) {
    const source = fs.readFileSync(footer, 'utf8');
    assert.doesNotMatch(source, /admin_csrf_token\s*\(/, `${footer} must not issue a CSRF token during rendering`);
    assert.match(source, /\$_SESSION\['admin_csrf'\]\s*\?\?\s*''/, `${footer} must read the existing CSRF token`);
  }
});
