import fs from 'node:fs/promises';
import path from 'node:path';

const debugPort = Number(process.env.SAVORA_CDP_PORT || 9227);
const baseUrl = process.env.SAVORA_BASE_URL || 'http://localhost/Savora';
const artifactDir = path.resolve('.superpowers/sdd/driver-portal/browser-qa');
const routes = [
  'driver_dashboard.php',
  'driver_delivery.php',
  'driver_history.php',
  'driver_earnings.php',
  'driver_profile.php'
];
const viewports = [
  { width: 320, height: 900 },
  { width: 768, height: 1024 },
  { width: 1440, height: 1000 }
];

class CdpClient {
  constructor(url) {
    this.socket = new WebSocket(url);
    this.nextId = 1;
    this.pending = new Map();
    this.listeners = new Map();
  }

  async connect() {
    await new Promise((resolve, reject) => {
      this.socket.addEventListener('open', resolve, { once: true });
      this.socket.addEventListener('error', reject, { once: true });
    });
    this.socket.addEventListener('message', event => {
      const message = JSON.parse(event.data);
      if (message.id) {
        const pending = this.pending.get(message.id);
        if (!pending) return;
        this.pending.delete(message.id);
        if (message.error) pending.reject(new Error(message.error.message));
        else pending.resolve(message.result || {});
        return;
      }
      for (const listener of this.listeners.get(message.method) || []) listener(message.params || {});
    });
  }

  send(method, params = {}) {
    const id = this.nextId++;
    const promise = new Promise((resolve, reject) => this.pending.set(id, { resolve, reject }));
    this.socket.send(JSON.stringify({ id, method, params }));
    return promise;
  }

  on(method, listener) {
    const listeners = this.listeners.get(method) || [];
    listeners.push(listener);
    this.listeners.set(method, listeners);
  }

  close() {
    this.socket.close();
  }
}

const delay = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

async function openPage() {
  const versionResponse = await fetch(`http://127.0.0.1:${debugPort}/json/version`);
  if (!versionResponse.ok) throw new Error(`Unable to inspect browser: ${versionResponse.status}`);
  const version = await versionResponse.json();
  const browserClient = new CdpClient(version.webSocketDebuggerUrl);
  await browserClient.connect();
  const { browserContextId } = await browserClient.send('Target.createBrowserContext');
  const { targetId } = await browserClient.send('Target.createTarget', { url: 'about:blank', browserContextId });
  let target = null;
  for (let attempt = 0; attempt < 20 && !target; attempt += 1) {
    const targetsResponse = await fetch(`http://127.0.0.1:${debugPort}/json/list`);
    const targets = targetsResponse.ok ? await targetsResponse.json() : [];
    target = targets.find(candidate => candidate.id === targetId);
    if (!target) await delay(50);
  }
  if (!target?.webSocketDebuggerUrl) throw new Error('Unable to connect to isolated browser page');
  const client = new CdpClient(target.webSocketDebuggerUrl);
  await client.connect();
  await Promise.all([
    client.send('Page.enable'),
    client.send('Runtime.enable'),
    client.send('Network.enable')
  ]);
  await client.send('Network.setCacheDisabled', { cacheDisabled: true });
  client.cleanup = async () => {
    client.close();
    try { await browserClient.send('Target.closeTarget', { targetId }); } catch (_) { /* already closed */ }
    try { await browserClient.send('Target.disposeBrowserContext', { browserContextId }); } catch (_) { /* already disposed */ }
    browserClient.close();
  };
  return client;
}

async function evaluate(client, expression) {
  const result = await client.send('Runtime.evaluate', {
    expression,
    awaitPromise: true,
    returnByValue: true
  });
  if (result.exceptionDetails) {
    throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text || 'Runtime evaluation failed');
  }
  return result.result ? result.result.value : undefined;
}

async function waitFor(client, expression, message, timeout = 8000) {
  const started = Date.now();
  let lastError = null;
  while (Date.now() - started < timeout) {
    try {
      if (await evaluate(client, expression)) return;
      lastError = null;
    } catch (error) {
      lastError = error;
    }
    await delay(75);
  }
  throw new Error(`Timed out: ${message}${lastError ? ` (${lastError.message})` : ''}`);
}

async function navigate(client, relativeUrl) {
  const url = relativeUrl.startsWith('http') ? relativeUrl : `${baseUrl}/${relativeUrl}`;
  await client.send('Page.navigate', { url });
  await waitFor(client, "document.readyState === 'complete'", `loading ${url}`);
  await delay(100);
}

