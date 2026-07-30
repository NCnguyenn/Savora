(function attachRestaurantStorefront(root, factory) {
  const api = factory(root);
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraRestaurantStorefront = api;
}(typeof window === 'undefined' ? null : window, function createRestaurantStorefront(root) {
  'use strict';

  const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
  const dayLabel = day => `${day.slice(0, 1).toUpperCase()}${day.slice(1)}`;
  const text = value => typeof value === 'string' ? value.slice(0, 500) : '';
  const clock = value => /^([01]\d|2[0-3]):[0-5]\d$/.test(text(value)) ? text(value) : '';
  const defaultDay = () => ({ open: '09:00', close: '17:00', closed: false });
  const create = (tag, attributes = {}, children = []) => {
    if (!root || !root.document) return null;
    const node = root.document.createElement(tag);
    Object.entries(attributes).forEach(([name, value]) => {
      if (value == null || value === false) return;
      if (name === 'className') node.className = String(value);
      else node.setAttribute(name, String(value));
    });
    [].concat(children).forEach(child => node.append(child && child.nodeType ? child : root.document.createTextNode(String(child))));
    return node;
  };

  function normalizeWeeklyHours(value) {
    const source = value && typeof value === 'object' && !Array.isArray(value) ? value : {};
    return Object.fromEntries(DAYS.map(day => {
      const item = source[day] && typeof source[day] === 'object' ? source[day] : {};
      if (item.closed === true) return [day, { open: '', close: '', closed: true }];
      const fallback = defaultDay();
      return [day, { open: clock(item.open) || fallback.open, close: clock(item.close) || fallback.close, closed: false }];
    }));
  }

  function validateOperations(value) {
    const source = value && typeof value === 'object' ? value : {};
    const radius = Number(source.deliveryRadius);
    const capacity = Number(source.capacity);
    const prepMinutes = Number(source.prepMinutes);
    const errors = {};
    if (!Number.isFinite(radius) || radius <= 0 || radius > 50) errors.deliveryRadius = 'Delivery radius must be between 0.1 and 50 miles.';
    if (!Number.isFinite(capacity) || capacity < 1 || capacity > 500) errors.capacity = 'Kitchen capacity must be between 1 and 500 orders.';
    if (!Number.isFinite(prepMinutes) || prepMinutes < 1 || prepMinutes > 180) errors.prepMinutes = 'Prep time must be between 1 and 180 minutes.';
    return { valid: Object.keys(errors).length === 0, errors };
  }

  function fields(form) {
    return name => form && form.elements && form.elements.namedItem(name);
  }
  function setMessage(node, message) { if (node) node.textContent = message; }
  function profileAddress(profile) {
    return [profile.addressLine1, profile.addressLine2, profile.city, profile.state, profile.postalCode, profile.country].map(text).filter(Boolean).join(', ') || text(profile.address);
  }
  function profilePatch(form) {
    const field = fields(form);
    const address = {
      addressLine1: text(field('address-line1').value), addressLine2: text(field('address-line2').value), city: text(field('address-city').value),
      state: text(field('address-state').value), postalCode: text(field('address-postal-code').value), country: text(field('address-country').value)
    };
    return {
      name: text(field('profile-name').value), cuisine: text(field('profile-cuisine').value), description: text(field('profile-description').value),
      phone: text(field('profile-phone').value), image: text(field('profile-image').value), address: profileAddress(address), locationMethod: form.dataset.locationMethod || 'manual', ...address
    };
  }
  function setInput(form, name, value, checked) {
    const input = fields(form)(name); if (!input) return;
    if (typeof checked === 'boolean') input.checked = checked;
    else input.value = value == null ? '' : String(value);
  }

  function renderProfilePreview(container, state) {
    if (!container) return;
    const profile = state.profile || {};
    const operations = state.operations || {};
    container.replaceChildren(
      create('p', { className: 'restaurant-eyebrow' }, 'Storefront card'),
      create('h3', {}, profile.name || 'Savora Kitchen'),
      create('p', {}, profile.description || 'Add a short description to help customers discover your restaurant.'),
      create('p', { className: 'restaurant-field-hint' }, profileAddress(profile) || 'Address will appear here after you save it.'),
      create('p', { className: 'restaurant-field-hint' }, `${operations.deliveryEnabled === false ? 'Pickup only' : `${operations.deliveryRadius || 0} mi delivery`} · ${operations.acceptingOrders === false ? 'Orders paused' : 'Open for orders'}`)
    );
  }

  function renderOperationsPreview(container, state) {
    if (!container) return;
    const profile = state.profile || {}; const operations = state.operations || {};
    const monday = (operations.weeklyHours || {}).monday || defaultDay();
    const hours = monday.closed ? 'Closed Monday' : `Monday ${monday.open}–${monday.close}`;
    container.replaceChildren(
      create('h3', {}, profile.name || 'Savora Kitchen'),
      create('p', {}, operations.acceptingOrders === false ? 'Orders are currently paused' : 'Accepting orders'),
      create('p', { className: 'restaurant-field-hint' }, `${hours} · ${operations.prepMinutes || 20} min prep`),
      create('p', { className: 'restaurant-field-hint' }, `${operations.deliveryEnabled === false ? 'Delivery unavailable' : 'Delivery available'} · ${operations.pickupEnabled === false ? 'Pickup unavailable' : 'Pickup available'}`)
    );
  }

  function renderMap(profile) {
    const host = root && root.document && root.document.getElementById('restaurant-location-map');
    if (!host) return;
    if (host._savoraMap) { host._savoraMap.remove(); host._savoraMap = null; }
    host.replaceChildren();
    host.style.minHeight = '160px';
    const hasCoordinates = profile.locationMethod === 'current' && profile.latitude !== null && profile.longitude !== null && Number.isFinite(Number(profile.latitude)) && Number.isFinite(Number(profile.longitude));
    if (root.L && hasCoordinates) {
      if (!root.document.querySelector('link[data-local-leaflet]')) root.document.head.append(create('link', { rel: 'stylesheet', href: 'assets/vendor/leaflet/leaflet.css', 'data-local-leaflet': 'true' }));
      const map = root.L.map(host, { zoomControl: true, attributionControl: false }).setView([profile.latitude, profile.longitude], 13);
      host._savoraMap = map;
      root.L.marker([profile.latitude, profile.longitude]).addTo(map).bindTooltip('Saved restaurant location').openTooltip();
      return;
    }
    host.append(create('p', { className: 'restaurant-empty' }, hasCoordinates ? `Saved location: ${Number(profile.latitude).toFixed(4)}, ${Number(profile.longitude).toFixed(4)}.` : 'No coordinates saved. Enter a manual address or use your current location.'));
  }

  function initProfile() {
    const form = root.document.querySelector('[data-store-profile-form]');
    if (!form || !root.SavoraRestaurantState) return;
    const api = root.SavoraRestaurantState; const ui = root.SavoraRestaurantUI; let state = api.load();
    const feedback = root.document.querySelector('[data-profile-feedback]');
    const addressFeedback = root.document.querySelector('[data-address-feedback]');
    const preview = root.document.querySelector('[data-storefront-preview]');
    const hydrate = () => {
      const profile = state.profile || {}; const operations = state.operations || {};
      form.dataset.locationMethod = profile.locationMethod === 'current' ? 'current' : 'manual';
      setInput(form, 'profile-name', profile.name); setInput(form, 'profile-cuisine', profile.cuisine); setInput(form, 'profile-description', profile.description); setInput(form, 'profile-phone', profile.phone); setInput(form, 'profile-image', profile.image);
      setInput(form, 'address-line1', profile.addressLine1); setInput(form, 'address-line2', profile.addressLine2); setInput(form, 'address-city', profile.city); setInput(form, 'address-state', profile.state); setInput(form, 'address-postal-code', profile.postalCode); setInput(form, 'address-country', profile.country);
      setInput(form, 'delivery-radius', operations.deliveryRadius); setInput(form, 'minimum-order', operations.minimumOrder); setInput(form, 'profile-prep-minutes', operations.prepMinutes);
      renderProfilePreview(preview, state); renderMap(profile);
    };
    const previewDraft = () => renderProfilePreview(preview, { ...state, profile: { ...state.profile, ...profilePatch(form) }, operations: { ...state.operations, deliveryRadius: Number(fields(form)('delivery-radius').value) || 0 } });
    form.addEventListener('input', previewDraft);
    form.addEventListener('submit', event => {
      event.preventDefault();
      const patch = profilePatch(form); const deliveryRadius = Number(fields(form)('delivery-radius').value); const minimumOrder = Number(fields(form)('minimum-order').value); const prepMinutes = Number(fields(form)('profile-prep-minutes').value);
      const validation = validateOperations({ deliveryRadius, capacity: state.operations.capacity || 1, prepMinutes });
      if (!patch.name) { setMessage(feedback, 'Restaurant name is required.'); fields(form)('profile-name').setAttribute('aria-invalid', 'true'); return; }
      if (!validation.valid) { setMessage(feedback, validation.errors.deliveryRadius || validation.errors.prepMinutes); return; }
      state = api.setProfile(state, patch);
      state = api.setOperations(state, { deliveryRadius, minimumOrder, prepMinutes });
      state = api.persist(state); if (ui) ui.refreshShell(); hydrate(); setMessage(feedback, 'Store profile saved locally.'); if (ui) ui.showToast('Store profile saved locally.');
    });
    const manual = root.document.querySelector('[data-manual-address]');
    if (manual) manual.addEventListener('click', () => { form.dataset.locationMethod = 'manual'; state = api.persist(api.setProfile(state, { locationMethod: 'manual' })); renderMap(state.profile); setMessage(addressFeedback, 'Enter the address manually, then save your profile.'); fields(form)('address-line1').focus(); });
    const locate = root.document.querySelector('[data-use-current-location]');
    if (locate) locate.addEventListener('click', () => {
      if (!root.navigator || !navigator.geolocation) { setMessage(addressFeedback, 'Current location is unavailable in this browser. You can enter the address manually.'); return; }
      setMessage(addressFeedback, 'Requesting your current location…');
      navigator.geolocation.getCurrentPosition(position => {
        form.dataset.locationMethod = 'current'; state = api.setProfile(state, { latitude: position.coords.latitude, longitude: position.coords.longitude, locationMethod: 'current' });
        state = api.persist(state); renderMap(state.profile); setMessage(addressFeedback, 'Current location saved. You can still update the manual address fields.'); if (ui) ui.showToast('Current location saved locally.');
      }, () => setMessage(addressFeedback, 'Current location could not be used. You can enter the address manually.'));
    });
    hydrate();
  }

  function specialRows(container, specialHours) {
    container.replaceChildren();
    specialHours.forEach((entry, index) => {
      const row = create('div', { className: 'restaurant-form-three-column', 'data-special-row': String(index) });
      const date = create('input', { type: 'date', value: entry.date, 'data-special-date': '', 'aria-label': 'Special hours date' });
      const open = create('input', { type: 'time', value: entry.open || '09:00', 'data-special-open': '', 'aria-label': 'Special hours opening time' });
      const close = create('input', { type: 'time', value: entry.close || '17:00', 'data-special-close': '', 'aria-label': 'Special hours closing time' });
      const closed = create('input', { type: 'checkbox', 'data-special-closed': '', 'aria-label': 'Closed on this date' }); closed.checked = entry.closed === true;
      const note = create('input', { type: 'text', value: entry.note || '', maxlength: '100', 'data-special-note': '', 'aria-label': 'Special hours note' });
      const setTimedInputs = () => { open.disabled = closed.checked; close.disabled = closed.checked; };
      closed.addEventListener('change', setTimedInputs); setTimedInputs();
      row.append(create('label', { className: 'restaurant-field' }, ['Date', date]), create('label', { className: 'restaurant-field' }, ['Open', open]), create('label', { className: 'restaurant-field' }, ['Close', close]), create('label', { className: 'restaurant-check-field' }, [closed, 'Closed']), create('label', { className: 'restaurant-field' }, ['Note', note]));
      container.append(row);
    });
  }
  function readSpecialHours(container) {
    return [...container.querySelectorAll('[data-special-row]')].map(row => {
      const date = row.querySelector('[data-special-date]'); const open = row.querySelector('[data-special-open]'); const close = row.querySelector('[data-special-close]'); const closed = row.querySelector('[data-special-closed]'); const note = row.querySelector('[data-special-note]');
      return { date: text(date.value), open: text(open.value), close: text(close.value), closed: closed.checked, note: text(note.value) };
    }).filter(item => /^\d{4}-\d{2}-\d{2}$/.test(item.date));
  }
  function weeklyRows(container, weeklyHours) {
    container.replaceChildren();
    DAYS.forEach(day => {
      const value = weeklyHours[day] || defaultDay(); const row = create('div', { className: 'restaurant-form-three-column', 'data-weekday': day });
      const open = create('input', { type: 'time', value: value.open, 'aria-label': `${dayLabel(day)} opening time` });
      const close = create('input', { type: 'time', value: value.close, 'aria-label': `${dayLabel(day)} closing time` });
      const closed = create('input', { type: 'checkbox', 'aria-label': `${dayLabel(day)} closed` }); closed.checked = value.closed === true;
      row.append(create('strong', {}, dayLabel(day)), create('label', { className: 'restaurant-field' }, ['Open', open]), create('label', { className: 'restaurant-field' }, ['Close', close]), create('label', { className: 'restaurant-check-field' }, [closed, 'Closed']));
      closed.addEventListener('change', () => { open.disabled = closed.checked; close.disabled = closed.checked; }); if (closed.checked) { open.disabled = true; close.disabled = true; }
      container.append(row);
    });
  }
  function readWeeklyHours(container) {
    const source = {};
    container.querySelectorAll('[data-weekday]').forEach(row => {
      const [open, close, closed] = row.querySelectorAll('input'); source[row.dataset.weekday] = { open: open.value, close: close.value, closed: closed.checked };
    });
    return normalizeWeeklyHours(source);
  }

  function initOperations() {
    const form = root.document.querySelector('[data-store-operations-form]');
    if (!form || !root.SavoraRestaurantState) return;
    const api = root.SavoraRestaurantState; const ui = root.SavoraRestaurantUI; let state = api.load(); const field = fields(form);
    const weekly = root.document.querySelector('[data-weekly-hours]'); const specials = root.document.querySelector('[data-special-hours]'); const feedback = root.document.querySelector('[data-operations-feedback]'); const warning = root.document.querySelector('[data-capacity-warning]'); const preview = root.document.querySelector('[data-operations-preview]');
    const updateWarning = () => { const capacity = Number(field('capacity').value); setMessage(warning, capacity > 0 && capacity < 5 ? 'Low capacity may pause orders during busy periods.' : ''); };
    const hydrate = () => { const operations = state.operations || {}; setInput(form, 'accepting-orders', '', operations.acceptingOrders !== false); setInput(form, 'prep-minutes', operations.prepMinutes); setInput(form, 'capacity', operations.capacity); setInput(form, 'delivery-enabled', '', operations.deliveryEnabled !== false); setInput(form, 'pickup-enabled', '', operations.pickupEnabled !== false); setInput(form, 'pickup-instructions', operations.pickupInstructions); weeklyRows(weekly, normalizeWeeklyHours(operations.weeklyHours)); specialRows(specials, operations.specialHours || []); updateWarning(); renderOperationsPreview(preview, state); };
    field('capacity').addEventListener('input', updateWarning);
    root.document.querySelector('[data-copy-hours]').addEventListener('click', () => { const monday = weekly.querySelector('[data-weekday="monday"]'); const [open, close, closed] = monday.querySelectorAll('input'); weeklyRows(weekly, Object.fromEntries(DAYS.map(day => [day, { open: open.value, close: close.value, closed: closed.checked }]))); });
    root.document.querySelector('[data-add-special-hours]').addEventListener('click', () => { specialRows(specials, [...readSpecialHours(specials), { date: '', open: '09:00', close: '17:00', closed: false, note: '' }]); });
    form.addEventListener('submit', event => {
      event.preventDefault(); const deliveryRadius = state.operations.deliveryRadius || 0; const capacity = Number(field('capacity').value); const prepMinutes = Number(field('prep-minutes').value); const validation = validateOperations({ deliveryRadius: deliveryRadius || 0.1, capacity, prepMinutes });
      if (!validation.valid) { setMessage(feedback, validation.errors.capacity || validation.errors.prepMinutes || validation.errors.deliveryRadius); return; }
      state = api.setOperations(state, { acceptingOrders: field('accepting-orders').checked, prepMinutes, capacity, deliveryEnabled: field('delivery-enabled').checked, pickupEnabled: field('pickup-enabled').checked, pickupInstructions: text(field('pickup-instructions').value), weeklyHours: readWeeklyHours(weekly), specialHours: readSpecialHours(specials) });
      state = api.persist(state); if (ui) ui.refreshShell(); renderOperationsPreview(preview, state); updateWarning(); setMessage(feedback, 'Operations and opening hours saved locally.'); if (ui) ui.showToast('Operations saved locally.');
    });
    hydrate();
  }

  function initialize() { if (!root || !root.document) return; initProfile(); initOperations(); }
  if (root && root.document) { if (root.document.readyState === 'loading') root.document.addEventListener('DOMContentLoaded', initialize, { once: true }); else initialize(); }
  return { normalizeWeeklyHours, validateOperations };
}));
