(function attachCatalog(root, factory) {
  const api = factory(root);
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraCatalog = api;
}(typeof window === 'undefined' ? null : window, function createCatalog(root) {
  const baseProducts = {
    '1': {
      id: '1', restaurant: 'Burger King', category: 'burger', categories: ['burger', 'combo'],
      name: 'Mega Burger Feast Combo', image: 'assets/images/catalog/mega-burger-feast-combo.jpg',
      price: 14.99, description: 'A flame-grilled double beef burger with crisp lettuce, tomato, fries and a chilled soft drink.',
      prepTime: '15-20 min', calories: 850, dietaryTags: ['high-protein'], allergens: ['gluten', 'milk', 'sesame'],
      ingredients: ['double beef patty', 'brioche bun', 'lettuce', 'tomato', 'signature sauce'],
      portions: [{ id: 'regular', label: 'Regular combo', price: 0 }, { id: 'large', label: 'Large fries combo', price: 2 }],
      addOns: [{ id: '1-coke', productId: '1', label: 'Coca-Cola (medium)', price: 1.5 }, { id: '1-cheese', productId: '1', label: 'Extra cheddar', price: 1 }]
    },
    '2': {
      id: '2', restaurant: 'Pizza Hut', category: 'pizza', categories: ['pizza', 'italian'],
      name: 'Supreme Pepperoni Pizza 12\"', image: 'assets/images/catalog/supreme-pepperoni-pizza.jpg',
      price: 13.99, description: 'A 12-inch hand-tossed pizza layered with pepperoni, mozzarella, roasted peppers and oregano tomato sauce.',
      prepTime: '25-30 min', calories: 920, dietaryTags: ['contains-meat'], allergens: ['gluten', 'milk'],
      ingredients: ['hand-tossed dough', 'pepperoni', 'mozzarella', 'bell pepper', 'oregano tomato sauce'],
      portions: [{ id: 'medium', label: '12-inch medium', price: 0 }, { id: 'large', label: '14-inch large', price: 4 }],
      addOns: [{ id: '2-cheese', productId: '2', label: 'Extra mozzarella', price: 1.75 }, { id: '2-dip', productId: '2', label: 'Garlic herb dip', price: 0.75 }]
    },
    '3': {
      id: '3', restaurant: 'Tokyo Sushi', category: 'sushi', categories: ['sushi', 'japanese'],
      name: 'Deluxe Salmon & Tuna Sushi Set', image: 'assets/images/catalog/deluxe-salmon-tuna-sushi.jpg',
      price: 24.99, description: 'A chef-selected set of salmon and tuna nigiri, spicy tuna maki and pickled ginger.',
      prepTime: '20-25 min', calories: 620, dietaryTags: ['pescatarian', 'gluten-aware'], allergens: ['fish', 'soy', 'sesame'],
      ingredients: ['salmon nigiri', 'tuna nigiri', 'sushi rice', 'nori', 'spicy tuna', 'pickled ginger'],
      portions: [{ id: 'set', label: '18-piece deluxe set', price: 0 }, { id: 'party', label: '28-piece party set', price: 12 }],
      addOns: [{ id: '3-miso', productId: '3', label: 'Miso soup', price: 2 }, { id: '3-wasabi', productId: '3', label: 'Fresh wasabi', price: 0.5 }]
    },
    '4': {
      id: '4', restaurant: 'Savora Boba Bar', category: 'boba', categories: ['boba', 'drinks'],
      name: 'Brown Sugar Boba Milk Tea', image: 'assets/images/catalog/brown-sugar-boba-milk-tea.jpg',
      price: 5.5, description: 'Creamy Assam milk tea swirled with brown sugar syrup and slow-cooked tapioca pearls.',
      prepTime: '10 min', calories: 380, dietaryTags: ['vegetarian'], allergens: ['milk'],
      ingredients: ['Assam black tea', 'whole milk', 'brown sugar syrup', 'tapioca pearls'],
      portions: [{ id: 'regular', label: 'Regular 500 ml', price: 0 }, { id: 'large', label: 'Large 700 ml', price: 1 }],
      addOns: [{ id: '4-pearls', productId: '4', label: 'Extra tapioca pearls', price: 0.75 }, { id: '4-pudding', productId: '4', label: 'Egg pudding', price: 0.9 }]
    }
  };
  const baseRestaurants = {
    'Burger King': { name: 'Burger King', cuisine: 'American', rating: 4.5, prepTime: '15-20 min', productIds: ['1'] },
    'Pizza Hut': { name: 'Pizza Hut', cuisine: 'Italian', rating: 4.2, prepTime: '25-30 min', productIds: ['2'] },
    'Tokyo Sushi': { name: 'Tokyo Sushi', cuisine: 'Japanese', rating: 4.8, prepTime: '20-25 min', productIds: ['3'] },
    'Savora Boba Bar': { name: 'Savora Boba Bar', cuisine: 'Tea & desserts', rating: 4.7, prepTime: '10 min', productIds: ['4'] }
  };
  const categories = [
    { id: 'burger', label: 'Burgers' }, { id: 'pizza', label: 'Pizza' },
    { id: 'sushi', label: 'Sushi' }, { id: 'boba', label: 'Boba' }
  ];
  const placeholderImage = 'assets/images/food-placeholder.svg';
  const restaurantStateKey = 'savora_restaurant_state_v1';
  const text = value => typeof value === 'string' ? value.slice(0, 500) : '';
  const localCatalogImage = value => {
    const image = text(value);
    return /^assets\/images\/catalog\/[a-z0-9][a-z0-9-]*\.(?:jpg|jpeg|png|webp)$/.test(image) ? image : '';
  };
  const number = value => Number.isFinite(Number(value)) ? Math.max(0, Number(value)) : 0;
  const textList = value => Array.isArray(value) ? value.map(text).filter(Boolean).slice(0, 20) : [];
  const menuOptions = value => Array.isArray(value) ? value.map(option => {
    const source = option && typeof option === 'object' && !Array.isArray(option) ? option : {};
    const label = text(source.label);
    return label ? { label, price: number(source.price) } : null;
  }).filter(Boolean).slice(0, 20) : [];
  const menuOptionGroups = value => Array.isArray(value) ? value.map(group => {
    const source = group && typeof group === 'object' && !Array.isArray(group) ? group : {};
    const name = text(source.name);
    return name ? { name, required: source.required === true, options: menuOptions(source.options) } : null;
  }).filter(Boolean).slice(0, 8) : [];
  const optionId = (itemId, kind, index) => `${itemId}-${kind}-${index + 1}`;
  const restaurantIdFor = name => text(name).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  const WEEK_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
  const clock = value => /^([01]\d|2[0-3]):[0-5]\d$/.test(text(value)) ? text(value) : '';
  const bounded = (value, minimum, maximum, fallback) => {
    const parsed = value !== null && value !== '' && Number.isFinite(Number(value)) ? Number(value) : null;
    return parsed === null ? fallback : Math.min(maximum, Math.max(minimum, parsed));
  };
  const weeklyHours = value => {
    const source = value && typeof value === 'object' && !Array.isArray(value) ? value : {};
    return Object.fromEntries(WEEK_DAYS.map(day => {
      const entry = source[day] && typeof source[day] === 'object' ? source[day] : {};
      return entry.closed === true ? [day, { open: '', close: '', closed: true }] : [day, { open: clock(entry.open) || '09:00', close: clock(entry.close) || '17:00', closed: false }];
    }));
  };
  const specialHours = value => Array.isArray(value) ? value.map(entry => {
    const source = entry && typeof entry === 'object' && !Array.isArray(entry) ? entry : {};
    const date = /^\d{4}-\d{2}-\d{2}$/.test(text(source.date)) ? text(source.date) : '';
    if (!date) return null;
    return source.closed === true ? { date, closed: true, note: text(source.note) } : { date, open: clock(source.open) || '09:00', close: clock(source.close) || '17:00', closed: false, note: text(source.note) };
  }).filter(Boolean).slice(0, 24) : [];
  const localDateKey = value => {
    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const month = String(date.getMonth() + 1).padStart(2, '0'); const day = String(date.getDate()).padStart(2, '0');
    return `${date.getFullYear()}-${month}-${day}`;
  };
  const minutes = value => {
    const match = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(text(value));
    return match ? Number(match[1]) * 60 + Number(match[2]) : null;
  };
  function storefrontStatus(operations, now = new Date()) {
    const settings = operations && typeof operations === 'object' && !Array.isArray(operations) ? operations : {};
    if (settings.acceptingOrders === false) return { isOpen: false, status: 'Orders paused' };
    const date = now instanceof Date ? now : new Date(now);
    if (Number.isNaN(date.getTime())) return { isOpen: false, status: 'Closed now' };
    const dateKey = localDateKey(date);
    const special = specialHours(settings.specialHours).find(entry => entry.date === dateKey);
    const day = WEEK_DAYS[(date.getDay() + 6) % 7];
    const hours = special || weeklyHours(settings.weeklyHours)[day];
    if (!hours || hours.closed) return { isOpen: false, status: special ? 'Closed today' : 'Closed now' };
    const open = minutes(hours.open); const close = minutes(hours.close); const current = date.getHours() * 60 + date.getMinutes();
    if (open === null || close === null) return { isOpen: false, status: 'Closed now' };
    const isOpen = close > open ? current >= open && current < close : current >= open || current < close;
    return { isOpen, status: isOpen ? 'Open for orders' : 'Closed now' };
  }
  const storedRestaurantState = () => {
    if (!root) return {};
    if (root.SavoraRestaurantState && typeof root.SavoraRestaurantState.load === 'function') return root.SavoraRestaurantState.load();
    try { return JSON.parse(root.localStorage.getItem(restaurantStateKey) || '{}'); } catch (_) { return {}; }
  };
  const copy = value => JSON.parse(JSON.stringify(value));
  const ownerFor = (profile, operations) => {
    const source = profile && typeof profile === 'object' ? profile : {};
    const settings = operations && typeof operations === 'object' ? operations : {};
    const acceptingOrders = settings.acceptingOrders !== false;
    const availability = storefrontStatus(settings);
    return {
      id: text(source.id).trim() || 'savora-kitchen', name: text(source.name).trim() || 'Savora Kitchen', cuisine: text(source.cuisine).trim(),
      image: localCatalogImage(source.image) || placeholderImage, description: text(source.description), address: text(source.address),
      deliveryEnabled: settings.deliveryEnabled !== false, pickupEnabled: settings.pickupEnabled !== false,
      deliveryRadius: bounded(settings.deliveryRadius, 0.1, 50, 5), prepMinutes: bounded(settings.prepMinutes, 1, 180, 20),
      acceptingOrders, isOpen: availability.isOpen, status: availability.status, weeklyHours: weeklyHours(settings.weeklyHours), specialHours: specialHours(settings.specialHours)
    };
  };
  const allowedOverride = (item, owner) => {
    const source = item && typeof item === 'object' && !Array.isArray(item) ? item : {};
    return {
      id: text(source.id), restaurantId: owner.id, restaurantName: owner.name,
      name: text(source.name), description: text(source.description), category: text(source.category),
      image: localCatalogImage(source.image), price: number(source.price), available: source.available !== false,
      status: source.status === 'draft' ? 'draft' : 'published', prepTime: number(source.prepTime),
      dietaryTags: textList(source.dietaryTags), optionGroups: menuOptionGroups(source.optionGroups), addOns: menuOptions(source.addOns)
    };
  };
  function applyRestaurantOverrides(sourceProducts, sourceRestaurants, state) {
    const products = copy(sourceProducts && typeof sourceProducts === 'object' ? sourceProducts : {});
    const restaurants = copy(sourceRestaurants && typeof sourceRestaurants === 'object' ? sourceRestaurants : {});
    Object.values(products).forEach(product => {
      product.restaurantId = text(product.restaurantId).trim() || restaurantIdFor(product.restaurant);
      product.restaurantName = text(product.restaurantName).trim() || text(product.restaurant);
      product.available = product.available !== false;
      product.image = localCatalogImage(product.image) || placeholderImage;
    });
    const source = state && typeof state === 'object' && !Array.isArray(state) ? state : {};
    const owner = ownerFor(source.profile, source.operations);
    if (!Object.hasOwn(restaurants, owner.name)) Object.defineProperty(restaurants, owner.name, {
      value: { name: owner.name, cuisine: owner.cuisine || 'Local kitchen', rating: 0, productIds: [] },
      enumerable: true, configurable: true, writable: true
    });
    const ownerRestaurant = restaurants[owner.name];
    Object.assign(ownerRestaurant, owner, { productIds: Array.isArray(ownerRestaurant.productIds) ? ownerRestaurant.productIds : [] });
    ownerRestaurant.prepTime = `${owner.prepMinutes} min`;
    const overrides = Array.isArray(source.menuItems) ? source.menuItems.map(item => allowedOverride(item, owner)).filter(item => item.id) : [];
    overrides.filter(override => override.status !== 'draft').forEach(override => {
      const existing = products[override.id];
      const product = existing || {
        id: override.id, name: 'Menu item', description: '', category: 'menu', categories: ['menu'],
        image: placeholderImage, price: 0, available: true, prepTime: '20 min', calories: 0,
        dietaryTags: [], allergens: [], ingredients: [], portions: [{ id: 'regular', label: 'Regular', price: 0 }], addOns: []
      };
      if (override.name) product.name = override.name;
      if (override.description) product.description = override.description;
      if (override.category) { product.category = override.category; product.categories = [override.category]; }
      if (override.image) product.image = override.image;
      if (Number.isFinite(Number(override.price)) && override.price > 0) product.price = override.price;
      product.prepTime = `${override.prepTime || 20} min`;
      product.calories = number(product.calories);
      product.dietaryTags = override.dietaryTags;
      product.allergens = textList(product.allergens);
      product.ingredients = textList(product.ingredients);
      const portionOptions = override.optionGroups.find(group => group.options.length)?.options || [];
      if (portionOptions.length || !existing) product.portions = (portionOptions.length ? portionOptions : [{ label: 'Regular', price: 0 }]).map((option, index) => ({ id: optionId(override.id, 'portion', index), label: option.label, price: option.price }));
      else product.portions = Array.isArray(product.portions) && product.portions.length ? product.portions : [{ id: optionId(override.id, 'portion', 0), label: 'Regular', price: 0 }];
      product.addOns = override.addOns.map((option, index) => ({ id: optionId(override.id, 'addon', index), productId: override.id, label: option.label, price: option.price }));
      product.available = override.available;
      product.restaurantId = override.restaurantId;
      product.restaurant = override.restaurantName;
      product.restaurantName = override.restaurantName;
      products[override.id] = product;
      Object.values(restaurants).forEach(restaurant => {
        restaurant.productIds = (Array.isArray(restaurant.productIds) ? restaurant.productIds : []).filter(id => String(id) !== override.id);
      });
      const ids = restaurants[owner.name].productIds;
      if (!ids.includes(override.id)) ids.push(override.id);
      restaurants[owner.name].prepTime = `${owner.prepMinutes} min`;
    });
    return { products, restaurants };
  }
  const merged = applyRestaurantOverrides(baseProducts, baseRestaurants, storedRestaurantState());
  const { products, restaurants } = merged;
  const imageFor = product => product && localCatalogImage(product.image)
    ? product.image
    : placeholderImage;
  const api = { products, restaurants, categories, recommendations: ['1', '2', '3', '4'], placeholderImage, imageFor, applyRestaurantOverrides, storefrontStatus };
  const freeze = value => {
    Object.values(value).forEach(child => child && typeof child === 'object' && !Object.isFrozen(child) && freeze(child));
    return Object.freeze(value);
  };
  return freeze(api);
}));
