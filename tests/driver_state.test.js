const test = require('node:test');
const assert = require('node:assert/strict');
const DriverState = require('../js/driver_state.js');

const readyOrder = overrides => ({
  id: 'SV-1042',
  status: 'ready_for_pickup',
  restaurantId: 'green-bowl',
  restaurantName: 'Green Bowl Kitchen',
  customerName: 'Emma Wilson',
  customerEmail: 'emma@example.test',
  address: '28 River Lane, Apt 4B',
  deliveryNote: 'Please leave at the front desk.',
  paymentMethod: 'cash',
  deliveryFee: 6.8,
  total: 24.5,
  createdAt: '2026-07-30T05:00:00.000Z',
  statusHistory: [],
  items: [
    { id: 'bowl', name: 'Grilled Chicken Bowl', quantity: 1, unitPrice: 17.7 },
    { id: 'tea', name: 'Iced Lemon Tea', quantity: 1, unitPrice: 0 }
  ],
  ...(overrides || {})
});

const restaurant = {
  profile: {
    id: 'green-bowl',
    name: 'Green Bowl Kitchen',
    address: '145 Pine Street',
    phone: '(555) 010-1145',
    latitude: 10.782,
    longitude: 106.693
  }
};

const onlineState = () => DriverState.setAvailability(DriverState.defaultState(), true);

test('normalizes driver state through allowlisted bounded profile, location and preference fields', () => {
  const state = DriverState.normalize({
    profile: {
      id: 'driver-7',
      fullName: 'Daniel Morgan',
      phone: '(555) 014-8820',
      email: 'daniel@example.test',
      vehicleType: 'Motorcycle',
      vehicleModel: 'Honda PCX 160',
      licensePlate: 'RDR-4821',
      vehicleColor: 'Forest green',
      password: 'must-not-survive'
    },
    location: { method: 'gps', address: '21 Oak Avenue', latitude: 91, longitude: 106.7 },
    serviceRadiusKm: 500,
    preferences: { newOffers: false, soundAlerts: true, cashOnDelivery: true, avoidHighways: true },
    unsafe: '<script>'
  });

  assert.equal(state.profile.fullName, 'Daniel Morgan');
  assert.equal(Object.hasOwn(state.profile, 'password'), false);
  assert.equal(state.location.method, 'manual');
  assert.equal(state.location.latitude, null);
  assert.equal(state.serviceRadiusKm, 50);
  assert.equal(state.preferences.newOffers, false);
  assert.equal(Object.hasOwn(state, 'unsafe'), false);
});

test('offers one ready order to an online idle driver for exactly 30 seconds', () => {
  const state = DriverState.createOffer(onlineState(), { orders: [readyOrder()] }, restaurant, 1000);

  assert.equal(state.currentOffer.orderId, 'SV-1042');
  assert.equal(state.currentOffer.expiresAt, 31000);
  assert.equal(state.currentOffer.restaurantName, 'Green Bowl Kitchen');
  assert.equal(state.currentOffer.pickupAddress, '145 Pine Street');
  assert.equal(state.currentOffer.dropoffAddress, '28 River Lane, Apt 4B');
  assert.deepEqual(state.currentOffer.items.map(item => item.name), ['Grilled Chicken Bowl', 'Iced Lemon Tea']);
});

test('does not create an offer while offline, busy, or without a ready order', () => {
  const offline = DriverState.defaultState();
  assert.equal(DriverState.createOffer(offline, { orders: [readyOrder()] }, restaurant, 1000).currentOffer, null);

  const noReady = DriverState.createOffer(onlineState(), { orders: [readyOrder({ status: 'preparing' })] }, restaurant, 1000);
  assert.equal(noReady.currentOffer, null);

  const offered = DriverState.createOffer(onlineState(), { orders: [readyOrder()] }, restaurant, 1000);
  const accepted = DriverState.acceptOffer(offered, { orders: [readyOrder()] }, restaurant, 'SV-1042', 2000);
  const second = readyOrder({ id: 'SV-2042' });
  assert.equal(DriverState.createOffer(accepted.state, { orders: [second] }, restaurant, 3000).currentOffer, null);
});

