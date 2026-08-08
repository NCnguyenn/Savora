(function attachState(root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraState = api;
}(typeof window === 'undefined' ? null : window, function createState() {
  'use strict';

  const KEY = 'savora_customer_cart_v3';
  const LEGACY_RESTAURANT_ID = 'savora-kitchen';
  const LEGACY_RESTAURANT_NAME = 'Savora Kitchen';
  const text = value => typeof value === 'string' ? value.slice(0, 500) : '';
  const number = value => Number.isFinite(Number(value)) ? Number(value) : 0;
  const restaurantIdentity = (source, fallback = {}) => {
    const id = text(source && source.restaurantId).trim() || text(fallback.id).trim() || LEGACY_RESTAURANT_ID;
    const name = text(source && (source.restaurantName || source.restaurant)).trim()
      || text(fallback.name).trim()
      || (id === LEGACY_RESTAURANT_ID ? LEGACY_RESTAURANT_NAME : id);
    return { id, name };
  };

  const defaultState = () => ({ version: 4, cart: [] });
  const normalizeOption = (option, migrateLegacyPortionId = false) => {
    let id = text(option && option.id);
    if (migrateLegacyPortionId && id.startsWith('portion-')) id = id.slice('portion-'.length);
    return {
      id,
      label: text(option && option.label),
      price: Math.max(0, number(option && option.price))
    };
  };
  const normalizeCartLine = (line, index = 0, migrateLegacyPortionId = false) => {
    const id = text(line && line.id);
    const restaurant = restaurantIdentity(line);
    return {
      lineId: text(line && line.lineId) || (id ? `legacy-${id}-${index}` : ''),
      id,
      restaurantId: restaurant.id,
      restaurantName: restaurant.name,
      name: text(line && line.name),
      image: text(line && line.image),
      unitPrice: Math.max(0, number(line && line.unitPrice)),
      quantity: Math.max(1, Math.min(20, Math.floor(number(line && line.quantity)))),
      options: Array.isArray(line && line.options) ? line.options.map(option => normalizeOption(option, migrateLegacyPortionId)).filter(option => option.id) : [],
      note: text(line && line.note).trim().slice(0, 120)
    };
  };
  const normalizeOwnedLines = (rawLines, migrateLegacyPortionId = false) => {
    const lines = Array.isArray(rawLines) ? rawLines.map((line, index) => normalizeCartLine(line, index, migrateLegacyPortionId)).filter(line => line.lineId && line.id) : [];
    const owner = restaurantIdentity(lines[0]);
    return lines.filter(line => line.restaurantId === owner.id).map(line => ({ ...line, restaurantId: owner.id, restaurantName: owner.name }));
  };

  function normalize(raw) {
    const source = raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : {};
    return { version: 4, cart: normalizeOwnedLines(source.cart, number(source.version) < 4) };
  }

  function load() {
    if (typeof localStorage === 'undefined') return defaultState();
    try { return normalize(JSON.parse(localStorage.getItem(KEY) || 'null')); }
    catch (_) { return defaultState(); }
  }

  function persist(state) {
    const next = normalize(state);
    if (typeof localStorage !== 'undefined') localStorage.setItem(KEY, JSON.stringify(next));
    return next;
  }

  function addCartLine(state, product, quantity, options = [], note = '') {
    const next = normalize(state);
    const qty = Math.max(1, Math.min(20, Math.floor(number(quantity))));
    const productId = product && product.id != null ? text(String(product.id)) : '';
    if (!productId) throw new Error('Product id is required');
    const restaurant = restaurantIdentity(product);
    const cartRestaurantId = next.cart[0] ? next.cart[0].restaurantId : '';
    if (restaurant.id && cartRestaurantId && restaurant.id !== cartRestaurantId) throw new Error('A cart can contain items from one restaurant only');
    const normalizedOptions = Array.isArray(options) ? options.map(option => normalizeOption(option)).filter(option => option.id) : [];
    const unitPrice = Math.max(0, number(product && product.price)) + normalizedOptions.reduce((sum, option) => sum + option.price, 0);
    const normalizedNote = text(note).trim().slice(0, 120);
    const key = JSON.stringify([productId, normalizedOptions.map(option => option.id).sort(), normalizedNote]);
    const existing = next.cart.find(line => JSON.stringify([line.id, line.options.map(option => option.id).sort(), line.note]) === key);
    if (existing) existing.quantity = Math.min(20, existing.quantity + qty);
    else next.cart.push({
      lineId: `${productId}-${Date.now()}-${Math.random().toString(16).slice(2)}`,
      id: productId, restaurantId: restaurant.id, restaurantName: restaurant.name, name: text(product && product.name),
      image: text(product && (product.image || product.img)), unitPrice, quantity: qty, options: normalizedOptions, note: normalizedNote
    });
    return next;
  }

  function removeCartLine(state, lineId) {
    const next = normalize(state);
    next.cart = next.cart.filter(line => line.lineId !== text(lineId));
    return next;
  }

  function updateCartQuantity(state, lineId, delta) {
    const next = normalize(state);
    const line = next.cart.find(item => item.lineId === text(lineId));
    if (!line) return next;
    line.quantity += Math.trunc(number(delta));
    return line.quantity > 0 ? next : removeCartLine(next, lineId);
  }

  return { KEY, defaultState, normalize, load, persist, addCartLine, removeCartLine, updateCartQuantity };
}));
