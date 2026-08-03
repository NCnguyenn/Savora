'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const imageRoot = path.join(root, 'assets', 'images', 'catalog');

function jpegSize(buffer) {
  assert.equal(buffer[0], 0xff, 'JPEG marker is missing');
  assert.equal(buffer[1], 0xd8, 'JPEG start marker is missing');
  let offset = 2;
  while (offset + 9 < buffer.length) {
    if (buffer[offset] !== 0xff) { offset += 1; continue; }
    const marker = buffer[offset + 1];
    offset += 2;
    if (marker === 0xd8 || marker === 0xd9 || (marker >= 0xd0 && marker <= 0xd7)) continue;
    const length = buffer.readUInt16BE(offset);
    if (marker >= 0xc0 && marker <= 0xc3 || marker >= 0xc5 && marker <= 0xc7 || marker >= 0xc9 && marker <= 0xcb || marker >= 0xcd && marker <= 0xcf) {
      return { width: buffer.readUInt16BE(offset + 5), height: buffer.readUInt16BE(offset + 3) };
    }
    offset += length;
  }
  throw new Error('JPEG dimensions could not be read');
}

test('rich catalog has one local 4K image for every seeded menu item', () => {
  const data = JSON.parse(fs.readFileSync(path.join(root, 'database', 'seeds', 'catalog_demo_data.json'), 'utf8'));
  const expected = [];
  for (const restaurant of data) {
    for (const item of restaurant.items) expected.push(`demo-${restaurant.demo_key}-${item.slug}.jpg`);
  }
  assert.equal(expected.length, 48);
  assert.equal(new Set(expected).size, 48);

  for (const filename of expected) {
    const imagePath = path.join(imageRoot, filename);
    assert.ok(fs.existsSync(imagePath), `Missing catalog image: ${filename}`);
    assert.ok(fs.statSync(imagePath).size > 100000, `Catalog image is unexpectedly small: ${filename}`);
    const dimensions = jpegSize(fs.readFileSync(imagePath));
    assert.ok(dimensions.width >= 3840 && dimensions.height >= 2880, `Catalog image is not 4K: ${filename}`);
  }
});
