(function attachRestaurantUI(root, factory) {
  const api = factory(root);
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraRestaurantUI = api;
}(typeof window === 'undefined' ? null : window, function createRestaurantUI(root) {
  'use strict';

  const documentRef = root && root.document;
  const returnFocus = new WeakMap();
  const get = id => documentRef && documentRef.getElementById(id);

  function el(tagName, attributes = {}, children = []) {
    if (!documentRef) return null;
    const node = documentRef.createElement(tagName);
    Object.entries(attributes).forEach(([name, value]) => {
      if (value == null || value === false) return;
      if (name === 'className') node.className = String(value);
      else if (name.startsWith('on') && typeof value === 'function') node.addEventListener(name.slice(2).toLowerCase(), value);
      else node.setAttribute(name, String(value));
    });
    [].concat(children).forEach(child => node.append(child && child.nodeType ? child : documentRef.createTextNode(String(child))));
    return node;
  }

  function formatMoney(value) {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(value) || 0);
  }

  function statusChip(status) {
    const labels = { pending: 'New', confirmed: 'Confirmed', preparing: 'Preparing', ready_for_pickup: 'Ready', on_the_way: 'On the way', completed: 'Completed', cancelled: 'Cancelled' };
    return el('span', { className: `restaurant-status-chip status-${String(status || 'pending').replace(/[^a-z_]/g, '')}` }, labels[status] || 'New');
  }

  function showToast(message) {
    const container = get('restaurant-toast-container');
    if (!container) return;
    const toast = el('div', { className: 'restaurant-toast', role: 'status' }, String(message || 'Updated locally.'));
    container.append(toast);
    if (root) root.setTimeout(() => toast.remove(), 3600);
  }

  function focusable(dialog) {
    return [...dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')].filter(node => !node.hidden);
  }

  function setDialogTrigger(dialog, expanded) {
    if (!dialog || !dialog.id || !documentRef) return;
    documentRef.querySelectorAll(`[aria-controls="${dialog.id}"]`).forEach(trigger => trigger.setAttribute('aria-expanded', String(expanded)));
  }

  function openDialog(dialogOrId, opener) {
    const dialog = typeof dialogOrId === 'string' ? get(dialogOrId) : dialogOrId;
    if (!dialog) return;
    returnFocus.set(dialog, opener || documentRef.activeElement);
    dialog.hidden = false;
    dialog.classList.add('is-open');
    setDialogTrigger(dialog, true);
    const first = focusable(dialog)[0];
    if (first && root) root.requestAnimationFrame(() => first.focus());
  }

  function closeDialog(dialogOrId) {
    const dialog = typeof dialogOrId === 'string' ? get(dialogOrId) : dialogOrId;
    if (!dialog || dialog.hidden) return;
    dialog.classList.remove('is-open');
    dialog.hidden = true;
    setDialogTrigger(dialog, false);
    const opener = returnFocus.get(dialog);
    if (opener && typeof opener.focus === 'function') opener.focus();
  }

  function restaurantState() { return root && root.SavoraRestaurantState; }
  function customerState() { return root && root.SavoraState; }

  function refreshShell() {
    const restaurant = restaurantState();
    const state = restaurant ? restaurant.load() : null;
    if (!state) return;
    documentRef.querySelectorAll('[data-restaurant-name]').forEach(node => { node.textContent = state.profile.name || 'Savora Kitchen'; });
    documentRef.querySelectorAll('[data-accepting-orders]').forEach(button => {
      const accepting = state.operations.acceptingOrders !== false;
      button.setAttribute('aria-pressed', String(accepting));
      button.classList.toggle('is-paused', !accepting);
      button.lastChild.textContent = '';
      button.childNodes[1].textContent = accepting ? 'Accepting orders' : 'Orders paused';
    });
  }

  function bindShell() {
    if (!documentRef) return;
    documentRef.addEventListener('click', event => {
      const closeControl = event.target.closest && event.target.closest('[data-close-dialog]');
      if (closeControl) { closeDialog(closeControl.dataset.closeDialog); return; }
      const target = event.target.closest ? event.target.closest('button, a') : null;
      if (!target) return;
      if (target.matches('.restaurant-mobile-menu-button')) { openDialog('restaurant-mobile-navigation', target); return; }
      if (target.matches('[data-accepting-orders]')) {
        const api = restaurantState();
        if (!api) return;
        const current = api.load();
        api.persist(api.setOperations(current, { acceptingOrders: current.operations.acceptingOrders === false }));
        refreshShell();
        showToast(current.operations.acceptingOrders === false ? 'Orders are now accepted.' : 'Orders are paused locally.');
        return;
      }
      if (target.matches('[data-owner-menu]')) {
        const popover = get('restaurant-owner-popover');
        if (popover) { popover.hidden = !popover.hidden; target.setAttribute('aria-expanded', String(!popover.hidden)); }
      }
    });
    documentRef.addEventListener('keydown', event => {
      const open = documentRef.querySelector('.restaurant-mobile-dialog.is-open');
      if (event.key === 'Escape' && open) { event.preventDefault(); closeDialog(open); }
      if (event.key === 'k' && (event.ctrlKey || event.metaKey)) { event.preventDefault(); const input = get('restaurant-search'); if (input) input.focus(); }
      if (event.key !== 'Tab' || !open) return;
      const items = focusable(open); if (!items.length) return;
      const first = items[0]; const last = items.at(-1);
      if (event.shiftKey && documentRef.activeElement === first) { event.preventDefault(); last.focus(); }
      if (!event.shiftKey && documentRef.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    documentRef.addEventListener('click', event => {
      if (event.target.closest && !event.target.closest('.restaurant-owner-menu')) {
        const popover = get('restaurant-owner-popover'); const button = documentRef.querySelector('[data-owner-menu]');
        if (popover) popover.hidden = true;
        if (button) button.setAttribute('aria-expanded', 'false');
      }
    });
  }

  if (root && documentRef) {
    const initialize = () => { bindShell(); refreshShell(); };
    if (documentRef.readyState === 'loading') documentRef.addEventListener('DOMContentLoaded', initialize, { once: true }); else initialize();
  }

  return { el, showToast, formatMoney, statusChip, refreshShell, openDialog, closeDialog };
}));
