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

test('delegates GPS and manual saves to the platform bridge', async () => {
  const calls = [];
  const bridge = {
    command: async (name, payload) => {
      calls.push({ name, payload });
      return { data: { address: 'Bangkok', locationMethod: name === 'save_gps_location' ? 'gps' : 'manual' } };
    }
  };
  assert.equal((await Client.saveGps(bridge, { latitude: 13.7563, longitude: 100.5018 })).address, 'Bangkok');
  assert.equal((await Client.saveManual(bridge, { address: 'Manual Road' })).locationMethod, 'manual');
  assert.deepEqual(calls, [
    { name: 'save_gps_location', payload: { latitude: 13.7563, longitude: 100.5018 } },
    { name: 'save_manual_location', payload: { address: 'Manual Road' } }
  ]);
});

test('maps denied, unavailable, and timeout errors distinctly', () => {
  assert.match(Client.messageForGeolocationError({ code: 1 }), /permission/i);
  assert.match(Client.messageForGeolocationError({ code: 2 }), /unavailable/i);
  assert.match(Client.messageForGeolocationError({ code: 3 }), /timed out/i);
});
