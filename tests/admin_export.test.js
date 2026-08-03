'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

test('Admin export controls use an authorized server CSV endpoint', () => {
  const endpoint = fs.readFileSync('api/admin_export.php', 'utf8');
  const analytics = fs.readFileSync('admin_analytics.php', 'utf8');
  const accounts = fs.readFileSync('admin_accounts.php', 'utf8');
  assert.match(endpoint, /admin_require_role/);
  assert.match(endpoint, /analytics_repository_report/);
  assert.match(endpoint, /export_send_csv/);
  assert.match(analytics, /api\/admin_export\.php\?type=analytics/);
  assert.match(accounts, /data-admin-export-table/);
});
