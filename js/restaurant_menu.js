(function attachRestaurantMenu(root, factory) {
  const api = factory(root, root && root.document);
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraRestaurantMenu = api;
}(typeof window === 'undefined' ? null : window, function createRestaurantMenu(root, documentRef) {
  'use strict';

  const PLACEHOLDER_IMAGE = 'assets/images/food-placeholder.svg';
  const imagePath = value => typeof value === 'string' && /^assets\/images\/catalog\/[a-z0-9][a-z0-9-]*\.(?:jpg|jpeg|png|webp)$/.test(value) ? value : '';
  const text = value => typeof value === 'string' ? value.trim().slice(0, 500) : '';
  const money = value => Number.isFinite(Number(value)) ? Math.max(0, Number(value)) : 0;
  const bool = value => value === true || value === 'true' || value === 'on' || value === '1';
  const uniqueTags = value => Array.isArray(value) ? [...new Set(value.map(text).filter(Boolean))].slice(0, 12) : [];
  const safeOptions = value => Array.isArray(value) ? value.map(option => {
    const label = text(option && option.label);
    return label ? { label, price: money(option.price) } : null;
  }).filter(Boolean).slice(0, 20) : [];
  const safeGroups = value => Array.isArray(value) ? value.map(group => {
    const name = text(group && group.name);
    return name ? { name, required: bool(group.required), options: safeOptions(group.options) } : null;
  }).filter(Boolean).slice(0, 8) : [];
  const editorFieldName = key => ({ compareAtPrice: 'menu-compare-price', taxCategory: 'menu-tax-category', prepTime: 'menu-prep-time' }[key] || `menu-${key}`);
  const appendOptionGroup = (groups, draft) => {
    const source = draft && typeof draft === 'object' ? draft : {};
    const name = text(source.name);
    if (!name) return safeGroups(groups);
    const label = text(source.optionLabel);
    const option = label ? [{ label, price: money(source.optionPrice) }] : [];
    return safeGroups([...(Array.isArray(groups) ? groups : []), { name, required: bool(source.required), options: option }]);
  };
  const appendAddOn = (addOns, draft) => {
    const source = draft && typeof draft === 'object' ? draft : {};
    const label = text(source.label);
    return label ? safeOptions([...(Array.isArray(addOns) ? addOns : []), { label, price: money(source.price) }]) : safeOptions(addOns);
  };

  function validateMenuItem(draft) {
    const source = draft && typeof draft === 'object' ? draft : {};
    const errors = {};
    if (!text(source.name)) errors.name = 'Enter an item name.';
    if (!text(source.category)) errors.category = 'Choose a category.';
    if (!Number.isFinite(Number(source.price)) || Number(source.price) <= 0) errors.price = 'Enter a price greater than zero.';
    if (Number(source.compareAtPrice) > 0 && Number(source.compareAtPrice) < Number(source.price)) errors.compareAtPrice = 'Compare-at price must be at least the base price.';
    return { valid: Object.keys(errors).length === 0, errors };
  }

  function validateMenuItemForStatus(draft, status) {
    return status === 'draft' ? { valid: true, errors: {} } : validateMenuItem(draft);
  }

  function ensureDraftId(holder, now = () => Date.now()) {
    if (!holder || !holder.dataset) return '';
    const current = text(holder.dataset.menuItemId);
    if (current) return current;
    const stamp = Math.max(0, Number(now()) || 0).toString(36);
    holder.dataset.menuItemId = `menu-${stamp}`;
    return holder.dataset.menuItemId;
  }

  function menuItemFromDraft(draft) {
    const source = draft && typeof draft === 'object' ? draft : {};
    return {
      id: text(source.id) || `menu-${Date.now()}`,
      name: text(source.name), description: text(source.description), category: text(source.category),
      image: imagePath(source.image) || PLACEHOLDER_IMAGE,
      price: money(source.price), compareAtPrice: money(source.compareAtPrice), taxCategory: text(source.taxCategory),
      optionGroups: safeGroups(source.optionGroups), addOns: safeOptions(source.addOns),
      available: source.available !== false && source.available !== 'false', stockTracking: bool(source.stockTracking),
      stock: money(source.stock), prepTime: money(source.prepTime) || 20, dietaryTags: uniqueTags(source.dietaryTags),
      status: source.status === 'draft' ? 'draft' : 'published'
    };
  }

  function ui() { return root && root.SavoraRestaurantUI; }
  function stateApi() { return root && root.SavoraRestaurantState; }
  function get(selector) { return documentRef && documentRef.querySelector(selector); }
  function getAll(selector) { return documentRef ? [...documentRef.querySelectorAll(selector)] : []; }
  function el(tag, attributes, content) { return ui().el(tag, attributes, content); }
  function itemImage(item) { return imagePath(item && item.image) || PLACEHOLDER_IMAGE; }
  function stringForSearch(item) { return [item.name, item.description, item.category].join(' ').toLowerCase(); }

  function filteredItems() {
    const api = stateApi();
    if (!api) return [];
    const state = api.load();
    const query = text(get('[data-menu-search]') && get('[data-menu-search]').value).toLowerCase();
    const category = get('[data-menu-category]') && get('[data-menu-category]').value || 'all';
    const availability = get('[data-menu-availability]') && get('[data-menu-availability]').value || 'all';
    const sort = get('[data-menu-sort]') && get('[data-menu-sort]').value || 'name';
    const items = (state.menuItems || []).filter(item => {
      const matchesSearch = !query || stringForSearch(item).includes(query);
      const matchesCategory = category === 'all' || item.category === category;
      const matchesAvailability = availability === 'all' || (availability === 'available' ? item.available !== false : item.available === false);
      return matchesSearch && matchesCategory && matchesAvailability;
    });
    return items.sort((left, right) => sort === 'price' ? Number(left.price || 0) - Number(right.price || 0) : text(left.name).localeCompare(text(right.name)));
  }

  function renderMenu() {
    const list = get('[data-menu-list]');
    const api = stateApi();
    if (!list || !api || !ui()) return;
    const items = filteredItems();
    list.replaceChildren();
    if (!items.length) list.append(el('p', { className: 'restaurant-empty' }, 'No menu items match these filters.'));
    items.forEach(item => {
      const availabilityLabel = item.available !== false ? 'Available' : 'Unavailable';
      const toggle = el('button', { type: 'button', className: 'restaurant-availability-toggle', 'data-menu-availability-toggle': item.id, 'aria-pressed': String(item.available !== false) }, availabilityLabel);
      const card = el('article', { className: 'restaurant-menu-card' }, [
        el('img', { src: itemImage(item), alt: text(item.name) || 'Menu item', className: 'restaurant-menu-image' }),
        el('div', { className: 'restaurant-menu-card-content' }, [
          el('p', { className: 'restaurant-menu-category' }, text(item.category) || 'Menu'),
          el('h2', {}, text(item.name) || 'Untitled menu item'),
          el('p', { className: 'restaurant-menu-price' }, ui().formatMoney(item.price)),
          el('p', { className: 'restaurant-menu-stock' }, item.stockTracking ? `${Number(item.stock || 0)} in stock` : 'Stock not tracked'),
          el('div', { className: 'restaurant-menu-card-actions' }, [toggle, el('a', { href: `restaurant_menu_item.php?id=${encodeURIComponent(item.id)}` }, 'Edit item')])
        ])
      ]);
      list.append(card);
    });
    const feedback = get('[data-menu-feedback]');
    if (feedback) feedback.textContent = `${items.length} menu item${items.length === 1 ? '' : 's'} shown.`;
  }

  function collectDraft(form) {
    const data = new FormData(form);
    return {
      id: form.dataset.menuItemId || '', name: data.get('menu-name'), description: data.get('menu-description'), category: data.get('menu-category'),
      image: data.get('menu-image'), price: data.get('menu-price'), compareAtPrice: data.get('menu-compare-price'), taxCategory: data.get('menu-tax-category'),
      available: data.get('menu-available') === 'on', stockTracking: data.get('menu-stock-tracking') === 'on', stock: data.get('menu-stock'), prepTime: data.get('menu-prep-time'),
      dietaryTags: String(data.get('menu-dietary-tags') || '').split(',').map(tag => tag.trim()), optionGroups: form.optionGroups || [], addOns: form.addOns || []
    };
  }

  function previewDraft(draft) {
    const preview = get('[data-menu-customer-preview]');
    if (!preview || !ui()) return;
    const item = menuItemFromDraft(draft);
    preview.replaceChildren(
      el('img', { src: itemImage(item), alt: item.name || 'Menu item preview', className: 'restaurant-preview-image' }),
      el('h2', {}, item.name || 'Your menu item'),
      el('p', { className: 'restaurant-menu-price' }, ui().formatMoney(item.price)),
      el('p', {}, item.description || 'Add a description customers can read.'),
      el('p', { className: 'restaurant-menu-category' }, item.category || 'Choose a category')
    );
  }

  function renderEditorLists(form) {
    const groups = get('[data-menu-option-groups]');
    const addOns = get('[data-menu-add-ons]');
    if (!groups || !addOns || !ui()) return;
    groups.replaceChildren(...(form.optionGroups || []).map(group => el('p', {}, `${group.name}${group.required ? ' (required)' : ''}: ${group.options.map(option => option.label).join(', ') || 'No options'}`)));
    addOns.replaceChildren(...(form.addOns || []).map(addOn => el('p', {}, `${addOn.label} · ${ui().formatMoney(addOn.price)}`)));
  }

  function setField(form, name, value) {
    const field = form.elements.namedItem(name);
    if (!field) return;
    if (field.type === 'checkbox') field.checked = value === true;
    else field.value = value == null ? '' : value;
  }

  function clearValidationState(form) {
    ['name', 'category', 'price', 'compareAtPrice'].forEach(key => {
      const field = form && form.elements && form.elements.namedItem(editorFieldName(key));
      if (field) field.removeAttribute('aria-invalid');
    });
  }

  function loadEditor(form) {
    const api = stateApi();
    if (!api) return;
    const id = new URLSearchParams(root.location.search).get('id');
    const item = id ? api.load().menuItems.find(entry => entry.id === id) : null;
    if (!item) { ensureDraftId(form); form.optionGroups = []; form.addOns = []; return; }
    form.dataset.menuItemId = item.id;
    ['name', 'description', 'category', 'image', 'price', 'compareAtPrice', 'taxCategory', 'stock', 'prepTime'].forEach(key => setField(form, editorFieldName(key), item[key]));
    setField(form, 'menu-available', item.available !== false);
    setField(form, 'menu-stock-tracking', item.stockTracking === true);
    setField(form, 'menu-dietary-tags', (item.dietaryTags || []).join(', '));
    form.optionGroups = safeGroups(item.optionGroups); form.addOns = safeOptions(item.addOns);
    const title = get('[data-menu-editor-title]'); if (title) title.textContent = 'Edit Menu Item';
  }

  function showValidation(form, errors) {
    clearValidationState(form);
    getAll('[data-menu-field-error]').forEach(node => { node.textContent = ''; });
    Object.entries(errors).forEach(([key, message]) => {
      const field = form.elements.namedItem(editorFieldName(key));
      if (field) field.setAttribute('aria-invalid', 'true');
      const error = get(`[data-menu-field-error="${key}"]`); if (error) error.textContent = message;
    });
    const summary = get('[data-menu-validation]');
    if (summary) summary.textContent = Object.values(errors).join(' ');
  }

  function bindMenu() {
    if (!documentRef || !get('[data-menu-page]')) return;
    ['input', 'change'].forEach(type => documentRef.addEventListener(type, event => {
      if (event.target.matches('[data-menu-search], [data-menu-category], [data-menu-availability], [data-menu-sort]')) renderMenu();
    }));
    documentRef.addEventListener('click', event => {
      const toggle = event.target.closest('[data-menu-availability-toggle]');
      if (toggle) {
        const api = stateApi(); if (!api) return;
        const next = api.setItemAvailability(api.load(), toggle.dataset.menuAvailabilityToggle, toggle.getAttribute('aria-pressed') !== 'true');
        api.persist(next); renderMenu();
        if (ui()) ui().showToast('Availability updated for the Customer catalog.');
      }
      const view = event.target.closest('[data-menu-view]');
      if (view) { getAll('[data-menu-view]').forEach(button => button.setAttribute('aria-pressed', String(button === view))); get('[data-menu-list]').dataset.view = view.dataset.menuView; }
    });
    renderMenu();
  }

  function bindEditor() {
    const form = get('[data-menu-editor-form]');
    if (!form || !documentRef || !ui()) return;
    loadEditor(form); renderEditorLists(form); previewDraft(collectDraft(form));
    form.addEventListener('input', () => previewDraft(collectDraft(form)));
    form.addEventListener('change', () => previewDraft(collectDraft(form)));
    documentRef.addEventListener('click', event => {
      if (event.target.closest('[data-menu-add-option-group]')) {
        form.optionGroups = appendOptionGroup(form.optionGroups, {
          name: form.elements.namedItem('menu-option-group-name').value, required: form.elements.namedItem('menu-option-required').checked,
          optionLabel: form.elements.namedItem('menu-option-label').value, optionPrice: form.elements.namedItem('menu-option-price').value
        });
        form.elements.namedItem('menu-option-group-name').value = ''; form.elements.namedItem('menu-option-label').value = ''; form.elements.namedItem('menu-option-price').value = '';
        renderEditorLists(form);
      }
      if (event.target.closest('[data-menu-add-addon]')) {
        form.addOns = appendAddOn(form.addOns, { label: form.elements.namedItem('menu-addon-label').value, price: form.elements.namedItem('menu-addon-price').value });
        form.elements.namedItem('menu-addon-label').value = ''; form.elements.namedItem('menu-addon-price').value = '';
        renderEditorLists(form);
      }
    });
      form.addEventListener('submit', async event => {
      event.preventDefault();
      const submitter = event.submitter || documentRef.activeElement;
      const draft = collectDraft(form);
      draft.status = submitter && submitter.dataset.menuSave === 'draft' ? 'draft' : 'published';
      const result = validateMenuItemForStatus(draft, draft.status);
      clearValidationState(form);
      if (!result.valid) { showValidation(form, result.errors); return; }
      const api = stateApi(); if (!api) return;
        const item = menuItemFromDraft(draft);
        if (!root.SavoraPlatformBridge) { showValidation(form, { name: 'The platform connection is not ready.' }); return; }
        try {
          const intentScope = 'restaurant-menu-' + item.id;
          await root.SavoraPlatformBridge.command('restaurant_sync_menu', item, root.SavoraApi.intentKey(intentScope));
          root.SavoraApi.clearIntentKey(intentScope);
        } catch (error) {
          showValidation(form, { name: error.message || 'Unable to synchronize this menu item.' });
          return;
        }
        api.persist(api.setMenuItem(api.load(), item));
      const validation = get('[data-menu-validation]'); if (validation) validation.textContent = '';
      const status = get('[data-menu-status]'); if (status) status.textContent = draft.status === 'draft' ? 'Draft saved locally.' : 'Menu item published to the Customer catalog.';
      root.setTimeout(() => { root.location.assign('restaurant_menu.php'); }, 350);
    });
  }

  if (root && documentRef) {
    const initialize = () => { bindMenu(); bindEditor(); };
    if (documentRef.readyState === 'loading') documentRef.addEventListener('DOMContentLoaded', initialize, { once: true }); else initialize();
  }

  return { PLACEHOLDER_IMAGE, appendAddOn, appendOptionGroup, clearValidationState, editorFieldName, ensureDraftId, validateMenuItem, validateMenuItemForStatus, menuItemFromDraft, renderMenu };
}));
