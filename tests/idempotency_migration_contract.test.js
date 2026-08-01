'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

test('migration 003 never invents a hash for legacy response records', () => {
  const migration = fs.readFileSync('database/migrations/003_idempotency_request_hash.php', 'utf8');
  assert.doesNotMatch(migration, /DEFAULT\s+''/i);
  assert.match(migration, /request payload/i);
  assert.match(migration, /cannot safely backfill/i);
  assert.match(migration, /throw new RuntimeException/);
});

test('idempotency endpoints acquire a per-key lock before lookup and mutation', () => {
  const service = fs.readFileSync('lib/idempotency.php', 'utf8');
  const platform = fs.readFileSync('api/platform_state.php', 'utf8');
  const admin = fs.readFileSync('lib/admin_actions.php', 'utf8');
  assert.match(service, /GET_LOCK/);
  assert.match(service, /RELEASE_LOCK/);
  assert.match(platform, /savora_idempotency_lock/);
  assert.match(admin, /savora_idempotency_lock/);
});
