'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

test('media endpoint serves controlled assets without exposing paths', () => {
  const source = fs.readFileSync('media.php', 'utf8');
  assert.match(source, /media_find_asset/);
  assert.match(source, /visibility/);
  assert.match(source, /savora_validate_session/);
  assert.match(source, /X-Content-Type-Options/);
  assert.match(source, /Content-Type/);
  assert.doesNotMatch(source, /echo\s+\$asset\[['"]stored_path/);
  assert.doesNotMatch(source, /\$_GET\[['"]stored_path/);
});
