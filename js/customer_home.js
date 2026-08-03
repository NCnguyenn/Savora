(function attachCustomerHome(root, factory) {
  const api = factory(root);
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraHome = api;
}(typeof window === 'undefined' ? globalThis : window, function createCustomerHome(root) {
  'use strict';

  const normalize = value => String(value || '').trim().toLowerCase();
  const matchesQuery = (item, query) => !query || normalize([
    item.name, item.restaurant, item.cuisine, item.slogan, item.categoryLabel
  ].join(' ')).includes(query);
  const cuisineMatches = (item, filter) => ['vietnamese', 'japanese', 'italian'].includes(filter)
    ? normalize(item.cuisine) === filter
    : true;
  const onePerRestaurant = items => {
    const seen = new Set();
    return items.filter(item => {
      const key = normalize(item.restaurant);
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    }).slice(0, 6);
  };

  function selectOverview(products, restaurants, selectedFilter = 'all', search = '') {
    const filter = normalize(selectedFilter) || 'all';
    const query = normalize(search);
    const visibleProducts = (Array.isArray(products) ? products : [])
      .filter(item => cuisineMatches(item, filter) && matchesQuery(item, query));
    const visibleRestaurants = (Array.isArray(restaurants) ? restaurants : [])
      .filter(item => cuisineMatches(item, filter) && matchesQuery(item, query));
    return {
      restaurants: visibleRestaurants,
      foods: filter === 'drinks' ? [] : onePerRestaurant(visibleProducts.filter(item => item.itemType === 'food')),
      drinks: filter === 'food' ? [] : onePerRestaurant(visibleProducts.filter(item => item.itemType === 'drink'))
    };
  }

  const money = value => `$${Number(value || 0).toFixed(2)}`;
  const text = value => String(value || '').trim();
  const restaurantValues = source => Object.values(source || {});
  const createElement = (documentRef, tag, attributes = {}, children = []) => {
    const node = documentRef.createElement(tag);
    Object.entries(attributes).forEach(([name, value]) => {
      if (name === 'className') node.className = value;
      else if (name === 'text') node.textContent = value;
      else if (value !== undefined && value !== null) node.setAttribute(name, String(value));
    });
    (Array.isArray(children) ? children : [children]).filter(Boolean).forEach(child => node.append(child));
    return node;
  };

  function initBrowser() {
    const documentRef = root && root.document;
    const catalog = root && root.SavoraCatalog;
    const apiClient = root && root.SavoraApi;
    if (!documentRef || !catalog || !apiClient) return;

    const controls = documentRef.getElementById('home-filter-controls');
    const searchInput = documentRef.getElementById('search-input');
    const searchForm = documentRef.getElementById('discovery-search-form');
    const restaurantGrid = documentRef.getElementById('featured-restaurants-grid');
    const foodGrid = documentRef.getElementById('popular-food-grid');
    const drinkGrid = documentRef.getElementById('popular-drink-grid');
    if (!controls || !searchInput || !searchForm || !restaurantGrid || !foodGrid || !drinkGrid) return;

    let profileSnapshot = { favorites: [] };
    let selectedFilter = 'all';
    const isAuthenticated = root.SavoraCustomerAuthenticated === true;
    const ui = root.SavoraUI;

    const favoriteSaved = (type, publicId) => (profileSnapshot.favorites || [])
      .some(item => item.type === type && String(item.publicId) === String(publicId));

    const renderFavorite = (type, publicId, name) => {
      const value = String(publicId);
      const button = createElement(documentRef, 'button', {
        className: 'discovery-favorite-button home-card-favorite', type: 'button',
        'aria-pressed': favoriteSaved(type, value),
        'aria-label': `${favoriteSaved(type, value) ? 'Remove' : 'Add'} ${name} ${favoriteSaved(type, value) ? 'from' : 'to'} favorites`,
        title: `${favoriteSaved(type, value) ? 'Remove' : 'Add'} ${name} ${favoriteSaved(type, value) ? 'from' : 'to'} favorites`
      });
      const paint = saved => {
        button.setAttribute('aria-pressed', String(saved));
        button.setAttribute('aria-label', `${saved ? 'Remove' : 'Add'} ${name} ${saved ? 'from' : 'to'} favorites`);
        button.setAttribute('title', `${saved ? 'Remove' : 'Add'} ${name} ${saved ? 'from' : 'to'} favorites`);
        button.replaceChildren(createElement(documentRef, 'i', {
          className: `fa-${saved ? 'solid' : 'regular'} fa-heart`, 'aria-hidden': 'true'
        }));
      };
      paint(favoriteSaved(type, value));
      button.addEventListener('click', async event => {
        event.preventDefault();
        event.stopPropagation();
        if (!isAuthenticated) {
          root.location.assign(`login.php?return_to=${encodeURIComponent('customer_dashboard.php')}`);
          return;
        }
        const active = !favoriteSaved(type, value);
        const scope = `customer-home-favorite-${type}-${value}`;
        button.disabled = true;
        try {
          await apiClient.post('api/profile.php', {
            action: 'set_favorite', payload: { type, publicId: value, active, version: 0 }
          }, apiClient.intentKey(scope));
          profileSnapshot = await apiClient.get('api/profile.php');
          apiClient.clearIntentKey(scope);
          paint(favoriteSaved(type, value));
          if (ui && typeof ui.announce === 'function') ui.announce(`${name} ${active ? 'added to' : 'removed from'} favorites.`);
        } catch (error) {
          if (ui && typeof ui.announce === 'function') ui.announce(error.message || 'Favorite was not changed.');
        } finally {
          button.disabled = false;
        }
      });
      return button;
    };

    const emptyState = (message, reset) => {
      const button = createElement(documentRef, 'button', { className: 'secondary-action', type: 'button', text: 'Clear filters' });
      button.addEventListener('click', reset);
      return createElement(documentRef, 'div', { className: 'home-empty-state', role: 'status' }, [
        createElement(documentRef, 'i', { className: 'fa-solid fa-seedling', 'aria-hidden': 'true' }),
        createElement(documentRef, 'p', { text: message }), button
      ]);
    };

    const restaurantCard = restaurant => {
      const menuItem = (restaurant.productIds || []).map(id => catalog.products[id]).find(Boolean);
      const image = catalog.imageFor({ image: restaurant.heroImage || restaurant.image });
      const link = createElement(documentRef, 'a', {
        className: 'home-card-link', href: `customer_restaurant.php?restaurant=${encodeURIComponent(restaurant.publicId || '')}`,
        'aria-label': `View ${restaurant.name} restaurant page`
      }, [
        createElement(documentRef, 'img', { className: 'home-card-image', src: image, alt: `${restaurant.name} food` }),
        createElement(documentRef, 'div', { className: 'home-card-body' }, [
          createElement(documentRef, 'h3', { text: restaurant.name }),
          createElement(documentRef, 'p', { className: 'home-card-slogan', text: restaurant.slogan || 'Thoughtful local food, served simply.' }),
          createElement(documentRef, 'p', { className: 'home-card-meta' }, [
            createElement(documentRef, 'span', { text: `★ ${restaurant.rating || 'New'}` }),
            createElement(documentRef, 'span', { text: restaurant.cuisine || 'Local kitchen' }),
            createElement(documentRef, 'span', { text: restaurant.prepTime || (menuItem && menuItem.prepTime) || 'Prepared to order' })
          ]),
          createElement(documentRef, 'span', { className: 'home-card-action', text: 'View restaurant →' })
        ])
      ]);
      const brand = createElement(documentRef, 'div', { className: 'home-restaurant-brand' }, [
        createElement(documentRef, 'img', { className: 'home-restaurant-logo', src: catalog.logoFor(restaurant), alt: `${restaurant.name} logo` })
      ]);
      return createElement(documentRef, 'article', { className: 'home-restaurant-card' }, [
        brand, link, renderFavorite('restaurant', restaurant.publicId, restaurant.name)
      ]);
    };

    const productCard = item => {
      const restaurant = catalog.restaurants[item.restaurant] || {};
      return createElement(documentRef, 'article', { className: 'home-product-card' }, [
        createElement(documentRef, 'a', {
          className: 'home-card-link', href: `product_detail.php?id=${encodeURIComponent(item.id)}`,
          'aria-label': `View ${item.name} from ${item.restaurant}`
        }, [
          createElement(documentRef, 'img', { className: 'home-card-image', src: catalog.imageFor(item), alt: '' }),
          createElement(documentRef, 'div', { className: 'home-card-body' }, [
            createElement(documentRef, 'h3', { text: item.name }),
            createElement(documentRef, 'p', { text: `${item.restaurant} · ${item.categoryLabel || 'Menu'}` }),
            createElement(documentRef, 'strong', { className: 'home-card-price', text: money(item.price) })
          ])
        ]),
        restaurant.publicId ? renderFavorite('product', item.id, item.name) : null
      ]);
    };

    const render = () => {
      const products = Object.values(catalog.products || {});
      const restaurants = restaurantValues(catalog.restaurants);
      const result = selectOverview(products, restaurants, selectedFilter, searchInput.value);
      const reset = () => { selectedFilter = 'all'; searchInput.value = ''; updateControls(); render(); searchInput.focus(); };
      restaurantGrid.replaceChildren(...(result.restaurants.length ? result.restaurants.map(restaurantCard) : [emptyState('No restaurants match your search.', reset)]));
      foodGrid.replaceChildren(...(result.foods.length ? result.foods.map(productCard) : [emptyState('No dishes match your search.', reset)]));
      drinkGrid.replaceChildren(...(result.drinks.length ? result.drinks.map(productCard) : [emptyState('No drinks match your search.', reset)]));
      const count = (id, value, label) => {
        const node = documentRef.getElementById(id);
        if (node) node.textContent = `${value} ${label}${value === 1 ? '' : 's'}`;
      };
      count('restaurant-result-count', result.restaurants.length, 'restaurant');
      count('food-result-count', result.foods.length, 'dish');
      count('drink-result-count', result.drinks.length, 'drink');
    };

    const filterOptions = [
      ['all', 'All', 'fa-grip'], ['vietnamese', 'Vietnamese', 'fa-bowl-food'],
      ['japanese', 'Japanese', 'fa-fish'], ['italian', 'Italian', 'fa-pizza-slice'],
      ['food', 'Food', 'fa-utensils'], ['drinks', 'Drinks', 'fa-mug-hot']
    ];
    const updateControls = () => controls.querySelectorAll('[data-home-filter]').forEach(button => {
      button.setAttribute('aria-pressed', String(button.dataset.homeFilter === selectedFilter));
    });
    controls.replaceChildren(...filterOptions.map(([value, label, icon]) => {
      const button = createElement(documentRef, 'button', {
        className: 'category-card', type: 'button', 'data-home-filter': value, 'aria-pressed': value === selectedFilter
      }, [createElement(documentRef, 'i', { className: `fa-solid ${icon}`, 'aria-hidden': 'true' }), createElement(documentRef, 'span', { text: label })]);
      button.addEventListener('click', () => { selectedFilter = value; updateControls(); render(); });
      return button;
    }));
    searchInput.addEventListener('input', render);
    searchForm.addEventListener('submit', event => { event.preventDefault(); render(); });

    const hydrate = async () => {
      try {
        await catalog.hydrate();
        if (isAuthenticated) {
          try { profileSnapshot = await apiClient.get('api/profile.php'); } catch (_) { profileSnapshot = { favorites: [] }; }
        }
        render();
      } catch (error) {
        const message = error.message || 'Catalog is temporarily unavailable.';
        restaurantGrid.replaceChildren(emptyState(message, hydrate));
        foodGrid.replaceChildren(emptyState(message, hydrate));
        drinkGrid.replaceChildren(emptyState(message, hydrate));
      }
    };
    hydrate();
  }

  if (root && root.document) {
    if (root.document.readyState === 'loading') root.document.addEventListener('DOMContentLoaded', initBrowser, { once: true });
    else initBrowser();
  }

  return { selectOverview, initBrowser };
}));
