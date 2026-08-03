(function attachCustomerLocation(root) {
  'use strict';
  if (!root || !root.document || !root.SavoraApi || !root.SavoraLocationClient) return;
  const doc = root.document;
  const Api = root.SavoraApi;
  const Client = root.SavoraLocationClient;
  const endpoint = 'api/location.php';
  const isAuthenticated = root.SavoraCustomerAuthenticated === true;
  const guestLocationKey = 'savora_guest_location_v1';
  let currentLocation = null;
  let dialogTrigger = null;
  const text = value => typeof value === 'string' ? value.trim() : '';
  const intent = scope => Api.intentKey(`customer-location-${scope}`);

  function loadGuestLocation() {
    if (!root.localStorage) return {};
    try {
      const value = JSON.parse(root.localStorage.getItem(guestLocationKey) || 'null');
      return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
    } catch (_) { return {}; }
  }

  function saveGuestLocation(location) {
    if (root.localStorage) root.localStorage.setItem(guestLocationKey, JSON.stringify(location || {}));
  }

  function render(location) {
    currentLocation = location || {};
    const address = text(currentLocation.address);
    const label = doc.querySelector('[data-customer-location-label]');
    if (label) label.textContent = address || 'Choose delivery address';
    doc.querySelectorAll('[data-customer-location-input]').forEach(input => { if (doc.activeElement !== input) input.value = address; });
    const profileAddress = doc.getElementById('profile-address');
    if (profileAddress && doc.activeElement !== profileAddress) profileAddress.value = address;
    const latitude = doc.getElementById('profile-latitude');
    const longitude = doc.getElementById('profile-longitude');
    if (latitude && currentLocation.latitude !== null && currentLocation.latitude !== undefined) latitude.value = String(currentLocation.latitude);
    if (longitude && currentLocation.longitude !== null && currentLocation.longitude !== undefined) longitude.value = String(currentLocation.longitude);
    const copy = doc.getElementById('saved-address-copy');
    if (copy) copy.textContent = address || 'No saved delivery address yet.';
  }

  function notify(location) {
    doc.dispatchEvent(new root.CustomEvent('savora:customer-location-changed', { detail: { location } }));
  }

  function setStatus(message, isError = false) {
    doc.querySelectorAll('[data-customer-location-status]').forEach(node => { node.textContent = message || ''; node.classList.toggle('is-error', isError); });
  }

  function restoreButton(button) {
    const label = button.dataset.locationLabel || 'Use current location';
    button.disabled = false; button.setAttribute('aria-busy', 'false');
    button.replaceChildren(Object.assign(doc.createElement('i'), { className: 'fa-solid fa-crosshairs' }), doc.createTextNode(label));
  }

  async function saveManualAddress(address) {
    const value = text(address);
    if (!value) throw new Error('Enter a delivery address.');
    if (!isAuthenticated) {
      const location = { ...loadGuestLocation(), address: value, method: 'manual' };
      saveGuestLocation(location); render(location); notify(location);
      return location;
    }
    const scope = 'manual';
    const location = await Client.saveManual(Api, { address: value }, intent(scope));
    Api.clearIntentKey(`customer-location-${scope}`);
    render(location); notify(location);
    return location;
  }

  async function useCurrentLocation(button) {
    const original = button.dataset.locationLabel || button.textContent.trim() || 'Use current location';
    button.dataset.locationLabel = original; button.disabled = true; button.setAttribute('aria-busy', 'true'); button.textContent = 'Locating...';
    setStatus('Requesting your current location...');
    try {
      const coordinates = await root.SavoraLocationClient.getPosition(root.navigator && root.navigator.geolocation);
      if (!isAuthenticated) {
        const location = { ...loadGuestLocation(), latitude: coordinates.latitude, longitude: coordinates.longitude, method: 'gps' };
        saveGuestLocation(location); render(location); notify(location);
        setStatus('Current location saved locally. Sign in to use it for checkout.');
        if (root.SavoraUI) root.SavoraUI.showToast('Current location saved locally.');
        return;
      }
      const scope = 'gps';
      const location = await Client.saveGps(Api, coordinates, intent(scope));
      Api.clearIntentKey(`customer-location-${scope}`);
      render(location); notify(location); setStatus('Current location saved. You can still edit the address manually.');
      if (root.SavoraUI) root.SavoraUI.showToast('Current location saved.');
    } catch (error) {
      const message = error && error.code ? Client.messageForGeolocationError(error) : (error.message || 'Current location could not be used. You can enter the address manually.');
      setStatus(message, true); if (root.SavoraUI) root.SavoraUI.showToast(message, 'error');
    } finally { restoreButton(button); }
  }

  function openDialog() {
    const dialog = doc.getElementById('customer-location-dialog');
    if (!dialog) return;
    dialogTrigger = doc.querySelector('[data-customer-location-trigger]');
    const input = dialog.querySelector('[data-customer-location-input]');
    if (input) input.value = text(currentLocation && currentLocation.address);
    dialog.hidden = false; if (dialogTrigger) dialogTrigger.setAttribute('aria-expanded', 'true'); input?.focus();
  }

  function closeDialog() {
    const dialog = doc.getElementById('customer-location-dialog');
    if (!dialog) return;
    dialog.hidden = true;
    if (dialogTrigger) { dialogTrigger.setAttribute('aria-expanded', 'false'); dialogTrigger.focus(); }
  }

  async function bind() {
    if (Client.endpoint !== endpoint) return;
    doc.querySelector('[data-customer-location-trigger]')?.addEventListener('click', openDialog);
    doc.querySelectorAll('[data-customer-location-close], [data-customer-location-skip]').forEach(button => button.addEventListener('click', closeDialog));
    doc.querySelector('[data-customer-location-form]')?.addEventListener('submit', async event => {
      event.preventDefault(); const input = event.currentTarget.querySelector('[data-customer-location-input]');
      try { await saveManualAddress(input && input.value); setStatus('Address saved.'); closeDialog(); }
      catch (error) { setStatus(error.message || 'Address could not be saved.', true); }
    });
    doc.querySelectorAll('[data-customer-use-gps]').forEach(button => button.addEventListener('click', () => useCurrentLocation(button)));
    try { render(isAuthenticated ? await root.SavoraLocationClient.load(Api) : loadGuestLocation()); }
    catch (error) { setStatus(error.message || 'Saved location is unavailable.', true); }
  }

  root.SavoraCustomerLocation = { saveManualAddress, render, useCurrentLocation };
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', bind, { once: true }); else bind();
}(typeof window === 'undefined' ? null : window));
