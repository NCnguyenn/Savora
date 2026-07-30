const test = require('node:test');
const assert = require('node:assert/strict');
const RestaurantState = require('../js/restaurant_state.js');
const Catalog = require('../js/customer_catalog.js');
const Menu = require('../js/restaurant_menu.js');
const Storefront = require('../js/restaurant_storefront.js');

test('accepts only valid order transitions and records an audit event', () => {
  const customer = { orders: [{ id: 'SVR-1', status: 'pending', items: [], total: 20 }] };
  const next = RestaurantState.updateOrderStatus(customer, 'SVR-1', 'confirmed', { prepMinutes: 20 });
  assert.equal(next.orders[0].status, 'confirmed');
  assert.equal(next.orders[0].prepMinutes, 20);
  assert.equal(next.orders[0].statusHistory.at(-1).status, 'confirmed');
  assert.throws(() => RestaurantState.updateOrderStatus(next, 'SVR-1', 'completed'), /transition/i);
});

test('menu and storefront changes normalize to safe customer overrides', () => {
  let state = RestaurantState.setItemAvailability(RestaurantState.defaultState(), '1', false);
  state = RestaurantState.setProfile(state, { name: 'Savora Kitchen', address: '<img onerror=alert(1)>' });
  assert.equal(state.menuItems.find(item => item.id === '1').available, false);
  assert.equal(state.profile.address, '<img onerror=alert(1)>');
  assert.equal(Object.hasOwn(state.profile, 'onerror'), false);
});

test('adds a new owned menu item to the safe Customer catalog and preserves availability', () => {
  let state = RestaurantState.setMenuItem(RestaurantState.defaultState(), {
    id: 'savora-special', name: 'Savora special', description: 'A local special', category: 'lunch',
    image: 'assets/images/catalog/mega-burger-feast-combo.jpg', price: 18
  });
  let catalog = Catalog.applyRestaurantOverrides(Catalog.products, Catalog.restaurants, state);

  assert.equal(catalog.products['savora-special'].restaurantId, 'savora-kitchen');
  assert.equal(catalog.products['savora-special'].restaurantName, 'Savora Kitchen');
  assert.equal(catalog.products['savora-special'].available, true);
  assert.ok(catalog.restaurants['Savora Kitchen'].productIds.includes('savora-special'));

  state = RestaurantState.setItemAvailability(state, 'savora-special', false);
  catalog = Catalog.applyRestaurantOverrides(Catalog.products, Catalog.restaurants, state);
  assert.equal(catalog.products['savora-special'].available, false);
});

test('rejects non-canonical catalog image paths and keeps default ownership consistent', () => {
  const invalidPaths = [
    'assets/images/catalog/../secret.jpg', 'assets\\images\\catalog\\dish.jpg',
    'assets/images/catalog/dish.jpg?size=2', 'assets/images/catalog/dish.jpg#preview',
    'assets/images/not-catalog/dish.jpg'
  ];
  for (const image of invalidPaths) {
    const state = RestaurantState.normalize({ profile: { image }, menuItems: [{ id: 'x', image }] });
    assert.equal(state.profile.image, '');
    assert.equal(state.menuItems[0].image, '');
    assert.equal(Catalog.imageFor({ image }), Catalog.placeholderImage);
  }
  const state = RestaurantState.defaultState();
  assert.equal(state.profile.id, state.menuItems[0].restaurantId);
  assert.equal(state.profile.name, state.menuItems[0].restaurantName);
});

test('creates own restaurant records for inherited profile names', () => {
  for (const name of ['constructor', 'toString', '__proto__']) {
    const catalog = Catalog.applyRestaurantOverrides(Catalog.products, Catalog.restaurants, {
      profile: { id: `id-${name}`, name },
      menuItems: [{ id: `item-${name}`, name: 'Safe menu item' }]
    });
    assert.ok(Object.hasOwn(catalog.restaurants, name));
    assert.ok(catalog.restaurants[name].productIds.includes(`item-${name}`));
  }
});

