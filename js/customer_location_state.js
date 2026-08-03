(function attachCustomerLocationState(root, factory) {
  'use strict';
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraCustomerLocationState = api;
}(typeof window === 'undefined' ? globalThis : window, function createCustomerLocationState() {
  const text = value => String(value == null ? '' : value).trim();

  function normalizeDeliveryDetails(value) {
    const result = text(value);
    if (result.length > 300) throw new Error('Delivery details must be 300 characters or fewer.');
    return result;
  }

  function coordinatesOf(value) {
    const latitude = Number(value && value.latitude);
    const longitude = Number(value && value.longitude);
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
      throw new Error('The location preview contains invalid coordinates.');
    }
    return { latitude, longitude };
  }

  function normalizePreview(value) {
    if (!value || typeof value !== 'object') throw new Error('A location preview is required.');
    const coordinates = coordinatesOf(value);
    const address = text(value.address || value.addressLine1);
    if (!address) throw new Error('A readable address is required.');
    return {
      address,
      addressLine1: text(value.addressLine1),
      addressLine2: text(value.addressLine2),
      city: text(value.city),
      state: text(value.state),
      postalCode: text(value.postalCode),
      country: text(value.country),
      ...coordinates
    };
  }

  function confirmGps(preview, deliveryDetails) {
    return { ...normalizePreview(preview), deliveryDetails: normalizeDeliveryDetails(deliveryDetails), method: 'gps', pendingSync: true };
  }

  function confirmManual(address, deliveryDetails) {
    const normalizedAddress = text(address);
    if (!normalizedAddress) throw new Error('Enter an address.');
    return {
      address: normalizedAddress,
      addressLine1: normalizedAddress,
      addressLine2: '',
      city: '',
      state: '',
      postalCode: '',
      country: '',
      latitude: null,
      longitude: null,
      deliveryDetails: normalizeDeliveryDetails(deliveryDetails),
      method: 'manual',
      pendingSync: true
    };
  }

  function parseGuestDraft(raw) {
    try {
      const value = typeof raw === 'string' ? JSON.parse(raw) : raw;
      if (!value || typeof value !== 'object' || !value.pendingSync || !['gps', 'manual'].includes(value.method)) return null;
      if (value.method === 'gps') return confirmGps(value, value.deliveryDetails);
      return confirmManual(value.address, value.deliveryDetails);
    } catch (_) { return null; }
  }

  function syncRequest(draft) {
    if (!draft || draft.pendingSync !== true) throw new Error('A confirmed location draft is required.');
    if (draft.method === 'gps') return { latitude: coordinatesOf(draft).latitude, longitude: coordinatesOf(draft).longitude, deliveryDetails: normalizeDeliveryDetails(draft.deliveryDetails) };
    if (draft.method === 'manual') return { address: text(draft.address), deliveryDetails: normalizeDeliveryDetails(draft.deliveryDetails) };
    throw new Error('Location method is invalid.');
  }

  return { normalizeDeliveryDetails, normalizePreview, confirmGps, confirmManual, parseGuestDraft, syncRequest };
}));