async function setViewport(client, width, height) {
  await client.send('Emulation.setDeviceMetricsOverride', {
    width,
    height,
    deviceScaleFactor: 1,
    mobile: false
  });
}

async function pressKey(client, key, code, virtualKeyCode) {
  await client.send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: virtualKeyCode });
  await client.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: virtualKeyCode });
}

async function captureScreenshot(client, name) {
  await evaluate(client, '(() => { scrollTo(0, 0); return true; })()');
  await delay(50);
  const screenshot = await client.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
  await fs.writeFile(path.join(artifactDir, 'screenshots', `${name}.png`), Buffer.from(screenshot.data, 'base64'));
}

async function loginAs(client, role) {
  await navigate(client, 'logout.php');
  await navigate(client, 'index.php');
  await waitFor(client, "Boolean(document.getElementById('username') && document.getElementById('password'))", 'login form');
  await evaluate(client, `(() => {
    document.getElementById('username').value = ${JSON.stringify(role)};
    document.getElementById('password').value = '123456';
    document.querySelector('form').submit();
    return true;
  })()`);
  const destinations = {
    customer: 'customer_dashboard.php',
    restaurant: 'restaurant_dashboard.php',
    driver: 'driver_dashboard.php'
  };
  await waitFor(client, `location.pathname.endsWith('/${destinations[role]}')`, `${role} login`);
}

async function seedLocalDemo(client) {
  await navigate(client, 'driver_dashboard.php');
  await evaluate(client, `(() => {
    localStorage.clear();
    const customer = SavoraState.normalize({
      profile: {
        fullName: 'Emma Wilson',
        email: 'emma@example.test',
        phone: '(555) 010-2244',
        address: '88 Riverside Drive, Downtown'
      },
      orders: [{
        id: 'QA-DRIVER',
        status: 'ready_for_pickup',
        restaurantId: 'savora-kitchen',
        restaurantName: 'Green Bowl Kitchen',
        customerId: 'emma@example.test',
        customerName: 'Emma Wilson',
        customerEmail: 'emma@example.test',
        customerPhone: '(555) 010-2244',
        address: '88 Riverside Drive, Downtown',
        deliveryNote: 'Leave at the lobby reception.',
        paymentMethod: 'cash',
        items: [
          { lineId: 'qa-bowl', id: 'qa-bowl', restaurantId: 'savora-kitchen', restaurantName: 'Green Bowl Kitchen', name: 'Harvest chicken bowl', unitPrice: 15.5, quantity: 1 },
          { lineId: 'qa-tea', id: 'qa-tea', restaurantId: 'savora-kitchen', restaurantName: 'Green Bowl Kitchen', name: 'Peach iced tea', unitPrice: 3.2, quantity: 2 }
        ],
        subtotal: 21.9,
        deliveryFee: 6.8,
        driverEarnings: 6.8,
        distanceToPickupKm: 1.3,
        distanceKm: 4.7,
        total: 28.7,
        createdAt: '2026-07-31T02:00:00.000Z',
        statusHistory: [{ status: 'ready_for_pickup', createdAt: '2026-07-31T02:20:00.000Z', actor: 'restaurant' }]
      }]
    });
    SavoraState.persist(customer);

    let restaurant = SavoraRestaurantState.defaultState();
    restaurant = SavoraRestaurantState.setProfile(restaurant, {
      id: 'savora-kitchen',
      name: 'Green Bowl Kitchen',
      phone: '(555) 010-1800',
      addressLine1: '145 Pine Street',
      city: 'Downtown',
      country: 'United States'
    });
    SavoraRestaurantState.persist(restaurant);

    let driver = SavoraDriverState.defaultState();
    driver = SavoraDriverState.setProfile(driver, {
      fullName: 'Daniel Brooks',
      phone: '(555) 014-8820',
      vehicleType: 'Motorcycle',
      vehicleModel: 'Honda PCX 160',
      licensePlate: 'RDR-4821'
    });
    driver = SavoraDriverState.setLocation(driver, {
      method: 'manual',
      address: '21 Oak Avenue, Downtown',
      serviceRadiusKm: 8
    });
    SavoraDriverState.persist(driver);
    return true;
  })()`);
  await navigate(client, 'driver_dashboard.php');
}

