const test = require('node:test');
const assert = require('node:assert/strict');
const RestaurantState = require('../js/restaurant_state.js');
const CustomerState = require('../js/customer_state.js');
const Catalog = require('../js/customer_catalog.js');
const Menu = require('../js/restaurant_menu.js');
const Storefront = require('../js/restaurant_storefront.js');
const Insights = require('../js/restaurant_insights.js');

test('derives finance only from completed sales and one negative refund per refunded order', () => {
  const finance = RestaurantState.deriveFinance({ orders: [
    { id: 'complete-1', status: 'completed', total: 100, createdAt: '2026-07-30T08:00:00.000Z' },
    { id: 'complete-2', status: 'completed', total: 40, createdAt: '2026-07-30T09:00:00.000Z' },
    { id: 'refund-1', status: 'refunded', total: 25, createdAt: '2026-07-30T10:00:00.000Z' },
    { id: 'pending-1', status: 'pending', total: 999 },
    { id: 'cancelled-1', status: 'cancelled', total: 999 }
  ] });

  assert.equal(finance.grossSales, 140);
  assert.equal(finance.refundTotal, -25);
  assert.equal(finance.netRevenue, 101);
  assert.equal(finance.completedOrders, 2);
  assert.equal(finance.refundedOrders, 1);
  assert.deepEqual(finance.transactions.map(transaction => [transaction.orderId, transaction.type, transaction.amount]), [
    ['complete-1', 'sale', 100], ['complete-2', 'sale', 40], ['refund-1', 'refund', -25]
  ]);
});

test('preserves a refunded Customer order through normalization for one negative finance transaction', () => {
  const customer = CustomerState.normalize({ orders: [{
    id: 'refund-through-load', status: 'refunded', total: 48, createdAt: '2026-07-30T11:00:00.000Z'
  }] });
  const finance = RestaurantState.deriveFinance(customer);

  assert.equal(customer.orders[0].status, 'refunded');
  assert.equal(CustomerState.getActiveOrder(customer), null);
  assert.deepEqual(finance.transactions.map(transaction => [transaction.type, transaction.amount]), [['refund', -48]]);
  assert.equal(finance.grossSales, 0);
  assert.equal(finance.refundTotal, -48);
});

test('accepts only valid order transitions and records an audit event', () => {
  const customer = { orders: [{ id: 'SVR-1', status: 'pending', items: [], total: 20 }] };
  const next = RestaurantState.updateOrderStatus(customer, 'SVR-1', 'confirmed', { prepMinutes: 20 });
  assert.equal(next.orders[0].status, 'confirmed');
  assert.equal(next.orders[0].prepMinutes, 20);
  assert.equal(next.orders[0].statusHistory.at(-1).status, 'confirmed');
  assert.throws(() => RestaurantState.updateOrderStatus(next, 'SVR-1', 'completed'), /transition/i);
});

test('Restaurant local state retains bounded menu drafts without catalog or storefront authority', () => {
  const draft = { name: 'Unsubmitted item', description: 'Still writing', category: 'lunch', price: 12, optionGroups: [], addOns: [] };
  const stored = RestaurantState.saveMenuDraft(RestaurantState.defaultState(), 'draft-1', draft);
  assert.equal(stored.menuDrafts['draft-1'].name, 'Unsubmitted item');
  assert.equal(Object.hasOwn(stored, 'profile'), false);
  assert.equal(Object.hasOwn(stored, 'operations'), false);
  assert.equal(Object.hasOwn(stored, 'menuItems'), false);
  assert.deepEqual(RestaurantState.clearMenuDraft(stored, 'draft-1').menuDrafts, {});
});

test('permits Restaurant-owned preparation transitions and rejects Driver-owned delivery transitions', () => {
  const permitted = [
    ['pending', 'confirmed'], ['pending', 'cancelled'],
    ['confirmed', 'preparing'], ['confirmed', 'cancelled'],
    ['preparing', 'ready_for_pickup'], ['preparing', 'cancelled']
  ];

  permitted.forEach(([from, to]) => {
    const customer = { orders: [{ id: `${from}-${to}`, status: from, items: [], total: 20 }] };
    const next = RestaurantState.updateOrderStatus(customer, `${from}-${to}`, to, { prepMinutes: 15 });
    assert.equal(next.orders[0].status, to, `${from} can move to ${to}`);
    assert.equal(next.orders[0].statusHistory.at(-1).actor, 'restaurant');
  });

  for (const [from, to] of [
    ['pending', 'preparing'],
    ['confirmed', 'ready_for_pickup'],
    ['preparing', 'completed'],
    ['ready_for_pickup', 'on_the_way'],
    ['ready_for_pickup', 'completed'],
    ['on_the_way', 'completed'],
    ['completed', 'confirmed'],
    ['cancelled', 'confirmed']
  ]) {
    const customer = { orders: [{ id: `${from}-${to}`, status: from, items: [], total: 20 }] };
    assert.throws(() => RestaurantState.updateOrderStatus(customer, `${from}-${to}`, to), /transition/i);
  }
});

