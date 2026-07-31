const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = () => fs.readFileSync(path.join(__dirname, '..', 'js', 'admin_ui.js'), 'utf8');

test('Admin UI exposes named safe interaction helpers', () => {
  const ui = source();
  for (const name of ['openDrawer', 'closeDrawer', 'openDialog', 'closeDialog', 'showToast', 'applyTableFilter', 'requestAction', 'formatMoney']) {
    assert.match(ui, new RegExp(`function ${name}\\s*\\(`));
  }
  assert.match(ui, /textContent/);
  assert.doesNotMatch(ui, /innerHTML\s*=/);
  assert.doesNotMatch(ui, /eval\s*\(/);
});

test('Admin UI manages focus, Escape and outside-click close behavior', () => {
  const ui = source();
  assert.match(ui, /event\.key === 'Escape'/);
  assert.match(ui, /event\.key === 'Tab'/);
  assert.match(ui, /previousFocus/);
  assert.match(ui, /\.focus\s*\(\)/);
  assert.match(ui, /data-admin-close/);
  assert.match(ui, /closest\s*\(/);
});

test('Admin UI sends protected and idempotent action requests', () => {
  const ui = source();
  assert.match(ui, /fetch\s*\(/);
  assert.match(ui, /X-CSRF-Token/);
  assert.match(ui, /Idempotency-Key/);
  assert.match(ui, /application\/json/);
  assert.match(ui, /URLSearchParams/);
  assert.match(ui, /history\.replaceState/);
  assert.match(ui, /data-admin-field-error/);
});

