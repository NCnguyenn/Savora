const test = require('node:test');
const assert = require('node:assert/strict');
const RestaurantState = require('../js/restaurant_state.js');
const Catalog = require('../js/customer_catalog.js');

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
