'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

test('migration registry is explicit and core relationships are constrained', () => {
  const registry = fs.readFileSync('lib/migrations.php', 'utf8');
  const migrate = fs.readFileSync('scripts/migrate.php', 'utf8');
  const integrity = fs.readFileSync('database/migrations/002_core_integrity.php', 'utf8');
  const testDatabase = fs.readFileSync('tests/support/test_database.php', 'utf8');
  const integration = fs.readFileSync('tests/migration_integrity_test.php', 'utf8');

  const existingSchema = registry.indexOf('001_existing_schema');
  const coreIntegrity = registry.indexOf('002_core_integrity');
  const requestHash = registry.indexOf('003_idempotency_request_hash');
  assert.ok(existingSchema >= 0, 'existing schema migration must be registered');
  assert.ok(coreIntegrity > existingSchema, 'core integrity migration must follow the existing schema migration');
  assert.ok(requestHash > coreIntegrity, 'idempotency request hash migration must follow core integrity migration');
  assert.match(registry, /003_idempotency_request_hash/);
  assert.match(migrate, /savora_apply_migrations\(\$conn\)/);
  assert.doesNotMatch(migrate, /platform_migrate\(\$conn\)/);

  for (const name of [
    'fk_orders_customer',
    'fk_orders_restaurant',
    'fk_order_items_order',
    'fk_order_history_order',
    'fk_payments_order',
    'fk_deliveries_order',
    'fk_user_sessions_user',
    'fk_restaurant_documents_application',
    'fk_driver_documents_application',
    'fk_notifications_user',
    'fk_refunds_order',
    'fk_payout_items_payout',
    'fk_case_messages_case',
  ]) {
    assert.match(integrity, new RegExp(name));
  }

  assert.match(integrity, /'RESTRICT'/);
  assert.match(integrity, /'CASCADE'/);
  assert.match(testDatabase, /SELECT DATABASE\(\) AS name/);
  assert.match(testDatabase, /!== 'savora_test'/);
  assert.match(integration, /savora_test_selected_database\(\$conn\) === 'savora_test'/);
  assert.match(integration, /Existing constraint fk_notifications_user does not match the migration definition/);
  assert.match(integration, /idx_orders_customer/);
  assert.match(integration, /idx_migration_reused_orders_restaurant/);
});

test('auth onboarding migration is registered after partner document storage', () => {
  const registry = fs.readFileSync('lib/migrations.php', 'utf8');
  const partnerStorage = registry.indexOf("'014_partner_document_storage'");
  const onboarding = registry.indexOf("'015_auth_onboarding'");
  assert.ok(partnerStorage >= 0, 'partner storage migration must be registered');
  assert.ok(onboarding > partnerStorage, 'auth onboarding migration must follow partner storage');
  assert.match(registry, /database\/migrations\/015_auth_onboarding\.php/);
});

test('profile location migration is registered after auth onboarding', () => {
  const registry = fs.readFileSync('lib/migrations.php', 'utf8');
  const onboarding = registry.indexOf("'015_auth_onboarding'");
  const profileLocations = registry.indexOf("'016_profile_locations'");
  assert.ok(onboarding >= 0, 'auth onboarding migration must be registered');
  assert.ok(profileLocations > onboarding, 'profile location migration must follow auth onboarding');
  assert.match(registry, /database\/migrations\/016_profile_locations\.php/);
});

test('customer GPS confirmation migration is registered after the customer storefront migrations', () => {
  const registry = fs.readFileSync('lib/migrations.php', 'utf8');
  const storefront = registry.indexOf("'018_customer_storefront'");
  const gpsConfirmation = registry.indexOf("'019_customer_gps_confirmation'");
  assert.ok(storefront >= 0, 'customer storefront migration must be registered');
  assert.ok(gpsConfirmation > storefront, 'GPS confirmation migration must follow customer storefront migration');
  assert.match(registry, /database\/migrations\/019_customer_gps_confirmation\.php/);
});
