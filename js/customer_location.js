(function attachCustomerLocation(root) {
  'use strict';
  if (!root || !root.document || !root.SavoraState || !root.SavoraLocationClient) return;

  const doc = root.document;
  const State = root.SavoraState;
  const Client = root.SavoraLocationClient;
  let dialogTrigger = null;

  const text = value => typeof value === 'string' ? value.trim() : '';
  const locationPatch = location => ({
    address: text(location && location.address),
    latitude: location && location.latitude,
    longitude: location && location.longitude,
    locationMethod: location && (location.locationMethod || location.method),
    locationUpdatedAt: text(location && (location.locationUpdatedAt || location.updatedAt))
  });

  function render(profile) {
    const address = text(profile && profile.address);
    const label = doc.querySelector('[data-customer-location-label]');
    if (label) label.textContent = address || 'Choose delivery address';
    doc.querySelectorAll('[data-customer-location-input]').forEach(input => {
      if (doc.activeElement !== input) input.value = address;
    });
    const profileInput = doc.getElementById('profile-address');
    if (profileInput && doc.activeElement !== profileInput) profileInput.value = address;
    const checkoutInput = doc.getElementById('checkout-address');
    if (checkoutInput && doc.activeElement !== checkoutInput) checkoutInput.value = address;
    const copy = doc.getElementById('saved-address-copy');
    if (copy) copy.textContent = address || 'No saved address yet.';
  }

  function applyLocation(location) {
    const next = State.persist(State.setProfile(State.load(), locationPatch(location)));
    render(next.profile);
    if (root.SavoraUI && typeof root.SavoraUI.refreshChrome === 'function') root.SavoraUI.refreshChrome();
    return next;
  }

  function setStatus(message, isError = false) {
    doc.querySelectorAll('[data-customer-location-status]').forEach(node => {
      node.textContent = message || '';
      node.classList.toggle('is-error', isError);
    });
  }

  function restoreButton(button) {
    const label = button.dataset.locationLabel || 'Use current location';
    button.disabled = false;
    button.setAttribute('aria-busy', 'false');
    button.replaceChildren(Object.assign(doc.createElement('i'), { className: 'fa-solid fa-crosshairs' }), doc.createTextNode(label));
  }

  async function saveManualAddress(address) {
    const value = text(address);
    if (!value) throw new Error('Enter a delivery address.');
    const bridge = root.SavoraPlatformBridge;
    const location = bridge
      ? await Client.saveManual(bridge, { address: value })
      : { address: value, locationMethod: 'manual', latitude: null, longitude: null };
    applyLocation(location);
    return location;
  }

  async function useCurrentLocation(button) {
    const original = button.dataset.locationLabel || button.textContent.trim() || 'Use current location';
    button.dataset.locationLabel = original;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.textContent = 'Locating…';
    setStatus('Requesting your current location…');
    try {
      const coordinates = await root.SavoraLocationClient.getPosition(root.navigator && root.navigator.geolocation);
      if (!root.SavoraPlatformBridge) throw new Error('The platform connection is not ready.');
      const location = await root.SavoraLocationClient.saveGps(root.SavoraPlatformBridge, coordinates);
      applyLocation(location);
      setStatus('Current location saved. You can still edit the address manually.');
      if (root.SavoraUI && typeof root.SavoraUI.showToast === 'function') root.SavoraUI.showToast('Current location saved.');
    } catch (error) {
      const message = error && error.code ? Client.messageForGeolocationError(error) : (error.message || 'Current location could not be used. You can enter the address manually.');
      setStatus(message, true);
      if (root.SavoraUI && typeof root.SavoraUI.showToast === 'function') root.SavoraUI.showToast(message, 'error');
    } finally {
      restoreButton(button);
    }
  }

  function openDialog() {
    const dialog = doc.getElementById('customer-location-dialog');
    if (!dialog) return;
    dialogTrigger = doc.querySelector('[data-customer-location-trigger]');
    const input = dialog.querySelector('[data-customer-location-input]');
    if (input) input.value = text(State.load().profile.address);
    dialog.hidden = false;
    if (dialogTrigger) dialogTrigger.setAttribute('aria-expanded', 'true');
    input?.focus();
  }

  function closeDialog() {
    const dialog = doc.getElementById('customer-location-dialog');
    if (!dialog) return;
    dialog.hidden = true;
    if (dialogTrigger) {
      dialogTrigger.setAttribute('aria-expanded', 'false');
      dialogTrigger.focus();
    }
  }

  function bind() {
    render(State.load().profile);
    doc.querySelector('[data-customer-location-trigger]')?.addEventListener('click', openDialog);
    doc.querySelectorAll('[data-customer-location-close], [data-customer-location-skip]').forEach(button => button.addEventListener('click', closeDialog));
    doc.querySelector('[data-customer-location-form]')?.addEventListener('submit', async event => {
      event.preventDefault();
      const input = event.currentTarget.querySelector('[data-customer-location-input]');
      try {
        await saveManualAddress(input && input.value);
        setStatus('Address saved.');
        closeDialog();
      } catch (error) {
        setStatus(error.message || 'Address could not be saved.', true);
      }
    });
    doc.querySelectorAll('[data-customer-use-gps]').forEach(button => {
      button.addEventListener('click', () => useCurrentLocation(button));
    });
    root.addEventListener('savora:platform-state', event => {
      if (event.detail && event.detail.location) applyLocation(event.detail.location);
    });
    const snapshot = root.SavoraPlatformBridge && typeof root.SavoraPlatformBridge.getSnapshot === 'function'
      ? root.SavoraPlatformBridge.getSnapshot()
      : null;
    if (snapshot && snapshot.location) applyLocation(snapshot.location);
  }

  root.SavoraCustomerLocation = { saveManualAddress, render, applyLocation };
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', bind, { once: true });
  else bind();
}(typeof window === 'undefined' ? null : window));
