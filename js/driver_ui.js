(function attachDriverUI(root, factory) {
  const api = factory(root);
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraDriverUI = api;
}(typeof window === 'undefined' ? null : window, function createDriverUI(root) {
  'use strict';

  const doc = root && root.document;
  let lastDialogTrigger = null;

  function el(tag, attributes = {}, children = []) {
    if (!doc) return null;
    const node = doc.createElement(tag);
    Object.entries(attributes || {}).forEach(([key, value]) => {
      if (value === null || value === undefined || value === false) return;
      if (key === 'className') node.className = String(value);
      else if (key === 'text') node.textContent = String(value);
      else if (key === 'dataset' && value && typeof value === 'object') {
        Object.entries(value).forEach(([name, entry]) => { node.dataset[name] = String(entry); });
      } else if (key === 'checked' || key === 'disabled' || key === 'hidden' || key === 'selected') {
        node[key] = Boolean(value);
      } else if (key.startsWith('on') && typeof value === 'function') {
        node.addEventListener(key.slice(2).toLowerCase(), value);
      } else node.setAttribute(key, String(value));
    });
    const list = Array.isArray(children) ? children : [children];
    list.flat(Infinity).forEach(child => {
      if (child === null || child === undefined || child === false) return;
      node.append(child && child.nodeType ? child : doc.createTextNode(String(child)));
    });
    return node;
  }

  const icon = className => el('i', { className: `fa-solid ${className}`, 'aria-hidden': 'true' });
  const money = value => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' })
    .format(Number(value) || 0);
  const formatDate = (value, options) => {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Date unavailable';
    return new Intl.DateTimeFormat('en-US', options || { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(date);
  };
  const titleCase = value => String(value || '')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, letter => letter.toUpperCase());

  function announce(message) {
    if (!doc) return;
    const node = doc.getElementById('driver-live-status');
    if (!node) return;
    node.textContent = '';
    root.setTimeout(() => { node.textContent = String(message || ''); }, 20);
  }

  function showToast(message, tone = 'success') {
    if (!doc) return;
    const container = doc.getElementById('driver-toast-container');
    if (!container) return;
    const toast = el('div', { className: `driver-toast is-${tone}`, role: 'status' }, [
      icon(tone === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'),
      el('span', { text: message })
    ]);
    container.append(toast);
    root.setTimeout(() => toast.remove(), 3200);
  }

  function focusable(dialog) {
    return [...dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
      .filter(node => !node.hidden);
  }

  function openDialog(dialogOrId, trigger) {
    if (!doc) return;
    const dialog = typeof dialogOrId === 'string' ? doc.getElementById(dialogOrId) : dialogOrId;
    if (!dialog) return;
    lastDialogTrigger = trigger || doc.activeElement;
    dialog.hidden = false;
    doc.body.classList.add('driver-dialog-open');
    const targets = focusable(dialog);
    if (targets[0]) targets[0].focus();
  }

  function closeDialog(dialogOrId) {
    if (!doc) return;
    const dialog = typeof dialogOrId === 'string' ? doc.getElementById(dialogOrId) : dialogOrId;
    if (!dialog) return;
    dialog.hidden = true;
    if (!doc.querySelector('.driver-dialog:not([hidden]), .driver-mobile-panel:not([hidden])')) {
      doc.body.classList.remove('driver-dialog-open');
    }
    if (lastDialogTrigger && typeof lastDialogTrigger.focus === 'function') lastDialogTrigger.focus();
    lastDialogTrigger = null;
  }

  function syncTopbar() {
    if (!doc || !root.SavoraDriverState) return;
    const state = root.SavoraDriverState.load();
    doc.querySelectorAll('[data-driver-topbar-status]').forEach(node => {
      node.textContent = state.online ? 'Online' : 'Offline';
      node.closest('.driver-topbar-status')?.classList.toggle('is-online', state.online);
    });
  }

  function bindChrome() {
    if (!doc) return;
    const menuButton = doc.querySelector('.driver-mobile-menu');
    const mobilePanel = doc.getElementById('driver-mobile-navigation');
    if (menuButton && mobilePanel) {
      menuButton.addEventListener('click', () => {
        menuButton.setAttribute('aria-expanded', 'true');
        openDialog(mobilePanel, menuButton);
      });
    }
    doc.addEventListener('click', event => {
      const close = event.target.closest('[data-close-driver-dialog]');
      const support = event.target.closest('[data-driver-support]');
      if (close) {
        const dialog = close.dataset.closeDriverDialog
          ? doc.getElementById(close.dataset.closeDriverDialog)
          : close.closest('.driver-dialog, .driver-mobile-panel');
        closeDialog(dialog);
        if (dialog === mobilePanel && menuButton) menuButton.setAttribute('aria-expanded', 'false');
      }
      if (support) openDialog('driver-support-dialog', support);
    });
    doc.addEventListener('keydown', event => {
      const dialog = doc.querySelector('.driver-dialog:not([hidden]), .driver-mobile-panel:not([hidden])');
      if (!dialog) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        closeDialog(dialog);
        if (dialog === mobilePanel && menuButton) menuButton.setAttribute('aria-expanded', 'false');
      }
      if (event.key !== 'Tab') return;
      const targets = focusable(dialog);
      if (!targets.length) return;
      const first = targets[0];
      const last = targets[targets.length - 1];
      if (event.shiftKey && doc.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && doc.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
    root.addEventListener('storage', syncTopbar);
    syncTopbar();
  }

  if (doc) {
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', bindChrome, { once: true });
    else bindChrome();
  }

  return { el, icon, money, formatDate, titleCase, showToast, announce, openDialog, closeDialog, syncTopbar };
}));