test('permits every Live Order Center transition and rejects terminal or skipped states', () => {
  const permitted = [
    ['pending', 'confirmed'], ['pending', 'cancelled'],
    ['confirmed', 'preparing'], ['confirmed', 'cancelled'],
    ['preparing', 'ready_for_pickup'], ['preparing', 'cancelled'],
    ['ready_for_pickup', 'on_the_way'], ['ready_for_pickup', 'completed'],
    ['on_the_way', 'completed']
  ];

  permitted.forEach(([from, to]) => {
    const customer = { orders: [{ id: `${from}-${to}`, status: from, items: [], total: 20 }] };
    const next = RestaurantState.updateOrderStatus(customer, `${from}-${to}`, to, { prepMinutes: 15 });
    assert.equal(next.orders[0].status, to, `${from} can move to ${to}`);
    assert.equal(next.orders[0].statusHistory.at(-1).actor, 'restaurant');
  });

  for (const [from, to] of [['pending', 'preparing'], ['confirmed', 'ready_for_pickup'], ['preparing', 'completed'], ['completed', 'confirmed'], ['cancelled', 'confirmed']]) {
    const customer = { orders: [{ id: `${from}-${to}`, status: from, items: [], total: 20 }] };
    assert.throws(() => RestaurantState.updateOrderStatus(customer, `${from}-${to}`, to), /transition/i);
  }
});

test('menu editor validates a publishable price, falls back from unsafe images, and preserves Customer-facing availability', () => {
  const invalid = Menu.validateMenuItem({ id: 'menu-unsafe', name: 'Unsafe special', category: 'lunch', price: '0', image: 'https://example.test/dish.jpg' });
  assert.equal(invalid.valid, false);
  assert.match(invalid.errors.price, /greater than zero/i);

  const item = Menu.menuItemFromDraft({
    id: 'menu-safe', name: 'Safe special', description: 'Local only', category: 'lunch',
    price: '12.50', image: 'assets/images/catalog/../secret.jpg', available: false,
    optionGroups: [{ name: 'Size', required: true, options: [{ label: 'Regular', price: '0' }] }],
    addOns: [{ label: 'Extra herb', price: '1.25' }], stock: '3', prepTime: '15', dietaryTags: ['vegetarian']
  });
  assert.equal(item.image, 'assets/images/food-placeholder.svg');
  assert.equal(item.price, 12.5);
  assert.equal(item.available, false);
  assert.equal(item.optionGroups[0].options[0].price, 0);

  const state = RestaurantState.setMenuItem(RestaurantState.defaultState(), item);
  const catalog = Catalog.applyRestaurantOverrides(Catalog.products, Catalog.restaurants, state);
  assert.equal(catalog.products['menu-safe'].available, false);
  assert.equal(catalog.products['menu-safe'].price, 12.5);
});

test('menu editor maps camel-case state fields back to their labelled form controls', () => {
  assert.equal(Menu.editorFieldName('taxCategory'), 'menu-tax-category');
  assert.equal(Menu.editorFieldName('prepTime'), 'menu-prep-time');
  assert.equal(Menu.editorFieldName('compareAtPrice'), 'menu-compare-price');
});

test('menu editor creates labelled option groups and add-ons from their form values', () => {
  const groups = Menu.appendOptionGroup([], { name: 'Choose a size', required: true, optionLabel: 'Large', optionPrice: '2.00' });
  const addOns = Menu.appendAddOn([], { label: 'Extra parmesan', price: '1.25' });
  assert.deepEqual(groups, [{ name: 'Choose a size', required: true, options: [{ label: 'Large', price: 2 }] }]);
  assert.deepEqual(addOns, [{ label: 'Extra parmesan', price: 1.25 }]);
});

test('published Restaurant items expose the complete Customer product contract', () => {
  const state = RestaurantState.setMenuItem(RestaurantState.defaultState(), {
    id: 'customer-ready', name: 'Customer ready bowl', description: 'Safe and complete', category: 'lunch',
    price: 14, prepTime: 12, dietaryTags: ['vegan'], status: 'published',
    optionGroups: [{ name: 'Choose a size', required: true, options: [{ label: 'Regular', price: 0 }, { label: 'Large', price: 3 }] }],
    addOns: [{ label: 'Extra herbs', price: 1.5 }]
  });
  const product = Catalog.applyRestaurantOverrides(Catalog.products, Catalog.restaurants, state).products['customer-ready'];

  assert.deepEqual(product.categories, ['lunch']);
  assert.equal(product.prepTime, '12 min');
  assert.equal(product.calories, 0);
  assert.deepEqual(product.dietaryTags, ['vegan']);
  assert.deepEqual(product.allergens, []);
  assert.deepEqual(product.ingredients, []);
  assert.deepEqual(product.portions.map(({ label, price }) => ({ label, price })), [{ label: 'Regular', price: 0 }, { label: 'Large', price: 3 }]);
  assert.deepEqual(product.addOns.map(({ productId, label, price }) => ({ productId, label, price })), [{ productId: 'customer-ready', label: 'Extra herbs', price: 1.5 }]);
  assert.doesNotThrow(() => product.portions.map(portion => portion.id).concat(product.addOns.filter(option => option.productId === product.id), product.dietaryTags, product.allergens, product.ingredients));
});

