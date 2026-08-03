(function attachCustomerCheckoutNote(root, factory) {
  'use strict';
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraCustomerCheckoutNote = api;
}(typeof window === 'undefined' ? globalThis : window, function createCustomerCheckoutNote() {
  const normalize = value => String(value == null ? '' : value).trim().slice(0, 300);
  function create() { return { value: '', dirty: false }; }
  function applyAddressDetails(state, deliveryDetails) {
    if (state && state.dirty) return state;
    return { value: normalize(deliveryDetails), dirty: false };
  }
  function edit(state, nextValue) { return { value: String(nextValue == null ? '' : nextValue).slice(0, 300), dirty: true }; }
  return { create, applyAddressDetails, edit };
}));
