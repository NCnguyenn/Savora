'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const source = relativePath => {
  const absolutePath = path.join(root, relativePath);
  assert.equal(fs.existsSync(absolutePath), true, `${relativePath} must exist.`);
  return fs.readFileSync(absolutePath, 'utf8');
};

test('tracking API is authenticated, role-scoped, and read-only', () => {
  const endpoint = source('api/tracking.php');

  assert.match(endpoint, /savora_request_actor\(\$conn, \['customer', 'restaurant', 'driver', 'admin'\]\)/);
  assert.match(endpoint, /REQUEST_METHOD/);
  assert.match(endpoint, /\$method !== 'GET'/);
  assert.match(endpoint, /\$_GET\['order'\]/);
  assert.match(endpoint, /demo_route_snapshot\(\$conn, \$actor, \$referenceCode\)/);
  assert.match(endpoint, /savora_json\(\$result/);
  assert.doesNotMatch(endpoint, /\$method === 'POST'|savora_read_json|begin_transaction|\bUPDATE\b|\bINSERT\b|\bDELETE\b/i);
  assert.doesNotMatch(endpoint, /password|secret|csrf|token/i);
});

test('demo route service exposes deterministic server-timed operations', () => {
  const service = source('lib/services/demo_route_service.php');
  const repository = source('lib/repositories/demo_route_repository.php');

  for (const name of ['demo_route_calculate_point', 'demo_route_start', 'demo_route_snapshot', 'demo_route_is_arrived', 'demo_route_finish']) {
    assert.match(service, new RegExp(`function\\s+${name}\\s*\\(`));
  }
  assert.match(repository, /NOW\(\),60,'running'/);
  assert.match(service, /sin\(M_PI \* \$progress\)/);
  assert.match(service, /min\(1\.0, \$elapsed \/ \$duration\)/);
  assert.match(service, /savora_demo_mode\(\)/);
  assert.match(service, /notification_queue/);
  assert.match(service, /audit_append/);
  assert.match(service, /savora_idempotency_(?:find|store)/);

  assert.match(repository, /delivery_demo_routes/);
  assert.match(repository, /LIMIT 1 FOR UPDATE/);
  assert.match(repository, /LEFT JOIN checkout_quotes q ON q\.id=o\.quote_id/);
  assert.match(repository, /LEFT JOIN customer_addresses qa ON qa\.id=q\.address_id AND qa\.customer_user_id=o\.customer_user_id/);
  assert.match(repository, /COALESCE\(qa\.latitude,da\.latitude\) AS end_latitude/);
  assert.match(repository, /COALESCE\(qa\.longitude,da\.longitude\) AS end_longitude/);
});

test('order reads use the immutable quoted address before the mutable default', () => {
  const repository = source('lib/repositories/order_repository.php');

  assert.match(repository, /LEFT JOIN checkout_quotes q ON q\.id=o\.quote_id/);
  assert.match(repository, /LEFT JOIN customer_addresses qa ON qa\.id=q\.address_id AND qa\.customer_user_id=o\.customer_user_id/);
  assert.match(repository, /LEFT JOIN customer_addresses da ON da\.customer_user_id=o\.customer_user_id AND da\.is_default=1/);
  assert.match(repository, /COALESCE\(qa\.latitude,da\.latitude\) AS customer_latitude/);
  assert.match(repository, /COALESCE\(qa\.longitude,da\.longitude\) AS customer_longitude/);
});

test('existing real and manual Driver location commands remain available', () => {
  const dispatch = source('api/dispatch.php');
  const location = source('api/location.php');
  const deliveryService = source('lib/services/delivery_service.php');

  assert.match(dispatch, /driver_update_location/);
  assert.match(location, /save_gps_location/);
  assert.match(location, /save_manual_location/);
  assert.match(deliveryService, /function driver_update_location/);
});
