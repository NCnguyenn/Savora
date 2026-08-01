(function attachDriverDelivery(root) {
  'use strict';

  if (!root || !root.document) return;
  const doc = root.document;
  const DriverState = root.SavoraDriverState;
  const CustomerState = root.SavoraState;
  const ui = root.SavoraDriverUI;
  const page = doc.querySelector('[data-driver-page="delivery"]');
  if (!page || !DriverState || !CustomerState || !ui) return;

  const timelineSteps = [
    { status: 'assigned', label: 'Assigned' },
    { status: 'arrived', label: 'Arrived at restaurant' },
    { status: 'picked_up', label: 'Picked up order' },
    { status: 'delivered', label: 'Delivered' }
  ];
  const nextAction = {
    assigned: { milestone: 'arrived', label: 'Confirm arrival' },
    arrived: { milestone: 'picked_up', label: 'Confirm pickup' },
    picked_up: { milestone: 'delivered', label: 'Confirm delivery' }
  };

  const setText = (selector, value) => {
    const node = doc.querySelector(selector);
    if (node) node.textContent = String(value);
  };

  const safeTel = value => {
    const phone = String(value || '').replace(/[^\d+]/g, '');
    return phone ? `tel:${phone}` : 'driver_delivery.php';
  };

  function renderTimeline(delivery) {
    const currentIndex = timelineSteps.findIndex(step => step.status === delivery.status);
    const timeline = doc.querySelector('[data-delivery-timeline]');
    if (!timeline) return;
    timeline.replaceChildren(...timelineSteps.map((step, index) => {
      const milestone = delivery.milestones.find(entry => entry.status === step.status);
      const state = index < currentIndex || delivery.status === 'delivered'
        ? 'is-complete'
        : index === currentIndex ? 'is-current' : 'is-upcoming';
      return ui.el('li', { className: state }, [
        ui.el('span', { 'aria-hidden': 'true' }, ui.icon(index <= currentIndex ? 'fa-check' : 'fa-circle')),
        ui.el('div', {}, [
          ui.el('strong', { text: step.label }),
          ui.el('small', { text: milestone ? ui.formatDate(milestone.createdAt) : index === currentIndex ? 'Current step' : 'Upcoming' })
        ])
      ]);
    }));
  }

  function renderDelivery(delivery) {
    const empty = doc.querySelector('[data-delivery-empty]');
    const content = doc.querySelector('[data-delivery-content]');
    if (!delivery) {
      empty.hidden = false;
      content.hidden = true;
      setText('[data-active-order-id]', 'No active order');
      setText('[data-active-delivery-status]', 'Waiting');
      return;
    }

    empty.hidden = true;
    content.hidden = false;
    setText('[data-active-order-id]', `Order #${delivery.orderId}`);
    setText('[data-active-delivery-status]', ui.titleCase(delivery.status));
    setText('[data-pickup-name]', delivery.restaurantName);
    setText('[data-pickup-address]', delivery.pickupAddress);
    setText('[data-customer-name]', delivery.customerName);
    setText('[data-customer-phone]', delivery.customerPhone || 'Phone available through Savora support');
    setText('[data-customer-address]', delivery.dropoffAddress);
    setText('[data-delivery-note]', delivery.deliveryNote || 'No delivery note provided.');
    setText('[data-payment-copy]', delivery.paymentMethod === 'cash'
      ? `Cash on delivery · Collect ${ui.money(delivery.orderTotal)}`
      : 'Paid with Savora Pay · No cash collection');
    const pickupLink = doc.querySelector('[data-pickup-map-link]');
    if (pickupLink) pickupLink.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(delivery.pickupAddress)}`;
    const customerCall = doc.querySelector('[data-customer-call]');
    if (customerCall) {
      customerCall.href = safeTel(delivery.customerPhone);
      customerCall.setAttribute('aria-disabled', String(!delivery.customerPhone));
    }
    const items = doc.querySelector('[data-delivery-items] ul');
    if (items) {
      items.replaceChildren(...delivery.items.map(item => ui.el('li', {}, [
        ui.el('span', { text: `${item.quantity} × ${item.name}` }),
        ui.el('strong', { text: ui.money(item.quantity * item.unitPrice) })
      ])));
    }
    const map = doc.querySelector('[data-delivery-map]');
    if (map) map.setAttribute('aria-label', `Route from ${delivery.pickupAddress} to ${delivery.dropoffAddress}`);
    renderTimeline(delivery);
    renderAction(delivery);
  }

  function renderAction(delivery) {
    const button = doc.querySelector('[data-delivery-primary-action]');
    const action = nextAction[delivery.status];
    if (!button) return;
    if (!action) {
      button.disabled = true;
      button.textContent = 'Delivery completed';
      return;
    }
    button.disabled = false;
    button.dataset.milestone = action.milestone;
    button.textContent = action.label;
    const bannerTitle = {
      assigned: `${delivery.restaurantName} is ready for pickup.`,
      arrived: 'Confirm the order before leaving.',
      picked_up: `Deliver to ${delivery.customerName}.`
    }[delivery.status];
    const bannerCopy = {
      assigned: `Navigate to ${delivery.pickupAddress}.`,
      arrived: 'Check the item list and confirm pickup.',
      picked_up: `Drop off at ${delivery.dropoffAddress}.`
    }[delivery.status];
    setText('[data-banner-title]', bannerTitle);
    setText('[data-banner-copy]', bannerCopy);
  }

  function render() {
    const state = DriverState.load();
    renderDelivery(DriverState.activeDelivery(state));
  }

  doc.querySelector('[data-delivery-primary-action]')?.addEventListener('click', async event => {
    const state = DriverState.load();
    const delivery = DriverState.activeDelivery(state);
    const milestone = event.currentTarget.dataset.milestone;
    if (!delivery || !milestone) return;
    try {
      const result = DriverState.updateMilestone(state, CustomerState.load(), delivery.orderId, milestone, Date.now());
      if (!root.SavoraPlatformBridge) throw new Error('The platform connection is not ready.');
      await root.SavoraPlatformBridge.command('driver_milestone', { reference_code: delivery.orderId, milestone });
      DriverState.persist(result.state);
      CustomerState.persist(result.customerState);
      ui.showToast(`${ui.titleCase(milestone)} confirmed.`);
      ui.announce(`Delivery ${delivery.orderId} is now ${ui.titleCase(milestone)}.`);
      if (milestone === 'delivered') {
        root.location.href = `driver_history.php?delivery=${encodeURIComponent(delivery.orderId)}`;
        return;
      }
      render();
    } catch (error) {
      ui.showToast(error.message || 'Unable to update this delivery.', 'error');
    }
  });

  doc.querySelector('[data-report-issue]')?.addEventListener('click', event => {
    ui.openDialog('driver-issue-dialog', event.currentTarget);
  });

  doc.querySelector('[data-driver-issue-form]')?.addEventListener('submit', event => {
    event.preventDefault();
    const select = event.currentTarget.elements['issue-reason'];
    const error = doc.querySelector('[data-driver-issue-error]');
    if (!select.value) {
      select.setAttribute('aria-invalid', 'true');
      if (error) error.textContent = 'Choose an issue reason.';
      select.focus();
      return;
    }
    select.removeAttribute('aria-invalid');
    if (error) error.textContent = '';
    ui.closeDialog('driver-issue-dialog');
    ui.showToast('Issue report saved for local support review.');
    ui.announce(`Issue reported: ${select.value}.`);
    event.currentTarget.reset();
  });

  root.addEventListener('storage', render);
  render();
}(typeof window === 'undefined' ? null : window));
