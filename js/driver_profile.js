(function attachDriverProfile(root) {
  'use strict';

  if (!root || !root.document) return;
  const doc = root.document;
  const DriverState = root.SavoraDriverState;
  const LocationClient = root.SavoraLocationClient;
  const ui = root.SavoraDriverUI;
  const form = doc.querySelector('[data-driver-profile-form]');
  if (!form || !DriverState || !ui) return;

  const field = name => form.elements[name];

  function applyPlatformLocation(location, state = DriverState.load()) {
    if (!location || !location.address) return state;
    return DriverState.setLocation(state, {
      method: location.locationMethod || location.method,
      address: location.address,
      latitude: location.latitude,
      longitude: location.longitude,
      updatedAt: location.locationUpdatedAt || location.updatedAt,
      serviceRadiusKm: field('serviceRadiusKm')?.value
    });
  }

  function populate() {
    const state = DriverState.load();
    Object.entries({
      fullName: state.profile.fullName,
      phone: state.profile.phone,
      email: state.profile.email,
      vehicleType: state.profile.vehicleType,
      vehicleModel: state.profile.vehicleModel,
      licensePlate: state.profile.licensePlate,
      vehicleColor: state.profile.vehicleColor,
      currentAddress: state.location.address,
      serviceRadiusKm: String(state.serviceRadiusKm)
    }).forEach(([name, value]) => {
      if (field(name)) field(name).value = value;
    });
    Object.entries(state.preferences).forEach(([name, value]) => {
      if (field(name)) field(name).checked = value;
    });
    doc.querySelector('[data-profile-display-name]').textContent = state.profile.fullName;
    doc.querySelector('[data-profile-driver-id]').textContent = `Driver ID ${state.profile.id}`;
    doc.querySelector('[data-profile-initial]').textContent = state.profile.fullName.split(/\s+/).filter(Boolean).map(part => part[0]).slice(0, 2).join('').toUpperCase() || 'DR';
    doc.querySelector('[data-document-license]').textContent = state.profile.driverLicenseStatus;
    doc.querySelector('[data-document-registration]').textContent = state.profile.registrationStatus;
    doc.querySelector('[data-document-insurance]').textContent = state.profile.insuranceStatus;
    doc.querySelector('[data-location-access]').replaceChildren(
      ui.icon('fa-circle-check'),
      state.location.method === 'gps' ? 'GPS location enabled' : 'Manual location enabled'
    );
  }

  function setError(message, input) {
    const error = doc.querySelector('[data-profile-error]');
    error.textContent = message || '';
    form.querySelectorAll('[aria-invalid="true"]').forEach(node => node.removeAttribute('aria-invalid'));
    if (input) {
      input.setAttribute('aria-invalid', 'true');
      input.focus();
    }
  }

  doc.querySelector('[data-profile-use-gps]')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    if (!LocationClient || !root.navigator.geolocation || !root.SavoraPlatformBridge) {
      ui.showToast('GPS is unavailable. Enter your address manually.', 'error');
      return;
    }
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.replaceChildren(ui.icon('fa-spinner'), 'Locating…');
    try {
      const coordinates = await LocationClient.getPosition(root.navigator.geolocation);
      const location = await LocationClient.saveGps(root.SavoraPlatformBridge, coordinates);
      const next = DriverState.persist(applyPlatformLocation(location));
      ui.showToast('GPS address saved.');
      populate(next);
    } catch (error) {
      ui.showToast(LocationClient.messageForGeolocationError(error), 'error');
    } finally {
      button.disabled = false;
      button.removeAttribute('aria-busy');
      button.replaceChildren(ui.icon('fa-crosshairs'), 'Use current GPS location');
    }
  });

  form.addEventListener('submit', async event => {
    event.preventDefault();
    const fullName = String(field('fullName').value || '').trim();
    const address = String(field('currentAddress').value || '').trim();
    if (!fullName) {
      setError('Enter your full name.', field('fullName'));
      return;
    }
    if (!address) {
      setError('Enter your current address or use GPS.', field('currentAddress'));
      return;
    }
    try {
      let state = DriverState.setProfile(DriverState.load(), {
        fullName,
        phone: field('phone').value,
        email: field('email').value,
        vehicleType: field('vehicleType').value,
        vehicleModel: field('vehicleModel').value,
        licensePlate: field('licensePlate').value,
        vehicleColor: field('vehicleColor').value
      });
      state = DriverState.setPreferences(state, {
        newOffers: field('newOffers').checked,
        soundAlerts: field('soundAlerts').checked,
        cashOnDelivery: field('cashOnDelivery').checked,
        avoidHighways: field('avoidHighways').checked
      });
      const current = DriverState.load();
      const locationUnchanged = current.location.method === 'gps' && current.location.address === address;
      if (locationUnchanged) {
        state = DriverState.setLocation(state, {
          method: 'gps',
          address,
          latitude: current.location.latitude,
          longitude: current.location.longitude,
          updatedAt: current.location.updatedAt,
          serviceRadiusKm: field('serviceRadiusKm').value
        });
      } else {
        const location = LocationClient && root.SavoraPlatformBridge
          ? await LocationClient.saveManual(root.SavoraPlatformBridge, { address })
          : { address, locationMethod: 'manual' };
        state = applyPlatformLocation(location, state);
      }
      DriverState.persist(state);
      setError('');
      ui.showToast('Profile and settings saved.');
      ui.announce('Driver profile changes saved locally.');
      ui.syncTopbar();
      populate();
    } catch (error) {
      setError(error.message || 'Unable to save profile settings.');
    }
  });

  doc.querySelector('[data-change-password]')?.addEventListener('click', () => {
    ui.showToast('Password changes are managed by Savora account support in this local demo.');
  });

  root.addEventListener('storage', populate);
  root.addEventListener('savora:platform-state', event => {
    if (!event.detail || !event.detail.location) return;
    DriverState.persist(applyPlatformLocation(event.detail.location));
    populate();
  });
  populate();
}(typeof window === 'undefined' ? null : window));
