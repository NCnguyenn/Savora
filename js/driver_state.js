(function attachDriverState(root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraDriverState = api;
}(typeof window === 'undefined' ? null : window, function createDriverState() {
  'use strict';

  const KEY = 'savora_driver_preferences_v3';
  const LEGACY_KEY = 'savora_driver_preferences_v2';
  const PREFERENCE_KEYS = ['newOffers', 'soundAlerts', 'cashOnDelivery', 'avoidHighways'];
  const text = value => typeof value === 'string' ? value.slice(0, 500) : '';
  const finite = value => value !== null && value !== '' && Number.isFinite(Number(value)) ? Number(value) : null;
  const bounded = (value, minimum, maximum, fallback) => {
    const number = finite(value);
    return number === null ? fallback : Math.min(maximum, Math.max(minimum, number));
  };
  const defaultPreferences = () => ({
    newOffers: true,
    soundAlerts: true,
    cashOnDelivery: true,
    avoidHighways: false
  });
  const defaultState = () => ({
    version: 3,
    preferences: defaultPreferences()
  });

  function normalize(raw) {
    const state = defaultState();
    const source = raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : {};
    const preferences = source.preferences && typeof source.preferences === 'object' ? source.preferences : {};
    PREFERENCE_KEYS.forEach(key => {
      state.preferences[key] = preferences[key] !== false;
    });
    return state;
  }

  function load() {
    if (typeof localStorage === 'undefined') return defaultState();
    let raw = null;
    try {
      raw = JSON.parse(localStorage.getItem(KEY) || 'null');
      if (!raw) raw = JSON.parse(localStorage.getItem(LEGACY_KEY) || 'null');
    } catch (_) {
      raw = null;
    }
    const next = normalize(raw);
    localStorage.setItem(KEY, JSON.stringify(next));
    localStorage.removeItem(LEGACY_KEY);
    return next;
  }

  function persist(state) {
    const next = normalize(state);
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem(KEY, JSON.stringify(next));
      localStorage.removeItem(LEGACY_KEY);
    }
    return next;
  }

  function setPreferences(state, patch) {
    const next = normalize(state);
    const source = patch && typeof patch === 'object' ? patch : {};
    PREFERENCE_KEYS.forEach(key => {
      if (Object.hasOwn(source, key)) next.preferences[key] = source[key] === true;
    });
    return next;
  }

  return { KEY, defaultState, normalize, load, persist, setPreferences };
}));
