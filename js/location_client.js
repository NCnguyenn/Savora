(function attachLocationClient(root, factory) {
  'use strict';
  if (typeof module === 'object' && module.exports) module.exports = factory();
  if (root) root.SavoraLocationClient = factory();
}(typeof window === 'undefined' ? null : window, function createLocationClient() {
  const OPTIONS = { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 };

  function coordinatesOf(position) {
    const latitude = Number(position && position.coords && position.coords.latitude);
    const longitude = Number(position && position.coords && position.coords.longitude);
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
      throw new Error('The browser returned invalid coordinates.');
    }
    return { latitude, longitude };
  }

  function getPosition(geolocation) {
    if (!geolocation || typeof geolocation.getCurrentPosition !== 'function') {
      return Promise.reject(new Error('Current location is unavailable in this browser.'));
    }
    return new Promise((resolve, reject) => {
      geolocation.getCurrentPosition(position => {
        try {
          resolve(coordinatesOf(position));
        } catch (error) {
          reject(error);
        }
      }, reject, OPTIONS);
    });
  }

  async function command(bridge, name, payload) {
    if (!bridge || typeof bridge.command !== 'function') throw new Error('The platform connection is not ready.');
    const response = await bridge.command(name, payload);
    return response && response.data ? response.data : response;
  }

  function saveGps(bridge, coordinates) {
    const safe = coordinatesOf({ coords: coordinates });
    return command(bridge, 'save_gps_location', safe);
  }

  function saveManual(bridge, payload) {
    return command(bridge, 'save_manual_location', payload || {});
  }

  function messageForGeolocationError(error) {
    const code = Number(error && error.code);
    if (code === 1) return 'Location permission was denied. You can enter the address manually.';
    if (code === 2) return 'Current location is unavailable. You can enter the address manually.';
    if (code === 3) return 'Location request timed out. You can enter the address manually.';
    return error && error.message ? error.message : 'Current location could not be used. You can enter the address manually.';
  }

  return { getPosition, saveGps, saveManual, messageForGeolocationError };
}));
