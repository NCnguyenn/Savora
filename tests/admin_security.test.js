const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('Admin platform exposes explicit migration, security, query and action boundaries', () => {
  for (const file of [
    'lib/platform_schema.php',
    'lib/admin_security.php',
    'lib/admin_repository.php',
    'lib/admin_actions.php'
  ]) {
    assert.ok(fs.existsSync(path.join(root, file)), `${file} must exist`);
  }

  const schema = read('lib/platform_schema.php');
  const security = read('lib/admin_security.php');
  const repository = read('lib/admin_repository.php');
  const actions = read('lib/admin_actions.php');

  assert.match(schema, /function\s+platform_migrate\s*\(mysqli\s+\$conn\)\s*:\s*void/);
  assert.match(schema, /function\s+platform_seed\s*\(mysqli\s+\$conn\)\s*:\s*void/);
  assert.match(security, /function\s+admin_escape\s*\(/);
  assert.match(security, /function\s+admin_csrf_token\s*\(/);
  assert.match(security, /function\s+admin_verify_csrf\s*\(/);
  assert.match(security, /hash_equals\s*\(/);
  assert.match(security, /function\s+admin_require_role\s*\(/);
  assert.match(repository, /function\s+admin_page_data\s*\(/);
  assert.match(actions, /function\s+admin_execute_action\s*\(/);
  assert.match(`${repository}\n${actions}`, /->prepare\s*\(/);
  assert.doesNotMatch(`${repository}\n${actions}`, /\$_(?:GET|POST|REQUEST)\s*\[/);
});

test('database and authentication honor the shared account status contract', () => {
  const db = read('db.php');
  const database = read('lib/database.php');
  const auth = read('auth.php');

  assert.match(db, /require_once\s+__DIR__\s*\.\s*['"]\/lib\/database\.php['"]/);
  assert.match(db, /savora_database_connect\(\)/);
  assert.match(database, /getenv\(['"]SAVORA_DB_NAME['"]\)/);
  assert.match(auth, /status/);
  assert.match(auth, /active/);
  assert.match(auth, /session_version/);
});
