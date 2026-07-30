import fs from 'node:fs/promises';
import path from 'node:path';

const debugPort = Number(process.env.SAVORA_CDP_PORT || 9227);
const baseUrl = process.env.SAVORA_BASE_URL || 'http://localhost/Savora';
const artifactDir = path.resolve('.superpowers/sdd/restaurant-portal/browser-qa');
const routes = [
  'restaurant_dashboard.php',
  'restaurant_orders.php',
  'restaurant_order_history.php',
  'restaurant_menu.php',
  'restaurant_menu_item.php',
  'restaurant_profile.php',
  'restaurant_operations.php',
  'restaurant_finance.php',
  'restaurant_invoices.php',
  'restaurant_analytics.php',
  'restaurant_reviews.php'
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
  if (!target?.webSocketDebuggerUrl) {
    await browserClient.send('Target.disposeBrowserContext', { browserContextId });
    browserClient.close();
    throw new Error('Unable to connect to isolated browser page');
  }
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
    try { await browserClient.send('Target.closeTarget', { targetId }); } catch (_) { /* target may already be closed */ }
    try { await browserClient.send('Target.disposeBrowserContext', { browserContextId }); } catch (_) { /* context may already be disposed */ }
    browserClient.close();
  };
  return client;
}

async function evaluate(client, expression) {
  const result = await client.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
  if (result.exceptionDetails) {
    throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text || 'Runtime evaluation failed');
  }
  return result.result ? result.result.value : undefined;
}

async function waitFor(client, expression, message, timeout = 8000) {
  const started = Date.now();
  while (Date.now() - started < timeout) {
    if (await evaluate(client, expression)) return;
    await delay(75);
  }
  throw new Error(`Timed out: ${message}`);
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
  const destination = role === 'restaurant' ? 'restaurant_dashboard.php' : 'customer_dashboard.php';
  await waitFor(client, `location.pathname.endsWith('/${destination}')`, `${role} login`);
}

async function seedLocalDemo(client) {
  await navigate(client, 'restaurant_dashboard.php');
  await evaluate(client, `(() => {
    localStorage.clear();
    const customer = SavoraState.normalize({
      profile: { fullName: 'QA Customer', email: 'qa@example.test', address: '12 QA Street' },
      orders: [
        {
          id: 'QA-LIVE',
          status: 'pending',
          restaurantId: 'savora-kitchen',
          restaurantName: 'Savora Kitchen',
          customerId: 'qa@example.test',
          customerName: 'QA Customer',
          customerEmail: 'qa@example.test',
          address: '12 QA Street',
          items: [{ lineId: 'qa-live-line', id: 'qa-dish', restaurantId: 'savora-kitchen', restaurantName: 'Savora Kitchen', name: 'QA Dish', unitPrice: 12, quantity: 1 }],
          subtotal: 12,
          deliveryFee: 2,
          total: 14,
          createdAt: '2026-07-30T12:00:00.000Z'
        },
        {
          id: 'QA-REVIEW',
          status: 'completed',
          restaurantId: 'savora-kitchen',
          restaurantName: 'Savora Kitchen',
          customerId: 'qa@example.test',
          customerName: 'QA Customer',
          customerEmail: 'qa@example.test',
          address: '12 QA Street',
          items: [{ lineId: 'qa-review-line', id: 'qa-dish', restaurantId: 'savora-kitchen', restaurantName: 'Savora Kitchen', name: 'QA Dish', unitPrice: 12, quantity: 1 }],
          subtotal: 12,
          deliveryFee: 2,
          total: 14,
          createdAt: '2026-07-29T12:00:00.000Z',
          review: { id: 'qa-review', rating: 5, comment: 'Fresh and fast', createdAt: '2026-07-29T13:00:00.000Z', topics: ['Fresh', 'Fast'] }
        }
      ]
    });
    SavoraState.persist(customer);
    let restaurant = SavoraRestaurantState.defaultState();
    restaurant = SavoraRestaurantState.setProfile(restaurant, {
      name: 'Savora Kitchen',
      cuisine: 'Local comfort food',
      description: 'QA storefront description',
      locationMethod: 'manual',
      addressLine1: '1 Initial Street',
      city: 'Bangkok',
      country: 'Thailand'
    });
    restaurant = SavoraRestaurantState.setOperations(restaurant, {
      acceptingOrders: true,
      deliveryEnabled: true,
      pickupEnabled: true,
      weeklyHours: {
        monday: { open: '00:00', close: '23:59' },
        tuesday: { open: '00:00', close: '23:59' },
        wednesday: { open: '00:00', close: '23:59' },
        thursday: { open: '00:00', close: '23:59' },
        friday: { open: '00:00', close: '23:59' },
        saturday: { open: '00:00', close: '23:59' },
        sunday: { open: '00:00', close: '23:59' }
      }
    });
    restaurant = SavoraRestaurantState.setMenuItem(restaurant, {
      id: 'qa-dish',
      name: 'QA Dish',
      description: 'Browser QA menu item',
      category: 'lunch',
      price: 12,
      image: 'assets/images/catalog/mega-burger-feast-combo.jpg',
      available: true,
      status: 'published'
    });
    SavoraRestaurantState.persist(restaurant);
    return true;
  })()`);
  await navigate(client, 'restaurant_dashboard.php');
}

