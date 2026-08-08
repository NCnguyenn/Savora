(function attachCustomerTracking(root, factory) {
  const tracking = factory(root);
  if (typeof module === 'object' && module.exports) module.exports = tracking;
  if (root) root.SavoraCustomerTracking = tracking;
}(typeof window === 'undefined' ? globalThis : window, function createCustomerTracking(root) {
  'use strict';

  const FINAL_STATUSES = new Set(['completed', 'cancelled', 'refunded']);
  const ACTIVE_STATUSES = new Set(['pending', 'confirmed', 'preparing', 'ready_for_pickup', 'assigned', 'picked_up', 'delivered']);
  const ROUTE_STATUSES = new Set(['assigned', 'picked_up', 'delivered']);
  const PROGRESS_STEPS = [
    ['pending', 'Order received'],
    ['confirmed', 'Restaurant confirmed'],
    ['preparing', 'Preparing your order'],
    ['ready_for_pickup', 'Finding a driver'],
    ['assigned', 'Driver assigned'],
    ['picked_up', 'On the way'],
    ['delivered', 'Ready for you']
  ];

  function displayState(order) {
    if (order?.status === 'pending' && order?.payment?.method === 'seapay' && order?.payment?.status !== 'paid') return 'waiting_payment';
    return ({ pending: 'waiting_restaurant', confirmed: 'preparing', preparing: 'preparing', ready_for_pickup: 'finding_driver', assigned: 'driver_assigned', picked_up: 'on_the_way', delivered: 'waiting_confirmation', completed: 'completed' })[order?.status] || 'unavailable';
  }

  function nextDelay(failures) {
    return Math.min(15000, 2000 * (2 ** Math.max(0, Number(failures) || 0)));
  }

  function selectActiveOrder(orders) {
    return (Array.isArray(orders) ? orders : []).find(order => order && ACTIVE_STATUSES.has(order.status)) || null;
  }

  function shouldPoll(visibilityState) {
    return visibilityState === 'visible';
  }

  function shouldLoadRoute(order) {
    return Boolean(order && ROUTE_STATUSES.has(order.status));
  }

  function receiptRequest(order) {
    const referenceCode = order?.referenceCode || order?.id;
    return {
      scope: `customer-confirm-received-${order?.id}`,
      body: { action: 'confirm_received', payload: { referenceCode, expectedVersion: Number(order?.version) } }
    };
  }

  function mount(options = {}) {
    const documentRef = options.document || root.document;
    const windowRef = options.window || root;
    const api = options.api || root.SavoraApi;
    const onOrderSnapshot = typeof options.onOrderSnapshot === 'function' ? options.onOrderSnapshot : () => {};
    if (!documentRef || !api) return { refresh() {}, stop() {} };

    const card = documentRef.querySelector('[data-customer-live-order]');
    if (!card) return { refresh() {}, stop() {} };
    const status = card.querySelector('[data-live-order-status]');
    const progress = card.querySelector('[data-live-order-progress]');
    const deliveryNote = card.querySelector('[data-live-order-note]');
    const driver = card.querySelector('[data-live-driver]');
    const mapElement = card.querySelector('[data-tracking-map]');
    const routeProgress = card.querySelector('[data-route-progress]');
    const routeUpdated = card.querySelector('[data-route-updated]');
    const receiptButton = card.querySelector('[data-confirm-received]');
    const feedback = card.querySelector('[data-live-order-feedback]');
    let currentOrder = null;
    let timer = 0;
    let stopped = false;
    let failures = 0;
    let map = null;
    let routeLayers = null;
    let generation = 0;
    let lifecycleGeneration = 0;
    let confirmationInFlight = false;

    const setText = (element, value) => { if (element) element.textContent = value; };
    const create = (name, text) => {
      const element = documentRef.createElement(name);
      if (text !== undefined) element.textContent = text;
      return element;
    };
    const stateLabel = state => ({
      waiting_payment: 'Waiting for payment confirmation',
      waiting_restaurant: 'Waiting for the restaurant to confirm',
      preparing: 'Your order is being prepared',
      finding_driver: 'Finding a driver for your order',
      driver_assigned: 'Your driver has been assigned',
      on_the_way: 'Your driver is on the way',
      waiting_confirmation: 'Your order has arrived — please confirm receipt',
      completed: 'Order completed',
      unavailable: 'Order status is unavailable'
    })[state] || 'Order status is unavailable';

    function renderProgress(order) {
      const current = Math.max(0, PROGRESS_STEPS.findIndex(([key]) => key === order.status));
      const items = PROGRESS_STEPS.map(([key, label], index) => {
        const item = create('li');
        item.className = index < current ? 'is-complete' : index === current ? 'is-current' : 'is-upcoming';
        const marker = create('span');
        marker.setAttribute('aria-hidden', 'true');
        item.append(marker, create('strong', label), create('small', index < current ? 'Complete' : index === current ? 'Current status' : 'Upcoming'));
        return item;
      });
      progress.replaceChildren(...items);
    }

    function renderOrder(order) {
      currentOrder = order;
      if (!order) {
        setText(status, 'No active orders. Your next order will appear here.');
        progress.replaceChildren();
        deliveryNote.hidden = true;
        driver.hidden = true;
        mapElement.hidden = true;
        receiptButton.hidden = true;
        return;
      }
      setText(status, stateLabel(displayState(order)));
      renderProgress(order);
      deliveryNote.hidden = !order.deliveryNote;
      setText(deliveryNote, order.deliveryNote ? `Delivery note: ${order.deliveryNote}` : '');
      const assignment = order.assignment || {};
      const dispatch = order.dispatch || {};
      const driverText = order.status === 'ready_for_pickup'
        ? (dispatch.status === 'offer_sent' ? 'A nearby driver is reviewing this delivery.' : 'Searching for a nearby driver.')
        : assignment.driverName || assignment.name
          ? `Driver assigned: ${assignment.driverName || assignment.name}${assignment.vehicle ? ` · ${assignment.vehicle}` : ''}`
          : order.status === 'assigned' ? 'A driver has been assigned to your order.' : '';
      const routeStage = ['assigned', 'picked_up', 'delivered'].includes(order.status);
      if (driverText) {
        driver.hidden = false;
        setText(routeUpdated, driverText);
      } else {
        driver.hidden = !routeStage;
      }
      const delivered = order.status === 'delivered';
      mapElement.hidden = !['picked_up', 'delivered'].includes(order.status);
      receiptButton.hidden = !delivered;
      receiptButton.disabled = delivered && confirmationInFlight;
      if (delivered) receiptButton.textContent = order.paymentMethod === 'cash' ? 'Received and paid' : 'I received my order';
    }

    function asLatLng(point) {
      const latitude = Number(point?.latitude);
      const longitude = Number(point?.longitude);
      return Number.isFinite(latitude) && Number.isFinite(longitude) ? [latitude, longitude] : null;
    }

    function showMapFallback() {
      mapElement.classList.add('is-map-fallback');
    }

    function ensureMap(route) {
      if (!mapElement || !root.L) {
        showMapFallback();
        return false;
      }
      if (!map) {
        map = root.L.map(mapElement, { zoomControl: true, attributionControl: true });
        if (root.navigator?.onLine !== false) {
          const tiles = root.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
          });
          tiles.on('tileerror', showMapFallback);
          tiles.addTo(map);
        } else {
          showMapFallback();
        }
      }
      return true;
    }

    function renderRoute(order, snapshot) {
      const route = snapshot?.route || snapshot;
      const start = asLatLng(route?.start);
      const current = asLatLng(route?.current);
      const end = asLatLng(route?.end);
      driver.hidden = false;
      setText(routeProgress, `${Math.round(Math.max(0, Math.min(1, Number(route?.progress) || 0)) * 100)}%`);
      setText(routeUpdated, route?.arrived || order.status === 'delivered' ? 'Driver has arrived at your delivery address.' : 'Live route updated just now.');
      if (!['picked_up', 'delivered'].includes(order.status) || !start || !current || !end || !ensureMap(route)) return;
      if (routeLayers) routeLayers.forEach(layer => map.removeLayer(layer));
      const points = [start, current, end];
      routeLayers = [
        root.L.polyline(points, { color: '#0b5d46', weight: 5, opacity: 0.9 }).addTo(map),
        root.L.circleMarker(start, { radius: 7, color: '#0b5d46', fillColor: '#ffffff', fillOpacity: 1 }).addTo(map),
        root.L.circleMarker(current, { radius: 8, color: '#c94b34', fillColor: '#c94b34', fillOpacity: 1 }).addTo(map),
        root.L.circleMarker(end, { radius: 7, color: '#0b5d46', fillColor: '#0b5d46', fillOpacity: 1 }).addTo(map)
      ];
      map.fitBounds(root.L.latLngBounds(points), { padding: [20, 20], maxZoom: 15 });
    }

    function renderFailure(error) {
      setText(feedback, error?.message || 'Live order updates are temporarily unavailable. Retrying soon.');
    }

    function schedule(delay) {
      if (stopped) return;
      windowRef.clearTimeout(timer);
      timer = windowRef.setTimeout(refresh, delay);
    }

    async function refresh() {
      if (stopped) return;
      if (!shouldPoll(documentRef.visibilityState)) {
        schedule(2000);
        return;
      }
      const requestGeneration = ++generation;
      const requestLifecycle = lifecycleGeneration;
      const canApply = () => !stopped && shouldPoll(documentRef.visibilityState) && requestLifecycle === lifecycleGeneration && requestGeneration === generation;
      try {
        const snapshot = await api.get('api/orders.php?pageSize=50');
        if (!canApply()) return;
        const active = selectActiveOrder(snapshot?.orders);
        renderOrder(active);
        if (shouldLoadRoute(active)) {
          try {
            const route = await api.get(`api/tracking.php?order=${encodeURIComponent(active.referenceCode || active.id)}`);
            if (!canApply()) return;
            renderRoute(active, route);
          } catch (error) {
            if (!canApply()) return;
            if (active.status !== 'assigned' || Number(error?.status) !== 404) throw error;
          }
        }
        if (!canApply()) return;
        failures = 0;
        setText(feedback, '');
      } catch (error) {
        if (!canApply()) return;
        failures += 1;
        renderFailure(error);
      }
      if (!canApply()) return;
      schedule(nextDelay(failures));
    }

    async function confirmReceived() {
      if (confirmationInFlight || !currentOrder || currentOrder.status !== 'delivered') return;
      const request = receiptRequest(currentOrder);
      const confirmationLifecycle = lifecycleGeneration;
      const canApplyConfirmation = () => !stopped && shouldPoll(documentRef.visibilityState) && confirmationLifecycle === lifecycleGeneration;
      confirmationInFlight = true;
      receiptButton.disabled = true;
      setText(feedback, 'Confirming receipt…');
      try {
        const result = await api.post('api/orders.php', request.body, api.intentKey(request.scope));
        api.clearIntentKey(request.scope);
        if (!canApplyConfirmation()) return;
        const completedSnapshot = {
          ...currentOrder,
          status: result?.status || 'completed',
          version: Number(result?.version || (Number(currentOrder.version) + 1)),
          payment: { ...(currentOrder.payment || {}), status: result?.paymentStatus || 'paid' }
        };
        currentOrder = completedSnapshot;
        onOrderSnapshot(completedSnapshot);
        renderOrder(null);
        setText(feedback, 'Receipt confirmed. Thank you.');
        await refresh();
      } catch (error) {
        if (!canApplyConfirmation()) return;
        setText(feedback, error?.message || 'Receipt could not be confirmed. Please try again.');
      } finally {
        confirmationInFlight = false;
        if (canApplyConfirmation()) receiptButton.disabled = false;
      }
    }

    receiptButton.addEventListener('click', confirmReceived);
    const onVisibility = () => {
      generation += 1;
      lifecycleGeneration += 1;
      windowRef.clearTimeout(timer);
      if (shouldPoll(documentRef.visibilityState)) refresh();
    };
    const stop = () => { stopped = true; generation += 1; lifecycleGeneration += 1; windowRef.clearTimeout(timer); };
    documentRef.addEventListener('visibilitychange', onVisibility);
    windowRef.addEventListener('pagehide', stop, { once: true });
    if (options.autoStart !== false) refresh();
    return { refresh, stop };
  }

  return { displayState, nextDelay, selectActiveOrder, shouldPoll, shouldLoadRoute, receiptRequest, mount };
}));
