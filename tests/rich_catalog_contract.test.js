'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('rich catalog migration is registered and defines restaurant/menu presentation fields', () => {
  const registry = read('lib/migrations.php');
  const migrationPath = path.join(root, 'database', 'migrations', '017_rich_catalog.php');
  assert.ok(fs.existsSync(migrationPath), 'rich catalog migration must exist');
  const migration = fs.readFileSync(migrationPath, 'utf8');

  assert.match(registry, /'017_rich_catalog'\s*=>\s*__DIR__.*017_rich_catalog\.php/);
  assert.ok(registry.indexOf('017_rich_catalog') > registry.indexOf('016_profile_locations'));

  const restaurantSection = migration.slice(migration.indexOf('$restaurantColumns'), migration.indexOf('$menuColumns'));
  for (const column of ['description', 'hero_image', 'demo_key']) {
    assert.match(restaurantSection, new RegExp(`\\['${column}',`));
  }
  for (const column of ['description', 'image_path', 'category', 'prep_time_minutes', 'calories', 'dietary_tags', 'allergens', 'ingredients', 'sort_order']) {
    assert.match(migration, new RegExp(`\\['${column}',`));
  }
  assert.match(migration, /UNIQUE KEY.*demo_key|demo_key.*UNIQUE/i);
});
