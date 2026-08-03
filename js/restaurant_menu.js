(function attachRestaurantMenu(root, factory) {
  const api = factory(root, root && root.document);
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraRestaurantMenu = api;
}(typeof window === 'undefined' ? globalThis : window, function createRestaurantMenu(root, documentRef) {
  'use strict';

  const PLACEHOLDER_IMAGE = 'assets/images/food-placeholder.svg';
  const text = value => typeof value === 'string' ? value.trim().slice(0, 500) : '';
  const money = value => Number.isFinite(Number(value)) ? Math.max(0, Number(value)) : 0;
  const bool = value => value === true || value === 'true' || value === 'on' || value === '1';
  const safeOptions = value => Array.isArray(value) ? value.map(option => {
    const label = text(option && option.label);
    return label ? { publicId: text(option.publicId), label, price: money(option.price), available: option.available !== false, sortOrder: Number.isInteger(Number(option.sortOrder)) ? Number(option.sortOrder) : 0 } : null;
  }).filter(Boolean).slice(0, 20) : [];
  const safeGroups = value => Array.isArray(value) ? value.map(group => {
    const name = text(group && group.name);
    return name ? { name, required: bool(group.required), selectionType: group.selectionType === 'multiple' ? 'multiple' : 'single', minimumChoices: Math.max(0, Number(group.minimumChoices) || (bool(group.required) ? 1 : 0)), maximumChoices: Math.max(1, Number(group.maximumChoices) || 1), sortOrder: Math.max(0, Number(group.sortOrder) || 0), options: safeOptions(group.options) } : null;
  }).filter(Boolean).slice(0, 8) : [];
  const editorFieldName = key => ({ compareAtPrice: 'menu-compare-price', taxCategory: 'menu-tax-category', prepTime: 'menu-prep-time' }[key] || `menu-${key}`);
  const appendOptionGroup = (groups, draft) => {
    const name = text(draft && draft.name);
    const label = text(draft && draft.optionLabel);
    return name ? safeGroups([...(Array.isArray(groups) ? groups : []), { name, required: bool(draft.required), options: label ? [{ label, price: money(draft.optionPrice) }] : [] }]) : safeGroups(groups);
  };
  const appendAddOn = (addOns, draft) => {
    const label = text(draft && draft.label);
    return label ? safeOptions([...(Array.isArray(addOns) ? addOns : []), { label, price: money(draft.price) }]) : safeOptions(addOns);
  };
  let snapshot = { restaurant: {}, items: [], weeklyHours: [], specialHours: [] };

  function validateMenuItem(draft) {
    const errors = {};
    if (!text(draft && draft.name)) errors.name = 'Enter an item name.';
    if (!text(draft && draft.category)) errors.category = 'Choose a category.';
    if (!Number.isFinite(Number(draft && draft.price)) || Number(draft.price) <= 0) errors.price = 'Enter a price greater than zero.';
    if (Number(draft && draft.compareAtPrice) > 0 && Number(draft.compareAtPrice) < Number(draft.price)) errors.compareAtPrice = 'Compare-at price must be at least the base price.';
    return { valid: Object.keys(errors).length === 0, errors };
  }
  function validateMenuItemForStatus(draft, status) { return status === 'draft' ? { valid: true, errors: {} } : validateMenuItem(draft); }
  function ensureDraftId(holder, now = () => Date.now()) {
    if (!holder || !holder.dataset) return '';
    if (!text(holder.dataset.menuItemId)) holder.dataset.menuItemId = `menu-${Math.max(0, Number(now()) || 0).toString(36)}`;
    return holder.dataset.menuItemId;
  }
  function menuItemFromDraft(draft) {
    const source = draft && typeof draft === 'object' ? draft : {};
    return {
      id: text(source.id) || `menu-${Date.now()}`, name: text(source.name), description: text(source.description), category: text(source.category),
      price: money(source.price), compareAtPrice: money(source.compareAtPrice), taxCategory: text(source.taxCategory), optionGroups: safeGroups(source.optionGroups), addOns: safeOptions(source.addOns),
      available: source.available !== false && source.available !== 'false', stockTracking: bool(source.stockTracking), stock: money(source.stock), prepTime: money(source.prepTime) || 20,
      dietaryTags: Array.isArray(source.dietaryTags) ? source.dietaryTags.map(text).filter(Boolean).slice(0, 12) : [], status: source.status === 'draft' ? 'draft' : 'published'
    };
  }
  const ui = () => root && root.SavoraRestaurantUI;
  const get = selector => documentRef && documentRef.querySelector(selector);
  const getAll = selector => documentRef ? [...documentRef.querySelectorAll(selector)] : [];
  const el = (tag, attributes, content) => ui().el(tag, attributes, content);
  const serverItem = item => ({
    id: String(item.publicId || ''), name: text(item.name), price: money(item.basePrice), available: item.available !== false,
    version: Number(item.version || 0), category: text(snapshot.restaurant && snapshot.restaurant.cuisine) || 'Menu', optionGroups: Array.isArray(item.optionGroups) ? item.optionGroups : []
  });
  function editorGroupsFromServer(groups) {
    return safeGroups((Array.isArray(groups) ? groups : []).map(group => ({
      name: group.name,
      required: Number(group.minimumChoices || 0) > 0,
      selectionType: group.selectionType,
      minimumChoices: group.minimumChoices,
      maximumChoices: group.maximumChoices,
      sortOrder: group.sortOrder,
      options: (Array.isArray(group.optionChoices) ? group.optionChoices : []).map(choice => ({ publicId: choice.publicId, label: choice.name, price: choice.priceDelta, available: choice.available !== false, sortOrder: choice.sortOrder }))
    })));
  }
  function editorDataFromServer(groups) {
    const mapped = editorGroupsFromServer(groups);
    const addOnGroup = mapped.find(group => group.name === 'Add-ons' && group.selectionType === 'multiple');
    return { optionGroups: mapped.filter(group => group !== addOnGroup), addOns: addOnGroup ? addOnGroup.options : [] };
  }
  async function loadSnapshot() {
    snapshot = await root.SavoraApi.get('api/catalog.php?scope=restaurant');
    snapshot.items = Array.isArray(snapshot.items) ? snapshot.items.map(serverItem) : [];
    return snapshot;
  }
  function filteredItems() {
    const query = text(get('[data-menu-search]') && get('[data-menu-search]').value).toLowerCase();
    const availability = get('[data-menu-availability]') && get('[data-menu-availability]').value || 'all';
    const sort = get('[data-menu-sort]') && get('[data-menu-sort]').value || 'name';
    return snapshot.items.filter(item => (!query || `${item.name} ${item.category}`.toLowerCase().includes(query)) && (availability === 'all' || (availability === 'available' ? item.available : !item.available)))
      .sort((left, right) => sort === 'price' ? left.price - right.price : left.name.localeCompare(right.name));
  }
  function renderMenu() {
    const list = get('[data-menu-list]');
    if (!list || !ui()) return;
    const items = filteredItems();
    list.replaceChildren(...(items.length ? items.map(item => el('article', { className: 'restaurant-menu-card' }, [
      el('img', { src: PLACEHOLDER_IMAGE, alt: item.name || 'Menu item', className: 'restaurant-menu-image' }),
      el('div', { className: 'restaurant-menu-card-content' }, [
        el('p', { className: 'restaurant-menu-category' }, item.category), el('h2', {}, item.name || 'Untitled menu item'),
        el('p', { className: 'restaurant-menu-price' }, ui().formatMoney(item.price)),
        el('div', { className: 'restaurant-menu-card-actions' }, [
          el('button', { type: 'button', className: 'restaurant-availability-toggle', 'data-menu-availability-toggle': item.id, 'data-menu-version': String(item.version), 'aria-pressed': String(item.available) }, item.available ? 'Available' : 'Unavailable'),
          el('a', { href: `restaurant_menu_item.php?id=${encodeURIComponent(item.id)}` }, 'Edit item')
        ])
      ])
    ])) : [el('p', { className: 'restaurant-empty' }, 'No menu items match these filters.')]));
    const feedback = get('[data-menu-feedback]'); if (feedback) feedback.textContent = `${items.length} menu item${items.length === 1 ? '' : 's'} shown.`;
  }
  function collectDraft(form) {
    const data = new FormData(form);
    return { id: form.dataset.menuItemId || '', name: data.get('menu-name'), description: data.get('menu-description'), category: data.get('menu-category'), price: data.get('menu-price'), compareAtPrice: data.get('menu-compare-price'), taxCategory: data.get('menu-tax-category'), available: data.get('menu-available') === 'on', stockTracking: data.get('menu-stock-tracking') === 'on', stock: data.get('menu-stock'), prepTime: data.get('menu-prep-time'), dietaryTags: String(data.get('menu-dietary-tags') || '').split(',').map(tag => tag.trim()), optionGroups: form.optionGroups || [], addOns: form.addOns || [] };
  }
  function renderEditorLists(form) {
    const groups = get('[data-menu-option-groups]'); const addOns = get('[data-menu-add-ons]');
    if (!groups || !addOns || !ui()) return;
    groups.replaceChildren(...(form.optionGroups || []).map(group => el('p', {}, `${group.name}${group.required ? ' (required)' : ''}: ${group.options.map(option => option.label).join(', ') || 'No options'}`)));
    addOns.replaceChildren(...(form.addOns || []).map(addOn => el('p', {}, `${addOn.label} · ${ui().formatMoney(addOn.price)}`)));
  }
  function previewDraft(draft) {
    const preview = get('[data-menu-customer-preview]'); if (!preview || !ui()) return;
    const item = menuItemFromDraft(draft);
    preview.replaceChildren(el('img', { src: PLACEHOLDER_IMAGE, alt: item.name || 'Menu item preview', className: 'restaurant-preview-image' }), el('h2', {}, item.name || 'Your menu item'), el('p', { className: 'restaurant-menu-price' }, ui().formatMoney(item.price)), el('p', {}, item.description || 'Add a description customers can read.'), el('p', { className: 'restaurant-menu-category' }, item.category || 'Choose a category'));
  }
  function setField(form, name, value, checked) { const field = form.elements.namedItem(name); if (field) typeof checked === 'boolean' ? field.checked = checked : field.value = value == null ? '' : value; }
  function clearValidationState(form) { ['name', 'category', 'price', 'compareAtPrice'].forEach(key => { const field = form && form.elements.namedItem(editorFieldName(key)); if (field) field.removeAttribute('aria-invalid'); }); }
  function showValidation(form, errors) { clearValidationState(form); Object.entries(errors).forEach(([key, message]) => { const field = form.elements.namedItem(editorFieldName(key)); if (field) field.setAttribute('aria-invalid', 'true'); }); const summary = get('[data-menu-validation]'); if (summary) summary.textContent = Object.values(errors).join(' '); }
  function serverPayload(item, version) {
    const groups = [...item.optionGroups, ...(item.addOns.length ? [{
      name: 'Add-ons', required: false, selectionType: 'multiple', minimumChoices: 0,
      maximumChoices: item.addOns.length, sortOrder: item.optionGroups.length, options: item.addOns
    }] : [])];
    return { publicId: item.id, name: item.name, price: item.price, available: item.available, version, optionGroups: groups.map((group, groupIndex) => ({ name: group.name, selectionType: group.selectionType === 'multiple' ? 'multiple' : 'single', minimumChoices: Math.max(0, Number(group.minimumChoices) || (group.required ? 1 : 0)), maximumChoices: Math.max(1, Number(group.maximumChoices) || (group.required ? 1 : group.options.length)), sortOrder: Number.isInteger(Number(group.sortOrder)) ? Number(group.sortOrder) : groupIndex, choices: group.options.map((option, optionIndex) => ({ publicId: text(option.publicId) || `${item.id}-${groupIndex}-${optionIndex}`, name: option.label, priceDelta: option.price, available: option.available !== false, sortOrder: Number.isInteger(Number(option.sortOrder)) ? Number(option.sortOrder) : optionIndex })) })) };
  }
  async function bindMenu() {
    if (!documentRef || !get('[data-menu-page]') || !root.SavoraApi) return;
    const feedback = get('[data-menu-feedback]');
    try { await loadSnapshot(); renderMenu(); } catch (error) { if (feedback) feedback.textContent = error.message || 'Menu records are unavailable.'; return; }
    ['input', 'change'].forEach(type => documentRef.addEventListener(type, event => { if (event.target.matches('[data-menu-search], [data-menu-category], [data-menu-availability], [data-menu-sort]')) renderMenu(); }));
    documentRef.addEventListener('click', async event => {
      const toggle = event.target.closest('[data-menu-availability-toggle]');
      if (!toggle) return;
      const scope = `restaurant-availability-${toggle.dataset.menuAvailabilityToggle}`;
      try {
        await root.SavoraApi.post('api/catalog.php', { action: 'set_item_availability', payload: { publicId: toggle.dataset.menuAvailabilityToggle, available: toggle.getAttribute('aria-pressed') !== 'true', version: Number(toggle.dataset.menuVersion || 0) } }, root.SavoraApi.intentKey(scope));
        await loadSnapshot(); root.SavoraApi.clearIntentKey(scope); renderMenu(); if (ui()) ui().showToast('Availability refreshed from the server.');
      } catch (error) { if (feedback) feedback.textContent = error.message || 'Availability was not changed.'; }
    });
  }
  async function bindEditor() {
    const form = get('[data-menu-editor-form]');
    if (!form || !documentRef || !ui() || !root.SavoraApi) return;
    try { await loadSnapshot(); } catch (error) { showValidation(form, { name: error.message || 'Menu records are unavailable.' }); return; }
    const id = new URLSearchParams(root.location.search).get('id'); const saved = id && snapshot.items.find(item => item.id === id);
    if (saved) { form.dataset.menuItemId = saved.id; setField(form, 'menu-name', saved.name); setField(form, 'menu-price', saved.price); setField(form, 'menu-available', '', saved.available); form.dataset.menuVersion = String(saved.version); const title = get('[data-menu-editor-title]'); if (title) title.textContent = 'Edit Menu Item'; const editorData = editorDataFromServer(saved.optionGroups); form.optionGroups = editorData.optionGroups; form.addOns = editorData.addOns; } else { ensureDraftId(form); form.dataset.menuVersion = '0'; form.optionGroups = []; form.addOns = []; }
    renderEditorLists(form); previewDraft(collectDraft(form));
    form.addEventListener('input', () => previewDraft(collectDraft(form))); form.addEventListener('change', () => previewDraft(collectDraft(form)));
    documentRef.addEventListener('click', event => {
      if (event.target.closest('[data-menu-add-option-group]')) { form.optionGroups = appendOptionGroup(form.optionGroups, { name: form.elements.namedItem('menu-option-group-name').value, required: form.elements.namedItem('menu-option-required').checked, optionLabel: form.elements.namedItem('menu-option-label').value, optionPrice: form.elements.namedItem('menu-option-price').value }); renderEditorLists(form); }
      if (event.target.closest('[data-menu-add-addon]')) { form.addOns = appendAddOn(form.addOns, { label: form.elements.namedItem('menu-addon-label').value, price: form.elements.namedItem('menu-addon-price').value }); renderEditorLists(form); }
    });
    form.addEventListener('submit', async event => {
      event.preventDefault(); const draft = collectDraft(form); draft.status = event.submitter && event.submitter.dataset.menuSave === 'draft' ? 'draft' : 'published'; const result = validateMenuItemForStatus(draft, draft.status); clearValidationState(form);
      if (!result.valid) { showValidation(form, result.errors); return; }
      if (draft.status === 'draft') { const state = root.SavoraRestaurantState; if (state && state.saveMenuDraft) state.persist(state.saveMenuDraft(state.load(), draft.id, draft)); const status = get('[data-menu-status]'); if (status) status.textContent = 'Draft kept locally and has not been submitted.'; return; }
      const item = menuItemFromDraft(draft); const scope = `restaurant-menu-${item.id}`;
      try {
        await root.SavoraApi.post('api/catalog.php', { action: 'save_item', payload: serverPayload(item, Number(form.dataset.menuVersion || 0)) }, root.SavoraApi.intentKey(scope));
        await loadSnapshot(); root.SavoraApi.clearIntentKey(scope); const state = root.SavoraRestaurantState; if (state && state.clearMenuDraft) state.persist(state.clearMenuDraft(state.load(), item.id)); root.location.assign('restaurant_menu.php');
      } catch (error) { showValidation(form, { name: error.message || 'Menu item was not saved.' }); }
    });
  }
  if (root && documentRef) { const initialize = () => { bindMenu(); bindEditor(); }; if (documentRef.readyState === 'loading') documentRef.addEventListener('DOMContentLoaded', initialize, { once: true }); else initialize(); }
  return { PLACEHOLDER_IMAGE, appendAddOn, appendOptionGroup, clearValidationState, editorFieldName, ensureDraftId, validateMenuItem, validateMenuItemForStatus, menuItemFromDraft, editorGroupsFromServer, editorDataFromServer, serverPayload, loadSnapshot, renderMenu };
}));