async function pressKey(client, key, code, virtualKeyCode, modifiers = 0) {
  await client.send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: virtualKeyCode, modifiers });
  await client.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: virtualKeyCode, modifiers });
}

async function checkRouteMatrix(client, runtimeErrors) {
  const results = [];
  for (const viewport of viewports) {
    await setViewport(client, viewport.width, viewport.height);
    for (const route of routes) {
      runtimeErrors.length = 0;
      await navigate(client, route);
      const status = await evaluate(client, `(() => {
        const main = document.querySelector('main');
        const text = document.body ? document.body.innerText : '';
        const vietnameseMatch = text.match(/[ăâđêôơưĂÂĐÊÔƠƯàáạảãầấậẩẫằắặẳẵèéẹẻẽềếệểễìíịỉĩòóọỏõồốộổỗờớợởỡùúụủũừứựửữỳýỵỷỹ]/);
        return {
          title: document.title,
          main: Boolean(main),
          overflow: document.documentElement.scrollWidth - window.innerWidth,
          hasVietnamese: Boolean(vietnameseMatch),
          vietnameseContext: vietnameseMatch
            ? text.slice(Math.max(0, vietnameseMatch.index - 30), vietnameseMatch.index + 31)
            : ''
        };
      })()`);
      assert(status.main, `${route} has no main landmark at ${viewport.width}px`);
      assert(status.title.includes('Savora'), `${route} has an invalid title at ${viewport.width}px`);
      assert(status.overflow <= 1, `${route} overflows horizontally by ${status.overflow}px at ${viewport.width}px`);
      assert(!status.hasVietnamese, `${route} contains non-English UI at ${viewport.width}px: ${JSON.stringify(status.vietnameseContext)}`);
      const screenshot = await client.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
      await fs.writeFile(path.join(artifactDir, 'screenshots', `${route}-${viewport.width}.png`), Buffer.from(screenshot.data, 'base64'));
      await evaluate(client, `(() => {
        const target = document.querySelector('main button:not([disabled]), main a[href], main input:not([disabled]), main select:not([disabled]), main textarea:not([disabled])');
        if (target) target.focus();
        return Boolean(target);
      })()`);
      await pressKey(client, 'Tab', 'Tab', 9);
      const focus = await evaluate(client, `(() => {
        const active = document.activeElement;
        const style = active ? getComputedStyle(active) : null;
        return {
          tag: active && active.tagName,
          visible: Boolean(style && style.outlineStyle !== 'none' && Number.parseFloat(style.outlineWidth) > 0)
        };
      })()`);
      assert(focus.tag && focus.tag !== 'BODY', `${route} has no keyboard focus target at ${viewport.width}px`);
      assert(focus.visible, `${route} has no visible focus indicator at ${viewport.width}px`);
      assert(!runtimeErrors.length, `${route} raised runtime errors at ${viewport.width}px: ${runtimeErrors.join(' | ')}`);
      results.push({ route, width: viewport.width, title: status.title, focus: focus.tag });
    }
  }
  return results;
}