test('draft validation allows safe partial items with a stable local id and keeps them out of Customer catalog', () => {
  const holder = { dataset: {} };
  const firstId = Menu.ensureDraftId(holder, () => 123456);
  const secondId = Menu.ensureDraftId(holder, () => 999999);
  assert.equal(firstId, secondId);

  const partial = { id: firstId, description: 'Still writing this dish', status: 'draft' };
  assert.equal(Menu.validateMenuItemForStatus(partial, 'draft').valid, true);
  assert.equal(Menu.validateMenuItemForStatus(partial, 'published').valid, false);

  const state = RestaurantState.setMenuItem(RestaurantState.defaultState(), Menu.menuItemFromDraft(partial));
  assert.equal(state.menuItems.find(item => item.id === firstId).description, 'Still writing this dish');
  const catalog = Catalog.applyRestaurantOverrides(Catalog.products, Catalog.restaurants, state);
  assert.equal(Object.hasOwn(catalog.products, firstId), false);
});

test('menu validation clears stale aria-invalid state before reporting current errors', () => {
  const fields = new Map(['name', 'category', 'price', 'compareAtPrice'].map(key => {
    const field = {
      invalid: true,
      setAttribute(name) { if (name === 'aria-invalid') this.invalid = true; },
      removeAttribute(name) { if (name === 'aria-invalid') this.invalid = false; }
    };
    return [Menu.editorFieldName(key), field];
  }));
  const form = { elements: { namedItem: name => fields.get(name) || null } };

  Menu.clearValidationState(form);

  assert.equal([...fields.values()].some(field => field.invalid), false);
});

test('storefront profile and operations retain safe manual and current-location settings', () => {
  let state = RestaurantState.setProfile(RestaurantState.defaultState(), {
    name: 'Savora <Kitchen>',
    addressLine1: '123 Market Street', addressLine2: 'Suite 4', city: 'Bangkok', state: 'Bangkok', postalCode: '10110', country: 'Thailand',
    latitude: 13.7563, longitude: 100.5018, locationMethod: 'current'
  });
  state = RestaurantState.setOperations(state, {
    acceptingOrders: false, deliveryRadius: 8, capacity: 42, deliveryEnabled: true, pickupEnabled: true,
    pickupInstructions: 'Ask for the host',
    weeklyHours: { monday: { open: '09:00', close: '21:00', closed: false }, sunday: { closed: true } },
    specialHours: [{ date: '2026-12-25', closed: true, note: 'Holiday' }]
  });

  assert.equal(state.profile.addressLine1, '123 Market Street');
  assert.equal(state.profile.locationMethod, 'current');
  assert.equal(state.profile.latitude, 13.7563);
  assert.equal(state.operations.acceptingOrders, false);
  assert.equal(state.operations.deliveryRadius, 8);
  assert.equal(state.operations.capacity, 42);
  assert.deepEqual(state.operations.weeklyHours.monday, { open: '09:00', close: '21:00', closed: false });
  assert.equal(state.operations.weeklyHours.sunday.closed, true);
  assert.deepEqual(state.operations.specialHours, [{ date: '2026-12-25', closed: true, note: 'Holiday' }]);
});

test('storefront helpers normalize weekly hours and reject invalid delivery settings', () => {
  const hours = Storefront.normalizeWeeklyHours({ monday: { open: '8:00', close: '26:00' }, tuesday: { open: '10:30', close: '18:00' } });
  assert.deepEqual(hours.monday, { open: '09:00', close: '17:00', closed: false });
  assert.deepEqual(hours.tuesday, { open: '10:30', close: '18:00', closed: false });
  assert.match(Storefront.validateOperations({ deliveryRadius: '-1', capacity: '0' }).errors.deliveryRadius, /between/i);
  assert.match(Storefront.validateOperations({ deliveryRadius: '5', capacity: '0' }).errors.capacity, /between/i);
});

test('storefront coordinates require a complete valid pair and manual address mode clears it', () => {
  const initial = RestaurantState.defaultState();
  assert.equal(initial.profile.latitude, null);
  assert.equal(initial.profile.longitude, null);

  const incomplete = RestaurantState.setProfile(initial, { locationMethod: 'current', latitude: 13.7 });
  assert.equal(incomplete.profile.latitude, null);
  assert.equal(incomplete.profile.longitude, null);
  assert.equal(incomplete.profile.locationMethod, 'manual');

  const located = RestaurantState.setProfile(initial, { locationMethod: 'current', latitude: 13.7563, longitude: 100.5018 });
  assert.equal(located.profile.locationMethod, 'current');
  const profileEdit = RestaurantState.setProfile(located, { locationMethod: 'current', description: 'Nearby lunch' });
  assert.equal(profileEdit.profile.latitude, 13.7563);
  assert.equal(profileEdit.profile.longitude, 100.5018);
  const manual = RestaurantState.setProfile(located, { locationMethod: 'manual', addressLine1: '12 Safe Street' });
  assert.equal(manual.profile.locationMethod, 'manual');
  assert.equal(manual.profile.latitude, null);
  assert.equal(manual.profile.longitude, null);
});

