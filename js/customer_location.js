(function attachCustomerLocation(root) {
  'use strict';
  if (!root || !root.document || !root.SavoraApi || !root.SavoraLocationClient || !root.SavoraCustomerLocationState) return;
  const doc = root.document;
  const Api = root.SavoraApi;
  const Client = root.SavoraLocationClient;
  const State = root.SavoraCustomerLocationState;
  const endpoint = 'api/location.php';
  const isAuthenticated = root.SavoraCustomerAuthenticated === true;
  const guestLocationKey = 'savora_guest_location_v1';
  let currentLocation = null;
  let pendingPreview = null;
  let mode = 'manual';
  let dialogTrigger = null;
  let saving = false;
  const text = value => typeof value === 'string' ? value.trim() : '';
  const intent = scope => Api.intentKey(`customer-location-${scope}`);

  function guestRaw() {
    if (!root.localStorage) return null;
    try { return root.localStorage.getItem(guestLocationKey); } catch (_) { return null; }
  }

  function loadGuestLocation() {
    const draft = State.parseGuestDraft(guestRaw());
    return draft || {};
  }

  function saveGuestLocation(location) {
    if (root.localStorage) root.localStorage.setItem(guestLocationKey, JSON.stringify(location || {}));
  }

  function notify(location) {
    doc.dispatchEvent(new root.CustomEvent('savora:customer-location-changed', { detail: { location } }));
  }

  function setStatus(message, isError = false) {
    doc.querySelectorAll('[data-customer-location-status]').forEach(node => {
      node.textContent = message || '';
      node.classList.toggle('is-error', isError);
    });
  }

  function render(location) {
    currentLocation = location || {};
    const address = text(currentLocation.address || currentLocation.addressLine1);
    const label = doc.querySelector('[data-customer-location-label]');
    if (label) label.textContent = address || 'Choose delivery address';
    doc.querySelectorAll('[data-customer-location-input]').forEach(input => { if (doc.activeElement !== input) input.value = address; });
    const details = text(currentLocation.deliveryDetails);
    const detailsInput = doc.querySelector('[data-customer-delivery-details]');
    if (detailsInput && doc.activeElement !== detailsInput) detailsInput.value = details;
    const profileAddress = doc.getElementById('profile-address');
    if (profileAddress && doc.activeElement !== profileAddress) profileAddress.value = address;
    const profileDetails = doc.getElementById('profile-delivery-details');
    if (profileDetails && doc.activeElement !== profileDetails) profileDetails.value = details;
    const latitude = doc.getElementById('profile-latitude');
    const longitude = doc.getElementById('profile-longitude');
    if (latitude) latitude.value = currentLocation.latitude === null || currentLocation.latitude === undefined ? '' : String(currentLocation.latitude);
    if (longitude) longitude.value = currentLocation.longitude === null || currentLocation.longitude === undefined ? '' : String(currentLocation.longitude);
    const copy = doc.getElementById('saved-address-copy');
    if (copy) copy.textContent = address || 'No saved delivery address yet.';
  }

  function dialog() { return doc.getElementById('customer-location-dialog'); }
  function input(selector) { return dialog()?.querySelector(selector); }

  function setSaveEnabled(enabled) {
    const button = input('[data-customer-location-save]');
    if (button) button.disabled = !enabled || saving;
  }

  function renderPreview(preview) {
    const card = input('[data-customer-location-preview]');
    const address = input('[data-customer-location-preview-address]');
    if (card) card.hidden = !preview;
    if (address) address.textContent = preview ? [preview.addressLine1, preview.addressLine2, preview.city, preview.state, preview.postalCode, preview.country].filter(Boolean).join(', ') || preview.address : '';
    setSaveEnabled(Boolean(preview));
  }

  function setBusy(button, busy, label) {
    if (!button) return;
    button.disabled = busy;
    button.setAttribute('aria-busy', busy ? 'true' : 'false');
    if (label) button.replaceChildren(Object.assign(doc.createElement('i'), { className: 'fa-solid fa-crosshairs' }), doc.createTextNode(label));
  }

  function openDialog(trigger) {
    const currentDialog = dialog();
    if (!currentDialog) return;
    dialogTrigger = trigger || doc.querySelector('[data-customer-location-trigger]');
    pendingPreview = null;
    mode = 'manual';
    const addressInput = input('[data-customer-location-input]');
    const detailsInput = input('[data-customer-delivery-details]');
    if (addressInput) addressInput.value = text(currentLocation && (currentLocation.address || currentLocation.addressLine1));
    if (detailsInput) detailsInput.value = text(currentLocation && currentLocation.deliveryDetails);
    renderPreview(null);
    setSaveEnabled(Boolean(text(addressInput?.value)));
    setStatus('');
    currentDialog.hidden = false;
    if (dialogTrigger) dialogTrigger.setAttribute('aria-expanded', 'true');
    (addressInput || detailsInput)?.focus();
  }

  function closeDialog() {
    const currentDialog = dialog();
    if (!currentDialog) return;
    pendingPreview = null;
    mode = 'manual';
    saving = false;
    renderPreview(null);
    currentDialog.hidden = true;
    if (dialogTrigger) { dialogTrigger.setAttribute('aria-expanded', 'false'); dialogTrigger.focus(); }
  }

  function selectManualMode() {
    mode = 'manual';
    pendingPreview = null;
    renderPreview(null);
    const addressInput = input('[data-customer-location-input]');
    setSaveEnabled(Boolean(text(addressInput?.value)));
    addressInput?.focus();
  }

  async function useCurrentLocation(button) {
    const original = button?.dataset.locationLabel || (button?.textContent || '').trim() || 'Use current location';
    if (button) button.dataset.locationLabel = original;
    mode = 'gps';
    pendingPreview = null;
    renderPreview(null);
    setSaveEnabled(false);
    setBusy(button, true, 'Finding your address…');
    setStatus('Requesting your location…');
    try {
      const coordinates = await root.SavoraLocationClient.getPosition(root.navigator && root.navigator.geolocation);
      setStatus('Finding your address…');
      pendingPreview = State.normalizePreview(await Client.previewGps(Api, coordinates));
      const addressInput = input('[data-customer-location-input]');
      if (addressInput) addressInput.value = pendingPreview.address;
      renderPreview(pendingPreview);
      setStatus('Address found. Please review it before saving.');
    } catch (error) {
      const message = error && error.status === 429
        ? 'Too many location previews. Please try again later.'
        : error && error.code ? Client.messageForGeolocationError(error) : (error.message || 'Current location could not be used. You can enter the address manually.');
      setStatus(message, true);
      if (root.SavoraUI) root.SavoraUI.showToast(message, 'error');
    } finally {
      setBusy(button, false, original);
    }
  }

  async function persistDraft(draft, scope) {
    if (!isAuthenticated) {
      saveGuestLocation(draft);
      render(draft);
      notify(draft);
      setStatus('Saved on this device. Sign in to sync it with your account.');
      if (root.SavoraUI) root.SavoraUI.showToast('Address saved on this device.');
      return draft;
    }
    const keyScope = scope || draft.method;
    const location = draft.method === 'gps'
      ? await Client.saveGps(Api, { latitude: draft.latitude, longitude: draft.longitude }, intent(keyScope), draft.deliveryDetails)
      : await Client.saveManual(Api, { address: draft.address, deliveryDetails: draft.deliveryDetails }, intent(keyScope));
    Api.clearIntentKey(`customer-location-${keyScope}`);
    render(location);
    notify(location);
    setStatus('Address saved.');
    return location;
  }

  async function saveManualAddress(address, deliveryDetails = '') {
    const draft = State.confirmManual(address, deliveryDetails);
    return persistDraft(draft, 'manual');
  }

  async function confirmDialog() {
    const addressInput = input('[data-customer-location-input]');
    const detailsInput = input('[data-customer-delivery-details]');
    const saveButton = input('[data-customer-location-save]');
    if (saving) return;
    const details = detailsInput?.value || '';
    const draft = mode === 'gps' && pendingPreview
      ? State.confirmGps(pendingPreview, details)
      : State.confirmManual(addressInput?.value || '', details);
    saving = true;
    if (saveButton) saveButton.disabled = true;
    try {
      await persistDraft(draft);
      closeDialog();
    } catch (error) {
      setStatus(error.message || 'Address could not be saved.', true);
      if (root.SavoraUI) root.SavoraUI.showToast(error.message || 'Address could not be saved.', 'error');
    } finally { saving = false; setSaveEnabled(Boolean(pendingPreview || text(addressInput?.value))); }
  }

  async function syncPendingGuest() {
    const draft = State.parseGuestDraft(guestRaw());
    if (!isAuthenticated || !draft) return null;
    if (draft.pendingSync !== true) return null;
    try {
      await persistDraft(draft, 'sync');
      const authoritative = await Client.load(Api);
      if (root.localStorage) root.localStorage.removeItem(guestLocationKey);
      render(authoritative);
      notify(authoritative);
      setStatus('Address synced with your account.');
      return authoritative;
    } catch (error) {
      setStatus('Your saved device address could not sync yet. Please try again.', true);
      return null;
    }
  }

  async function bind() {
    if (Client.endpoint !== endpoint) return;
    doc.querySelectorAll('[data-customer-location-trigger]').forEach(button => button.addEventListener('click', () => {
      if (!button.hasAttribute('data-customer-use-gps')) openDialog(button);
    }));
    doc.querySelectorAll('[data-customer-location-close], [data-customer-location-skip]').forEach(button => button.addEventListener('click', closeDialog));
    doc.querySelector('[data-customer-location-manual]')?.addEventListener('click', selectManualMode);
    doc.querySelector('[data-customer-location-retry]')?.addEventListener('click', event => useCurrentLocation(event.currentTarget));
    doc.querySelectorAll('[data-customer-use-gps]').forEach(button => button.addEventListener('click', () => {
      if (!dialog()?.contains(button)) openDialog(button);
      useCurrentLocation(input('[data-customer-use-gps]'));
    }));
    doc.querySelector('[data-customer-location-input]')?.addEventListener('input', event => {
      if (mode === 'manual') setSaveEnabled(Boolean(text(event.target.value)));
    });
    doc.querySelector('[data-customer-location-form]')?.addEventListener('submit', async event => { event.preventDefault(); await confirmDialog(); });
    try {
      if (isAuthenticated) {
        const synced = await syncPendingGuest();
        render(synced || await root.SavoraLocationClient.load(Api));
      } else render(loadGuestLocation());
    } catch (error) { setStatus(error.message || 'Saved location is unavailable.', true); }
  }

  root.SavoraCustomerLocation = { saveManualAddress, render, useCurrentLocation, openDialog };
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', bind, { once: true }); else bind();
}(typeof window === 'undefined' ? null : window));