async function checkMobileNavigation(client) {
  await setViewport(client, 320, 900);
  await navigate(client, 'restaurant_dashboard.php');
  await evaluate(client, "document.querySelector('.restaurant-mobile-menu-button').click()");
  await waitFor(client, "!document.getElementById('restaurant-mobile-navigation').hidden", 'opening Restaurant mobile navigation');
  assert(await evaluate(client, "document.querySelector('.restaurant-mobile-menu-button').getAttribute('aria-expanded') === 'true'"), 'mobile menu trigger did not expand');
  await pressKey(client, 'Escape', 'Escape', 27);
  await waitFor(client, "document.getElementById('restaurant-mobile-navigation').hidden", 'closing Restaurant mobile navigation');
  assert(await evaluate(client, "document.querySelector('.restaurant-mobile-menu-button').getAttribute('aria-expanded') === 'false'"), 'mobile menu trigger did not collapse');
}

async function checkAcceptingOrders(client) {
  await setViewport(client, 1440, 1000);
  await navigate(client, 'restaurant_dashboard.php');
  await evaluate(client, "document.querySelector('[data-accepting-orders]').click()");
  assert(await evaluate(client, "SavoraRestaurantState.load().operations.acceptingOrders === false"), 'pausing orders did not persist');

  await loginAs(client, 'customer');
  await navigate(client, 'customer_dashboard.php');
  await evaluate(client, `(() => {
    const state = SavoraState.load();
    state.cart = [];
    SavoraState.persist(state);
    SavoraUI.addCatalogProduct(SavoraCatalog.products['qa-dish']);
    return SavoraState.load().cart.length;
  })()`);
  await navigate(client, 'customer_checkout.php');
  const orderCount = await evaluate(client, 'SavoraState.load().orders.length');
  await evaluate(client, "document.getElementById('checkout-form').requestSubmit()");
  await waitFor(client, "document.getElementById('toast-container').innerText.includes('not accepting orders')", 'paused Restaurant checkout rejection');
  assert(await evaluate(client, `SavoraState.load().orders.length === ${orderCount}`), 'paused Restaurant checkout created a Customer order');

  await loginAs(client, 'restaurant');
  await navigate(client, 'restaurant_dashboard.php');
  await evaluate(client, "document.querySelector('[data-accepting-orders]').click()");
  assert(await evaluate(client, "SavoraRestaurantState.load().operations.acceptingOrders === true"), 'resuming orders did not persist');
}

async function checkOrderAndMenuBridge(client) {
  await navigate(client, 'restaurant_orders.php');
  await waitFor(client, "Boolean(document.querySelector('[data-order-select=\"QA-LIVE\"]'))", 'QA live order');
  await evaluate(client, "document.querySelector('[data-order-select=\"QA-LIVE\"]').click()");
  await evaluate(client, "document.querySelector('[data-order-action=\"accept\"]:not([disabled])').click()");
  await waitFor(client, "SavoraState.load().orders.find(order => order.id === 'QA-LIVE')?.status === 'confirmed'", 'accepting QA order');

  await navigate(client, 'restaurant_menu.php');
  await waitFor(client, "Boolean(document.querySelector('[data-menu-availability-toggle=\"qa-dish\"]'))", 'QA menu item');
  await evaluate(client, "document.querySelector('[data-menu-availability-toggle=\"qa-dish\"]').click()");
  await waitFor(client, "SavoraRestaurantState.load().menuItems.find(item => item.id === 'qa-dish')?.available === false", 'menu availability persistence');
}

async function checkProfileAndGeolocation(client) {
  await navigate(client, 'restaurant_profile.php');
  await evaluate(client, `(() => {
    Object.defineProperty(navigator, 'geolocation', {
      configurable: true,
      value: { getCurrentPosition: (_success, error) => error({ code: 1, message: 'Denied for QA' }) }
    });
    document.querySelector('[data-use-current-location]').click();
    return true;
  })()`);
  await waitFor(client, "document.querySelector('[data-address-feedback]').textContent.includes('could not be used')", 'geolocation error fallback');
  await evaluate(client, `(() => {
    document.querySelector('[data-manual-address]').click();
    const form = document.querySelector('[data-store-profile-form]');
    form.elements.namedItem('address-line1').value = '88 Browser QA Avenue';
    form.elements.namedItem('address-city').value = 'Bangkok';
    form.elements.namedItem('address-country').value = 'Thailand';
    form.requestSubmit();
    return true;
  })()`);
  await waitFor(client, "SavoraRestaurantState.load().profile.addressLine1 === '88 Browser QA Avenue'", 'manual address persistence');
}

