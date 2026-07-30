const test = require('node:test');
const assert = require('node:assert/strict');
const State = require('../js/customer_state.js');

test('normalizes malformed persisted state without copying unsafe fields', () => {
  const state = State.normalize({
    cart: [{ id: '1', quantity: '2', note: '<img src=x onerror=alert(1)>' }],
    wallet: { balance: '20.5' }
  });
  assert.equal(state.cart[0].quantity, 2);
  assert.equal(state.cart[0].note, '<img src=x onerror=alert(1)>');
  assert.equal(state.wallet.balance, 20.5);
  assert.equal(Object.hasOwn(state.cart[0], 'onerror'), false);
});

test('adds compatible cart lines and calculates a demo order once', () => {
  let state = State.defaultState();
  state = State.addCartLine(state, { id: '1', name: 'Pasta', price: 12.5 }, 2, []);
  const result = State.placeDemoOrder(state, { address: '12 Food Street', paymentMethod: 'cash' });
  assert.equal(result.state.cart.length, 0);
  assert.equal(result.order.status, 'pending');
  assert.equal(result.order.total, 27);
});

test('customer cart rejects items from a second restaurant', () => {
  let state = State.addCartLine(State.defaultState(), {
    id: '1', restaurantId: 'savora-kitchen', restaurant: 'Savora Kitchen', name: 'Pasta', price: 12
  }, 1);
  assert.throws(() => State.addCartLine(state, {
    id: '2', restaurantId: 'pizza-hut', restaurant: 'Pizza Hut', name: 'Pizza', price: 10
  }, 1), /one restaurant/i);
});

test('normalizes persisted carts and orders to one restaurant owner', () => {
  const state = State.normalize({
    cart: [
      { id: '1', restaurantId: 'savora-kitchen', restaurantName: 'Savora Kitchen' },
      { id: '2', restaurantId: 'pizza-hut', restaurantName: 'Pizza Hut' }
    ],
    orders: [{
      id: 'SVR-1', restaurantId: 'savora-kitchen', restaurantName: 'Savora Kitchen',
      items: [
        { id: '1', restaurantId: 'savora-kitchen', restaurantName: 'Savora Kitchen' },
        { id: '2', restaurantId: 'pizza-hut', restaurantName: 'Pizza Hut' }
      ]
    }]
  });

  assert.deepEqual(state.cart.map(line => line.restaurantId), ['savora-kitchen']);
  assert.deepEqual(state.orders[0].items.map(line => line.restaurantId), ['savora-kitchen']);
  assert.throws(() => State.addCartLine(state, {
    id: '3', restaurantId: 'pizza-hut', restaurantName: 'Pizza Hut', name: 'Pizza', price: 10
  }, 1), /one restaurant/i);
});

test('assigns legacy cart and order lines a deterministic restaurant identity', () => {
  const state = State.normalize({
    cart: [{ id: '1', name: 'Legacy pasta' }],
    orders: [{ id: 'SVR-legacy', items: [{ id: '1', name: 'Legacy pasta' }] }]
  });

  assert.deepEqual(state.cart[0].restaurantId, 'savora-kitchen');
  assert.deepEqual(state.cart[0].restaurantName, 'Savora Kitchen');
  assert.deepEqual(state.orders[0].restaurantId, 'savora-kitchen');
  assert.deepEqual(state.orders[0].items[0].restaurantName, 'Savora Kitchen');
  assert.throws(() => State.addCartLine(state, {
    id: '2', restaurantId: 'pizza-hut', restaurantName: 'Pizza Hut', name: 'Pizza', price: 10
  }, 1), /one restaurant/i);
});

test('rejects an empty delivery address and insufficient wallet payment', () => {
  const state = State.addCartLine(State.defaultState(), { id: '1', name: 'Pasta', price: 12.5 }, 1, []);
  assert.throws(() => State.placeDemoOrder(state, { address: '', paymentMethod: 'cash' }), /address/i);
  assert.throws(() => State.placeDemoOrder(state, { address: '1 Main', paymentMethod: 'wallet' }), /balance/i);
});

test('rejects a cart line without a usable product identifier', () => {
  assert.throws(() => State.addCartLine(State.defaultState(), { name: 'Pasta', price: 12.5 }, 1), /product id/i);
});

