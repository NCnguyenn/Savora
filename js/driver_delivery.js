function primaryAction(delivery, demoMode) {
  if (!delivery) return null;
  if (demoMode && delivery.status === 'assigned') return ['demo_start_delivery', 'Picked up - start delivery'];
  if (delivery.status === 'assigned') return ['record_arrival', 'Mark arrived at pickup'];
  if (delivery.status === 'arrived') return ['record_pickup', 'Confirm pickup'];
  if (delivery.status === 'picked_up') return ['record_completion', 'Delivered to Customer'];
  return null;
}

function resetProofState(input, status) {
  if (input) input.value = '';
  if (status) status.textContent = '';
}

if (typeof module === 'object' && module.exports) module.exports = { primaryAction, resetProofState };

(function attachDriverDelivery(root) {
  'use strict';

  if (!root || !root.document) return;
  const doc = root.document;
  const Api = root.SavoraApi;
  const ui = root.SavoraDriverUI;
  const demoMode = root.SavoraDemoMode === true;
  const page = doc.querySelector('[data-driver-page="delivery"]');
  if (!page || !Api || !ui) return;
  const timelineSteps = [
    { status: 'assigned', label: 'Assigned' }, { status: 'arrived', label: 'Arrived at pickup' },
    { status: 'picked_up', label: 'Picked up order' }, { status: 'delivered', label: 'Delivered' }
  ];
  let activeDelivery = null;
  let activeRoute = null;
  let routeTimer = null;
  const setText = (selector, value) => { const node = doc.querySelector(selector); if (node) node.textContent = String(value); };
  const key = scope => Api.intentKey(`driver-delivery-${scope}`);
  const safeTel = value => { const phone = String(value || '').replace(/[^\d+]/g, ''); return phone ? `tel:${phone}` : 'driver_delivery.php'; };

  function renderTimeline(delivery) {
    const currentIndex = timelineSteps.findIndex(step => step.status === delivery.status);
    const timeline = doc.querySelector('[data-delivery-timeline]');
    if (!timeline) return;
    timeline.replaceChildren(...timelineSteps.map((step, index) => {
      const milestone = delivery.milestones.find(entry => entry.status === step.status);
      const state = index < currentIndex || delivery.status === 'delivered' ? 'is-complete' : index === currentIndex ? 'is-current' : 'is-upcoming';
      return ui.el('li', { className: state }, [ui.el('span', { 'aria-hidden': 'true' }, ui.icon(index <= currentIndex ? 'fa-check' : 'fa-circle')), ui.el('div', {}, [ui.el('strong', { text: step.label }), ui.el('small', { text: milestone ? ui.formatDate(milestone.createdAt) : index === currentIndex ? 'Current step' : 'Upcoming' })])]);
    }));
  }

  function renderAction(delivery) {
    const button = doc.querySelector('[data-delivery-primary-action]');
    if (!button) return;
    const next = primaryAction(delivery, demoMode);
    const waitingForDemoArrival = demoMode && delivery.status === 'picked_up' && activeRoute?.arrived !== true;
    button.disabled = !next || waitingForDemoArrival;
    button.textContent = next ? next[1] : 'Delivery completed';
    button.dataset.command = next ? next[0] : '';
    button.dataset.deliveryId = String(delivery.deliveryId);
    button.dataset.deliveryVersion = String(delivery.version);
    const proof = doc.querySelector('[data-delivery-proof]');
    if (proof) proof.hidden = delivery.status !== 'picked_up' || delivery.proofRequired !== true;
    setText('[data-banner-title]', delivery.status === 'picked_up' ? `Deliver to ${delivery.customerName}.` : `${delivery.restaurantName} is ready for pickup.`);
    setText('[data-banner-copy]', delivery.status === 'picked_up' ? `Drop off at ${delivery.dropoffAddress}.` : `Navigate to ${delivery.pickupAddress}.`);
  }

  function renderRoute(route) {
    const progress = doc.querySelector('[data-demo-route-progress]');
    if (!progress) return;
    const visible = demoMode && activeDelivery?.status === 'picked_up';
    progress.hidden = !visible;
    if (!visible) return;
    const percent = Math.max(0, Math.min(100, Math.round(Number(route?.progress || 0) * 100)));
    const meter = doc.querySelector('[data-demo-route-meter]');
    if (meter) meter.value = percent;
    setText('[data-demo-route-percent]', `${percent}%`);
    const arrived = route && route.arrived === true;
    setText('[data-demo-route-status]', arrived ? 'Arrived at Customer' : `Driving to Customer - ${percent}%`);
  }

  async function refreshRoute() {
    if (!demoMode || activeDelivery?.status !== 'picked_up') return;
    const tracking = await Api.get(`api/tracking.php?order=${encodeURIComponent(activeDelivery.orderId)}`);
    activeRoute = tracking?.route || null;
    renderRoute(activeRoute);
    renderAction(activeDelivery);
  }

  function scheduleRouteRefresh() {
    if (!demoMode || activeDelivery?.status !== 'picked_up' || routeTimer !== null) return;
    routeTimer = root.setTimeout(async () => {
      routeTimer = null;
      if (doc.visibilityState === 'visible') {
        try { await refreshRoute(); }
        catch (_) { activeRoute = null; renderRoute(null); renderAction(activeDelivery); }
      }
      scheduleRouteRefresh();
    }, 2000);
  }

  async function uploadEvidence(delivery) {
    const input = doc.getElementById('driver-delivery-proof');
    const status = doc.querySelector('[data-delivery-proof-status]');
    const file = input && input.files && input.files[0];
    if (!file) throw new Error('Choose a proof-of-delivery photo or PDF.');
    const form = new FormData();
    form.append('deliveryId', String(delivery.deliveryId));
    form.append('type', 'photo');
    form.append('evidence', file, file.name);
    if (status) status.textContent = 'Uploading and verifying proof...';
    const response = await root.fetch('api/delivery_evidence.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-Token': root.SavoraCsrfToken || '', 'Idempotency-Key': key(`evidence-${delivery.deliveryId}`) },
      body: form
    });
    let payload;
    try { payload = await response.json(); } catch (_) { payload = { ok: false, message: 'Invalid server response.' }; }
    if (!response.ok || !payload.ok) throw new Error(payload.message || 'Proof of delivery could not be uploaded.');
    if (status) status.textContent = 'Proof verified by the server.';
    return Number(payload.data && payload.data.evidenceId);
  }

  function renderDelivery(delivery) {
    const empty = doc.querySelector('[data-delivery-empty]');
    const content = doc.querySelector('[data-delivery-content]');
    const previousDeliveryId = activeDelivery?.deliveryId ?? null;
    const proofInput = doc.getElementById('driver-delivery-proof');
    const proofStatus = doc.querySelector('[data-delivery-proof-status]');
    if (!delivery) { resetProofState(proofInput, proofStatus); activeDelivery = null; empty.hidden = false; content.hidden = true; setText('[data-active-order-id]', 'No active order'); setText('[data-active-delivery-status]', 'Waiting'); return; }
    if (previousDeliveryId !== null && previousDeliveryId !== delivery.deliveryId) resetProofState(proofInput, proofStatus);
    activeDelivery = delivery; empty.hidden = true; content.hidden = false;
    setText('[data-active-order-id]', `Order #${delivery.orderId}`); setText('[data-active-delivery-status]', ui.titleCase(delivery.status));
    setText('[data-pickup-name]', delivery.restaurantName); setText('[data-pickup-address]', delivery.pickupAddress); setText('[data-customer-name]', delivery.customerName);
    setText('[data-customer-phone]', delivery.customerPhone || 'Phone available through Savora support'); setText('[data-customer-address]', delivery.dropoffAddress); setText('[data-delivery-note]', delivery.deliveryNote || 'No delivery note provided.');
    setText('[data-payment-copy]', delivery.paymentMethod === 'cash' ? `Cash on delivery · Collect ${ui.money(delivery.orderTotal)}` : 'Paid with Savora Pay · No cash collection');
    const pickupLink = doc.querySelector('[data-pickup-map-link]'); if (pickupLink) pickupLink.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(delivery.pickupAddress)}`;
    const customerCall = doc.querySelector('[data-customer-call]'); if (customerCall) { customerCall.href = safeTel(delivery.customerPhone); customerCall.setAttribute('aria-disabled', String(!delivery.customerPhone)); }
    const items = doc.querySelector('[data-delivery-items] ul'); if (items) items.replaceChildren(...delivery.items.map(item => ui.el('li', {}, [ui.el('span', { text: `${item.quantity} × ${item.name}` }), ui.el('strong', { text: ui.money(item.quantity * item.unitPrice) })])));
    const map = doc.querySelector('[data-delivery-map]'); if (map) map.setAttribute('aria-label', `Server route from ${delivery.pickupAddress} to ${delivery.dropoffAddress}`);
    renderTimeline(delivery); renderAction(delivery);
  }

  function deliveryForOrder(order) {
    const assignment = order && order.assignment;
    if (!assignment || !['assigned', 'arrived', 'picked_up'].includes(assignment.status)) return null;
    return { orderId: order.id, internalOrderId: Number(order.internalId), deliveryId: Number(assignment.deliveryId), version: Number(assignment.version), proofRequired: assignment.proofRequired === true, restaurantName: order.restaurant && order.restaurant.name || order.restaurantName || 'Restaurant', pickupAddress: [order.restaurant && order.restaurant.address, order.restaurant && order.restaurant.city].filter(Boolean).join(', '), customerName: order.customer && order.customer.fullName || 'Customer', customerPhone: order.customer && order.customer.phone, dropoffAddress: order.address, deliveryNote: order.deliveryNote, paymentMethod: order.paymentMethod, orderTotal: order.total, status: assignment.status, milestones: assignment.milestones || [], items: (order.items || []).map(item => ({ name: item.name, quantity: item.quantity, unitPrice: item.unitPrice })) };
  }

  async function refresh() {
    const snapshot = await Api.get('api/orders.php?status=assigned&pageSize=50');
    const picked = await Api.get('api/orders.php?status=picked_up&pageSize=50');
    const arrived = await Api.get('api/orders.php?status=arrived&pageSize=50').catch(() => ({ orders: [] }));
    const orders = [...(snapshot.orders || []), ...(arrived.orders || []), ...(picked.orders || [])];
    const delivery = orders.map(deliveryForOrder).find(Boolean) || null;
    if (!delivery || delivery.status !== 'picked_up') activeRoute = null;
    renderDelivery(delivery);
    renderRoute(activeRoute);
    if (demoMode && delivery?.status === 'picked_up') {
      await refreshRoute().catch(() => { activeRoute = null; renderRoute(null); renderAction(delivery); });
      scheduleRouteRefresh();
    }
  }

  doc.querySelector('[data-delivery-primary-action]')?.addEventListener('click', async event => {
    const button = event.currentTarget; const command = button.dataset.command;
    if (!command || !activeDelivery) return;
    button.disabled = true;
    try {
      const evidenceIds = command === 'record_completion' && activeDelivery.proofRequired === true ? [await uploadEvidence(activeDelivery)] : [];
      await Api.post('api/dispatch.php', { command, payload: { deliveryId: Number(activeDelivery.deliveryId), expectedVersion: Number(activeDelivery.version), evidenceIds } }, key(`${command}-${activeDelivery.deliveryId}`));
      ui.showToast('Delivery status saved by the server.'); await refresh();
    } catch (error) { button.disabled = false; ui.showToast(error.message || 'The server rejected this delivery update.', 'error'); }
  });

  doc.querySelector('[data-report-issue]')?.addEventListener('click', event => ui.openDialog('driver-issue-dialog', event.currentTarget));
  doc.addEventListener('visibilitychange', () => {
    if (doc.visibilityState === 'visible' && demoMode && activeDelivery?.status === 'picked_up' && routeTimer === null) {
      refreshRoute().catch(() => {});
      scheduleRouteRefresh();
    }
  });
  doc.querySelector('[data-driver-issue-form]')?.addEventListener('submit', async event => { event.preventDefault(); const select = event.currentTarget.elements['issue-reason']; const error = doc.querySelector('[data-driver-issue-error]'); if (!select.value) { select.setAttribute('aria-invalid', 'true'); if (error) error.textContent = 'Choose an issue reason.'; select.focus(); return; } if (!activeDelivery || !activeDelivery.internalOrderId) { if (error) error.textContent = 'An active server delivery is required.'; return; } select.removeAttribute('aria-invalid'); if (error) error.textContent = ''; try { const result = await Api.post('api/support.php', { action: 'open_case', payload: { orderId: activeDelivery.internalOrderId, deliveryId: activeDelivery.deliveryId, caseType: 'delivery_issue', subject: `Delivery issue: ${select.value}`, message: `Driver reported: ${select.value}` } }, key(`support-${activeDelivery.deliveryId}`)); ui.closeDialog('driver-issue-dialog'); ui.showToast(`Support case ${result.referenceCode || 'opened'} was created.`); event.currentTarget.reset(); } catch (requestError) { if (error) error.textContent = requestError.message || 'Unable to open the support case.'; } });
  refresh().catch(error => ui.showToast(error.message || 'Unable to load the assigned delivery.', 'error'));
}(typeof window === 'undefined' ? null : window));