test('going offline while an offer is open returns it to the next eligible driver', () => {
  const offered = DriverState.createOffer(onlineState(), { orders: [readyOrder()] }, restaurant, 1000);
  const offline = DriverState.setAvailability(offered, false);

  assert.equal(offline.currentOffer, null);
  assert.equal(DriverState.dispatchForOrder(offline, 'SV-1042').status, 'offer_sent');
  assert.notEqual(DriverState.dispatchForOrder(offline, 'SV-1042').candidateDriverId, offered.profile.id);
});

test('decline and timeout clear the offer without changing the shared order status', () => {
  const order = readyOrder();
  const offered = DriverState.createOffer(onlineState(), { orders: [order] }, restaurant, 1000);
  const declined = DriverState.declineOffer(offered, 'SV-1042', 2000);
  const expired = DriverState.expireOffer(offered, 31000);

  assert.equal(declined.currentOffer, null);
  assert.deepEqual(declined.declinedOrderIds, ['SV-1042']);
  assert.equal(declined.offerAttempts.at(-1).outcome, 'declined');
  assert.equal(DriverState.dispatchForOrder(declined, 'SV-1042').status, 'offer_sent');
  assert.notEqual(DriverState.dispatchForOrder(declined, 'SV-1042').candidateDriverId, declined.profile.id);
  assert.equal(expired.currentOffer, null);
  assert.equal(expired.offerAttempts.at(-1).outcome, 'expired');
  assert.equal(DriverState.dispatchForOrder(expired, 'SV-1042').status, 'offer_sent');
  assert.equal(order.status, 'ready_for_pickup');
});

test('decline automatically offers the order exclusively to the next eligible driver', () => {
  const customer = { orders: [readyOrder()] };
  const firstOffer = DriverState.createOffer(onlineState(), customer, restaurant, 1000);
  const declined = DriverState.declineOffer(firstOffer, 'SV-1042', 2000);
  const dispatch = DriverState.dispatchForOrder(declined, 'SV-1042');

  assert.equal(dispatch.status, 'offer_sent');
  assert.equal(dispatch.attemptedDriverIds.includes('driver'), true);
  assert.ok(dispatch.candidateDriverId);

  let secondDriver = DriverState.setProfile(declined, {
    id: dispatch.candidateDriverId,
    fullName: 'Alex Rivera',
    phone: '(555) 010-9901'
  });
  secondDriver = DriverState.createOffer(secondDriver, customer, restaurant, 2500);

  assert.equal(secondDriver.currentOffer.orderId, 'SV-1042');
  assert.equal(DriverState.dispatchForOrder(secondDriver, 'SV-1042').candidateDriverId, dispatch.candidateDriverId);
  const accepted = DriverState.acceptOffer(secondDriver, customer, restaurant, 'SV-1042', 3000);
  assert.equal(DriverState.activeDelivery(accepted.state).driverId, dispatch.candidateDriverId);
  assert.equal(DriverState.dispatchForOrder(accepted.state, 'SV-1042').status, 'assigned');
});

test('unmaterialized redispatched offers continue to expire and advance through eligible drivers', () => {
  const offered = DriverState.createOffer(onlineState(), { orders: [readyOrder()] }, restaurant, 1000);
  const firstHandoff = DriverState.declineOffer(offered, 'SV-1042', 2000);
  const firstDispatch = DriverState.dispatchForOrder(firstHandoff, 'SV-1042');
  const secondHandoff = DriverState.expireDispatches(firstHandoff, firstDispatch.expiresAt);
  const secondDispatch = DriverState.dispatchForOrder(secondHandoff, 'SV-1042');

  assert.equal(secondDispatch.lastOutcome, 'expired');
  assert.equal(secondDispatch.status, 'offer_sent');
  assert.notEqual(secondDispatch.candidateDriverId, firstDispatch.candidateDriverId);
  assert.equal(secondHandoff.offerAttempts.at(-1).outcome, 'expired');

  const exhausted = DriverState.expireDispatches(secondHandoff, secondDispatch.expiresAt);
  assert.equal(DriverState.dispatchForOrder(exhausted, 'SV-1042').status, 'searching_driver');
});

