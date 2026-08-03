const test = require('node:test');
const assert = require('node:assert/strict');
const RestaurantState = require('../js/restaurant_state.js');
const CustomerState = require('../js/customer_state.js');
const Catalog = require('../js/customer_catalog.js');
const Menu = require('../js/restaurant_menu.js');
const Storefront = require('../js/restaurant_storefront.js');
const Insights = require('../js/restaurant_insights.js');

test('Restaurant state keeps only preferences and unsubmitted menu drafts', () => {
  const normalized = RestaurantState.normalize({ orders: [{ id: 'legacy-order' }], profile: {}, operations: {} });
  assert.equal(Object.hasOwn(normalized, 'orders'), false);
  assert.equal(Object.hasOwn(normalized, 'profile'), false);
  assert.equal(Object.hasOwn(RestaurantState, 'updateOrderStatus'), false);
  const stored = RestaurantState.saveMenuDraft(RestaurantState.defaultState(), 'draft-1', { name: 'Unsubmitted item', price: 12 });
  assert.equal(stored.menuDrafts['draft-1'].name, 'Unsubmitted item');
  assert.deepEqual(RestaurantState.clearMenuDraft(stored, 'draft-1').menuDrafts, {});
});

test('Customer state has no submitted order or history persistence', () => {
  const customer = CustomerState.normalize({ orders: [{ id: 'legacy-order', status: 'delivered' }], statusHistory: [] });
  assert.deepEqual(customer, { version: 3, cart: [] });
  assert.equal(CustomerState.getActiveOrder, undefined);
});

test('menu editor validates and normalizes server-bound item drafts', () => {
  const invalid = Menu.validateMenuItem({ id: 'menu-unsafe', name: 'Unsafe special', category: 'lunch', price: '0', image: 'https://example.test/dish.jpg' });
  assert.equal(invalid.valid, false);
  assert.match(invalid.errors.price, /greater than zero/i);
  const item = Menu.menuItemFromDraft({ id: 'menu-safe', name: 'Safe special', description: 'Local only', category: 'lunch', price: '12.50', available: false, optionGroups: [{ name: 'Size', required: true, options: [{ label: 'Regular', price: '0' }] }], addOns: [{ label: 'Extra herb', price: '1.25' }], stock: '3', prepTime: '15', dietaryTags: ['vegetarian'] });
  assert.equal(item.price, 12.5);
  assert.equal(item.available, false);
  assert.equal(item.optionGroups[0].options[0].price, 0);
});

test('menu editor keeps stable draft ids and labelled fields', () => {
  const holder = { dataset: {} };
  const firstId = Menu.ensureDraftId(holder, () => 123456);
  assert.equal(firstId, Menu.ensureDraftId(holder, () => 999999));
  assert.equal(Menu.editorFieldName('taxCategory'), 'menu-tax-category');
  assert.equal(Menu.editorFieldName('prepTime'), 'menu-prep-time');
  assert.equal(Menu.editorFieldName('compareAtPrice'), 'menu-compare-price');
  assert.equal(Menu.validateMenuItemForStatus({ id: firstId, description: 'Still writing', status: 'draft' }, 'draft').valid, true);
});

test('menu editor creates labelled option groups and add-ons', () => {
  assert.deepEqual(Menu.appendOptionGroup([], { name: 'Choose a size', required: true, optionLabel: 'Large', optionPrice: '2.00' }), [{ name: 'Choose a size', required: true, selectionType: 'single', minimumChoices: 1, maximumChoices: 1, sortOrder: 0, options: [{ publicId: '', label: 'Large', price: 2, available: true, sortOrder: 0 }] }]);
  assert.deepEqual(Menu.appendAddOn([], { label: 'Extra parmesan', price: '1.25' }), [{ publicId: '', label: 'Extra parmesan', price: 1.25, available: true, sortOrder: 0 }]);
});

test('server catalog and storefront helpers retain their narrow contracts', () => {
  const product = Catalog.itemFromRecord({ publicId: 'customer-ready', name: 'Customer ready bowl', basePrice: 14, available: true, version: 3, restaurant: { id: 9, name: 'Savora Kitchen', cuisine: 'Lunch' }, optionGroups: [{ optionChoices: [{ publicId: 'regular', name: 'Regular', priceDelta: 0 }] }, { optionChoices: [{ publicId: 'herbs', name: 'Extra herbs', priceDelta: 1.5 }] }] });
  assert.equal(product.restaurant, 'Savora Kitchen');
  assert.equal(product.version, 3);
  assert.deepEqual(product.portions.map(({ label, price }) => ({ label, price })), [{ label: 'Regular', price: 0 }]);
  assert.equal(Object.hasOwn(Storefront, 'validateOperations'), false);
  assert.equal(Object.hasOwn(Storefront, 'normalizeWeeklyHours'), false);
});

test('analytics helper filters malformed dates without owning order state', () => {
  const ranged = Insights.ordersInDateRange([
    { id: 'bad-month', createdAt: '9999-99-99T10:00:00.000Z' },
    { id: 'bad-day', createdAt: '2026-02-30T10:00:00.000Z' },
    { id: 'valid', createdAt: '2026-07-30T10:00:00.000Z' }
  ], 7);
  assert.deepEqual(ranged.map(order => order.id), ['valid']);
  assert.equal(Insights.verifiedReviews([{ publicId: 'rv-1', orderReference: 'server-1', customerName: 'Customer', rating: 5, comment: 'Great', createdAt: '2026-07-24T08:00:00.000Z', replyStatus: 'none', items: [] }]).length, 1);
});
