(function attachSavoraUI(root, factory) {
  const api = factory(root);
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraUI = api;
}(typeof window === 'undefined' ? null : window, function createSavoraUI(root) {
  'use strict';

  const documentRef = root && root.document;
  const returnFocus = new WeakMap();
  let activeItem = null;

  function money(value) {
    return `$${Number(value || 0).toFixed(2)}`;
  }

  function el(tagName, attributes = {}, children = []) {
    const node = documentRef.createElement(tagName);
    Object.entries(attributes).forEach(([name, value]) => {
      if (value === false || value == null) return;
      if (name === 'className') node.className = value;
      else if (name.startsWith('on')) node.addEventListener(name.slice(2).toLowerCase(), value);
      else node.setAttribute(name, String(value));
    });
    [].concat(children).forEach(child => node.append(child && child.nodeType ? child : documentRef.createTextNode(String(child))));
    return node;
  }

  function stateApi() {
    return root && root.SavoraState;
  }

  function catalog() {
    return (root && root.SavoraCatalog) || { products: {}, restaurants: {} };
  }

  function load() {
    const api = stateApi();
    return api ? api.load() : { cart: [], wallet: { balance: 0 }, profile: {} };
  }

  function persist(state) {
    const api = stateApi();
    if (api) api.persist(state);
  }

  function productImage(product) {
    const api = catalog();
    return typeof api.imageFor === 'function' ? api.imageFor(product) : 'assets/images/food-placeholder.svg';
  }

  function legacyLine(line) {
    const catalogProduct = catalog().products[String(line.id)] || null;
    return {
      lineId: line.lineId,
      id: line.id,
      name: line.name,
      price: Number(line.unitPrice || 0),
      qty: Number(line.quantity || 0),
      img: productImage(catalogProduct)
    };
  }

  function syncLegacyState(state = load()) {
    if (!root) return state;
    root.cart = state.cart.map(legacyLine);
    root.walletBalance = Number(state.wallet && state.wallet.balance || 0);
    try { root.localStorage.setItem('savora_wallet', String(root.walletBalance)); } catch (_) { /* local demo fallback */ }
    return state;
  }

  function saveLegacyState() {
    const api = stateApi();
    if (!api || !root) return;
    let state = load();
    const source = Array.isArray(root.cart) ? root.cart : [];
    state.cart = source.map((item, index) => {
      const id = String(item.id || item.name || `legacy-${index}`);
      const catalogProduct = catalog().products[id] || null;
      return {
        lineId: String(item.lineId || `legacy-${Date.now()}-${index}`),
        id,
        name: String(item.name || ''),
        image: productImage(catalogProduct),
        unitPrice: Math.max(0, Number(item.price || item.unitPrice || 0)),
        quantity: Math.max(1, Math.trunc(Number(item.qty || item.quantity || 1))),
        options: [],
        note: ''
      };
    });
    state.wallet.balance = Math.max(0, Number(root.walletBalance || state.wallet.balance || 0));
    persist(state);
    syncLegacyState(state);
    refreshChrome();
  }

  function dialogById(id) {
    return documentRef && documentRef.getElementById(id);
  }

  function focusable(dialog) {
    return [...dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
      .filter(node => !node.hidden);
  }

  function openDialog(dialogOrId, opener) {
    const dialog = typeof dialogOrId === 'string' ? dialogById(dialogOrId) : dialogOrId;
    if (!dialog) return;
    returnFocus.set(dialog, opener || (documentRef && documentRef.activeElement));
    dialog.hidden = false;
    dialog.classList.add('active');
    const first = focusable(dialog)[0];
    if (first) root.requestAnimationFrame(() => first.focus());
  }

  function closeDialog(dialogOrId) {
    const dialog = typeof dialogOrId === 'string' ? dialogById(dialogOrId) : dialogOrId;
    if (!dialog || dialog.hidden) return;
    dialog.classList.remove('active');
    dialog.hidden = true;
    const opener = returnFocus.get(dialog);
    if (opener && typeof opener.focus === 'function') opener.focus();
  }

  function announce(message) {
    const container = dialogById('toast-container');
    if (!container) return;
    const toast = el('div', { className: 'toast', role: 'status' }, message);
    container.append(toast);
    root.setTimeout(() => toast.remove(), 3500);
  }

  function renderCart() {
    const container = dialogById('cart-items-container');
    if (!container) return;
    const state = load();
    const fragment = documentRef.createDocumentFragment();
    let subtotal = 0;
    if (!state.cart.length) {
      fragment.append(el('p', { className: 'empty-state' }, 'Your cart is empty.'));
    } else {
      state.cart.forEach(line => {
        const lineTotal = Number(line.unitPrice || 0) * Number(line.quantity || 0);
        subtotal += lineTotal;
        const catalogProduct = catalog().products[String(line.id)] || null;
        const image = el('img', { className: 'cart-item-img', src: productImage(catalogProduct), alt: '' });
        const decrease = el('button', { className: 'qty-btn', type: 'button', 'aria-label': `Decrease ${line.name || 'item'} quantity`, onclick: () => changeLineQuantity(line.lineId, -1) }, '−');
        const increase = el('button', { className: 'qty-btn', type: 'button', 'aria-label': `Increase ${line.name || 'item'} quantity`, onclick: () => changeLineQuantity(line.lineId, 1) }, '+');
        fragment.append(el('article', { className: 'cart-item' }, [
          image,
          el('div', { className: 'cart-item-details' }, [el('h3', { className: 'cart-item-title' }, line.name || 'Item'), el('p', { className: 'cart-item-price' }, money(line.unitPrice))]),
          el('div', { className: 'qty-control', role: 'group', 'aria-label': 'Quantity' }, [decrease, el('span', { 'aria-live': 'polite' }, String(line.quantity)), increase])
        ]));
      });
    }
    container.replaceChildren(fragment);
    const delivery = state.cart.length ? Number(stateApi().DELIVERY_FEE || 2) : 0;
    const summary = [['cart-subtotal', subtotal], ['cart-delivery', delivery], ['cart-total', subtotal + delivery]];
    summary.forEach(([id, value]) => { const node = dialogById(id); if (node) node.textContent = money(value); });
  }

  function refreshChrome() {
    const state = syncLegacyState(load());
    const count = state.cart.reduce((sum, line) => sum + Number(line.quantity || 0), 0);
    const countNode = dialogById('cart-count');
    if (countNode) { countNode.textContent = String(count); countNode.hidden = count === 0; }
    const avatar = documentRef && documentRef.querySelector('[data-avatar]');
    if (avatar && state.profile && state.profile.fullName) avatar.textContent = state.profile.fullName.trim().charAt(0).toUpperCase() || 'S';
    if (documentRef && root.location) {
      const currentPage = root.location.pathname.split('/').pop();
      documentRef.querySelectorAll('.customer-nav a').forEach(link => {
        if (link.getAttribute('href') === currentPage) link.setAttribute('aria-current', 'page');
        else link.removeAttribute('aria-current');
      });
    }
    renderCart();
  }

  function changeLineQuantity(lineId, delta) {
    const api = stateApi();
    if (!api) return;
    const state = api.updateCartQuantity(load(), lineId, delta);
    persist(state);
    refreshChrome();
    if (typeof root.renderFullCart === 'function') root.renderFullCart();
  }

  function addCatalogProduct(product, quantity = 1) {
    const api = stateApi();
    if (!api || !product) return;
    const state = api.addCartLine(load(), product, quantity, []);
    persist(state);
    refreshChrome();
    if (typeof root.renderFullCart === 'function') root.renderFullCart();
    announce(`Added ${product.name || 'item'} to cart.`);
  }

  function openProductDetailModal(id) {
    const product = catalog().products[String(id)] || catalog().products['1'];
    if (!product) return;
    activeItem = { ...product, quantity: 1 };
    const assign = (elementId, value) => { const node = dialogById(elementId); if (node) node.textContent = value; };
    assign('pdetail-name', product.name);
    assign('pdetail-price', money(product.price));
    assign('pdetail-desc', product.description || '');
    assign('pdetail-preptime', product.prepTime || '—');
    assign('pdetail-calories', product.calories ? `${product.calories} kcal` : '—');
    const banner = dialogById('pdetail-banner');
    if (banner) banner.style.backgroundImage = `url("${String(productImage(product)).replace(/["\\]/g, '')}")`;
    updateCustomPrice();
    openDialog('customize-modal');
  }

  function updateCustomPrice() {
    if (!activeItem) return;
    const qty = dialogById('cust-qty');
    const total = dialogById('cust-calculated-total');
    if (qty) qty.textContent = String(activeItem.quantity);
    if (total) total.textContent = money(Number(activeItem.price || 0) * activeItem.quantity);
  }

  function changeCustQty(delta) {
    if (!activeItem) return;
    activeItem.quantity = Math.max(1, activeItem.quantity + Number(delta || 0));
    updateCustomPrice();
  }

  function confirmAddCustomToCart() {
    if (!activeItem) return;
    addCatalogProduct(activeItem, activeItem.quantity);
    closeDialog('customize-modal');
  }

  function openMenuModal(restName) {
    const modal = dialogById('menu-modal');
    const title = dialogById('modal-rest-name');
    const grid = dialogById('modal-food-grid');
    if (!modal || !grid) return;
    if (title) title.textContent = `${restName} menu`;
    const entries = Object.values(catalog().products).filter(product => product.restaurant === restName);
    const fragment = documentRef.createDocumentFragment();
    if (!entries.length) fragment.append(el('p', { className: 'empty-state' }, 'No menu items are available yet.'));
    entries.forEach(product => {
      const add = el('button', { className: 'primary-action', type: 'button', onclick: () => addCatalogProduct(product) }, 'Add to cart');
      const image = el('img', { className: 'food-card-img', src: productImage(product), alt: '' });
      fragment.append(el('article', { className: 'food-card' }, [image, el('h3', { className: 'food-card-title' }, product.name), el('p', { className: 'food-card-meta' }, product.description), el('div', { className: 'card-action-row' }, [el('strong', {}, money(product.price)), add])]));
    });
    grid.replaceChildren(fragment);
    openDialog(modal);
  }

  function topUpAmount(amount) {
    const api = stateApi();
    if (!api) return;
    try {
      const state = api.topUpWallet(load(), amount);
      persist(state);
      refreshChrome();
      closeDialog('topup-modal');
      announce(`${money(amount)} added to Savora Pay.`);
    } catch (error) {
      announce(error.message || 'Unable to top up.');
    }
  }

  function toggleDropdown(event) {
    if (event) event.stopPropagation();
    const menu = dialogById('userDropdown');
    const button = documentRef && documentRef.querySelector('.user-avatar');
    if (!menu || !button) return;
    const open = menu.hidden;
    menu.hidden = !open;
    button.setAttribute('aria-expanded', String(open));
  }

  function bindInteractions() {
    if (!documentRef) return;
    const menuToggle = documentRef.querySelector('.mobile-menu-toggle');
    const nav = dialogById('customer-mobile-menu');
    function setMobileMenuOpen(open, shouldMoveFocus = false, shouldReturnFocus = false) {
      if (!nav || !menuToggle) return;
      nav.dataset.open = String(open);
      menuToggle.setAttribute('aria-expanded', String(open));
      menuToggle.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
      if (open && shouldMoveFocus) {
        const firstLink = nav.querySelector('a[href]');
        if (firstLink) root.requestAnimationFrame(() => firstLink.focus());
      }
      if (shouldReturnFocus) menuToggle.focus();
    }
    menuToggle && menuToggle.addEventListener('click', () => {
      const open = nav.dataset.open !== 'true';
      setMobileMenuOpen(open, open);
    });
    nav && nav.addEventListener('click', event => { if (event.target.closest('a')) setMobileMenuOpen(false); });
    const cartButton = dialogById('open-cart-btn');
    cartButton && cartButton.addEventListener('click', () => openDialog('cart-overlay', cartButton));
    const avatar = documentRef.querySelector('.user-avatar');
    avatar && avatar.addEventListener('click', toggleDropdown);
    documentRef.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => closeDialog(button.dataset.closeDialog)));
    documentRef.querySelectorAll('[data-custom-quantity]').forEach(button => button.addEventListener('click', () => changeCustQty(button.dataset.customQuantity)));
    const confirm = dialogById('confirm-custom-cart');
    confirm && confirm.addEventListener('click', confirmAddCustomToCart);
    documentRef.querySelectorAll('[data-topup]').forEach(button => button.addEventListener('click', () => topUpAmount(button.dataset.topup)));
    documentRef.addEventListener('click', event => {
      const clickedElement = event.target.closest ? event.target : null;
      const mobileViewport = root.matchMedia && root.matchMedia('(max-width: 768px)').matches;
      if (mobileViewport && nav && nav.dataset.open === 'true' && clickedElement
        && !clickedElement.closest('#customer-mobile-menu, .mobile-menu-toggle')) {
        setMobileMenuOpen(false, false, nav.contains(documentRef.activeElement));
      }
      if (!clickedElement || !clickedElement.closest('.user-dropdown')) {
        const menu = dialogById('userDropdown');
        const button = documentRef.querySelector('.user-avatar');
        if (menu) menu.hidden = true;
        if (button) button.setAttribute('aria-expanded', 'false');
      }
    });
    documentRef.addEventListener('keydown', event => {
      if (event.key === 'Escape' && nav.dataset.open === 'true') {
        event.preventDefault();
        setMobileMenuOpen(false, false, true);
        return;
      }
      const active = [...documentRef.querySelectorAll('.dialog.active')].pop();
      if (!active) return;
      if (event.key === 'Escape') { event.preventDefault(); closeDialog(active); return; }
      if (event.key !== 'Tab') return;
      const nodes = focusable(active);
      if (!nodes.length) return;
      const first = nodes[0]; const last = nodes[nodes.length - 1];
      if (event.shiftKey && documentRef.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && documentRef.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    root.addEventListener('storage', event => { if (event.key === stateApi().KEY) refreshChrome(); });
  }

  const api = {
    el, openDialog, closeDialog, refreshChrome, renderCart, announce, showToast: announce,
    openCart: () => openDialog('cart-overlay'), closeCart: () => closeDialog('cart-overlay'),
    openProductDetailModal, closeCustomizeModal: () => closeDialog('customize-modal'), changeCustQty, confirmAddCustomToCart,
    openMenuModal, closeMenuModal: () => closeDialog('menu-modal'), openTopUpModal: () => openDialog('topup-modal'), closeTopUpModal: () => closeDialog('topup-modal'),
    topUpAmount, toggleDropdown, changeLineQuantity, addCatalogProduct, saveLegacyState
  };

  if (root && documentRef) {
    Object.assign(root, {
      openCart: api.openCart, closeCart: api.closeCart, openProductDetailModal: api.openProductDetailModal,
      closeCustomizeModal: api.closeCustomizeModal, changeCustQty: api.changeCustQty, confirmAddCustomToCart: api.confirmAddCustomToCart,
      openMenuModal: api.openMenuModal, closeMenuModal: api.closeMenuModal, openTopUpModal: api.openTopUpModal,
      closeTopUpModal: api.closeTopUpModal, topUpAmount: api.topUpAmount, toggleDropdown: api.toggleDropdown,
      changeQty: (index, delta) => { const line = (root.cart || [])[index]; if (line) changeLineQuantity(line.lineId, delta); },
      addToCart: (name, price, img, quantity = 1) => {
        const product = Object.values(catalog().products).find(item => item.name === name) || { id: String(name), name, price, image: '' };
        addCatalogProduct(product, quantity);
      },
      quickReorder: (name, price) => root.addToCart(name, price, '', 1), showToast: announce,
      updateCartUI: refreshChrome, saveState: saveLegacyState
    });
    // Page-local DOMContentLoaded handlers are registered before this footer script.
    // Export their compatibility state now, rather than waiting for this helper's listener.
    syncLegacyState(load());
    const initialize = () => { bindInteractions(); refreshChrome(); };
    if (documentRef.readyState === 'loading') documentRef.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
  }

  return api;
}));
