'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('catalog migration is registered after idempotency and defines the catalog ownership contract', () => {
  const registry = read('lib/migrations.php');
  const migration = read('database/migrations/004_catalog_contract.php');
  assert.ok(registry.indexOf('004_catalog_contract') > registry.indexOf('003_idempotency_request_hash'));
  assert.match(migration, /latitude/);
  assert.match(migration, /longitude/);
  assert.match(migration, /restaurant_weekly_hours/);
  assert.match(migration, /restaurant_special_hours/);
  assert.match(migration, /menu_option_groups/);
  assert.match(migration, /menu_option_choices/);
  assert.match(migration, /fk_option_group_item[\s\S]*ON DELETE CASCADE/);
  assert.match(migration, /fk_option_choice_group[\s\S]*ON DELETE CASCADE/);
});

test('catalog API delegates filtered customer reads and guarded Restaurant mutations to the service', () => {
  const api = read('api/catalog.php');
  const service = read('lib/services/catalog_service.php');
  const repository = read('lib/repositories/catalog_repository.php');

  assert.match(api, /\$_SERVER\['REQUEST_METHOD'\].*GET/);
  assert.match(api, /catalog_for_customer/);
  assert.match(api, /\$_GET\['q'\]/);
  assert.match(api, /\$_GET\['restaurant'\]/);
  assert.match(api, /savora_request_actor\(\$conn, \['restaurant'\]\)/);
  assert.match(api, /savora_require_csrf/);
  assert.match(api, /savora_require_idempotency_key/);
  assert.match(api, /savora_idempotency_lock/);
  assert.match(`${api}\n${service}`, /save_item/);
  assert.match(`${api}\n${service}`, /set_item_availability/);
  assert.match(`${api}\n${service}`, /save_profile/);
  assert.match(`${api}\n${service}`, /save_operations/);
  assert.match(service, /function\s+catalog_save_item\s*\(/);
  assert.match(service, /expectedVersion/);
  assert.match(service, /catalog_error\(403/);
  assert.match(service, /catalog_error\(409/);
  assert.match(repository, /r\.status='active'/);
  assert.match(repository, /m\.is_available=1/);
  assert.match(repository, /optionGroups/);
  assert.match(repository, /optionChoices/);
});