async function checkRouteMatrix(client, runtimeErrors) {
  const results = [];
  for (const viewport of viewports) {
    await setViewport(client, viewport.width, viewport.height);
    for (const route of routes) {
      runtimeErrors.length = 0;
      await navigate(client, route);
      const status = await evaluate(client, `(() => {
        const text = document.body?.innerText || '';
        const corruptText = text.match(/(?:Ã.|Â.|â€|ï¿½)/);
        const strayNull = text.split(/\\r?\\n/).some(line => line.trim().toLowerCase() === 'null');
        return {
          title: document.title,
          main: Boolean(document.querySelector('main')),
          heading: document.querySelector('main h1')?.textContent.trim() || '',
          overflow: document.documentElement.scrollWidth - window.innerWidth,
          corruptText: corruptText ? corruptText[0] : '',
          strayNull
        };
      })()`);
      assert(status.main, `${route} has no main landmark at ${viewport.width}px`);
      assert(status.heading, `${route} has no page heading at ${viewport.width}px`);
      assert(status.title.includes('Savora'), `${route} has an invalid title at ${viewport.width}px`);
      assert(status.overflow <= 1, `${route} overflows horizontally by ${status.overflow}px at ${viewport.width}px`);
      assert(!status.corruptText, `${route} contains corrupt text at ${viewport.width}px: ${status.corruptText}`);
      assert(!status.strayNull, `${route} exposes an internal null value at ${viewport.width}px`);
      await captureScreenshot(client, `${path.parse(route).name}-${viewport.width}`);
      await evaluate(client, `(() => {
        const main = document.querySelector('main');
        const target = main?.querySelector('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled])');
        if (main) {
          main.setAttribute('tabindex', '-1');
          main.focus();
        }
        return Boolean(target);
      })()`);
      await pressKey(client, 'Tab', 'Tab', 9);
      const focus = await evaluate(client, `(() => {
        const active = document.activeElement;
        const style = active ? getComputedStyle(active) : null;
        return {
          tag: active?.tagName || '',
          className: active?.className || '',
          id: active?.id || '',
          outlineStyle: style?.outlineStyle || '',
          outlineWidth: style?.outlineWidth || '',
          visible: Boolean(style && style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0)
        };
      })()`);
      assert(focus.tag && focus.tag !== 'BODY', `${route} has no keyboard focus target at ${viewport.width}px`);
      assert(focus.visible, `${route} has no visible focus indicator at ${viewport.width}px: ${JSON.stringify(focus)}`);
      assert(!runtimeErrors.length, `${route} raised runtime errors at ${viewport.width}px: ${runtimeErrors.join(' | ')}`);
      results.push({ route, width: viewport.width, title: status.title, heading: status.heading, focus: focus.tag });
    }
  }
  return results;
}

async function checkMobileNavigation(client) {
  await setViewport(client, 320, 900);
  await navigate(client, 'driver_dashboard.php');
  await evaluate(client, "document.querySelector('.driver-mobile-menu').click()");
  await waitFor(client, "!document.getElementById('driver-mobile-navigation').hidden", 'opening driver mobile navigation');
  assert(await evaluate(client, "document.querySelector('.driver-mobile-menu').getAttribute('aria-expanded') === 'true'"), 'mobile navigation did not expand');
  await pressKey(client, 'Escape', 'Escape', 27);
  await waitFor(client, "document.getElementById('driver-mobile-navigation').hidden", 'closing driver mobile navigation');
  assert(await evaluate(client, "document.querySelector('.driver-mobile-menu').getAttribute('aria-expanded') === 'false'"), 'mobile navigation did not collapse');
}

async function checkManualLocation(client) {
  await setViewport(client, 1440, 1000);
  await navigate(client, 'driver_dashboard.php');
  await evaluate(client, "document.querySelector('[data-enter-driver-address]').click()");
  await waitFor(client, "!document.getElementById('driver-address-dialog').hidden", 'opening manual address dialog');
  await evaluate(client, `(() => {
    const form = document.querySelector('[data-driver-address-form]');
    form.elements.namedItem('driver-address').value = '42 Browser QA Avenue';
    form.requestSubmit();
    return true;
  })()`);
  await waitFor(client, "SavoraDriverState.load().location.address === '42 Browser QA Avenue'", 'manual address persistence');
  assert(await evaluate(client, "document.querySelector('[data-driver-location-address]').textContent.includes('42 Browser QA Avenue')"), 'overview did not render the saved address');
}

