const test = require('node:test');
const assert = require('node:assert/strict');

const Client = require('../js/location_client.js');

test('gets one high-accuracy position', async () => {
  let calls = 0;
  const geolocation = {
    getCurrentPosition(success, failure, options) {
      calls += 1;
      assert.deepEqual(options, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
      success({ coords: { latitude: 13.7563, longitude: 100.5018 } });
    }
  };
  assert.deepEqual(await Client.getPosition(geolocation), { latitude: 13.7563, longitude: 100.5018 });
  assert.equal(calls, 1);
});

test('loads and saves locations through the focused API', async () => {
  const calls = [];
  const api = {
    get: async url => {
      calls.push({ method: 'GET', url });
      return { location: { address: 'Saved Road', locationMethod: 'manual' } };
    },
    post: async (url, body, intentKey) => {
      calls.push({ method: 'POST', url, body, intentKey });
      return { location: { address: 'Bangkok', locationMethod: body.action === 'save_gps_location' ? 'gps' : 'manual' } };
    }
  };
  assert.equal((await Client.load(api)).address, 'Saved Road');
  assert.equal((await Client.saveGps(api, { latitude: 13.7563, longitude: 100.5018 }, 'gps-key')).address, 'Bangkok');
  assert.equal((await Client.saveManual(api, { address: 'Manual Road' }, 'manual-key')).locationMethod, 'manual');
  assert.deepEqual(calls, [
    { method: 'GET', url: 'api/location.php' },
    { method: 'POST', url: 'api/location.php', body: { action: 'save_gps_location', payload: { latitude: 13.7563, longitude: 100.5018 } }, intentKey: 'gps-key' },
    { method: 'POST', url: 'api/location.php', body: { action: 'save_manual_location', payload: { address: 'Manual Road' } }, intentKey: 'manual-key' }
  ]);
});

test('maps denied, unavailable, and timeout errors distinctly', () => {
  assert.match(Client.messageForGeolocationError({ code: 1 }), /permission/i);
  assert.match(Client.messageForGeolocationError({ code: 2 }), /unavailable/i);
  assert.match(Client.messageForGeolocationError({ code: 3 }), /timed out/i);
});
