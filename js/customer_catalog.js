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
  const restaurantIdFor = name => text(name).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  const storedRestaurantState = () => {
    if (!root) return {};
    if (root.SavoraRestaurantState && typeof root.SavoraRestaurantState.load === 'function') return root.SavoraRestaurantState.load();
    try { return JSON.parse(root.localStorage.getItem(restaurantStateKey) || '{}'); } catch (_) { return {}; }
  };
  const copy = value => JSON.parse(JSON.stringify(value));
  const ownerFor = profile => ({
    id: text(profile && profile.id).trim() || 'savora-kitchen',
    name: text(profile && profile.name).trim() || 'Savora Kitchen',
    cuisine: text(profile && profile.cuisine).trim()
  });
  const allowedOverride = (item, owner) => {
    const source = item && typeof item === 'object' && !Array.isArray(item) ? item : {};
    return {
      id: text(source.id), restaurantId: owner.id, restaurantName: owner.name,
      name: text(source.name), description: text(source.description), category: text(source.category),
      image: localCatalogImage(source.image), price: number(source.price), available: source.available !== false
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
    const owner = ownerFor(source.profile);
    const overrides = Array.isArray(source.menuItems) ? source.menuItems.map(item => allowedOverride(item, owner)).filter(item => item.id) : [];
    overrides.forEach(override => {
      const product = products[override.id] || {
        id: override.id, name: 'Menu item', description: '', category: 'menu', categories: ['menu'],
        image: placeholderImage, price: 0, available: true, portions: [], addOns: []
      };
      if (override.name) product.name = override.name;
      if (override.description) product.description = override.description;
      if (override.category) { product.category = override.category; product.categories = [override.category]; }
      if (override.image) product.image = override.image;
      if (Number.isFinite(Number(override.price)) && override.price > 0) product.price = override.price;
      product.available = override.available;
      product.restaurantId = override.restaurantId;
      product.restaurant = override.restaurantName;
      product.restaurantName = override.restaurantName;
      products[override.id] = product;
      Object.values(restaurants).forEach(restaurant => {
        restaurant.productIds = (Array.isArray(restaurant.productIds) ? restaurant.productIds : []).filter(id => String(id) !== override.id);
      });
      if (!restaurants[owner.name]) restaurants[owner.name] = {
        name: owner.name, cuisine: owner.cuisine || 'Local kitchen', rating: 0, prepTime: '', productIds: []
      };
      const ids = restaurants[owner.name].productIds;
      if (!ids.includes(override.id)) ids.push(override.id);
    });
    return { products, restaurants };
  }
  const merged = applyRestaurantOverrides(baseProducts, baseRestaurants, storedRestaurantState());
  const { products, restaurants } = merged;
  const imageFor = product => product && localCatalogImage(product.image)
    ? product.image
    : placeholderImage;
  const api = { products, restaurants, categories, recommendations: ['1', '2', '3', '4'], placeholderImage, imageFor, applyRestaurantOverrides };
  const freeze = value => {
    Object.values(value).forEach(child => child && typeof child === 'object' && !Object.isFrozen(child) && freeze(child));
    return Object.freeze(value);
  };
  return freeze(api);
}));