async function resetOffer(client, expiresIn = 30000) {
  await navigate(client, 'driver_dashboard.php');
  await evaluate(client, `(() => {
    let driver = SavoraDriverState.defaultState();
    driver = SavoraDriverState.setProfile(driver, { fullName: 'Daniel Brooks', phone: '(555) 014-8820' });
    driver = SavoraDriverState.setLocation(driver, { method: 'manual', address: '42 Browser QA Avenue', serviceRadiusKm: 8 });
    driver = SavoraDriverState.setAvailability(driver, true);
    driver = SavoraDriverState.createOffer(driver, SavoraState.load(), SavoraRestaurantState.load(), Date.now());
    if (driver.currentOffer) driver.currentOffer.expiresAt = Date.now() + ${expiresIn};
    SavoraDriverState.persist(driver);
    return true;
  })()`);
  await navigate(client, 'driver_dashboard.php');
  await waitFor(client, "Boolean(SavoraDriverState.load().currentOffer)", 'delivery offer');
}

async function checkOfferDecisionFlow(client) {
  await resetOffer(client);
  const offer = await evaluate(client, `(() => ({
    seconds: Number(document.querySelector('[data-offer-countdown]').textContent.split(':')[1]),
    text: document.querySelector('[data-delivery-offer]').innerText
  }))()`);
  assert(offer.seconds >= 28 && offer.seconds <= 30, `offer countdown did not start at 30 seconds: ${offer.seconds}`);
  for (const expected of ['Green Bowl Kitchen', '145 Pine Street', 'Emma Wilson', '88 Riverside Drive', 'Harvest chicken bowl', 'Peach iced tea']) {
    assert(offer.text.includes(expected), `offer does not show ${expected}`);
  }
  await setViewport(client, 1440, 1000);
  await captureScreenshot(client, 'state-offer-1440');
  await setViewport(client, 320, 900);
  const mobileOrder = await evaluate(client, `(() => ({
    offerTop: document.querySelector('[data-delivery-offer]').getBoundingClientRect().top,
    locationTop: document.querySelector('[data-driver-location]').getBoundingClientRect().top,
    actionTop: document.querySelector('[data-accept-offer]').getBoundingClientRect().top,
    actionBottom: document.querySelector('[data-accept-offer]').getBoundingClientRect().bottom,
    viewport: innerHeight
  }))()`);
  assert(mobileOrder.offerTop < mobileOrder.locationTop, 'time-sensitive offer is not prioritized above location tools on mobile');
  assert(mobileOrder.actionTop >= 0 && mobileOrder.actionBottom <= mobileOrder.viewport - 74, 'accept action is not immediately reachable above mobile navigation');
  await captureScreenshot(client, 'state-offer-320');
  await setViewport(client, 1440, 1000);
  await evaluate(client, "document.querySelector('[data-decline-offer]').click()");
  await waitFor(client, "!SavoraDriverState.load().currentOffer", 'declining offer');
  assert(await evaluate(client, "SavoraState.load().orders.find(order => order.id === 'QA-DRIVER').status === 'ready_for_pickup'"), 'decline changed the shared order status');

  await resetOffer(client, 250);
  await waitFor(client, "!SavoraDriverState.load().currentOffer", 'automatic offer expiry', 2500);
  assert(await evaluate(client, "SavoraDriverState.load().offerAttempts.some(attempt => attempt.orderId === 'QA-DRIVER' && attempt.outcome === 'expired')"), 'expired offer was not recorded');
}

