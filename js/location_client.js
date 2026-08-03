(function attachLocationClient(root, factory) {
  'use strict';
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraLocationClient = api;
}(typeof window === 'undefined' ? null : window, function createLocationClient() {
  const OPTIONS = { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 };
  const ENDPOINT = 'api/location.php';
  const PREVIEW_ENDPOINT = 'api/location_preview.php';

  function coordinatesOf(position) {
    const latitude = Number(position && position.coords && position.coords.latitude);
    const longitude = Number(position && position.coords && position.coords.longitude);
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) throw new Error('The browser returned invalid coordinates.');
    return { latitude, longitude };
  }

  function getPosition(geolocation) {
    if (!geolocation || typeof geolocation.getCurrentPosition !== 'function') return Promise.reject(new Error('Current location is unavailable in this browser.'));
    return new Promise((resolve, reject) => geolocation.getCurrentPosition(position => {
      try { resolve(coordinatesOf(position)); } catch (error) { reject(error); }
    }, reject, OPTIONS));
  }

  function requireApi(api) {
    if (!api || typeof api.get !== 'function' || typeof api.post !== 'function') throw new Error('The server connection is not ready.');
    return api;
  }

  function requirePublicApi(api) {
    if (!api || typeof api.postPublic !== 'function') throw new Error('The public server connection is not ready.');
    return api;
  }

  async function load(api) {
    const response = await requireApi(api).get(ENDPOINT);
    return response && response.location ? response.location : response;
  }

  async function previewGps(api, coordinates) {
    const response = await requirePublicApi(api).postPublic(PREVIEW_ENDPOINT, coordinatesOf({ coords: coordinates }));
    return response && response.location ? response.location : response;
  }

  async function save(api, action, payload, intentKey) {
    const response = await requireApi(api).post(ENDPOINT, { action, payload: payload || {} }, intentKey);
    return response && response.location ? response.location : response;
  }

  function saveGps(api, coordinates, intentKey, deliveryDetails = '') {
    const payload = coordinatesOf({ coords: coordinates });
    const details = String(deliveryDetails || '').trim();
    if (details) payload.deliveryDetails = details;
    return save(api, 'save_gps_location', payload, intentKey);
  }

  function saveManual(api, payload, intentKey) {
    return save(api, 'save_manual_location', payload || {}, intentKey);
  }

  function messageForGeolocationError(error) {
    const code = Number(error && error.code);
    if (code === 1) return 'Location permission was denied. You can enter the address manually.';
    if (code === 2) return 'Current location is unavailable. You can enter the address manually.';
    if (code === 3) return 'Location request timed out. You can enter the address manually.';
    return error && error.message ? error.message : 'Current location could not be used. You can enter the address manually.';
  }

  return { endpoint: ENDPOINT, previewEndpoint: PREVIEW_ENDPOINT, getPosition, load, previewGps, saveGps, saveManual, messageForGeolocationError };
}));
