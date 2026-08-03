(function attachRestaurantState(root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraRestaurantState = api;
}(typeof window === 'undefined' ? null : window, function createRestaurantState() {
  'use strict';

  const KEY = 'savora_restaurant_preferences_v2';
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
  const localCatalogImage = value => {
    const image = text(value);
    return /^assets\/images\/catalog\/[a-z0-9][a-z0-9-]*\.(?:jpg|jpeg|png|webp)$/.test(image) ? image : '';
  };
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
  const defaultState = () => ({ version: 2, preferences: { menuView: 'grid' }, menuDrafts: {} });

  function normalize(raw) {
    const state = defaultState();
    const source = raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : {};
    const preferences = source.preferences && typeof source.preferences === 'object' ? source.preferences : {};
    state.preferences.menuView = preferences.menuView === 'list' ? 'list' : 'grid';
    const drafts = source.menuDrafts && typeof source.menuDrafts === 'object' ? source.menuDrafts : {};
    Object.entries(drafts).slice(0, 10).forEach(([id, draft]) => {
      const safeId = text(id);
      if (!safeId || !draft || typeof draft !== 'object') return;
      state.menuDrafts[safeId] = {
        id: safeId, name: text(draft.name), description: text(draft.description), category: text(draft.category), price: nonNegative(draft.price),
        available: draft.available !== false, optionGroups: normalizeOptionGroups(draft.optionGroups), addOns: normalizeOptions(draft.addOns)
      };
    });
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
  function saveMenuDraft(state, id, draft) {
    const next = normalize(state); const draftId = text(id);
    if (!draftId) throw new Error('Menu draft id is required');
    next.menuDrafts[draftId] = normalize({ menuDrafts: { [draftId]: { ...draft, id: draftId } } }).menuDrafts[draftId];
    return normalize(next);
  }
  function clearMenuDraft(state, id) { const next = normalize(state); delete next.menuDrafts[text(id)]; return next; }
  return { KEY, defaultState, normalize, load, persist, saveMenuDraft, clearMenuDraft };
}));