async function checkDeliveryFlow(client) {
  await resetOffer(client);
  await evaluate(client, "document.querySelector('[data-accept-offer]').click()");
  await waitFor(client, "location.pathname.endsWith('/driver_delivery.php')", 'opening active delivery');
  await waitFor(client, "SavoraDriverState.activeDelivery(SavoraDriverState.load())?.status === 'assigned'", 'accepted delivery');
  assert(await evaluate(client, "SavoraDriverState.deliveryForOrder(SavoraDriverState.load(), 'QA-DRIVER')?.driverName.length > 0"), 'accepted delivery does not identify the assigned driver');

  await loginAs(client, 'customer');
  await navigate(client, 'customer_dashboard.php');
  assert(await evaluate(client, "document.querySelector('main').innerText.includes('Driver assigned')"), 'Customer portal does not show the assigned driver');
  await loginAs(client, 'restaurant');
  await navigate(client, 'restaurant_orders.php');
  await waitFor(client, "document.querySelector('main').innerText.includes('Driver dispatch')", 'Restaurant driver dispatch status');
  assert(await evaluate(client, "document.querySelector('main').innerText.includes('Mike Smith')"), 'Restaurant portal does not show the assigned driver');
  await loginAs(client, 'driver');
  await navigate(client, 'driver_delivery.php');
  await setViewport(client, 1440, 1000);
  await captureScreenshot(client, 'state-delivery-assigned-1440');
  await setViewport(client, 320, 900);
  const mobileAction = await evaluate(client, `(() => {
    scrollTo(0, 0);
    const rect = document.querySelector('[data-delivery-primary-action]').getBoundingClientRect();
    return { top: rect.top, bottom: rect.bottom, viewport: innerHeight };
  })()`);
  assert(mobileAction.top >= 0 && mobileAction.bottom <= mobileAction.viewport - 74, 'active delivery action is not immediately reachable above mobile navigation');
  await captureScreenshot(client, 'state-delivery-assigned-320');
  await setViewport(client, 1440, 1000);

  await evaluate(client, "document.querySelector('[data-delivery-primary-action]').click()");
  await waitFor(client, "SavoraDriverState.activeDelivery(SavoraDriverState.load())?.status === 'arrived'", 'restaurant arrival');
  await evaluate(client, "document.querySelector('[data-delivery-primary-action]').click()");
  await waitFor(client, "SavoraDriverState.activeDelivery(SavoraDriverState.load())?.status === 'picked_up'", 'pickup confirmation');
  assert(await evaluate(client, "SavoraState.load().orders.find(order => order.id === 'QA-DRIVER').status === 'on_the_way'"), 'pickup did not update Customer order to on the way');
  await evaluate(client, "document.querySelector('[data-delivery-primary-action]').click()");
  await waitFor(client, "location.pathname.endsWith('/driver_history.php')", 'completed delivery history');
  await waitFor(client, "globalThis.SavoraState?.load().orders.find(order => order.id === 'QA-DRIVER')?.status === 'completed'", 'completed Customer order');
  await waitFor(client, "document.querySelector('main')?.innerText.includes('QA-DRIVER')", 'completed delivery in history');
  await navigate(client, 'driver_earnings.php');
  assert(await evaluate(client, "document.querySelector('main').innerText.includes('$6.80')"), 'completed delivery earnings are missing');
  await captureScreenshot(client, 'state-earnings-completed-1440');
}

async function checkProfilePersistence(client) {
  await navigate(client, 'driver_profile.php');
  await evaluate(client, `(() => {
    const form = document.querySelector('[data-driver-profile-form]');
    form.elements.namedItem('fullName').value = 'Daniel Brooks';
    form.elements.namedItem('currentAddress').value = '75 Cedar Road, Downtown';
    form.elements.namedItem('serviceRadiusKm').value = '12';
    form.elements.namedItem('avoidHighways').checked = true;
    form.requestSubmit();
    return true;
  })()`);
  await waitFor(client, "SavoraDriverState.load().location.address === '75 Cedar Road, Downtown'", 'profile address persistence');
  const state = await evaluate(client, `(() => {
    const driver = SavoraDriverState.load();
    return {
      radius: driver.serviceRadiusKm,
      avoidHighways: driver.preferences.avoidHighways,
      method: driver.location.method
    };
  })()`);
  assert(state.radius === 12, 'profile service radius did not persist');
  assert(state.avoidHighways, 'profile preferences did not persist');
  assert(state.method === 'manual', 'manual profile address did not set manual location mode');
}

async function run() {
  await fs.mkdir(path.join(artifactDir, 'screenshots'), { recursive: true });
  const client = await openPage();
  const runtimeErrors = [];
  client.on('Runtime.exceptionThrown', params => {
    const details = params.exceptionDetails || {};
    runtimeErrors.push(details.exception?.description || details.text || 'Unknown runtime exception');
  });
  const report = { routes: [], interactions: {} };
  const runInteraction = async (name, callback) => {
    runtimeErrors.length = 0;
    await callback(client);
    await delay(100);
    assert(!runtimeErrors.length, `${name} raised runtime errors: ${runtimeErrors.join(' | ')}`);
    report.interactions[name] = true;
  };
  try {
    await loginAs(client, 'driver');
    await seedLocalDemo(client);
    report.routes = await checkRouteMatrix(client, runtimeErrors);
    await runInteraction('mobileNavigation', checkMobileNavigation);
    await runInteraction('manualLocation', checkManualLocation);
    await runInteraction('offerDecision', checkOfferDecisionFlow);
    await runInteraction('deliveryLifecycle', checkDeliveryFlow);
    await runInteraction('profilePersistence', checkProfilePersistence);
    await fs.writeFile(path.join(artifactDir, 'report.json'), JSON.stringify(report, null, 2));
    console.log(`Driver browser QA passed: ${report.routes.length} route/viewport checks and ${Object.keys(report.interactions).length} interaction groups.`);
  } finally {
    await client.cleanup();
  }
}

run().catch(error => {
  console.error(error.stack || error.message || error);
  process.exitCode = 1;
});
