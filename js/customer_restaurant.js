(function attachCustomerRestaurant(root, factory) {
  const api = factory(root);
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraRestaurantPage = api;
}(typeof window === 'undefined' ? globalThis : window, function createCustomerRestaurant(root) {
  'use strict';

  const normalize = value => String(value || '').trim().toLowerCase();
  const money = value => `$${Number(value || 0).toFixed(2)}`;

  function filterItems(items, selectedFilter = 'all', query = '') {
    const filter = normalize(selectedFilter) || 'all';
    const needle = normalize(query);
    return (Array.isArray(items) ? items : []).filter(item => {
      const typeMatch = filter === 'food' ? item.itemType === 'food' : filter === 'drinks' ? item.itemType === 'drink' : true;
      const categoryMatch = !['all', 'food', 'drinks'].includes(filter) && filter
        ? normalize(item.category) === filter
        : true;
      const queryMatch = !needle || normalize([item.name, item.description, item.categoryLabel].join(' ')).includes(needle);
      return typeMatch && categoryMatch && queryMatch;
    });
  }

  function promotionCopy(promotion) {
    const code = String(promotion && promotion.code || 'offer');
    const minimum = Number(promotion && promotion.minimumOrder || 0);
    const value = money(promotion && promotion.discountValue);
    const discount = String(promotion && promotion.discountType || '').toLowerCase() === 'percentage'
      ? `${Number(promotion.discountValue || 0)}% off`
      : `${value} off`;
    return `${discount}${minimum > 0 ? ` orders over ${money(minimum)}` : ''} with code ${code}`;
  }

  function formatTime(value) {
    const match = String(value || '').match(/^(\d{1,2}):(\d{2})/);
    if (!match) return '';
    const hours = Number(match[1]);
    const suffix = hours >= 12 ? 'PM' : 'AM';
    const displayHours = hours % 12 || 12;
    return `${displayHours}:${match[2]} ${suffix}`;
  }

  function statusLabel(hours, now = new Date()) {
    const today = (Array.isArray(hours) ? hours : []).find(entry => Number(entry.weekday) === now.getDay());
    if (!today) return 'Hours unavailable';
    if (Number(today.is_closed) === 1) return 'Closed today';
    return `Open today until ${formatTime(today.closes_at) || 'late'}`;
  }

  function initBrowser() {
    const documentRef = root && root.document;
    const catalog = root && root.SavoraCatalog;
    const apiClient = root && root.SavoraApi;
    if (!documentRef || !catalog || !apiClient) return;

    const params = new URLSearchParams(root.location && root.location.search || '');
    const publicId = String(params.get('restaurant') || '').trim();
    const errorNode = documentRef.getElementById('restaurant-error');
    const setText = (id, value) => {
      const node = documentRef.getElementById(id);
      if (node) node.textContent = String(value || '');
      return node;
    };
    const createElement = (tag, attributes = {}, children = []) => {
      const node = documentRef.createElement(tag);
      Object.entries(attributes).forEach(([name, value]) => {
        if (name === 'className') node.className = value;
        else if (name === 'text') node.textContent = value;
        else if (value !== undefined && value !== null) node.setAttribute(name, String(value));
      });
      (Array.isArray(children) ? children : [children]).filter(Boolean).forEach(child => node.append(child));
      return node;
    };
    const ui = root.SavoraUI;
    const isAuthenticated = root.SavoraCustomerAuthenticated === true;
    let profileSnapshot = { favorites: [] };
    let restaurant = null;
    let items = [];
    let weeklyHours = [];
    let selectedFilter = 'all';
    let searchQuery = '';

    const showError = message => {
      if (errorNode) { errorNode.hidden = false; errorNode.textContent = message; }
      const content = documentRef.querySelector('.restaurant-hero-card');
      if (content) content.hidden = true;
    };
    const favoriteSaved = type => (profileSnapshot.favorites || [])
      .some(item => item.type === type && String(item.publicId) === String(restaurant && restaurant.publicId));

    const paintFavorite = button => {
      const saved = favoriteSaved('restaurant');
      button.setAttribute('aria-pressed', String(saved));
      button.classList.toggle('is-saved', saved);
      button.replaceChildren(
        createElement('i', { className: `fa-${saved ? 'solid' : 'regular'} fa-heart`, 'aria-hidden': 'true' }),
        createElement('span', { text: saved ? 'Saved restaurant' : 'Save restaurant' })
      );
    };

    const renderFavoriteControl = () => {
      const button = documentRef.getElementById('restaurant-favorite-button');
      if (!button) return;
      paintFavorite(button);
      button.addEventListener('click', async () => {
        if (!restaurant) return;
        if (!isAuthenticated) {
          root.location.assign(`login.php?return_to=${encodeURIComponent(`customer_restaurant.php?restaurant=${restaurant.publicId}`)}`);
          return;
        }
        const active = !favoriteSaved('restaurant');
        const scope = `customer-storefront-favorite-${restaurant.publicId}`;
        button.disabled = true;
        try {
          await apiClient.post('api/profile.php', {
            action: 'set_favorite', payload: { type: 'restaurant', publicId: restaurant.publicId, active, version: 0 }
          }, apiClient.intentKey(scope));
          profileSnapshot = await apiClient.get('api/profile.php');
          apiClient.clearIntentKey(scope);
          paintFavorite(button);
          if (ui && typeof ui.announce === 'function') ui.announce(`${restaurant.name} ${active ? 'added to' : 'removed from'} favorites.`);
        } catch (error) {
          if (ui && typeof ui.announce === 'function') ui.announce(error.message || 'Favorite was not changed.');
        } finally { button.disabled = false; }
      });
    };

    const renderHero = () => {
      const heroImage = documentRef.getElementById('restaurant-hero-image');
      const logo = documentRef.getElementById('restaurant-logo');
      if (heroImage) { heroImage.src = catalog.imageFor({ image: restaurant.heroImage }); heroImage.alt = `${restaurant.name} food`; }
      if (logo) { logo.src = catalog.logoFor({ logoPath: restaurant.logoPath }); logo.alt = `${restaurant.name} logo`; }
      setText('restaurant-cuisine', restaurant.cuisine || 'Local kitchen');
      setText('storefront-name', restaurant.name);
      setText('storefront-slogan', restaurant.slogan || 'Thoughtful food, served simply.');
      setText('restaurant-description', restaurant.description || 'A local kitchen making every order with care.');
      setText('storefront-address', restaurant.address ? `${restaurant.address}${restaurant.city ? `, ${restaurant.city}` : ''}` : 'Address unavailable');
      const phone = documentRef.getElementById('restaurant-phone');
      if (phone) {
        phone.href = restaurant.phone ? `tel:${restaurant.phone}` : '#';
        phone.replaceChildren(createElement('i', { className: 'fa-solid fa-phone', 'aria-hidden': 'true' }), createElement('span', { text: restaurant.phone || 'Phone unavailable' }));
      }
      setText('restaurant-rating', restaurant.rating ? `★ ${Number(restaurant.rating).toFixed(1)}` : 'New');
      setText('restaurant-status', statusLabel(weeklyHours));
      setText('restaurant-item-count', items.length);
      documentRef.title = `${restaurant.name} | Savora`;
    };

    const renderPromotions = promotions => {
      const section = documentRef.getElementById('storefront-offers');
      const list = documentRef.getElementById('restaurant-promotions-list');
      if (!section || !list) return;
      list.replaceChildren(...(Array.isArray(promotions) ? promotions : []).map(promotion => createElement('article', { className: 'restaurant-promotion' }, [
        createElement('strong', { text: promotionCopy(promotion) }),
        createElement('code', { text: String(promotion.code || 'OFFER') })
      ])));
      section.hidden = !list.childElementCount;
    };

    const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const renderHours = () => {
      const list = documentRef.getElementById('storefront-hours-list');
      if (!list) return;
      const today = new Date().getDay();
      list.replaceChildren(...(Array.isArray(weeklyHours) ? weeklyHours : []).map(hour => {
        const closed = Number(hour.is_closed) === 1;
        return createElement('div', { className: `restaurant-hours-row${Number(hour.weekday) === today ? ' today' : ''}` }, [
          createElement('span', { text: dayNames[Number(hour.weekday)] || 'Day' }),
          createElement('strong', { text: closed ? 'Closed' : `${formatTime(hour.opens_at)} – ${formatTime(hour.closes_at)}` })
        ]);
      }));
    };

    const emptyMenu = message => createElement('p', { className: 'restaurant-menu-empty', role: 'status', text: message });
    const renderMenuCard = item => createElement('article', { className: 'restaurant-menu-card' }, [
      createElement('img', { src: catalog.imageFor(item), alt: '', loading: 'lazy' }),
      createElement('div', { className: 'restaurant-menu-card-body' }, [
        createElement('h4', { text: item.name }),
        createElement('p', { text: item.description || 'Prepared fresh to order.' }),
        createElement('div', { className: 'menu-card-meta' }, [
          createElement('strong', { text: money(item.price) }),
          createElement('span', { text: item.prepTime || 'Prepared to order' }),
          createElement('a', { className: 'home-card-action', href: `product_detail.php?id=${encodeURIComponent(item.id)}`, text: 'View details' })
        ])
      ])
    ]);

    const renderMenu = () => {
      const visible = filterItems(items, selectedFilter, searchQuery);
      const food = visible.filter(item => item.itemType === 'food');
      const drinks = visible.filter(item => item.itemType === 'drink');
      const foodGrid = documentRef.getElementById('storefront-food-grid');
      const drinksGrid = documentRef.getElementById('storefront-drink-grid');
      if (foodGrid) foodGrid.replaceChildren(...(food.length ? food.map(renderMenuCard) : [emptyMenu('No food items match this filter.')]));
      if (drinksGrid) drinksGrid.replaceChildren(...(drinks.length ? drinks.map(renderMenuCard) : [emptyMenu('No drinks match this filter.')]));
      setText('restaurant-menu-count', `${visible.length} ${visible.length === 1 ? 'item' : 'items'}`);
      setText('restaurant-food-count', `${food.length} ${food.length === 1 ? 'dish' : 'dishes'}`);
      setText('restaurant-drinks-count', `${drinks.length} ${drinks.length === 1 ? 'drink' : 'drinks'}`);
    };

    const updateFilters = () => documentRef.querySelectorAll('[data-restaurant-filter]').forEach(button => {
      button.setAttribute('aria-pressed', String(button.dataset.restaurantFilter === selectedFilter));
    });
    const renderFilters = () => {
      const container = documentRef.getElementById('restaurant-menu-filters');
      if (!container) return;
      const categories = [...new Map(items.map(item => [item.category, item.categoryLabel || item.category])).entries()];
      const options = [['all', 'All'], ['food', 'Food'], ['drinks', 'Drinks'], ...categories];
      container.replaceChildren(...options.map(([value, label]) => {
        const button = createElement('button', { className: 'restaurant-menu-filter', type: 'button', 'data-restaurant-filter': value, 'aria-pressed': value === selectedFilter, text: label });
        button.addEventListener('click', () => { selectedFilter = value; updateFilters(); renderMenu(); });
        return button;
      }));
    };

    const renderActiveOrder = async () => {
      const panel = documentRef.getElementById('storefront-active-order');
      const copy = documentRef.getElementById('storefront-active-order-copy');
      if (!panel || !copy || !isAuthenticated) return;
      try {
        const data = await apiClient.get('api/orders.php');
        const active = (Array.isArray(data && data.orders) ? data.orders : []).find(order => ['pending', 'confirmed', 'preparing', 'ready_for_pickup', 'assigned', 'picked_up', 'on_the_way'].includes(order.status));
        if (!active) return;
        copy.textContent = `Order ${active.id} is ${String(active.status || 'in progress').replaceAll('_', ' ')}.`;
        panel.hidden = false;
      } catch (_) { /* The storefront remains useful when order tracking is unavailable. */ }
    };

    const bindSearch = () => {
      const input = documentRef.getElementById('restaurant-menu-search');
      if (!input) return;
      input.addEventListener('input', () => { searchQuery = input.value; renderMenu(); });
    };

    const load = async () => {
      if (!publicId) { showError('Choose a restaurant from the Discover page.'); return; }
      try {
        const data = await apiClient.get(`api/restaurant_storefront.php?restaurant=${encodeURIComponent(publicId)}`);
        restaurant = data.restaurant;
        const rawItems = Array.isArray(data.items) ? data.items : [];
        weeklyHours = Array.isArray(data.weeklyHours) ? data.weeklyHours : [];
        catalog.replaceRecords(rawItems);
        items = Object.values(catalog.products || {});
        renderHero();
        renderPromotions(data.promotions);
        renderHours();
        renderFilters();
        renderMenu();
        renderFavoriteControl();
        if (isAuthenticated) {
          try { profileSnapshot = await apiClient.get('api/profile.php'); } catch (_) { profileSnapshot = { favorites: [] }; }
          const button = documentRef.getElementById('restaurant-favorite-button');
          if (button) paintFavorite(button);
        }
        bindSearch();
        renderActiveOrder();
      } catch (error) { showError(error.message || 'This restaurant is unavailable.'); }
    };
    load();
  }

  if (root && root.document) {
    if (root.document.readyState === 'loading') root.document.addEventListener('DOMContentLoaded', initBrowser, { once: true });
    else initBrowser();
  }

  return { filterItems, promotionCopy, statusLabel, initBrowser };
}));
