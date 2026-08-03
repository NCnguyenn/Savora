'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');

test('public JSON posts use same-origin credentials without CSRF or idempotency headers', async () => {
  const originalFetch = global.fetch;
  const requests = [];
  global.fetch = async (url, options) => {
    requests.push({ url, options });
    return {
      ok: true,
      status: 200,
      async json() { return { ok: true, data: { location: { address: 'Preview Road' } } }; }
    };
  };
  delete require.cache[require.resolve('../js/api_client.js')];
  const Api = require('../js/api_client.js');
  try {
    const result = await Api.postPublic('api/location_preview.php', { latitude: 13.7563, longitude: 100.5018 });
    assert.equal(result.location.address, 'Preview Road');
    assert.equal(requests.length, 1);
    assert.equal(requests[0].options.credentials, 'same-origin');
    assert.equal(requests[0].options.headers['X-CSRF-Token'], undefined);
    assert.equal(requests[0].options.headers['Idempotency-Key'], undefined);
  } finally {
    global.fetch = originalFetch;
  }
});
