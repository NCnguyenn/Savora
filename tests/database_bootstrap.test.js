'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('normal db include opens a configured database without migration or seed writes', () => {
  const db = read('db.php');
  assert.match(db, /savora_database_connect\(\)/);
  assert.doesNotMatch(db, /CREATE DATABASE|platform_migrate|platform_seed/);
});

test('migration and seed are explicit CLI-only entry points', () => {
  const migrate = read('scripts/migrate.php');
  const seed = read('scripts/seed.php');
  assert.match(migrate, /PHP_SAPI !== 'cli'/);
  assert.match(seed, /PHP_SAPI !== 'cli'/);
  assert.match(migrate, /savora_apply_migrations/);
  assert.doesNotMatch(migrate, /platform_migrate\(\$conn\)/);
  assert.match(seed, /platform_seed/);
});
