(function attachDriverDashboard(root) {
  'use strict';

  if (!root || !root.document) return;
  const doc = root.document;
  const DriverState = root.SavoraDriverState;
  const CustomerState = root.SavoraState;
  const RestaurantState = root.SavoraRestaurantState;
  const ui = root.SavoraDriverUI;
  const page = doc.querySelector('[data-driver-page="overview"]');
  if (!page || !DriverState || !CustomerState || !RestaurantState || !ui) return;

  let countdownTimer = null;
  let dispatchTimer = null;

  const setText = (selector, value) => {
    const node = doc.querySelector(selector);
    if (node) node.textContent = String(value);
  };

  function currentStates() {
    return {
      driver: DriverState.load(),
      customer: CustomerState.load(),
      restaurant: RestaurantState.load()
    };
  }

  function syncSessionProfile(state) {
    const sessionName = String(doc.body.dataset.driverSessionName || '').replace(/\s*\(Driver\)\s*$/i, '').trim();
    const sessionId = String(doc.body.dataset.driverSessionId || '').trim();
    const patch = {};
    if (sessionName && sessionName !== state.profile.fullName) patch.fullName = sessionName;
    if (sessionId && sessionId !== state.profile.id) patch.id = sessionId;
    return Object.keys(patch).length ? DriverState.setProfile(state, patch) : state;
  }

  function prepareDriverState(states) {
    let driver = syncSessionProfile(states.driver);
    const now = Date.now();
    driver = DriverState.expireOffer(driver, now);
    driver = DriverState.expireDispatches(driver, now);
    driver = DriverState.createOffer(driver, states.customer, states.restaurant, now);
    return DriverState.persist(driver);
  }

  function renderIdentity(state) {
    const firstName = state.profile.fullName.split(/\s+/).filter(Boolean)[0] || 'Driver';
    setText('[data-driver-first-name]', firstName);
  }

  function renderAvailability(state) {
    const button = doc.querySelector('[data-driver-availability]');
    if (!button) return;
    button.setAttribute('aria-pressed', String(state.online));
    button.classList.toggle('is-online', state.online);
    const strong = button.querySelector('strong');
    const small = button.querySelector('small');
    if (strong) strong.textContent = state.online ? 'Online' : 'Offline';
    if (small) small.textContent = state.online ? 'Receiving delivery offers' : 'Not receiving offers';
    ui.syncTopbar();
  }

  function renderLocation(state) {
    setText('[data-driver-location-address]', state.location.address || 'Location unavailable');
    const map = doc.querySelector('[data-driver-map]');
    if (map) {
      map.dataset.locationMethod = state.location.method;
      map.setAttribute('aria-label', `${state.location.address}. ${state.serviceRadiusKm} kilometer service area.`);
    }
    const input = doc.querySelector('[name="driver-address"]');
    if (input && doc.activeElement !== input) input.value = state.location.address || '';
  }

  function renderSummary(state) {
    const earnings = DriverState.deriveEarnings(state);
    const today = new Date().toISOString().slice(0, 10);
    const completedToday = earnings.records.filter(delivery => String(delivery.deliveredAt || '').slice(0, 10) === today);
    const todayTotal = completedToday.reduce((sum, delivery) => sum + delivery.earnings + delivery.bonus, 0);
    const attempts = state.offerAttempts.length;
    const accepted = state.offerAttempts.filter(attempt => attempt.outcome === 'accepted').length;
    const acceptanceRate = attempts ? Math.round((accepted / attempts) * 100) : 100;
    setText('[data-summary-deliveries]', completedToday.length);
    setText('[data-summary-earnings]', ui.money(todayTotal));
    setText('[data-summary-acceptance]', `${acceptanceRate}%`);
  }

  function emptyOfferCopy(state) {
    if (DriverState.activeDelivery(state)) {
      return {
        title: 'Delivery in progress',
        copy: 'Finish your active delivery before receiving another offer.',
        action: ui.el('a', { className: 'driver-primary-action', href: 'driver_delivery.php' }, [
          ui.icon('fa-route'), 'Open active delivery'
        ])
      };
    }
    if (!state.online) {
      return {
        title: 'Go online to receive offers',
        copy: 'Your next eligible delivery will appear here with restaurant, customer, route, and earnings details.'
      };
    }
    const reassigned = state.dispatches.find(dispatch =>
      dispatch.status === 'offer_sent' && dispatch.candidateDriverId && dispatch.candidateDriverId !== state.profile.id &&
      state.offerAttempts.some(attempt => attempt.orderId === dispatch.orderId && ['declined', 'expired'].includes(attempt.outcome))
    );
    if (reassigned) {
      return {
        title: 'Offer reassigned',
        copy: 'This delivery is now being offered to another eligible driver. Savora is looking for your next nearby delivery.'
      };
    }
    return {
      title: 'Looking for nearby deliveries',
      copy: 'You are online. Savora will show one eligible offer at a time.'
    };
  }

  function renderOffer(state) {
    const empty = doc.querySelector('[data-offer-empty]');
    const content = doc.querySelector('[data-offer-content]');
    const offer = state.currentOffer;
    if (!empty || !content) return;
    page.classList.toggle('has-active-offer', Boolean(offer));
    if (!offer) {
      const copy = emptyOfferCopy(state);
      empty.replaceChildren(...[
        ui.el('span', {}, ui.icon(state.online ? 'fa-satellite-dish' : 'fa-wifi')),
        ui.el('h2', { text: copy.title }),
        ui.el('p', { text: copy.copy }),
        copy.action
      ].filter(Boolean));
      empty.hidden = false;
      content.hidden = true;
      if (countdownTimer) root.clearInterval(countdownTimer);
      countdownTimer = null;
      scheduleDispatchReconciliation(state);
      return;
    }

    if (dispatchTimer) root.clearTimeout(dispatchTimer);
    dispatchTimer = null;

    empty.hidden = true;
    content.hidden = false;
    setText('[data-offer-restaurant]', offer.restaurantName);
    setText('[data-offer-pickup-address]', offer.pickupAddress);
    setText('[data-offer-customer]', offer.customerName);
    setText('[data-offer-dropoff-address]', offer.dropoffAddress);
    setText('[data-offer-pickup-distance]', `${offer.distanceToPickupKm.toFixed(1)} km to pickup`);
    setText('[data-offer-distance]', `${offer.distanceKm.toFixed(1)} km trip`);
    setText('[data-offer-earnings]', ui.money(offer.earnings));
    setText('[data-offer-payment]', offer.paymentMethod === 'cash' ? `Cash on delivery · ${ui.money(offer.orderTotal)}` : 'Savora Pay');
    const list = doc.querySelector('[data-offer-items]');
    if (list) {
      list.replaceChildren(...offer.items.map(item => ui.el('li', {}, [
        ui.el('span', { text: `${item.quantity} × ${item.name}` }),
        ui.el('strong', { text: ui.money(item.quantity * item.unitPrice) })
      ])));
    }
    startCountdown(offer);
  }

  function scheduleDispatchReconciliation(state) {
    if (dispatchTimer) root.clearTimeout(dispatchTimer);
    dispatchTimer = null;
    const expiresAt = state.dispatches
      .filter(dispatch => dispatch.status === 'offer_sent' && dispatch.expiresAt > Date.now())
      .map(dispatch => dispatch.expiresAt)
      .sort((a, b) => a - b)[0];
    if (!expiresAt) return;
    dispatchTimer = root.setTimeout(() => {
      dispatchTimer = null;
      renderAll();
    }, Math.max(0, expiresAt - Date.now()) + 25);
  }

  function updateCountdown(offer) {
    const remaining = Math.max(0, offer.expiresAt - Date.now());
    const seconds = Math.ceil(remaining / 1000);
    const node = doc.querySelector('[data-offer-countdown]');
    if (node) {
      node.textContent = `00:${String(seconds).padStart(2, '0')}`;
      node.setAttribute('datetime', `PT${seconds}S`);
    }
    if (remaining > 0) return;
    const state = DriverState.persist(DriverState.expireOffer(DriverState.load(), Date.now()));
    ui.showToast('Offer expired. Savora is searching for another driver.', 'error');
    ui.announce('The delivery offer expired after 30 seconds.');
    renderAll(state);
  }

  function startCountdown(offer) {
    if (countdownTimer) root.clearInterval(countdownTimer);
    updateCountdown(offer);
    countdownTimer = root.setInterval(() => {
      const current = DriverState.load().currentOffer;
      if (!current || current.orderId !== offer.orderId) {
        root.clearInterval(countdownTimer);
        countdownTimer = null;
        return;
      }
      updateCountdown(current);
    }, 1000);
  }

  function renderAll(providedState) {
    const states = currentStates();
    const state = providedState || prepareDriverState(states);
    renderIdentity(state);
    renderAvailability(state);
    renderLocation(state);
    renderSummary(state);
    renderOffer(state);
  }

  doc.querySelector('[data-driver-availability]')?.addEventListener('click', event => {
    const current = DriverState.load();
    const next = DriverState.persist(DriverState.setAvailability(current, !current.online));
    ui.showToast(next.online ? 'You are online and ready for offers.' : 'You are now offline.');
    ui.announce(next.online ? 'Online. Looking for nearby deliveries.' : 'Offline. New offers are paused.');
    renderAll(next.online ? prepareDriverState({ ...currentStates(), driver: next }) : next);
    event.currentTarget.focus();
  });

  doc.querySelector('[data-use-driver-gps]')?.addEventListener('click', event => {
    const button = event.currentTarget;
    if (!root.navigator.geolocation) {
      ui.showToast('GPS is unavailable. Enter your address manually.', 'error');
      return;
    }
    button.disabled = true;
    button.textContent = 'Locating…';
    root.navigator.geolocation.getCurrentPosition(position => {
      const current = DriverState.load();
      const next = DriverState.setLocation(current, {
        method: 'gps',
        address: current.location.address || 'Current GPS location',
        latitude: position.coords.latitude,
        longitude: position.coords.longitude
      });
      DriverState.persist(next);
      button.disabled = false;
      button.replaceChildren(ui.icon('fa-crosshairs'), 'Use GPS');
      ui.showToast('Current GPS location saved.');
      renderAll(next);
    }, () => {
      button.disabled = false;
      button.replaceChildren(ui.icon('fa-crosshairs'), 'Use GPS');
      ui.showToast('Location permission was not granted. Enter an address manually.', 'error');
    }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
  });

  doc.querySelector('[data-enter-driver-address]')?.addEventListener('click', event => {
    const input = doc.querySelector('[name="driver-address"]');
    if (input) input.value = DriverState.load().location.address || '';
    ui.openDialog('driver-address-dialog', event.currentTarget);
  });

  doc.querySelector('[data-driver-address-form]')?.addEventListener('submit', event => {
    event.preventDefault();
    const input = event.currentTarget.elements['driver-address'];
    const error = doc.querySelector('[data-driver-address-error]');
    const address = String(input.value || '').trim();
    if (!address) {
      if (error) error.textContent = 'Enter your current address.';
      input.setAttribute('aria-invalid', 'true');
      input.focus();
      return;
    }
    input.removeAttribute('aria-invalid');
    if (error) error.textContent = '';
    const next = DriverState.persist(DriverState.setLocation(DriverState.load(), { method: 'manual', address }));
    ui.closeDialog('driver-address-dialog');
    ui.showToast('Current address saved.');
    renderAll(next);
  });

  doc.querySelector('[data-accept-offer]')?.addEventListener('click', () => {
    const states = currentStates();
    const offer = states.driver.currentOffer;
    if (!offer) return;
    try {
      const result = DriverState.acceptOffer(states.driver, states.customer, states.restaurant, offer.orderId, Date.now());
      DriverState.persist(result.state);
      CustomerState.persist(result.customerState);
      ui.announce(`Delivery ${offer.orderId} accepted.`);
      root.location.href = 'driver_delivery.php';
    } catch (error) {
      ui.showToast(error.message || 'Unable to accept this offer.', 'error');
      renderAll();
    }
  });

  doc.querySelector('[data-decline-offer]')?.addEventListener('click', () => {
    const state = DriverState.load();
    if (!state.currentOffer) return;
    try {
      const next = DriverState.persist(DriverState.declineOffer(state, state.currentOffer.orderId, Date.now()));
      ui.showToast('Offer declined. Savora will find another driver.');
      ui.announce('Delivery declined and returned to driver search.');
      renderAll(next);
    } catch (error) {
      ui.showToast(error.message || 'Unable to decline this offer.', 'error');
    }
  });

  root.addEventListener('storage', () => renderAll());
  renderAll();
}(typeof window === 'undefined' ? null : window));
