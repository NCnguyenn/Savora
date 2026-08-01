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
