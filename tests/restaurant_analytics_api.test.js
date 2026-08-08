'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const insightsApi = require('../js/restaurant_insights.js');

test('Restaurant analytics uses the authorized server report and export boundary', () => {
  const endpoint = fs.readFileSync('api/analytics.php', 'utf8');
  const insights = fs.readFileSync('js/restaurant_insights.js', 'utf8');
  assert.match(endpoint, /savora_request_actor/);
  assert.match(endpoint, /analytics_repository_report/);
  assert.match(endpoint, /export_send_csv/);
  assert.match(insights, /api\/analytics\.php/);
});

test('Restaurant Insights consumes server KPI definitions instead of discarding the report', () => {
  const insights = fs.readFileSync('js/restaurant_insights.js', 'utf8');
  assert.match(insights, /serverAnalytics/);
  assert.match(insights, /serverAnalytics\.kpis/);
  assert.match(insights, /report\.kpis/);
});

test('Restaurant dashboard and local insights classify completed orders as fulfilled, not live', () => {
  const dashboard = fs.readFileSync('restaurant_dashboard.php', 'utf8');
  const insights = fs.readFileSync('js/restaurant_insights.js', 'utf8');
  assert.match(dashboard, /\['delivered',\s*'completed'\]\.includes\(order\.status\)/);
  assert.match(dashboard, /!\['delivered',\s*'completed',\s*'cancelled',\s*'refunded'\]\.includes\(order\.status\)/);
  assert.match(insights, /\['delivered',\s*'completed'\]\.includes\(order\.status\)/);
  assert.match(insights, /statusCounts[^\n]*'completed'/);
});

test('server analytics completedOrders counts delivered and completed statuses with a safe fallback', () => {
  assert.equal(typeof insightsApi.countFulfilledOrders, 'function');
  assert.equal(insightsApi.countFulfilledOrders({ delivered: 2, completed: 3 }), 5);
  assert.equal(insightsApi.countFulfilledOrders({ delivered: 4 }), 4);
  assert.equal(insightsApi.countFulfilledOrders({ completed: 6 }), 6);
  assert.equal(insightsApi.countFulfilledOrders(null), 0);
  assert.equal(insightsApi.countFulfilledOrders({ delivered: 'invalid', completed: null }), 0);
});
