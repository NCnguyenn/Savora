(function attachDriverDelivery(root) {
  'use strict';

  if (!root || !root.document) return;
  const doc = root.document;
  const Api = root.SavoraApi;
  const ui = root.SavoraDriverUI;
  const page = doc.querySelector('[data-driver-page="delivery"]');
  if (!page || !Api || !ui) return;
  const timelineSteps = [
    { status: 'assigned', label: 'Assigned' }, { status: 'arrived', label: 'Arrived at pickup' },
    { status: 'picked_up', label: 'Picked up order' }, { status: 'delivered', label: 'Delivered' }
  ];
  let activeDelivery = null;
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
    const next = { assigned: ['record_arrival', 'Mark arrived at pickup'], arrived: ['record_pickup', 'Confirm pickup'], picked_up: ['record_completion', 'Confirm delivery'] }[delivery.status];
    button.disabled = !next;
    button.textContent = next ? next[1] : 'Delivery completed';
    button.dataset.command = next ? next[0] : '';
    button.dataset.deliveryId = String(delivery.deliveryId);
    button.dataset.deliveryVersion = String(delivery.version);
    setText('[data-banner-title]', delivery.status === 'picked_up' ? `Deliver to ${delivery.customerName}.` : `${delivery.restaurantName} is ready for pickup.`);
    setText('[data-banner-copy]', delivery.status === 'picked_up' ? `Drop off at ${delivery.dropoffAddress}.` : `Navigate to ${delivery.pickupAddress}.`);
  }

  function renderDelivery(delivery) {
    const empty = doc.querySelector('[data-delivery-empty]');
    const content = doc.querySelector('[data-delivery-content]');
    if (!delivery) { empty.hidden = false; content.hidden = true; setText('[data-active-order-id]', 'No active order'); setText('[data-active-delivery-status]', 'Waiting'); return; }
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
    return { orderId: order.id, internalOrderId: Number(order.internalId), deliveryId: Number(assignment.deliveryId), version: Number(assignment.version), restaurantName: order.restaurant && order.restaurant.name || order.restaurantName || 'Restaurant', pickupAddress: [order.restaurant && order.restaurant.address, order.restaurant && order.restaurant.city].filter(Boolean).join(', '), customerName: order.customer && order.customer.fullName || 'Customer', customerPhone: order.customer && order.customer.phone, dropoffAddress: order.address, deliveryNote: order.deliveryNote, paymentMethod: order.paymentMethod, orderTotal: order.total, status: assignment.status, milestones: assignment.milestones || [], items: (order.items || []).map(item => ({ name: item.name, quantity: item.quantity, unitPrice: item.unitPrice })) };
  }

  async function refresh() {
    const snapshot = await Api.get('api/orders.php?status=assigned&pageSize=50');
    const picked = await Api.get('api/orders.php?status=picked_up&pageSize=50');
    const arrived = await Api.get('api/orders.php?status=arrived&pageSize=50').catch(() => ({ orders: [] }));
    const orders = [...(snapshot.orders || []), ...(arrived.orders || []), ...(picked.orders || [])];
    renderDelivery(orders.map(deliveryForOrder).find(Boolean) || null);
  }

  doc.querySelector('[data-delivery-primary-action]')?.addEventListener('click', async event => {
    const button = event.currentTarget; const command = button.dataset.command;
    if (!command || !activeDelivery) return;
    button.disabled = true;
    try {
      await Api.post('api/dispatch.php', { command, payload: { deliveryId: Number(activeDelivery.deliveryId), expectedVersion: Number(activeDelivery.version), evidence: [] } }, key(`${command}-${activeDelivery.deliveryId}`));
      ui.showToast('Delivery status saved by the server.'); await refresh();
    } catch (error) { button.disabled = false; ui.showToast(error.message || 'The server rejected this delivery update.', 'error'); }
  });

  doc.querySelector('[data-report-issue]')?.addEventListener('click', event => ui.openDialog('driver-issue-dialog', event.currentTarget));
  doc.querySelector('[data-driver-issue-form]')?.addEventListener('submit', async event => { event.preventDefault(); const select = event.currentTarget.elements['issue-reason']; const error = doc.querySelector('[data-driver-issue-error]'); if (!select.value) { select.setAttribute('aria-invalid', 'true'); if (error) error.textContent = 'Choose an issue reason.'; select.focus(); return; } if (!activeDelivery || !activeDelivery.internalOrderId) { if (error) error.textContent = 'An active server delivery is required.'; return; } select.removeAttribute('aria-invalid'); if (error) error.textContent = ''; try { const result = await Api.post('api/support.php', { action: 'open_case', payload: { orderId: activeDelivery.internalOrderId, deliveryId: activeDelivery.deliveryId, caseType: 'delivery_issue', subject: `Delivery issue: ${select.value}`, message: `Driver reported: ${select.value}` } }, key(`support-${activeDelivery.deliveryId}`)); ui.closeDialog('driver-issue-dialog'); ui.showToast(`Support case ${result.referenceCode || 'opened'} was created.`); event.currentTarget.reset(); } catch (requestError) { if (error) error.textContent = requestError.message || 'Unable to open the support case.'; } });
  refresh().catch(error => ui.showToast(error.message || 'Unable to load the assigned delivery.', 'error'));
}(typeof window === 'undefined' ? null : window));