test('gives malformed legacy lines distinct identifiers for line-level removal', () => {
  const state = State.normalize({
    cart: [
      { id: '1', quantity: 1, note: 'no onions' },
      { id: '1', quantity: 1, note: 'extra sauce' }
    ]
  });
  assert.notEqual(state.cart[0].lineId, state.cart[1].lineId);
  assert.equal(State.removeCartLine(state, state.cart[0].lineId).cart.length, 1);
});

test('wallet orders debit the persisted state exactly once, including from a zero-safe balance', () => {
  let state = State.defaultState();
  state.wallet.balance = 20;
  state = State.addCartLine(state, { id: '1', name: 'Pasta', price: 12 }, 1, []);

  const result = State.placeDemoOrder(state, { address: '1 Main Street', paymentMethod: 'wallet' });

  assert.equal(result.state.wallet.balance, 6);
  assert.deepEqual(result.state.wallet.transactions.map(transaction => transaction.kind), ['debit']);
  assert.equal(result.state.cart.length, 0);
});

test('quantity reduction removes a cart line at zero', () => {
  let state = State.addCartLine(State.defaultState(), { id: '1', name: 'Pasta', price: 12.5 }, 1, []);
  state = State.updateCartQuantity(state, state.cart[0].lineId, -1);
  assert.equal(state.cart.length, 0);
});

test('one placement records a local order and wallet debit exactly once', () => {
  let state = State.addCartLine(State.defaultState(), { id: '1', name: 'Pasta', price: 10 }, 1, []);
  state.wallet.balance = 20;

  const first = State.placeDemoOrder(state, {
    address: 'One Street',
    paymentMethod: 'wallet',
    promoCode: 'LOCALDEMO'
  });

  assert.equal(first.state.wallet.balance, 8);
  assert.equal(first.state.orders.length, 1);
  assert.equal(first.state.orders[0].promoCode, 'LOCALDEMO');
  assert.equal(first.state.wallet.transactions.length, 1);
  assert.equal(first.state.wallet.transactions[0].amount, 12);
});

test('delivery notes are trimmed, capped and preserved on demo orders', () => {
  const state = State.addCartLine(State.defaultState(), { id: '1', name: 'Pasta', price: 10 }, 1, []);
  const note = `  <img src=x onerror=window.__task7Xss=1> ${'x'.repeat(140)}  `;
  const result = State.placeDemoOrder(state, {
    address: '12 Food Street',
    paymentMethod: 'cash',
    deliveryNote: note
  });

  assert.equal(result.order.deliveryNote, note.trim().slice(0, 120));
  assert.equal(result.state.orders[0].deliveryNote, note.trim().slice(0, 120));
  assert.equal(State.normalize({ orders: [{ id: 'saved', deliveryNote: note }] }).orders[0].deliveryNote, note.trim().slice(0, 120));
});

test('favorite toggling is idempotent and scoped by kind', () => {
  let state = State.defaultState();
  state = State.toggleFavorite(state, 'products', '1');
  state = State.toggleFavorite(state, 'products', '1');
  assert.deepEqual(state.favorites.products, []);
  assert.deepEqual(state.favorites.restaurants, []);
});

test('active order selection excludes completed and cancelled orders', () => {
  const order = State.getActiveOrder({
    orders: [
      { status: 'completed' },
      { status: 'cancelled' },
      { status: 'on_the_way', id: 'active' }
    ]
  });
  assert.equal(order.id, 'active');
});

test('profile patch preserves allowed fields and ignores password claims', () => {
  const state = State.setProfile(State.defaultState(), {
    fullName: 'Nguyen',
    address: '1 Food Lane',
    password: 'secret'
  });

  assert.equal(state.profile.fullName, 'Nguyen');
  assert.equal(state.profile.address, '1 Food Lane');
  assert.equal(Object.hasOwn(state.profile, 'password'), false);
});

test('wallet top-up updates balance and prepends a credit transaction', () => {
  const state = State.topUpWallet(State.defaultState(), 50);

  assert.equal(state.wallet.balance, 50);
  assert.equal(state.wallet.transactions[0].kind, 'credit');
  assert.equal(state.wallet.transactions[0].amount, 50);
});

test('wallet top-up rejects invalid amounts without changing a zero balance', () => {
  const state = State.defaultState();

  assert.throws(() => State.topUpWallet(state, 0), /positive/i);
  assert.throws(() => State.topUpWallet(state, -10), /positive/i);
  assert.equal(state.wallet.balance, 0);
  assert.equal(state.wallet.transactions.length, 0);
});