test('offer selection enforces distance, COD, document eligibility, and nearest-order ranking', () => {
  const farCash = readyOrder({ id: 'far', distanceToPickupKm: 30 });
  const nearCash = readyOrder({ id: 'near', distanceToPickupKm: 2 });
  let driver = DriverState.setLocation(onlineState(), {
    method: 'manual',
    address: '21 Oak Avenue',
    serviceRadiusKm: 12
  });

  const ranked = DriverState.createOffer(driver, { orders: [farCash, nearCash] }, restaurant, 1000);
  assert.equal(ranked.currentOffer.orderId, 'near');

  driver = DriverState.setLocation(driver, {
    method: 'manual',
    address: '21 Oak Avenue',
    serviceRadiusKm: 1
  });
  assert.equal(DriverState.createOffer(driver, { orders: [nearCash] }, restaurant, 1000).currentOffer, null);

  const noCash = DriverState.setPreferences(onlineState(), { cashOnDelivery: false });
  assert.equal(DriverState.createOffer(noCash, { orders: [nearCash] }, restaurant, 1000).currentOffer, null);

  const unverified = DriverState.setProfile(onlineState(), { driverLicenseStatus: 'Expired' });
  assert.equal(DriverState.createOffer(unverified, { orders: [nearCash] }, restaurant, 1000).currentOffer, null);
});

test('accepts an offer once and enforces one active delivery', () => {
  const customer = { orders: [readyOrder()] };
  const offered = DriverState.createOffer(onlineState(), customer, restaurant, 1000);
  const accepted = DriverState.acceptOffer(offered, customer, restaurant, 'SV-1042', 2000);

  assert.equal(accepted.state.currentOffer, null);
  assert.equal(DriverState.activeDelivery(accepted.state).status, 'assigned');
  assert.equal(DriverState.activeDelivery(accepted.state).driverName, 'Mike Smith');
  assert.equal(accepted.customerState.orders[0].status, 'ready_for_pickup');
  assert.throws(
    () => DriverState.acceptOffer(accepted.state, customer, restaurant, 'SV-1042', 2500),
    /offer/i
  );
});

test('pickup and delivery are driver-owned shared order transitions', () => {
  const customer = { orders: [readyOrder()] };
  const offered = DriverState.createOffer(onlineState(), customer, restaurant, 1000);
  const accepted = DriverState.acceptOffer(offered, customer, restaurant, 'SV-1042', 2000);
  const arrived = DriverState.updateMilestone(accepted.state, accepted.customerState, 'SV-1042', 'arrived', 2500);
  const pickedUp = DriverState.updateMilestone(arrived.state, arrived.customerState, 'SV-1042', 'picked_up', 3000);
  const delivered = DriverState.updateMilestone(pickedUp.state, pickedUp.customerState, 'SV-1042', 'delivered', 4000);

  assert.equal(arrived.customerState.orders[0].status, 'ready_for_pickup');
  assert.equal(pickedUp.customerState.orders[0].status, 'on_the_way');
  assert.equal(delivered.customerState.orders[0].status, 'completed');
  assert.equal(delivered.customerState.orders[0].statusHistory.at(-1).actor, 'driver');
  assert.equal(DriverState.activeDelivery(delivered.state), null);
  assert.throws(
    () => DriverState.updateMilestone(accepted.state, accepted.customerState, 'SV-1042', 'delivered', 5000),
    /transition/i
  );
});