async function checkReviewPersistence(client) {
  await navigate(client, 'restaurant_reviews.php');
  await waitFor(client, "!document.querySelector('[name=\"review-public-reply\"]').disabled", 'verified review composer');
  await evaluate(client, `(() => {
    const field = document.querySelector('[name="review-public-reply"]');
    field.value = 'Thank you from browser QA';
    field.dispatchEvent(new Event('input', { bubbles: true }));
    document.querySelector('[data-review-save-draft]').click();
    return true;
  })()`);
  await waitFor(client, "SavoraRestaurantState.load().reviews.find(review => review.id === 'qa-review')?.status === 'draft'", 'review draft persistence');
  await evaluate(client, "document.querySelector('[data-review-publish]').click()");
  await waitFor(client, "SavoraRestaurantState.load().reviews.find(review => review.id === 'qa-review')?.status === 'published'", 'review publish persistence');
}

async function checkCustomerBridge(client) {
  await loginAs(client, 'customer');
  await navigate(client, 'customer_history.php');
  const historyText = await evaluate(client, "document.querySelector('main').innerText");
  assert(historyText.includes('QA-LIVE') && historyText.includes('Confirmed'), 'Customer History did not receive the Restaurant order status');

  await navigate(client, 'customer_dashboard.php');
  await waitFor(client, "Boolean([...document.querySelectorAll('.restaurant-discovery-card')].find(card => card.innerText.includes('Savora Kitchen')))", 'Savora Kitchen discovery card');
  await evaluate(client, "[...document.querySelectorAll('.restaurant-discovery-card')].find(card => card.innerText.includes('Savora Kitchen')).click()");
  await waitFor(client, "!document.getElementById('menu-modal').hidden", 'opening Customer restaurant menu');
  const availability = await evaluate(client, `(() => {
    const card = [...document.querySelectorAll('#modal-food-grid .food-card')].find(item => item.innerText.includes('QA Dish'));
    if (!card) return { present: false, blocked: false };
    const button = card.querySelector('button');
    return { present: true, blocked: Boolean(button && button.disabled && /unavailable/i.test(button.textContent)) };
  })()`);
  assert(availability.present && availability.blocked, 'Customer menu did not expose the Restaurant-unavailable item as blocked');
  const guardedCart = await evaluate(client, `(() => {
    const before = JSON.stringify(SavoraState.load().cart);
    SavoraUI.addCatalogProduct(SavoraCatalog.products['qa-dish']);
    return { before, after: JSON.stringify(SavoraState.load().cart) };
  })()`);
  assert(guardedCart.after === guardedCart.before, 'Customer availability guard added an unavailable item');
  const storefront = await evaluate(client, "[...document.querySelectorAll('.restaurant-discovery-card')].find(card => card.innerText.includes('Savora Kitchen')).innerText");
  assert(storefront.includes('QA storefront description'), 'Customer discovery did not receive the Restaurant storefront profile');
}

async function run() {
  await fs.mkdir(artifactDir, { recursive: true });
  await fs.mkdir(path.join(artifactDir, 'screenshots'), { recursive: true });
  const client = await openPage();
  const runtimeErrors = [];
  client.on('Runtime.exceptionThrown', params => {
    const details = params.exceptionDetails || {};
    const description = details.exception?.description || details.text || 'Unknown runtime exception';
    runtimeErrors.push(`${details.url || 'inline'}:${Number(details.lineNumber || 0) + 1}:${Number(details.columnNumber || 0) + 1} ${description}`);
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
    await loginAs(client, 'restaurant');
    await seedLocalDemo(client);
    report.routes = await checkRouteMatrix(client, runtimeErrors);
    await runInteraction('mobileNavigation', checkMobileNavigation);
    await runInteraction('acceptingOrders', checkAcceptingOrders);
    await runInteraction('orderAndMenu', checkOrderAndMenuBridge);
    await runInteraction('profileAndGeolocation', checkProfileAndGeolocation);
    await runInteraction('reviewReply', checkReviewPersistence);
    await runInteraction('customerBridge', checkCustomerBridge);
    await fs.writeFile(path.join(artifactDir, 'report.json'), JSON.stringify(report, null, 2));
    console.log(`Restaurant browser QA passed: ${report.routes.length} route/viewport checks and ${Object.keys(report.interactions).length} integration groups.`);
  } finally {
    await client.cleanup();
  }
}

run().catch(error => {
  console.error(error.stack || error.message || error);
  process.exitCode = 1;
});
