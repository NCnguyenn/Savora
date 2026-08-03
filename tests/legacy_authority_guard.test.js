'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const forbidden = [
  'savora_customer_state_v2.orders', 'placeDemoOrder', 'topUpWallet', 'savora_restaurant_state_v1',
  'savora_driver_state_v1', "command('place_order'", "command('restaurant_order_status'", "command('driver_accept_order'",
  "command('driver_milestone'", 'admin_operations_action_v2'
];
const files = [];
function collect(directory) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    if (['.git', '.worktrees', '.superpowers', 'node_modules'].includes(entry.name)) continue;
    const full = path.join(directory, entry.name);
    if (entry.isDirectory()) collect(full);
    else if (entry.isFile() && /.(php|js)$/.test(entry.name) && !full.includes(`${path.sep}tests${path.sep}`)) files.push(full);
  }
}
collect(root);

test('no final legacy authoritative browser or compatibility writers remain', () => {
  const matches = [];
  for (const file of files) {
    const source = fs.readFileSync(file, 'utf8');
    for (const pattern of forbidden) if (source.includes(pattern)) matches.push(`${path.relative(root, file)}: ${pattern}`);
  }
  assert.deepEqual(matches, [], `Legacy authority remains:\n${matches.join('\n')}`);
});
