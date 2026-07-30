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
  const PROFILE_KEYS = ['id', 'name', 'address', 'description', 'cuisine', 'phone', 'image'];
  const OPERATION_KEYS = ['acceptingOrders', 'prepMinutes', 'deliveryRadius', 'capacity'];
  const MENU_KEYS = ['id', 'restaurantId', 'restaurantName', 'name', 'description', 'category', 'image', 'price', 'available'];
  const text = value => typeof value === 'string' ? value.slice(0, 500) : '';
  const nonNegative = value => Number.isFinite(Number(value)) ? Math.max(0, Number(value)) : 0;
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
      available: source.available !== false
    };
  };
  const defaultState = () => ({
    version: 1,
    profile: { id: 'savora-kitchen', name: 'Savora Kitchen', address: '', description: '', cuisine: '', phone: '', image: '' },
    operations: { acceptingOrders: true, prepMinutes: 20, deliveryRadius: 0, capacity: 0 },
    menuItems: [{ id: '1', restaurantId: 'savora-kitchen', restaurantName: 'Savora Kitchen', name: '', description: '', category: '', image: '', price: 0, available: true }],
    reviews: []
  });

  function normalize(raw) {
    const state = defaultState();
    const source = raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : {};
    const profile = source.profile && typeof source.profile === 'object' ? source.profile : {};
    PROFILE_KEYS.forEach(key => { state.profile[key] = key === 'image' ? localCatalogImage(profile[key]) : text(profile[key]); });
    state.profile.id = state.profile.id || 'savora-kitchen';
    state.profile.name = state.profile.name || 'Savora Kitchen';
    const operations = source.operations && typeof source.operations === 'object' ? source.operations : {};
    state.operations.acceptingOrders = operations.acceptingOrders !== false;
    ['prepMinutes', 'deliveryRadius', 'capacity'].forEach(key => { state.operations[key] = nonNegative(operations[key]); });
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
    next.menuItems = next.menuItems.map(item => normalizeMenuItem(item, next.profile));
    return next;
  }
  function setOperations(state, patch) {
    const next = normalize(state);
    const source = patch && typeof patch === 'object' && !Array.isArray(patch) ? patch : {};
    if (Object.hasOwn(source, 'acceptingOrders')) next.operations.acceptingOrders = source.acceptingOrders !== false;
    ['prepMinutes', 'deliveryRadius', 'capacity'].forEach(key => {
      if (Object.hasOwn(source, key)) next.operations[key] = nonNegative(source[key]);
    });
    return next;
  }
  function setReviewReply(state, reviewId, reply) {
    const next = normalize(state);
    const id = text(reviewId);
    if (!id) throw new Error('Review id is required');
    const index = next.reviews.findIndex(review => review.id === id);
    const review = { id, reply: text(reply), repliedAt: new Date().toISOString() };
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
    const grossSales = completed.reduce((sum, order) => sum + nonNegative(order.total), 0);
    return { grossSales, completedOrders: completed.length, averageOrderValue: completed.length ? grossSales / completed.length : 0, orders: completed };
  }
  function deriveAnalytics(customerState) {
    const orders = ordersForMetrics(customerState);
    const statusCounts = Object.fromEntries(Object.keys(ORDER_TRANSITIONS).map(status => [status, 0]));
    orders.forEach(order => { if (order && Object.hasOwn(statusCounts, order.status)) statusCounts[order.status] += 1; });
    const finance = deriveFinance(customerState);
    return { totalOrders: orders.length, completedOrders: finance.completedOrders, grossSales: finance.grossSales, statusCounts };
  }
  return { KEY, ORDER_TRANSITIONS, defaultState, normalize, load, persist, updateOrderStatus, setMenuItem, setItemAvailability, setProfile, setOperations, setReviewReply, deriveFinance, deriveAnalytics };
}));
