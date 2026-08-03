'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

test('production configuration and public auth paths expose bounded security controls', () => {
  const environment = fs.readFileSync('lib/environment.php', 'utf8');
  const database = fs.readFileSync('lib/database.php', 'utf8');
  const auth = fs.readFileSync('auth.php', 'utf8');
  const reset = fs.readFileSync('reset_password.php', 'utf8');
  const index = fs.readFileSync('index.php', 'utf8');
  const actions = fs.readFileSync('lib/admin_actions.php', 'utf8');
  assert.match(environment, /SAVORA_APP_SECRET/);
  assert.match(database, /savora_require_production_database_config/);
  assert.match(auth, /rate_limit_consume/);
  assert.match(reset, /rate_limit_consume/);
  assert.match(index, /savora_demo_mode/);
  assert.doesNotMatch(auth, /\$_POST\[['"]role['"]\]/);
  assert.match(fs.readFileSync('logout.php', 'utf8'), /savora_revoke_current_session/);
  assert.match(fs.readFileSync('logout.php', 'utf8'), /auth_notice/);
  assert.match(fs.readFileSync('lib/session_security.php', 'utf8'), /session_id\(['"]['"]\)/);
  assert.doesNotMatch(actions, /responseData\['recovery_url'\]/);
});