test('storefront operations clamp persisted values and preserve timed special hours', () => {
  const state = RestaurantState.normalize({
    operations: {
      deliveryRadius: 999, capacity: -2, prepMinutes: 999,
      specialHours: [{ date: '2026-12-31', open: '11:00', close: '15:30', note: 'New Year menu' }]
    }
  });
  assert.equal(state.operations.deliveryRadius, 50);
  assert.equal(state.operations.capacity, 1);
  assert.equal(state.operations.prepMinutes, 180);
  assert.deepEqual(state.operations.specialHours, [{ date: '2026-12-31', open: '11:00', close: '15:30', closed: false, note: 'New Year menu' }]);
  assert.match(Storefront.validateOperations({ deliveryRadius: 5, capacity: 20, prepMinutes: 0 }).errors.prepMinutes, /between/i);
});

test('Customer catalog receives only safe storefront profile and operations fields', () => {
  const state = RestaurantState.setOperations(RestaurantState.setProfile(RestaurantState.defaultState(), {
    name: 'Savora Kitchen', description: '<b>Fresh bowls</b>', address: '123 Market Street', image: 'assets/images/catalog/mega-burger-feast-combo.jpg'
  }), {
    acceptingOrders: false, deliveryEnabled: true, pickupEnabled: false, deliveryRadius: 6, prepMinutes: 25,
    weeklyHours: { monday: { open: '10:00', close: '20:00' } }, specialHours: [{ date: '2026-12-31', open: '11:00', close: '15:00' }]
  });
  const catalog = Catalog.applyRestaurantOverrides(Catalog.products, Catalog.restaurants, state);
  const restaurant = catalog.restaurants['Savora Kitchen'];
  assert.equal(restaurant.image, 'assets/images/catalog/mega-burger-feast-combo.jpg');
  assert.equal(restaurant.description, '<b>Fresh bowls</b>');
  assert.equal(restaurant.address, '123 Market Street');
  assert.equal(restaurant.acceptingOrders, false);
  assert.equal(restaurant.deliveryRadius, 6);
  assert.equal(restaurant.pickupEnabled, false);
  assert.deepEqual(restaurant.weeklyHours.monday, { open: '10:00', close: '20:00', closed: false });
  assert.equal(restaurant.specialHours[0].open, '11:00');
  assert.equal(Object.hasOwn(restaurant, 'onerror'), false);
});

test('Customer storefront availability respects accepting status, local hours, and special-date overrides', () => {
  const weeklyHours = {
    monday: { open: '09:00', close: '17:00' },
    tuesday: { open: '20:00', close: '02:00' }
  };
  assert.deepEqual(Catalog.storefrontStatus({ acceptingOrders: false, weeklyHours }, new Date(2026, 5, 1, 10, 0)), { isOpen: false, status: 'Orders paused' });
  assert.deepEqual(Catalog.storefrontStatus({ acceptingOrders: true, weeklyHours }, new Date(2026, 5, 1, 8, 59)), { isOpen: false, status: 'Closed now' });
  assert.deepEqual(Catalog.storefrontStatus({ acceptingOrders: true, weeklyHours }, new Date(2026, 5, 1, 10, 0)), { isOpen: true, status: 'Open for orders' });
  assert.deepEqual(Catalog.storefrontStatus({ acceptingOrders: true, weeklyHours, specialHours: [{ date: '2026-06-01', closed: true }] }, new Date(2026, 5, 1, 10, 0)), { isOpen: false, status: 'Closed today' });
  assert.deepEqual(Catalog.storefrontStatus({ acceptingOrders: true, weeklyHours, specialHours: [{ date: '2026-06-01', open: '11:00', close: '13:00' }] }, new Date(2026, 5, 1, 12, 0)), { isOpen: true, status: 'Open for orders' });
  assert.deepEqual(Catalog.storefrontStatus({ acceptingOrders: true, weeklyHours }, new Date(2026, 5, 2, 1, 0)), { isOpen: true, status: 'Open for orders' });
});
