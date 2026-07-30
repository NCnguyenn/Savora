(function attachDriverState(root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraDriverState = api;
}(typeof window === 'undefined' ? null : window, function createDriverState() {
  'use strict';

  const KEY = 'savora_driver_state_v1';
  const OFFER_DURATION_MS = 30000;
  const ACTIVE_DELIVERY_STATUSES = ['assigned', 'arrived', 'picked_up'];
  const DELIVERY_STATUSES = [...ACTIVE_DELIVERY_STATUSES, 'delivered', 'cancelled', 'failed'];
  const MILESTONE_TRANSITIONS = {
    assigned: ['arrived'],
    arrived: ['picked_up'],
    picked_up: ['delivered'],
    delivered: [],
    cancelled: [],
    failed: []
  };
  const PROFILE_KEYS = [
    'id', 'fullName', 'phone', 'email', 'vehicleType', 'vehicleModel',
    'licensePlate', 'vehicleColor', 'driverLicenseStatus',
    'registrationStatus', 'insuranceStatus'
  ];
  const PREFERENCE_KEYS = ['newOffers', 'soundAlerts', 'cashOnDelivery', 'avoidHighways'];
  const text = value => typeof value === 'string' ? value.slice(0, 500) : '';
  const finite = value => value !== null && value !== '' && Number.isFinite(Number(value)) ? Number(value) : null;
  const nonNegative = value => Math.max(0, finite(value) || 0);
  const bounded = (value, minimum, maximum, fallback) => {
    const number = finite(value);
    return number === null ? fallback : Math.min(maximum, Math.max(minimum, number));
  };
  const coordinatePair = (latitude, longitude) => {
    const lat = finite(latitude);
    const lng = finite(longitude);
    return lat !== null && lng !== null && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180
      ? { latitude: lat, longitude: lng }
      : null;
  };
  const timestamp = value => {
    const number = finite(value);
    return number === null ? 0 : Math.max(0, Math.floor(number));
  };
  const iso = value => {
    const raw = text(value);
    return raw && !Number.isNaN(new Date(raw).getTime()) ? raw : '';
  };
  const copy = value => JSON.parse(JSON.stringify(value));
  const defaultProfile = () => ({
    id: 'driver',
    fullName: 'Mike Smith',
    phone: '(555) 014-8820',
    email: 'driver@savora.local',
    vehicleType: 'Motorcycle',
    vehicleModel: 'Honda PCX 160',
    licensePlate: 'RDR-4821',
    vehicleColor: 'Forest green',
    driverLicenseStatus: 'Verified',
    registrationStatus: 'Verified',
    insuranceStatus: 'Expires Sep 18'
  });
  const defaultPreferences = () => ({
    newOffers: true,
    soundAlerts: true,
    cashOnDelivery: true,
    avoidHighways: false
  });
  const defaultState = () => ({
    version: 1,
    online: false,
    profile: defaultProfile(),
    location: {
      method: 'manual',
      address: '21 Oak Avenue, Downtown',
      latitude: null,
      longitude: null
    },
    serviceRadiusKm: 8,
    preferences: defaultPreferences(),
    currentOffer: null,
    declinedOrderIds: [],
    offerAttempts: [],
    deliveries: []
  });

  const normalizeItems = items => Array.isArray(items) ? items.map(item => {
    const source = item && typeof item === 'object' && !Array.isArray(item) ? item : {};
    const name = text(source.name);
    return name ? {
      id: text(source.id),
      name,
      quantity: Math.max(1, Math.floor(nonNegative(source.quantity) || 1)),
      unitPrice: nonNegative(source.unitPrice)
    } : null;
  }).filter(Boolean).slice(0, 50) : [];

  const normalizeMilestones = milestones => Array.isArray(milestones) ? milestones.map(entry => {
    const status = DELIVERY_STATUSES.includes(entry && entry.status) ? entry.status : '';
    return status ? { status, createdAt: iso(entry && entry.createdAt) } : null;
  }).filter(Boolean).slice(0, 20) : [];

  function normalizeOffer(raw) {
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return null;
    const orderId = text(raw.orderId);
    if (!orderId) return null;
    return {
      orderId,
      restaurantId: text(raw.restaurantId),
      restaurantName: text(raw.restaurantName) || 'Savora restaurant',
      restaurantPhone: text(raw.restaurantPhone),
      pickupAddress: text(raw.pickupAddress) || 'Restaurant pickup address unavailable',
      customerName: text(raw.customerName) || 'Savora customer',
      customerEmail: text(raw.customerEmail),
      customerPhone: text(raw.customerPhone),
      dropoffAddress: text(raw.dropoffAddress) || 'Delivery address unavailable',
      deliveryNote: text(raw.deliveryNote).slice(0, 120),
      paymentMethod: raw.paymentMethod === 'wallet' ? 'wallet' : 'cash',
      orderTotal: nonNegative(raw.orderTotal),
      earnings: nonNegative(raw.earnings),
      distanceToPickupKm: bounded(raw.distanceToPickupKm, 0, 100, 1.4),
      distanceKm: bounded(raw.distanceKm, 0, 250, 4.8),
      items: normalizeItems(raw.items),
      createdAt: timestamp(raw.createdAt),
      expiresAt: timestamp(raw.expiresAt)
    };
  }

  function normalizeDelivery(raw) {
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return null;
    const orderId = text(raw.orderId);
    const status = DELIVERY_STATUSES.includes(raw.status) ? raw.status : '';
    if (!orderId || !status) return null;
    return {
      orderId,
      status,
      restaurantId: text(raw.restaurantId),
      restaurantName: text(raw.restaurantName) || 'Savora restaurant',
      restaurantPhone: text(raw.restaurantPhone),
      pickupAddress: text(raw.pickupAddress) || 'Restaurant pickup address unavailable',
      customerName: text(raw.customerName) || 'Savora customer',
      customerEmail: text(raw.customerEmail),
      customerPhone: text(raw.customerPhone),
      dropoffAddress: text(raw.dropoffAddress) || 'Delivery address unavailable',
      deliveryNote: text(raw.deliveryNote).slice(0, 120),
      paymentMethod: raw.paymentMethod === 'wallet' ? 'wallet' : 'cash',
      orderTotal: nonNegative(raw.orderTotal),
      earnings: nonNegative(raw.earnings),
      bonus: nonNegative(raw.bonus),
      distanceToPickupKm: bounded(raw.distanceToPickupKm, 0, 100, 1.4),
      distanceKm: bounded(raw.distanceKm, 0, 250, 4.8),
      items: normalizeItems(raw.items),
      driverId: text(raw.driverId),
      driverName: text(raw.driverName),
      driverPhone: text(raw.driverPhone),
      vehicle: text(raw.vehicle),
      acceptedAt: iso(raw.acceptedAt),
      deliveredAt: iso(raw.deliveredAt),
      cancelledAt: iso(raw.cancelledAt),
      failureReason: text(raw.failureReason).slice(0, 200),
      milestones: normalizeMilestones(raw.milestones)
    };
  }

  function normalize(raw) {
    const state = defaultState();
    const source = raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : {};
    const profile = source.profile && typeof source.profile === 'object' && !Array.isArray(source.profile) ? source.profile : {};
    PROFILE_KEYS.forEach(key => {
      if (Object.hasOwn(profile, key)) state.profile[key] = text(profile[key]);
    });
    state.profile.id = state.profile.id || 'driver';
    state.profile.fullName = state.profile.fullName || 'Savora Driver';
    state.online = source.online === true;
    const location = source.location && typeof source.location === 'object' && !Array.isArray(source.location) ? source.location : {};
    const coordinates = coordinatePair(location.latitude, location.longitude);
    state.location = {
      method: location.method === 'gps' && coordinates ? 'gps' : 'manual',
      address: text(location.address) || state.location.address,
      latitude: coordinates ? coordinates.latitude : null,
      longitude: coordinates ? coordinates.longitude : null
    };
    state.serviceRadiusKm = bounded(source.serviceRadiusKm, 0.1, 50, state.serviceRadiusKm);
    const preferences = source.preferences && typeof source.preferences === 'object' && !Array.isArray(source.preferences) ? source.preferences : {};
    PREFERENCE_KEYS.forEach(key => {
      if (Object.hasOwn(preferences, key)) state.preferences[key] = preferences[key] === true;
    });
    state.currentOffer = normalizeOffer(source.currentOffer);
    state.declinedOrderIds = [...new Set(Array.isArray(source.declinedOrderIds)
      ? source.declinedOrderIds.map(text).filter(Boolean).slice(0, 200)
      : [])];
    state.offerAttempts = Array.isArray(source.offerAttempts) ? source.offerAttempts.map(attempt => {
      const orderId = text(attempt && attempt.orderId);
      const outcome = ['accepted', 'declined', 'expired'].includes(attempt && attempt.outcome) ? attempt.outcome : '';
      return orderId && outcome ? {
        orderId,
        outcome,
        createdAt: iso(attempt && attempt.createdAt)
      } : null;
    }).filter(Boolean).slice(-300) : [];
    state.deliveries = Array.isArray(source.deliveries)
      ? source.deliveries.map(normalizeDelivery).filter(Boolean).slice(-300)
      : [];
    return state;
  }

  function load() {
    if (typeof localStorage === 'undefined') return defaultState();
    try {
      return normalize(JSON.parse(localStorage.getItem(KEY) || 'null'));
    } catch (_) {
      return defaultState();
    }
  }

  function persist(state) {
    const next = normalize(state);
    if (typeof localStorage !== 'undefined') localStorage.setItem(KEY, JSON.stringify(next));
    return next;
  }

  function setAvailability(state, online) {
    const next = normalize(state);
    next.online = online === true;
    if (!next.online) next.currentOffer = null;
    return next;
  }

  function setLocation(state, patch) {
    const next = normalize(state);
    const source = patch && typeof patch === 'object' && !Array.isArray(patch) ? patch : {};
    const coordinates = coordinatePair(source.latitude, source.longitude);
    const wantsGps = source.method === 'gps';
    next.location = {
      method: wantsGps && coordinates ? 'gps' : 'manual',
      address: Object.hasOwn(source, 'address') ? text(source.address).trim() : next.location.address,
      latitude: wantsGps && coordinates ? coordinates.latitude : null,
      longitude: wantsGps && coordinates ? coordinates.longitude : null
    };
    if (!next.location.address) throw new Error('Current location address is required');
    if (Object.hasOwn(source, 'serviceRadiusKm')) {
      next.serviceRadiusKm = bounded(source.serviceRadiusKm, 0.1, 50, next.serviceRadiusKm);
    }
    return next;
  }

  function setProfile(state, patch) {
    const next = normalize(state);
    const source = patch && typeof patch === 'object' && !Array.isArray(patch) ? patch : {};
    PROFILE_KEYS.forEach(key => {
      if (Object.hasOwn(source, key)) next.profile[key] = text(source[key]).trim();
    });
    next.profile.id = next.profile.id || 'driver';
    if (!next.profile.fullName) throw new Error('Full name is required');
    return next;
  }

  function setPreferences(state, patch) {
    const next = normalize(state);
    const source = patch && typeof patch === 'object' && !Array.isArray(patch) ? patch : {};
    PREFERENCE_KEYS.forEach(key => {
      if (Object.hasOwn(source, key)) next.preferences[key] = source[key] === true;
    });
    return next;
  }

  const restaurantAddress = profile => {
    const parts = [
      text(profile.addressLine1),
      text(profile.addressLine2),
      text(profile.city),
      text(profile.state),
      text(profile.postalCode)
    ].filter(Boolean);
    return text(profile.address) || parts.join(', ') || 'Restaurant pickup address unavailable';
  };

  function offerSnapshot(order, restaurantState, now) {
    const restaurantSource = restaurantState && typeof restaurantState === 'object' ? restaurantState : {};
    const profile = restaurantSource.profile && typeof restaurantSource.profile === 'object' ? restaurantSource.profile : {};
    return {
      orderId: text(order.id),
      restaurantId: text(order.restaurantId || profile.id),
      restaurantName: text(order.restaurantName || profile.name) || 'Savora restaurant',
      restaurantPhone: text(profile.phone),
      pickupAddress: restaurantAddress(profile),
      customerName: text(order.customerName) || 'Savora customer',
      customerEmail: text(order.customerEmail),
      customerPhone: text(order.customerPhone),
      dropoffAddress: text(order.address) || 'Delivery address unavailable',
      deliveryNote: text(order.deliveryNote).slice(0, 120),
      paymentMethod: order.paymentMethod === 'wallet' ? 'wallet' : 'cash',
      orderTotal: nonNegative(order.total),
      earnings: nonNegative(order.driverEarnings || order.deliveryFee),
      distanceToPickupKm: bounded(order.distanceToPickupKm, 0, 100, 1.4),
      distanceKm: bounded(order.distanceKm, 0, 250, 4.8),
      items: normalizeItems(order.items),
      createdAt: now,
      expiresAt: now + OFFER_DURATION_MS
    };
  }

  function activeDelivery(state) {
    return normalize(state).deliveries.find(delivery => ACTIVE_DELIVERY_STATUSES.includes(delivery.status)) || null;
  }

  function createOffer(state, customerState, restaurantState, now = Date.now()) {
    const next = normalize(state);
    if (!next.online || activeDelivery(next) || next.currentOffer || next.preferences.newOffers === false) return next;
    const orders = Array.isArray(customerState && customerState.orders) ? customerState.orders : [];
    const order = orders.find(item =>
      item && item.status === 'ready_for_pickup' &&
      !next.declinedOrderIds.includes(text(item.id)) &&
      !next.deliveries.some(delivery => delivery.orderId === text(item.id))
    );
    if (!order) return next;
    next.currentOffer = normalizeOffer(offerSnapshot(order, restaurantState, timestamp(now)));
    return next;
  }

  function recordAttempt(state, orderId, outcome, now) {
    state.offerAttempts.push({
      orderId: text(orderId),
      outcome,
      createdAt: new Date(now).toISOString()
    });
    state.offerAttempts = state.offerAttempts.slice(-300);
  }

  function expireOffer(state, now = Date.now()) {
    const next = normalize(state);
    if (!next.currentOffer || timestamp(now) < next.currentOffer.expiresAt) return next;
    recordAttempt(next, next.currentOffer.orderId, 'expired', timestamp(now));
    next.declinedOrderIds = [...new Set([...next.declinedOrderIds, next.currentOffer.orderId])];
    next.currentOffer = null;
    return normalize(next);
  }

  function declineOffer(state, orderId, now = Date.now()) {
    const next = normalize(state);
    if (!next.currentOffer || next.currentOffer.orderId !== text(orderId)) throw new Error('Delivery offer not found');
    recordAttempt(next, next.currentOffer.orderId, 'declined', timestamp(now));
    next.declinedOrderIds = [...new Set([...next.declinedOrderIds, next.currentOffer.orderId])];
    next.currentOffer = null;
    return normalize(next);
  }

  function acceptOffer(state, customerState, restaurantState, orderId, now = Date.now()) {
    const next = normalize(state);
    const current = next.currentOffer;
    const time = timestamp(now);
    if (!current || current.orderId !== text(orderId) || time >= current.expiresAt) throw new Error('Delivery offer is unavailable');
    if (activeDelivery(next)) throw new Error('Driver already has an active delivery');
    const customer = copy(customerState && typeof customerState === 'object' ? customerState : {});
    if (!Array.isArray(customer.orders)) customer.orders = [];
    const order = customer.orders.find(item => text(item && item.id) === current.orderId);
    if (!order || order.status !== 'ready_for_pickup') throw new Error('Order is no longer ready for pickup');
    const profile = next.profile;
    const vehicle = [profile.vehicleColor, profile.vehicleModel, profile.licensePlate].filter(Boolean).join(' · ');
    next.deliveries.push(normalizeDelivery({
      ...current,
      status: 'assigned',
      driverId: profile.id,
      driverName: profile.fullName,
      driverPhone: profile.phone,
      vehicle,
      acceptedAt: new Date(time).toISOString(),
      milestones: [{ status: 'assigned', createdAt: new Date(time).toISOString() }]
    }));
    recordAttempt(next, current.orderId, 'accepted', time);
    next.currentOffer = null;
    return { state: normalize(next), customerState: customer };
  }

  function deliveryForOrder(state, orderId) {
    const id = text(orderId);
    return normalize(state).deliveries.find(delivery => delivery.orderId === id) || null;
  }

  function updateMilestone(state, customerState, orderId, milestone, now = Date.now()) {
    const next = normalize(state);
    const customer = copy(customerState && typeof customerState === 'object' ? customerState : {});
    if (!Array.isArray(customer.orders)) customer.orders = [];
    const delivery = next.deliveries.find(item => item.orderId === text(orderId));
    if (!delivery || !Object.hasOwn(MILESTONE_TRANSITIONS, delivery.status) ||
      !MILESTONE_TRANSITIONS[delivery.status].includes(milestone)) {
      throw new Error('Invalid delivery milestone transition');
    }
    const order = customer.orders.find(item => text(item && item.id) === delivery.orderId);
    if (!order) throw new Error('Order not found');
    const time = timestamp(now);
    delivery.status = milestone;
    delivery.milestones.push({ status: milestone, createdAt: new Date(time).toISOString() });
    if (milestone === 'picked_up' || milestone === 'delivered') {
      order.status = milestone === 'picked_up' ? 'on_the_way' : 'completed';
      if (!Array.isArray(order.statusHistory)) order.statusHistory = [];
      order.statusHistory.push({
        status: order.status,
        createdAt: new Date(time).toISOString(),
        actor: 'driver'
      });
    }
    if (milestone === 'delivered') delivery.deliveredAt = new Date(time).toISOString();
    return { state: normalize(next), customerState: customer };
  }

  function deriveHistory(state) {
    return normalize(state).deliveries
      .filter(delivery => ['delivered', 'cancelled', 'failed'].includes(delivery.status))
      .sort((a, b) => text(b.deliveredAt || b.cancelledAt || b.acceptedAt)
        .localeCompare(text(a.deliveredAt || a.cancelledAt || a.acceptedAt)));
  }

  function deriveEarnings(state) {
    const completed = normalize(state).deliveries.filter(delivery => delivery.status === 'delivered');
    const total = completed.reduce((sum, delivery) => sum + delivery.earnings + delivery.bonus, 0);
    const codCollected = completed
      .filter(delivery => delivery.paymentMethod === 'cash')
      .reduce((sum, delivery) => sum + delivery.orderTotal, 0);
    const bonuses = completed.reduce((sum, delivery) => sum + delivery.bonus, 0);
    return {
      total,
      deliveries: completed.length,
      average: completed.length ? total / completed.length : 0,
      bonuses,
      codCollected,
      amountToSettle: codCollected,
      records: completed
    };
  }

  return {
    KEY,
    OFFER_DURATION_MS,
    ACTIVE_DELIVERY_STATUSES,
    MILESTONE_TRANSITIONS,
    defaultState,
    normalize,
    load,
    persist,
    setAvailability,
    setLocation,
    setProfile,
    setPreferences,
    createOffer,
    expireOffer,
    declineOffer,
    acceptOffer,
    updateMilestone,
    activeDelivery,
    deliveryForOrder,
    deriveHistory,
    deriveEarnings
  };
}));
