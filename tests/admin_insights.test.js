const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

function assertSharedPage(file, heading) {
  assert.ok(fs.existsSync(path.join(root, file)), `${file} must exist`);
  const page = read(file);
  assert.match(page, /components\/admin_header\.php/);
  assert.match(page, /components\/admin_footer\.php/);
  assert.match(page, /<main\b[^>]*id="admin-main"/i);
  const escapedHeading = heading.replace('&', '&(?:amp;)?');
  assert.match(page, new RegExp(`<h1[^>]*>\\s*${escapedHeading}\\s*</h1>`, 'i'));
  assert.doesNotMatch(page, /href\s*=\s*["']#["']|\son[a-z]+\s*=/i);
  return page;
}

test('Overview exposes live MySQL operations, approvals, trends and alerts', () => {
  const page = assertSharedPage('admin_dashboard.php', 'System Overview');
  for (const copy of ['Gross order value', 'Active orders', 'Platform revenue', 'Pending approvals', 'Live Operations', 'Approval Queue', 'Revenue Trend', 'Order Status Distribution', 'High Priority Alerts', 'Recent Admin Activity']) {
    assert.match(page, new RegExp(copy, 'i'));
  }
  assert.match(page, /admin_page_data\s*\(\s*\$conn\s*,\s*['"]overview['"]/);
  assert.match(page, /role="img"[^>]+aria-label=/i);
  assert.doesNotMatch(page, /Michael Scott|Pizza Planet|Tom Hardy/);
});

test('Analytics provides report filters, exports, accessible charts and performance tables', () => {
  const page = assertSharedPage('admin_analytics.php', 'Analytics & Reports');
  for (const copy of ['Date range', 'All service areas', 'All payment methods', 'All order types', 'Export CSV', 'Export PDF', 'Gross Order Value', 'Orders', 'Completion Rate', 'Average Delivery Time', 'Order & Revenue Trend', 'Order Completion Funnel', 'Cancellation Reasons', 'Order Health', 'Hourly Demand', 'Top Restaurants', 'Driver Efficiency', 'Customer Retention']) {
    assert.match(page, new RegExp(copy.replace('&', '&(?:amp;)?'), 'i'));
  }
  assert.match(page, /admin_page_data\s*\(\s*\$conn\s*,\s*['"]analytics['"]/);
  assert.ok((page.match(/role="img"/g) || []).length >= 4);
  assert.match(page, /<form[^>]+method="get"[^>]+data-admin-filter/i);
});

test('Settings exposes platform, notification, security and immutable audit controls', () => {
  const page = assertSharedPage('admin_settings.php', 'Settings & Audit Log');
  for (const copy of ['Platform Settings', 'Notification Templates', 'Security', 'Audit Log', 'Order Timeouts', 'Dispatch Rules', 'Support SLA', 'Maintenance Mode', 'Immutable Audit Log', 'Search audit records', 'Reference ID', 'Before', 'After']) {
    assert.match(page, new RegExp(copy, 'i'));
  }
  assert.match(page, /admin_page_data\s*\(\s*\$conn\s*,\s*['"]settings['"]/);
  assert.match(page, /data-admin-action="update_setting"/);
  assert.match(page, /data-admin-action="update_notification_template"/);
  assert.match(page, /disabled[^>]+Immutable|Immutable[^>]+disabled/is);
  assert.match(page, /aria-label="Settings sections"/);
});

test('Repository uses grouped MySQL insight queries and action layer validates setting keys', () => {
  const repository = read('lib/admin_repository.php');
  const actions = read('lib/admin_actions.php');
  const schema = read('lib/platform_schema.php');

  for (const table of ['orders', 'restaurant_applications', 'driver_applications', 'ledger_entries', 'support_cases', 'audit_logs', 'platform_settings', 'notification_templates']) {
    assert.match(repository + schema, new RegExp(table));
  }
  assert.match(repository, /GROUP BY/i);
  assert.match(repository, /DATE_SUB|INTERVAL/i);
  assert.match(actions, /update_setting/);
  assert.match(actions, /update_notification_template/);
  assert.match(actions, /allowedSettingKeys/);
  assert.match(actions, /START TRANSACTION|begin_transaction/i);
  assert.match(actions, /INSERT INTO audit_logs/i);
  assert.match(actions, /version\s*=\s*version\s*\+\s*1/i);
  assert.doesNotMatch(actions, /\$_(?:GET|POST|REQUEST)/);
});