test('menu editor validates a publishable server-bound item', () => {
  const invalid = Menu.validateMenuItem({ id: 'menu-unsafe', name: 'Unsafe special', category: 'lunch', price: '0', image: 'https://example.test/dish.jpg' });
  assert.equal(invalid.valid, false);
  assert.match(invalid.errors.price, /greater than zero/i);

  const item = Menu.menuItemFromDraft({
    id: 'menu-safe', name: 'Safe special', description: 'Local only', category: 'lunch', price: '12.50', available: false,
    optionGroups: [{ name: 'Size', required: true, options: [{ label: 'Regular', price: '0' }] }],
    addOns: [{ label: 'Extra herb', price: '1.25' }], stock: '3', prepTime: '15', dietaryTags: ['vegetarian']
  });
  assert.equal(item.price, 12.5);
  assert.equal(item.available, false);
  assert.equal(item.optionGroups[0].options[0].price, 0);
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

test('Customer catalog maps server records into the product contract', () => {
  const product = Catalog.itemFromRecord({ publicId: 'customer-ready', name: 'Customer ready bowl', basePrice: 14, available: true, version: 3, restaurant: { id: 9, name: 'Savora Kitchen', cuisine: 'Lunch' }, optionGroups: [{ optionChoices: [{ publicId: 'regular', name: 'Regular', priceDelta: 0 }] }, { optionChoices: [{ publicId: 'herbs', name: 'Extra herbs', priceDelta: 1.5 }] }] });
  assert.equal(product.restaurant, 'Savora Kitchen');
  assert.equal(product.version, 3);
  assert.deepEqual(product.portions.map(({ label, price }) => ({ label, price })), [{ label: 'Regular', price: 0 }]);
  assert.deepEqual(product.addOns.map(({ productId, label, price }) => ({ productId, label, price })), [{ productId: 'customer-ready', label: 'Extra herbs', price: 1.5 }]);
});

test('draft validation allows safe partial items with a stable local id and keeps them out of Customer catalog', () => {
  const holder = { dataset: {} };
  const firstId = Menu.ensureDraftId(holder, () => 123456);
  const secondId = Menu.ensureDraftId(holder, () => 999999);
  assert.equal(firstId, secondId);

  const partial = { id: firstId, description: 'Still writing this dish', status: 'draft' };
  assert.equal(Menu.validateMenuItemForStatus(partial, 'draft').valid, true);
  assert.equal(Menu.validateMenuItemForStatus(partial, 'published').valid, false);

  const state = RestaurantState.saveMenuDraft(RestaurantState.defaultState(), firstId, Menu.menuItemFromDraft(partial));
  assert.equal(state.menuDrafts[firstId].description, 'Still writing this dish');
  assert.equal(Object.hasOwn(Catalog.products, firstId), false);
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

test('storefront helpers normalize weekly hours and reject invalid delivery settings', () => {
  const hours = Storefront.normalizeWeeklyHours({ monday: { open: '8:00', close: '26:00' }, tuesday: { open: '10:30', close: '18:00' } });
  assert.deepEqual(hours.monday, { open: '09:00', close: '17:00', closed: false });
  assert.deepEqual(hours.tuesday, { open: '10:30', close: '18:00', closed: false });
  assert.match(Storefront.validateOperations({ deliveryRadius: '-1', capacity: '0' }).errors.deliveryRadius, /between/i);
  assert.match(Storefront.validateOperations({ deliveryRadius: '5', capacity: '0' }).errors.capacity, /between/i);
});

test('storefront helpers validate an operations draft before it is submitted to the server', () => {
  assert.match(Storefront.validateOperations({ deliveryRadius: 5, capacity: 20, prepMinutes: 0 }).errors.prepMinutes, /between/i);
  assert.equal(Object.hasOwn(RestaurantState.defaultState(), 'operations'), false);
});

test('derives deterministic analytics with date, status, menu, kitchen, and empty fallbacks', () => {
  const analytics = RestaurantState.deriveAnalytics({ orders: [
    { id: 'a1', status: 'completed', total: 24, createdAt: '2026-07-30T12:15:00.000Z', items: [{ name: 'Noodles', quantity: 2 }], prepMinutes: 18 },
    { id: 'a2', status: 'cancelled', total: 10, createdAt: '2026-07-29T18:40:00.000Z', items: [{ name: 'Tea', quantity: 1 }], prepMinutes: 25 }
  ] });

  assert.equal(analytics.totalOrders, 2);
  assert.equal(analytics.completedOrders, 1);
  assert.equal(analytics.averageOrderValue, 24);
  assert.deepEqual(analytics.statusCounts.completed, 1);
  assert.deepEqual(analytics.salesByDay.map(day => day.key), ['2026-07-30']);
  assert.deepEqual(analytics.menuItems[0], { name: 'Noodles', quantity: 2, revenue: 24 });
  assert.equal(analytics.orderingTimes['12'], 1);
  assert.equal(analytics.kitchen.averagePrepMinutes, 21.5);
  assert.deepEqual(RestaurantState.deriveAnalytics({}).salesByDay, []);
  assert.deepEqual(RestaurantState.deriveAnalytics({}).menuItems, []);
});

test('stores bounded plain-text review replies by review id', () => {
  const reply = `<strong>Thank you</strong>${'x'.repeat(400)}`;
  const state = RestaurantState.setReviewReply(RestaurantState.defaultState(), 'review-7', reply, 'draft');

  assert.equal(state.reviews[0].id, 'review-7');
  assert.equal(state.reviews[0].reply.length, 300);
  assert.equal(state.reviews[0].reply.startsWith('<strong>'), true);
  assert.equal(state.reviews[0].status, 'draft');
  assert.equal(state.reviews[0].repliedAt, '');

  const published = RestaurantState.setReviewReply(state, 'review-7', 'Published thanks', 'published');
  assert.equal(published.reviews[0].status, 'published');
  assert.match(published.reviews[0].repliedAt, /^\d{4}-\d{2}-\d{2}T/);
});

test('analytics use net revenue, customer identities, item prices, and full calendar-day ranges', () => {
  const normalized = CustomerState.normalize({ orders: [
    {
      id: 'insight-1', status: 'completed', total: 32, deliveryFee: 2, customerId: 'repeat-1',
      createdAt: '2026-07-24T08:00:00.000Z',
      items: [
        { lineId: 'i1', id: 'entree', name: 'Entree', unitPrice: 20, quantity: 1 },
        { lineId: 'i2', id: 'tea', name: 'Tea', unitPrice: 10, quantity: 1 }
      ],
      review: { id: 'rv-1', rating: 5, comment: 'Great' }
    },
    {
      id: 'insight-2', status: 'completed', total: 12, customerId: 'repeat-1',
      createdAt: '2026-07-30T12:00:00.000Z',
      items: [{ lineId: 'i3', id: 'tea', name: 'Tea', unitPrice: 10, quantity: 1 }]
    },
    { id: 'insight-refund', status: 'refunded', total: 10, createdAt: '2026-07-30T13:00:00.000Z', items: [] }
  ] });

  const ranged = Insights.ordersInDateRange(normalized.orders, 7);
  assert.deepEqual(ranged.map(order => order.id), ['insight-1', 'insight-2', 'insight-refund']);
  const analytics = RestaurantState.deriveAnalytics({ orders: ranged });
  assert.equal(analytics.netRevenue, 29.6);
  assert.equal(analytics.repeatCustomers, 1);
  assert.deepEqual(analytics.menuItems, [
    { name: 'Entree', quantity: 1, revenue: 20 },
    { name: 'Tea', quantity: 2, revenue: 20 }
  ]);
  assert.equal(Insights.verifiedReviews(normalized, RestaurantState.defaultState()).length, 1);
});

test('analytics date ranges ignore malformed persisted dates without crashing', () => {
  const ranged = Insights.ordersInDateRange([
    { id: 'bad-month', createdAt: '9999-99-99T10:00:00.000Z' },
    { id: 'bad-day', createdAt: '2026-02-30T10:00:00.000Z' },
    { id: 'valid', createdAt: '2026-07-30T10:00:00.000Z' }
  ], 7);

  assert.deepEqual(ranged.map(order => order.id), ['valid']);
});