test('milestones reject a stale shared order that was cancelled or already changed', () => {
  const customer = { orders: [readyOrder()] };
  const offered = DriverState.createOffer(onlineState(), customer, restaurant, 1000);
  const accepted = DriverState.acceptOffer(offered, customer, restaurant, 'SV-1042', 2000);
  const cancelledBeforeArrival = structuredClone(accepted.customerState);
  cancelledBeforeArrival.orders[0].status = 'cancelled';
  assert.throws(
    () => DriverState.updateMilestone(accepted.state, cancelledBeforeArrival, 'SV-1042', 'arrived', 2500),
    /order.*status|no longer/i
  );

  const arrived = DriverState.updateMilestone(accepted.state, accepted.customerState, 'SV-1042', 'arrived', 2500);
  const cancelledBeforePickup = structuredClone(arrived.customerState);
  cancelledBeforePickup.orders[0].status = 'cancelled';
  assert.throws(
    () => DriverState.updateMilestone(arrived.state, cancelledBeforePickup, 'SV-1042', 'picked_up', 3000),
    /order.*status|no longer/i
  );
});

test('profile, location and preference setters preserve explicit safe fields', () => {
  let state = DriverState.setProfile(DriverState.defaultState(), {
    fullName: 'Daniel Morgan',
    phone: '(555) 014-8820',
    vehicleModel: 'Honda PCX 160',
    password: 'ignored'
  });
  state = DriverState.setLocation(state, {
    method: 'gps',
    address: '21 Oak Avenue, Downtown',
    latitude: 10.7812,
    longitude: 106.6945,
    serviceRadiusKm: 8
  });
  state = DriverState.setPreferences(state, { cashOnDelivery: false, avoidHighways: true });

  assert.equal(state.profile.fullName, 'Daniel Morgan');
  assert.equal(Object.hasOwn(state.profile, 'password'), false);
  assert.equal(state.location.method, 'gps');
  assert.equal(state.location.latitude, 10.7812);
  assert.equal(state.serviceRadiusKm, 8);
  assert.equal(state.preferences.cashOnDelivery, false);
  assert.equal(state.preferences.avoidHighways, true);
});

test('resolved GPS addresses replace coordinate-only fallback and manual mode clears coordinates', () => {
  const gps = DriverState.setLocation(DriverState.defaultState(), {
    method: 'gps',
    address: '12 GPS Road, Bangkok',
    latitude: 13.7563,
    longitude: 100.5018
  });
  assert.equal(gps.location.address, '12 GPS Road, Bangkok');
  assert.equal(gps.location.method, 'gps');
  const manual = DriverState.setLocation(gps, { method: 'manual', address: 'Manual Road' });
  assert.equal(manual.location.method, 'manual');
  assert.equal(manual.location.latitude, null);
  assert.equal(manual.location.longitude, null);
});

test('derives completed history and earnings while reconciling cash separately', () => {
  const state = DriverState.normalize({
    deliveries: [
      {
        orderId: 'done',
        status: 'delivered',
        restaurantName: 'Green Bowl Kitchen',
        customerName: 'Emma Wilson',
        earnings: 6.8,
        distanceKm: 4.8,
        paymentMethod: 'cash',
        orderTotal: 24.5,
        acceptedAt: '2026-07-30T11:30:00.000Z',
        deliveredAt: '2026-07-30T12:00:00.000Z'
      },
      {
        orderId: 'active',
        status: 'picked_up',
        earnings: 99,
        paymentMethod: 'cash',
        orderTotal: 99
      }
    ]
  });
  const history = DriverState.deriveHistory(state);
  const earnings = DriverState.deriveEarnings(state);

  assert.equal(history.length, 1);
  assert.equal(history[0].orderId, 'done');
  assert.equal(earnings.total, 6.8);
  assert.equal(earnings.deliveries, 1);
  assert.equal(earnings.codCollected, 24.5);
});
