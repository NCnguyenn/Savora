(function attachDriverDashboard(root) {
  'use strict';

  if (!root || !root.document) return;
  const doc = root.document;
  const Api = root.SavoraApi;
  const ui = root.SavoraDriverUI;
  const page = doc.querySelector('[data-driver-page="overview"]');
  if (!page || !Api || !ui) return;

  let serverOrders = [];
  let dispatch = { offers: [], availabilityStatus: 'offline', eligibilityStatus: 'pending', location: null };
  const setText = (selector, value) => { const node = doc.querySelector(selector); if (node) node.textContent = String(value); };
  const key = scope => Api.intentKey(`driver-${scope}`);

  function refreshIdentity() {
    const name = String(doc.body.dataset.driverSessionName || 'Savora Driver').replace(/\s*\(Driver\)\s*$/i, '').trim();
    setText('[data-driver-first-name]', name.split(/\s+/)[0] || 'Driver');
  }

  async function refreshServer() {
    const [assigned, pickedUp, snapshot] = await Promise.all([
      Api.get('api/orders.php?status=assigned&pageSize=50'),
      Api.get('api/orders.php?status=picked_up&pageSize=50'),
      Api.get('api/dispatch.php')
    ]);
    const byId = new Map();
    for (const result of [assigned, pickedUp]) for (const order of Array.isArray(result && result.orders) ? result.orders : []) byId.set(order.id, order);
    serverOrders = [...byId.values()];
    dispatch = snapshot || dispatch;
  }

  function renderLocation() {
    const location = dispatch.location || serverOrders.map(order => order.assignment && order.assignment.location).find(Boolean);
    const fresh = location && location.recordedAt && (Date.now() - new Date(location.recordedAt).getTime()) <= 5 * 60 * 1000;
    setText('[data-driver-location-address]', fresh ? `Server GPS · ${ui.formatDate(location.recordedAt)}` : 'Server location unavailable');
    const map = doc.querySelector('[data-driver-map]');
    if (map) {
      map.dataset.locationRecordedAt = fresh ? String(location.recordedAt) : '';
      map.setAttribute('aria-label', fresh ? `Server GPS location recorded ${ui.formatDate(location.recordedAt)}.` : 'Server GPS location temporarily unavailable.');
    }
  }

  function renderSummary() {
    const earnings = serverOrders.reduce((sum, order) => sum + Number(order.assignment && order.assignment.earning || 0), 0);
    setText('[data-summary-deliveries]', serverOrders.length);
    setText('[data-summary-earnings]', ui.money(earnings));
    setText('[data-summary-source]', 'MySQL server');
  }

  function renderOrders() {
    const list = doc.querySelector('[data-server-order-list]');
    if (!list) return;
    list.replaceChildren();
    if (!serverOrders.length) list.append(ui.el('li', { className: 'driver-muted' }, 'No assigned deliveries are currently visible.'));
    for (const order of serverOrders) {
      const assignment = order.assignment || {};
      list.append(ui.el('li', {}, [ui.el('strong', { text: order.id || 'Order' }), ui.el('span', { text: `${ui.titleCase(assignment.status || order.status)} · ${order.restaurantName || 'Restaurant'}` })]));
    }
  }

  function renderOffers() {
    const card = doc.querySelector('[data-delivery-offer]');
    const status = doc.querySelector('[data-driver-dispatch-status]');
    if (status) status.textContent = dispatch.eligibilityStatus !== 'eligible'
      ? 'Dispatch requires an eligible Driver profile.'
      : `Server availability: ${ui.titleCase(dispatch.availabilityStatus || 'offline')}.`;
    if (!card) return;
    const offer = dispatch.offers && dispatch.offers[0];
    card.querySelectorAll('[data-offer-content]').forEach(node => node.remove());
    const content = ui.el('div', { dataset: { offerContent: 'true' } });
    if (!offer) {
      content.append(ui.el('h2', { text: 'No active server offer' }), ui.el('p', { text: 'New offers appear here only when the server dispatch service assigns one to this Driver.' }));
    } else {
      content.append(ui.el('h2', { text: `${offer.pickup.restaurantName} · ${offer.orderReference}` }), ui.el('p', { text: `${offer.pickup.address}, ${offer.pickup.city} · ${offer.distanceKm === null ? 'Distance unavailable' : `${offer.distanceKm} km`} · Expires ${ui.formatDate(offer.expiresAt)}` }));
      const actions = ui.el('div', { className: 'driver-offer-actions' }, [
        ui.el('button', { className: 'driver-primary-action', type: 'button', dataset: { offerAccept: offer.offerReference } }, 'Accept offer'),
        ui.el('button', { className: 'driver-secondary-action', type: 'button', dataset: { offerDecline: offer.offerReference } }, 'Decline')
      ]);
      content.append(actions);
    }
    card.append(content);
    card.querySelector('[data-offer-accept]')?.addEventListener('click', () => respondToOffer('accept_offer', offer.offerReference));
    card.querySelector('[data-offer-decline]')?.addEventListener('click', () => respondToOffer('decline_offer', offer.offerReference));
  }

  async function respondToOffer(command, offerReference) {
    try {
      await Api.post('api/dispatch.php', { command, payload: { offerReference } }, key(`${command}-${offerReference}`));
      Api.clearIntentKey(`driver-${command}-${offerReference}`);
      ui.showToast(command === 'accept_offer' ? 'Offer accepted by the server.' : 'Offer declined.');
      await refreshServer(); renderAll();
    } catch (error) { ui.showToast(error.message || 'The server could not process the offer.', 'error'); }
  }

  function renderAll() { refreshIdentity(); renderLocation(); renderSummary(); renderOrders(); renderOffers(); }

  async function sendGps(position) {
    const delivery = serverOrders.find(order => order.assignment && ['assigned', 'arrived', 'picked_up'].includes(order.assignment.status));
    const payload = { latitude: position.coords.latitude, longitude: position.coords.longitude, accuracyMeters: position.coords.accuracy, recordedAt: new Date().toISOString().slice(0, 19).replace('T', ' '), expectedVersion: Number(delivery && delivery.assignment && delivery.assignment.location && delivery.assignment.location.version || 0) };
    if (delivery) payload.deliveryId = Number(delivery.assignment.deliveryId);
    const command = delivery ? 'update_location' : 'set_availability';
    if (!delivery) payload.availabilityStatus = dispatch.availabilityStatus || 'online';
    await Api.post('api/dispatch.php', { command, payload }, key(`gps-${delivery ? delivery.assignment.deliveryId : 'availability'}`));
    await refreshServer(); renderAll();
  }

  root.SavoraDriverDispatchLocation = { sendGps };

  (async function initialize() {
    try { await refreshServer(); renderAll(); }
    catch (error) { setText('[data-driver-dispatch-status]', error.message || 'Server dispatch data is unavailable.'); renderAll(); }
  }());
}(typeof window === 'undefined' ? null : window));
