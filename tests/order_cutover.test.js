'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('all cross-role order views consume the shared server order API', () => {
  const sources = {
    'customer_history.php': 'customer_history.php',
    'customer_dashboard.php': 'customer_dashboard.php',
    'restaurant_dashboard.php': 'restaurant_dashboard.php',
    'restaurant_orders.php': 'js/restaurant_orders.js',
    'restaurant_order_history.php': 'restaurant_order_history.php',
    'driver_dashboard.php': 'driver_dashboard.php|js/driver_dashboard.js',
    'driver_delivery.php': 'js/driver_delivery.js',
    'driver_history.php': 'js/driver_history.js',
    'driver_earnings.php': 'js/driver_earnings.js'
  };
  for (const [page, files] of Object.entries(sources)) {
    const source = files.split('|').map(read).join('\n');
    assert.match(source, /api\/orders\.php/, `${page} must consume api/orders.php`);
  }
});

test('local state modules contain no submitted-order authority or cross-role mutation', () => {
  const customer = read('js/customer_state.js');
  const restaurant = read('js/restaurant_state.js');
  const driver = read('js/driver_state.js');

  assert.doesNotMatch(customer, /orders|statusHistory|orderId/);
  assert.doesNotMatch(restaurant, /updateOrderStatus|ordersForMetrics|customerState|ORDER_TRANSITIONS/);
  assert.doesNotMatch(driver, /CustomerState|customerState|acceptOffer|updateMilestone|createOffer|currentOffer|dispatches|deliveries/);
});

test('legacy order writers and duplicate transition branches are removed', () => {
  const platform = read('api/dispatch.php');
  const dashboard = read('js/driver_dashboard.js');
  const history = read('js/driver_history.js');
  const earnings = read('js/driver_earnings.js');
  const restaurantDashboard = read('restaurant_dashboard.php');
  const restaurantFinance = read('js/restaurant_finance.js');
  const restaurantInsights = read('js/restaurant_insights.js');

  assert.doesNotMatch(platform, /place_order|restaurant_order_status|driver_accept_order|driver_milestone/);
  assert.doesNotMatch(dashboard, /SavoraState|SavoraRestaurantState|SavoraPlatformBridge|acceptOffer|createOffer/);
  assert.doesNotMatch(history, /DriverState\.load\(\).*deliveries|deriveHistory|deliveryForOrder/);
  assert.doesNotMatch(earnings, /deriveEarnings|DriverState\.load/);
  assert.doesNotMatch(restaurantDashboard, /customerState\.orders|local order/);
  assert.doesNotMatch(restaurantFinance, /SavoraState|deriveFinance/);
  assert.doesNotMatch(restaurantInsights, /SavoraState|deriveAnalytics|readOrders/);
});

test('Driver surfaces expose only server offer acceptance actions', () => {
  const page = read('driver_dashboard.php');
  const script = read('js/driver_dashboard.js');
  const api = read('api/dispatch.php');
  assert.match(page, /data-delivery-offer/);
  assert.match(script, /api\/dispatch\.php/);
  assert.match(script, /data-offer-accept|data-offer-decline/);
  assert.match(api, /accept_offer|decline_offer/);
});
