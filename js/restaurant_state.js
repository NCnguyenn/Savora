(function attachRestaurantState(root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraRestaurantState = api;
}(typeof window === 'undefined' ? null : window, function createRestaurantState() {
  'use strict';

  const KEY = 'savora_restaurant_state_v1';
  const ORDER_TRANSITIONS = {
    pending: ['confirmed', 'cancelled'],
    confirmed: ['preparing', 'cancelled'],
    preparing: ['ready_for_pickup', 'cancelled'],
    ready_for_pickup: ['on_the_way', 'completed'],
    on_the_way: ['completed'],
    completed: [],
    cancelled: []
  };
  const PROFILE_KEYS = ['id', 'name', 'address', 'description', 'cuisine', 'phone', 'image', 'addressLine1', 'addressLine2', 'city', 'state', 'postalCode', 'country', 'locationMethod'];
  const OPERATION_KEYS = ['acceptingOrders', 'prepMinutes', 'deliveryRadius', 'minimumOrder', 'capacity', 'deliveryEnabled', 'pickupEnabled', 'pickupInstructions'];
  const WEEK_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
  const text = value => typeof value === 'string' ? value.slice(0, 500) : '';
  const replyText = value => text(value).slice(0, 300);
  const nonNegative = value => Number.isFinite(Number(value)) ? Math.max(0, Number(value)) : 0;
  const finiteNumber = value => value !== null && value !== '' && Number.isFinite(Number(value)) ? Number(value) : null;
  const bounded = (value, minimum, maximum, fallback) => {
    const number = finiteNumber(value);
    return number === null ? fallback : Math.min(maximum, Math.max(minimum, number));
  };
  const coordinatePair = (latitude, longitude) => {
    const lat = finiteNumber(latitude); const lng = finiteNumber(longitude);
    return lat !== null && lng !== null && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180 ? { latitude: lat, longitude: lng } : null;
  };
  const clock = value => /^([01]\d|2[0-3]):[0-5]\d$/.test(text(value)) ? text(value) : '';
  const defaultDayHours = () => ({ open: '09:00', close: '17:00', closed: false });
  const normalizeWeeklyHours = value => {
    const source = value && typeof value === 'object' && !Array.isArray(value) ? value : {};
    return Object.fromEntries(WEEK_DAYS.map(day => {
      const entry = source[day] && typeof source[day] === 'object' ? source[day] : {};
      if (entry.closed === true) return [day, { open: '', close: '', closed: true }];
      const fallback = defaultDayHours();
      const open = clock(entry.open) || fallback.open;
      const close = clock(entry.close) || fallback.close;
      return [day, { open, close, closed: false }];
    }));
  };
  const normalizeSpecialHours = value => Array.isArray(value) ? value.map(entry => {
    const source = entry && typeof entry === 'object' && !Array.isArray(entry) ? entry : {};
    const date = /^\d{4}-\d{2}-\d{2}$/.test(text(source.date)) ? text(source.date) : '';
    if (!date) return null;
    const closed = source.closed === true;
    return closed
      ? { date, closed: true, note: text(source.note) }
      : { date, open: clock(source.open) || '09:00', close: clock(source.close) || '17:00', closed: false, note: text(source.note) };
  }).filter(Boolean).slice(0, 24) : [];
  const copy = value => JSON.parse(JSON.stringify(value));
  const localCatalogImage = value => {
    const image = text(value);
    return /^assets\/images\/catalog\/[a-z0-9][a-z0-9-]*\.(?:jpg|jpeg|png|webp)$/.test(image) ? image : '';
  };
  const allowedStatus = value => Object.hasOwn(ORDER_TRANSITIONS, value) ? value : '';
  const normalizeHistory = history => Array.isArray(history) ? history.map(entry => {
    const status = allowedStatus(entry && entry.status);
    return status ? { status, createdAt: text(entry && entry.createdAt), actor: text(entry && entry.actor) } : null;
  }).filter(Boolean) : [];
  const normalizeOptions = options => Array.isArray(options) ? options.map(option => {
    const source = option && typeof option === 'object' && !Array.isArray(option) ? option : {};
    const label = text(source.label);
    return label ? { label, price: nonNegative(source.price) } : null;
  }).filter(Boolean).slice(0, 20) : [];
  const normalizeOptionGroups = groups => Array.isArray(groups) ? groups.map(group => {
    const source = group && typeof group === 'object' && !Array.isArray(group) ? group : {};
    const name = text(source.name);
    return name ? { name, required: source.required === true, options: normalizeOptions(source.options) } : null;
  }).filter(Boolean).slice(0, 8) : [];
  const normalizeDietaryTags = tags => Array.isArray(tags) ? [...new Set(tags.map(text).filter(Boolean))].slice(0, 12) : [];
  const normalizeMenuItem = (item, owner = {}) => {
    const source = item && typeof item === 'object' && !Array.isArray(item) ? item : {};
    return {
      id: text(source.id),
      restaurantId: text(owner.id) || 'savora-kitchen',
      restaurantName: text(owner.name) || 'Savora Kitchen',
      name: text(source.name),
      description: text(source.description),
      category: text(source.category),
      image: localCatalogImage(source.image),
      price: nonNegative(source.price),
      compareAtPrice: nonNegative(source.compareAtPrice),
      taxCategory: text(source.taxCategory),
      optionGroups: normalizeOptionGroups(source.optionGroups),
      addOns: normalizeOptions(source.addOns),
      available: source.available !== false,
      stockTracking: source.stockTracking === true,
      stock: nonNegative(source.stock),
      prepTime: nonNegative(source.prepTime),
      dietaryTags: normalizeDietaryTags(source.dietaryTags),
      status: source.status === 'draft' ? 'draft' : 'published'
    };
  };
  const defaultState = () => ({
    version: 1,
    profile: { id: 'savora-kitchen', name: 'Savora Kitchen', address: '', description: '', cuisine: '', phone: '', image: '', addressLine1: '', addressLine2: '', city: '', state: '', postalCode: '', country: '', locationMethod: 'manual', latitude: null, longitude: null },
    operations: { acceptingOrders: true, prepMinutes: 20, deliveryRadius: 5, minimumOrder: 0, capacity: 20, deliveryEnabled: true, pickupEnabled: true, pickupInstructions: '', weeklyHours: normalizeWeeklyHours(), specialHours: [] },
    menuItems: [{ id: '1', restaurantId: 'savora-kitchen', restaurantName: 'Savora Kitchen', name: '', description: '', category: '', image: '', price: 0, compareAtPrice: 0, taxCategory: '', optionGroups: [], addOns: [], available: true, stockTracking: false, stock: 0, prepTime: 20, dietaryTags: [], status: 'published' }],
    reviews: []
  });

  function normalize(raw) {
    const state = defaultState();
    const source = raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : {};
    const profile = source.profile && typeof source.profile === 'object' ? source.profile : {};
    PROFILE_KEYS.forEach(key => { state.profile[key] = key === 'image' ? localCatalogImage(profile[key]) : text(profile[key]); });
    state.profile.id = state.profile.id || 'savora-kitchen';
    state.profile.name = state.profile.name || 'Savora Kitchen';
    const coordinates = coordinatePair(profile.latitude, profile.longitude);
    state.profile.locationMethod = profile.locationMethod === 'current' && coordinates ? 'current' : 'manual';
    state.profile.latitude = coordinates && state.profile.locationMethod === 'current' ? coordinates.latitude : null;
    state.profile.longitude = coordinates && state.profile.locationMethod === 'current' ? coordinates.longitude : null;
    const operations = source.operations && typeof source.operations === 'object' ? source.operations : {};
    state.operations.acceptingOrders = operations.acceptingOrders !== false;
    state.operations.prepMinutes = bounded(operations.prepMinutes, 1, 180, state.operations.prepMinutes);
    state.operations.deliveryRadius = bounded(operations.deliveryRadius, 0.1, 50, state.operations.deliveryRadius);
    state.operations.minimumOrder = Object.hasOwn(operations, 'minimumOrder') ? nonNegative(operations.minimumOrder) : state.operations.minimumOrder;
    state.operations.capacity = bounded(operations.capacity, 1, 500, state.operations.capacity);
    state.operations.deliveryEnabled = operations.deliveryEnabled !== false;
    state.operations.pickupEnabled = operations.pickupEnabled !== false;
    state.operations.pickupInstructions = text(operations.pickupInstructions);
    state.operations.weeklyHours = normalizeWeeklyHours(operations.weeklyHours);
    state.operations.specialHours = normalizeSpecialHours(operations.specialHours);
    state.menuItems = Array.isArray(source.menuItems)
      ? source.menuItems.map(item => normalizeMenuItem(item, state.profile)).filter(item => item.id)
      : state.menuItems;
    if (!state.menuItems.length) state.menuItems = defaultState().menuItems;
    state.reviews = Array.isArray(source.reviews) ? source.reviews.map(review => {
      const id = text(review && review.id);
      return id ? { id, reply: text(review && review.reply), repliedAt: text(review && review.repliedAt) } : null;
    }).filter(Boolean) : [];
    return state;
  }
  function load() {
    if (typeof localStorage === 'undefined') return defaultState();
    try { return normalize(JSON.parse(localStorage.getItem(KEY) || 'null')); } catch (_) { return defaultState(); }
  }
  function persist(state) {
    const next = normalize(state);
    if (typeof localStorage !== 'undefined') localStorage.setItem(KEY, JSON.stringify(next));
    return next;
  }
  function updateOrderStatus(customerState, orderId, nextStatus, metadata) {
    const next = copy(customerState && typeof customerState === 'object' ? customerState : {});
    if (!Array.isArray(next.orders)) next.orders = [];
    const order = next.orders.find(item => text(item && item.id) === text(orderId));
    if (!order) throw new Error('Order not found');
    const currentStatus = allowedStatus(order.status) || 'pending';
    if (!ORDER_TRANSITIONS[currentStatus].includes(nextStatus)) throw new Error('Invalid status transition');
    const source = metadata && typeof metadata === 'object' && !Array.isArray(metadata) ? metadata : {};
    order.status = nextStatus;
    if (Object.hasOwn(source, 'prepMinutes')) order.prepMinutes = nonNegative(source.prepMinutes);
    if (Object.hasOwn(source, 'cancelReason')) order.cancelReason = text(source.cancelReason);
    const history = normalizeHistory(order.statusHistory);
    history.push({ status: nextStatus, createdAt: new Date().toISOString(), actor: 'restaurant' });
    order.statusHistory = history;
    return next;
  }
  function setMenuItem(state, item) {
    const next = normalize(state);
    const source = item && typeof item === 'object' && !Array.isArray(item) ? item : {};
    const id = text(source.id);
    if (!id) throw new Error('Menu item id is required');
    const index = next.menuItems.findIndex(entry => entry.id === id);
    const current = index >= 0 ? next.menuItems[index] : {};
    const patch = normalizeMenuItem({ ...current, ...source, id }, next.profile);
    if (index >= 0) next.menuItems[index] = patch;
    else next.menuItems.push(patch);
    return normalize(next);
  }
  function setItemAvailability(state, id, available) {
    const next = normalize(state);
    const itemId = text(id);
    const item = next.menuItems.find(entry => entry.id === itemId);
    if (item) item.available = available !== false;
    else next.menuItems.push(normalizeMenuItem({ id: itemId, available }, next.profile));
    return normalize(next);
  }
  function setProfile(state, patch) {
    const next = normalize(state);
    const source = patch && typeof patch === 'object' && !Array.isArray(patch) ? patch : {};
    PROFILE_KEYS.forEach(key => {
      if (Object.hasOwn(source, key)) next.profile[key] = key === 'image' ? localCatalogImage(source[key]) : text(source[key]);
    });
    next.profile.id = next.profile.id || 'savora-kitchen';
    next.profile.name = next.profile.name || 'Savora Kitchen';
    const locationChanged = Object.hasOwn(source, 'locationMethod') || Object.hasOwn(source, 'latitude') || Object.hasOwn(source, 'longitude');
    if (locationChanged) {
      const hasLatitude = Object.hasOwn(source, 'latitude'); const hasLongitude = Object.hasOwn(source, 'longitude');
      const coordinates = source.locationMethod === 'current' && !hasLatitude && !hasLongitude && next.profile.locationMethod === 'current'
        ? coordinatePair(next.profile.latitude, next.profile.longitude)
        : source.locationMethod === 'current' ? coordinatePair(source.latitude, source.longitude) : null;
      next.profile.locationMethod = coordinates ? 'current' : 'manual';
      next.profile.latitude = coordinates ? coordinates.latitude : null;
      next.profile.longitude = coordinates ? coordinates.longitude : null;
    }
    next.menuItems = next.menuItems.map(item => normalizeMenuItem(item, next.profile));
    return next;
  }
  function setOperations(state, patch) {
    const next = normalize(state);
    const source = patch && typeof patch === 'object' && !Array.isArray(patch) ? patch : {};
    if (Object.hasOwn(source, 'acceptingOrders')) next.operations.acceptingOrders = source.acceptingOrders !== false;
    if (Object.hasOwn(source, 'prepMinutes')) next.operations.prepMinutes = bounded(source.prepMinutes, 1, 180, next.operations.prepMinutes);
    if (Object.hasOwn(source, 'deliveryRadius')) next.operations.deliveryRadius = bounded(source.deliveryRadius, 0.1, 50, next.operations.deliveryRadius);
    if (Object.hasOwn(source, 'minimumOrder')) next.operations.minimumOrder = nonNegative(source.minimumOrder);
    if (Object.hasOwn(source, 'capacity')) next.operations.capacity = bounded(source.capacity, 1, 500, next.operations.capacity);
    ['deliveryEnabled', 'pickupEnabled'].forEach(key => {
      if (Object.hasOwn(source, key)) next.operations[key] = source[key] !== false;
    });
    if (Object.hasOwn(source, 'pickupInstructions')) next.operations.pickupInstructions = text(source.pickupInstructions);
    if (Object.hasOwn(source, 'weeklyHours')) next.operations.weeklyHours = normalizeWeeklyHours(source.weeklyHours);
    if (Object.hasOwn(source, 'specialHours')) next.operations.specialHours = normalizeSpecialHours(source.specialHours);
    return next;
  }
  function setReviewReply(state, reviewId, reply) {
    const next = normalize(state);
    const id = text(reviewId);
    if (!id) throw new Error('Review id is required');
    const index = next.reviews.findIndex(review => review.id === id);
    const review = { id, reply: replyText(reply), repliedAt: new Date().toISOString() };
    if (index >= 0) next.reviews[index] = review;
    else next.reviews.push(review);
    return next;
  }
  function ordersForMetrics(customerState) {
    return Array.isArray(customerState && customerState.orders) ? customerState.orders : [];
  }
  function deriveFinance(customerState) {
    const orders = ordersForMetrics(customerState);
    const completed = orders.filter(order => order && order.status === 'completed');
    const refunded = orders.filter(order => order && order.status === 'refunded');
    const grossSales = completed.reduce((sum, order) => sum + nonNegative(order.total), 0);
    const platformFees = completed.reduce((sum, order) => sum + (nonNegative(order.total) * 0.1), 0);
    const refundTotal = refunded.reduce((sum, order) => sum - nonNegative(order.total), 0);
    const transactions = [...completed.map(order => ({
      orderId: text(order.id), type: 'sale', amount: nonNegative(order.total), fee: nonNegative(order.total) * 0.1,
      net: nonNegative(order.total) * 0.9, createdAt: text(order.createdAt), status: 'paid'
    })), ...refunded.map(order => ({
      orderId: text(order.id), type: 'refund', amount: -nonNegative(order.total), fee: 0,
      net: -nonNegative(order.total), createdAt: text(order.createdAt), status: 'refunded'
    }))];
    const netRevenue = grossSales - platformFees + refundTotal;
    return {
      grossSales, platformFees, refundTotal, netRevenue, completedOrders: completed.length,
      refundedOrders: refunded.length, averageOrderValue: completed.length ? grossSales / completed.length : 0,
      orders: [...completed, ...refunded], transactions
    };
  }
  function deriveAnalytics(customerState) {
    const orders = ordersForMetrics(customerState);
    const statusCounts = Object.fromEntries([...Object.keys(ORDER_TRANSITIONS), 'refunded'].map(status => [status, 0]));
    orders.forEach(order => { if (order && Object.hasOwn(statusCounts, order.status)) statusCounts[order.status] += 1; });
    const finance = deriveFinance(customerState);
    const sales = new Map();
    const menu = new Map();
    const orderingTimes = Object.fromEntries(Array.from({ length: 24 }, (_, hour) => [String(hour), 0]));
    const prepTimes = [];
    const customers = new Map();
    orders.forEach(order => {
      if (!order || typeof order !== 'object') return;
      const createdAt = text(order.createdAt);
      const date = /^\d{4}-\d{2}-\d{2}/.test(createdAt) ? createdAt.slice(0, 10) : '';
      const hour = new Date(createdAt).getUTCHours();
      if (Number.isInteger(hour)) orderingTimes[String(hour)] += 1;
      const prep = finiteNumber(order.prepMinutes);
      if (prep !== null) prepTimes.push(nonNegative(prep));
      const customer = text(order.customerName || order.customerEmail || order.customerId);
      if (customer) customers.set(customer, (customers.get(customer) || 0) + 1);
      if (order.status !== 'completed') return;
      const total = nonNegative(order.total);
      if (date) {
        const day = sales.get(date) || { key: date, orders: 0, revenue: 0 };
        day.orders += 1; day.revenue += total; sales.set(date, day);
      }
      const items = Array.isArray(order.items) ? order.items : Array.isArray(order.lines) ? order.lines : [];
      const quantityTotal = items.reduce((sum, item) => sum + Math.max(1, nonNegative(item && item.quantity) || 1), 0) || 1;
      items.forEach(item => {
        const name = text(item && (item.name || item.productName || item.title));
        if (!name) return;
        const quantity = Math.max(1, nonNegative(item && item.quantity) || 1);
        const current = menu.get(name) || { name, quantity: 0, revenue: 0 };
        current.quantity += quantity;
        current.revenue += total * (quantity / quantityTotal);
        menu.set(name, current);
      });
    });
    const salesByDay = [...sales.values()].sort((a, b) => a.key.localeCompare(b.key));
    const menuItems = [...menu.values()].sort((a, b) => b.revenue - a.revenue || a.name.localeCompare(b.name));
    return {
      totalOrders: orders.length, completedOrders: finance.completedOrders, grossSales: finance.grossSales,
      averageOrderValue: finance.averageOrderValue, statusCounts, salesByDay, menuItems, orderingTimes,
      repeatCustomers: [...customers.values()].filter(count => count > 1).length,
      kitchen: { averagePrepMinutes: prepTimes.length ? prepTimes.reduce((sum, value) => sum + value, 0) / prepTimes.length : 0 }
    };
  }
  return { KEY, ORDER_TRANSITIONS, defaultState, normalize, load, persist, updateOrderStatus, setMenuItem, setItemAvailability, setProfile, setOperations, setReviewReply, deriveFinance, deriveAnalytics };
}));
