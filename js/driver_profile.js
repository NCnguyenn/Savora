(function attachDriverProfile(root) {
  'use strict';
  if (!root || !root.document) return;
  const doc = root.document; const Api = root.SavoraApi; const DriverState = root.SavoraDriverState; const ui = root.SavoraDriverUI;
  const form = doc.querySelector('[data-driver-profile-form]'); if (!form || !Api || !ui) return;
  let snapshot = null;
  const field = name => form.elements[name];
  const message = text => { const node = doc.querySelector('[data-profile-error]'); if (node) node.textContent = text || ''; };
  const intent = scope => Api.intentKey(`driver-profile-${scope}`);

  function populate() {
    if (!snapshot) return;
    const profile = snapshot.profile || {}; const vehicle = snapshot.vehicle || {}; const preferences = snapshot.preferences || {};
    Object.entries({ fullName: profile.fullName, phone: profile.phone, email: profile.email, vehicleType: vehicle.type, vehicleModel: vehicle.model, licensePlate: vehicle.licensePlate, vehicleColor: vehicle.color, currentAddress: 'Location is managed by server GPS updates' }).forEach(([name, value]) => { if (field(name)) field(name).value = value || ''; });
    if (field('serviceRadiusKm')) field('serviceRadiusKm').value = '8';
    Object.entries(preferences).forEach(([name, value]) => { if (field(name)) field(name).checked = value === true; });
    const fullName = String(profile.fullName || 'Savora Driver');
    doc.querySelector('[data-profile-display-name]').textContent = fullName;
    doc.querySelector('[data-profile-driver-id]').textContent = `Driver ID ${profile.id || 'server'}`;
    doc.querySelector('[data-profile-initial]').textContent = fullName.split(/\s+/).filter(Boolean).map(part => part[0]).slice(0, 2).join('').toUpperCase() || 'DR';
    const documents = Object.fromEntries((snapshot.documents || []).map(document => [document.type, document.status]));
    doc.querySelector('[data-document-license]').textContent = documents.driver_license || 'Not submitted';
    doc.querySelector('[data-document-registration]').textContent = documents.vehicle_registration || 'Not submitted';
    doc.querySelector('[data-document-insurance]').textContent = documents.insurance || 'Not submitted';
    doc.querySelector('[data-location-access]').replaceChildren(ui.icon('fa-server'), 'Server location authority');
  }

  async function load() { snapshot = await Api.get('api/profile.php'); populate(); }

  doc.querySelector('[data-profile-use-gps]')?.addEventListener('click', () => ui.showToast('GPS updates are sent from Driver Overview to the server.'));
  doc.querySelector('[data-change-password]')?.addEventListener('click', () => ui.showToast('Password changes are managed by Savora account support.'));
  form.addEventListener('submit', async event => {
    event.preventDefault(); if (!snapshot) return;
    const profile = snapshot.profile || {}; const vehicle = snapshot.vehicle || {}; const currentPreferences = snapshot.preferences || {};
    const contact = { fullName: String(field('fullName').value || '').trim(), email: String(field('email').value || '').trim(), phone: String(field('phone').value || '').trim() };
    if (!contact.fullName || !contact.email) { message('Full name and a valid email address are required.'); return; }
    const vehiclePayload = { vehicleType: String(field('vehicleType').value || ''), vehicleModel: String(field('vehicleModel').value || ''), licensePlate: String(field('licensePlate').value || ''), vehicleColor: String(field('vehicleColor').value || '') };
    const vehicleChanged = vehiclePayload.vehicleType !== String(vehicle.type || '') || vehiclePayload.vehicleModel !== String(vehicle.model || '') || vehiclePayload.licensePlate !== String(vehicle.licensePlate || '') || vehiclePayload.vehicleColor !== String(vehicle.color || '');
    const preferences = { newOffers: field('newOffers').checked, soundAlerts: field('soundAlerts').checked, cashOnDelivery: field('cashOnDelivery').checked, avoidHighways: field('avoidHighways').checked };
    const preferencesChanged = Object.keys(preferences).some(key => preferences[key] !== (currentPreferences[key] === true));
    let version = Number(profile.version || 0);
    const save = async (action, payload, scope) => { const result = await Api.post('api/profile.php', { action, payload: { ...payload, version } }, intent(scope)); if (result && Number.isFinite(Number(result.version))) version = Number(result.version); return result; };
    const button = doc.querySelector('[data-profile-save]'); if (button) button.disabled = true; message('');
    try {
      const contactChanged = contact.fullName !== String(profile.fullName || '') || contact.email !== String(profile.email || '') || contact.phone !== String(profile.phone || '');
      if (contactChanged) await save('update_driver_contact', contact, 'contact');
      if (vehicleChanged) await save('request_driver_vehicle_change', vehiclePayload, 'vehicle');
      if (preferencesChanged) {
        const result = await save('update_driver_preferences', preferences, 'preferences');
        if (DriverState && result && result.preferences) DriverState.persist(DriverState.setPreferences(DriverState.load(), result.preferences));
      }
      Api.clearIntentKey('driver-profile-contact'); Api.clearIntentKey('driver-profile-vehicle'); Api.clearIntentKey('driver-profile-preferences');
      await load(); ui.showToast(vehicleChanged ? 'Contact and preferences saved; vehicle changes are pending review.' : 'Profile preferences saved on the server.'); ui.announce('Driver profile changes saved on the server.'); ui.syncTopbar();
    } catch (error) { message(error.message || 'Unable to save Driver profile.'); }
    finally { if (button) button.disabled = false; }
  });
  load().catch(error => message(error.message || 'Driver profile is unavailable.'));
}(typeof window === 'undefined' ? null : window));
