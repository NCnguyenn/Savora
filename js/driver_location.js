(function attachDriverLocation(root) {
  'use strict';
  if (!root || !root.document || !root.SavoraApi || !root.SavoraLocationClient) return;
  const doc = root.document; const Api = root.SavoraApi; const Client = root.SavoraLocationClient;
  const endpoint = 'api/location.php';
  const key = scope => Api.intentKey(`driver-profile-location-${scope}`);
  let location = null;
  const text = value => typeof value === 'string' ? value.trim() : '';

  function render(next) {
    location = next || {};
    const address = text(location.address);
    const heading = doc.querySelector('[data-driver-location-address]');
    if (heading) heading.textContent = address || 'Location unavailable';
    const input = doc.getElementById('driver-current-address');
    if (input && doc.activeElement !== input) input.value = address;
    const access = doc.querySelector('[data-location-access]');
    if (access) access.textContent = location.locationMethod === 'gps' ? 'GPS address saved by Savora' : 'Manual address saved by Savora';
  }

  function restore(button, label) { button.disabled = false; button.setAttribute('aria-busy', 'false'); button.textContent = label; }
  async function saveManual(address) {
    const value = text(address); if (!value) throw new Error('Enter an address.');
    const scope = 'manual'; const saved = await Client.saveManual(Api, { address: value }, key(scope));
    Api.clearIntentKey(`driver-profile-location-${scope}`); render(saved); return saved;
  }

  async function useGps(button) {
    const label = button.textContent.trim() || 'Use current location'; button.disabled = true; button.setAttribute('aria-busy', 'true'); button.textContent = 'Locating...';
    try {
      const coordinates = await root.SavoraLocationClient.getPosition(root.navigator && root.navigator.geolocation);
      const scope = 'gps'; const saved = await Client.saveGps(Api, coordinates, key(scope));
      Api.clearIntentKey(`driver-profile-location-${scope}`); render(saved);
      if (root.SavoraDriverDispatchLocation && typeof root.SavoraDriverDispatchLocation.sendGps === 'function') await root.SavoraDriverDispatchLocation.sendGps({ coords: { ...coordinates, accuracy: null } });
      if (root.SavoraDriverUI) root.SavoraDriverUI.showToast('Current address saved by the server.');
    } catch (error) {
      const message = error && error.code ? Client.messageForGeolocationError(error) : (error.message || 'Current location could not be saved.');
      if (root.SavoraDriverUI) root.SavoraDriverUI.showToast(message, 'error');
    } finally { restore(button, label); }
  }

  async function bind() {
    if (Client.endpoint !== endpoint) return;
    doc.querySelectorAll('[data-use-driver-gps], [data-profile-use-gps]').forEach(button => button.addEventListener('click', () => useGps(button)));
    doc.querySelector('[data-enter-driver-address]')?.addEventListener('click', event => root.SavoraDriverUI?.openDialog('driver-address-dialog', event.currentTarget));
    doc.querySelector('[data-driver-address-form]')?.addEventListener('submit', async event => {
      event.preventDefault(); const input = event.currentTarget.elements['driver-address'];
      try { await saveManual(input && input.value); root.SavoraDriverUI?.closeDialog('driver-address-dialog'); root.SavoraDriverUI?.showToast('Manual address saved by the server.'); }
      catch (error) { const node = doc.querySelector('[data-driver-address-error]'); if (node) node.textContent = error.message || 'Address could not be saved.'; }
    });
    doc.getElementById('driver-current-address')?.addEventListener('change', async event => {
      try { await saveManual(event.currentTarget.value); }
      catch (error) { root.SavoraDriverUI?.showToast(error.message || 'Address could not be saved.', 'error'); }
    });
    try { render(await Client.load(Api)); }
    catch (error) { if (root.SavoraDriverUI) root.SavoraDriverUI.showToast(error.message || 'Saved address is unavailable.', 'error'); }
  }
  root.SavoraDriverLocation = { render, saveManual, useGps };
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', bind, { once: true }); else bind();
}(typeof window === 'undefined' ? null : window));
