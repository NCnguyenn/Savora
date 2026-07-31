import fs from 'node:fs/promises';
import path from 'node:path';

const debugPort = Number(process.env.SAVORA_CDP_PORT || 9227);
const baseUrl = process.env.SAVORA_BASE_URL || 'http://localhost/Savora';
const artifactDir = path.resolve('.superpowers/sdd/customer-ui-2026-07-29/task-7-qa');
const routes = {
  discover: 'customer_dashboard.php',
  product: 'product_detail.php?id=2',
  cart: 'customer_cart.php',
  checkout: 'customer_checkout.php',
  orders: 'customer_history.php',
  favorites: 'customer_favorites.php',
  profile: 'customer_profile.php',
  wallet: 'customer_wallet.php'
};
const expectedCriticalImages = {
  discover: 4,
  product: 1,
  cart: 1,
  checkout: 1,
  orders: 1,
  favorites: 1,
  profile: 0,
  wallet: 0
};
const expectedCriticalBackgrounds = {
  discover: 2,
  product: 0,
  cart: 0,
  checkout: 0,
  orders: 0,
  favorites: 0,
  profile: 0,
  wallet: 0
};
const expectedDocumentTitles = {
  discover: 'Discover | Savora',
  product: 'Supreme Pepperoni Pizza 12" | Savora',
  cart: 'Your cart | Savora',
  checkout: 'Checkout | Savora',
  orders: 'Your orders | Savora',
  favorites: 'Favorites | Savora',
  profile: 'Your profile | Savora',
  wallet: 'Savora Pay | Savora'
};

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

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

async function openPage() {
  const response = await fetch(`http://127.0.0.1:${debugPort}/json/new?about:blank`, { method: 'PUT' });
  if (!response.ok) throw new Error(`Unable to create browser page: ${response.status}`);
  const target = await response.json();
  const client = new CdpClient(target.webSocketDebuggerUrl);
  await client.connect();
  await Promise.all([
    client.send('Page.enable'),
    client.send('Runtime.enable'),
    client.send('Network.enable')
  ]);
  await client.send('Network.setCacheDisabled', { cacheDisabled: true });
  await client.send('Page.bringToFront');
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

async function waitFor(client, expression, message, timeout = 7000) {
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
  await waitFor(client, "document.readyState !== 'loading'", `loading ${url}`);
}

async function setViewport(client, width) {
  await client.send('Emulation.setDeviceMetricsOverride', {
    width,
    height: 900,
    deviceScaleFactor: 1,
    mobile: false
  });
}

async function screenshot(client, filename) {
  const result = await client.send('Page.captureScreenshot', {
    format: 'png',
    fromSurface: true,
    captureBeyondViewport: true
  });
  await fs.writeFile(path.join(artifactDir, filename), Buffer.from(result.data, 'base64'));
}

async function visualAssetStatus(client, expectedImages, expectedBackgrounds, context) {
  await evaluate(client, "document.fonts ? document.fonts.ready.then(() => true) : true");
  if (expectedImages > 0) {
    await waitFor(
      client,
      `document.querySelectorAll('main img[src^="assets/images/"]').length >= ${expectedImages}`,
      `${context} local catalog images`,
      9000
    );
  }
  await waitFor(client, `(() => {
    const images = [...document.querySelectorAll('main img[src^="assets/images/"]')];
    return images.length >= ${expectedImages} && images.every(image => image.complete);
  })()`, `${context} image settlement`, 9000);
  const status = await evaluate(client, `(async () => {
    const images = [...document.querySelectorAll('main img[src^="assets/images/"]')];
    const icons = [...document.querySelectorAll('.fa-solid, .fa-regular')];
    const visibleBackgroundElements = [...document.querySelectorAll('[data-critical-background]')].filter(element => {
      const style = getComputedStyle(element);
      const bounds = element.getBoundingClientRect();
      return style.display !== 'none' && style.visibility !== 'hidden' && bounds.width > 0 && bounds.height > 0;
    });
    const backgroundEntries = visibleBackgroundElements.flatMap(element => {
      const style = getComputedStyle(element);
      const backgroundImage = style.backgroundImage;
      return [...backgroundImage.matchAll(/url[(]["']?([^"')]+)["']?[)]/g)].map(match => {
        const absoluteUrl = new URL(match[1], location.href);
        return {
          element: element.className,
          src: absoluteUrl.href,
          sameOrigin: absoluteUrl.origin === location.origin,
          fallback: element.dataset.backgroundFallback || '',
          backgroundImage
        };
      });
    });
    const backgrounds = await Promise.all(backgroundEntries.map(entry => new Promise(resolve => {
      const image = new Image();
      let settled = false;
      const finish = loaded => {
        if (settled) return;
        settled = true;
        resolve({
          ...entry,
          loaded,
          naturalWidth: image.naturalWidth,
          naturalHeight: image.naturalHeight
        });
      };
      image.addEventListener('load', () => finish(true), { once: true });
      image.addEventListener('error', () => finish(false), { once: true });
      image.src = entry.src;
      if (image.complete) finish(image.naturalWidth > 0);
      setTimeout(() => finish(false), 5000);
    })));
    return {
      fontSetStatus: document.fonts ? document.fonts.status : 'unsupported',
      icons: icons.map(icon => {
        const style = getComputedStyle(icon, '::before');
        return { className: icon.className, family: style.fontFamily, content: style.content };
      }),
      images: images.map(image => ({
        src: image.getAttribute('src'),
        complete: image.complete,
        naturalWidth: image.naturalWidth,
        naturalHeight: image.naturalHeight,
        fallback: image.getAttribute('src').endsWith('/food-placeholder.svg') || image.getAttribute('src') === 'assets/images/food-placeholder.svg'
      })),
      backgrounds
    };
  })()`);
  const missingGlyph = status.icons.find(icon => !icon.family.includes('Font Awesome') || icon.content === 'none' || icon.content === 'normal' || icon.content === '""');
  const brokenImage = status.images.find(image => !image.complete || image.naturalWidth <= 0 || image.naturalHeight <= 0);
  const brokenBackground = status.backgrounds.find(background => !background.sameOrigin || !background.loaded || background.naturalWidth <= 0 || background.naturalHeight <= 0);
  assert(status.fontSetStatus === 'loaded' && status.icons.length > 0 && !missingGlyph, `${context} icon font degraded: ${JSON.stringify(status)}`);
  assert(status.images.length >= expectedImages && !brokenImage, `${context} critical image degraded: ${JSON.stringify(status)}`);
  assert(status.backgrounds.length >= expectedBackgrounds && !brokenBackground, `${context} critical CSS background degraded: ${JSON.stringify(status)}`);
  return {
    fontSetStatus: status.fontSetStatus,
    iconCount: status.icons.length,
    criticalImageCount: status.images.length,
    fallbackImageCount: status.images.filter(image => image.fallback).length,
    images: status.images,
    criticalBackgroundCount: status.backgrounds.length,
    backgrounds: status.backgrounds
  };
}

async function login(client) {
  await navigate(client, 'index.php');
  const onLogin = await evaluate(client, "Boolean(document.getElementById('username') && document.getElementById('password'))");
  if (onLogin) {
    await evaluate(client, `(() => {
      document.getElementById('username').value = 'customer';
      document.getElementById('password').value = '123456';
      document.querySelector('form').submit();
      return true;
    })()`);
  }
  await waitFor(client, "location.pathname.endsWith('/customer_dashboard.php')", 'customer login');
}

async function checkMobileMenuKeyboard(client) {
  await setViewport(client, 320);
  await navigate(client, routes.discover);
  await evaluate(client, "document.querySelector('.mobile-menu-toggle').click(); true");
  await waitFor(client, "document.getElementById('customer-mobile-menu').dataset.open === 'true'", 'opening mobile menu');
  await waitFor(client, "document.activeElement?.closest('#customer-mobile-menu')", 'moving focus into mobile menu');
  await screenshot(client, 'mobile-menu-320.png');
  const opened = await evaluate(client, `(() => {
    const nav = document.getElementById('customer-mobile-menu');
    const links = [...nav.querySelectorAll('a')];
    return {
      expanded: document.querySelector('.mobile-menu-toggle').getAttribute('aria-expanded'),
      activeHref: document.activeElement.getAttribute('href'),
      focusableLinks: links.length,
      allNamed: links.every(link => link.textContent.trim().length > 0)
    };
  })()`);
  assert(opened.expanded === 'true' && opened.focusableLinks >= 5 && opened.allNamed, `mobile menu is not keyboard ready: ${JSON.stringify(opened)}`);
  const escaped = await evaluate(client, `(() => {
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    const toggle = document.querySelector('.mobile-menu-toggle');
    return {
      open: document.getElementById('customer-mobile-menu').dataset.open,
      expanded: toggle.getAttribute('aria-expanded'),
      focusReturned: document.activeElement === toggle
    };
  })()`);
  assert(escaped.open === 'false' && escaped.expanded === 'false' && escaped.focusReturned, `mobile menu Escape/focus return failed: ${JSON.stringify(escaped)}`);

  await evaluate(client, "document.querySelector('.mobile-menu-toggle').click(); true");
  await waitFor(client, "document.activeElement?.closest('#customer-mobile-menu')", 'moving focus into reopened mobile menu');
  await evaluate(client, "document.querySelector('main h1').click(); true");
  const outside = await evaluate(client, `(() => {
    const toggle = document.querySelector('.mobile-menu-toggle');
    return {
      open: document.getElementById('customer-mobile-menu').dataset.open,
      expanded: toggle.getAttribute('aria-expanded'),
      focusReturned: document.activeElement === toggle
    };
  })()`);
  assert(outside.open === 'false' && outside.expanded === 'false' && outside.focusReturned, `mobile outside click did not close with safe focus: ${JSON.stringify(outside)}`);

  await evaluate(client, `(() => {
    document.querySelector('.mobile-menu-toggle').click();
    document.querySelector('#customer-mobile-menu a[href="customer_favorites.php"]').click();
    return true;
  })()`);
  await waitFor(client, "location.pathname.endsWith('/customer_favorites.php')", 'mobile menu link navigation');
  const linked = await evaluate(client, `({
    path: location.pathname,
    open: document.getElementById('customer-mobile-menu').dataset.open,
    expanded: document.querySelector('.mobile-menu-toggle').getAttribute('aria-expanded')
  })`);
  assert(linked.open === 'false' && linked.expanded === 'false', `mobile link navigation left menu open: ${JSON.stringify(linked)}`);
  return { opened, escaped, outside, linked };
}

async function checkDesktopNavigation(client) {
  await setViewport(client, 1440);
  await navigate(client, routes.discover);
  const desktop = await evaluate(client, `(() => {
    const nav = document.getElementById('customer-mobile-menu');
    const toggle = document.querySelector('.mobile-menu-toggle');
    const link = nav.querySelector('a[href="customer_history.php"]');
    link.focus();
    const before = { open: nav.dataset.open, focused: document.activeElement.getAttribute('href') };
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    return {
      navDisplay: getComputedStyle(nav).display,
      toggleDisplay: getComputedStyle(toggle).display,
      before,
      after: { open: nav.dataset.open, focused: document.activeElement.getAttribute('href') }
    };
  })()`);
  assert(desktop.navDisplay === 'flex' && desktop.toggleDisplay === 'none', `desktop navigation visibility regressed: ${JSON.stringify(desktop)}`);
  assert(desktop.before.open === desktop.after.open && desktop.before.focused === desktop.after.focused, `desktop Escape changed nav state/focus: ${JSON.stringify(desktop)}`);
  return desktop;
}

async function checkIntentionalImageFallback(client) {
  await navigate(client, routes.discover);
  const fallback = await evaluate(client, `new Promise(resolve => {
    const image = new Image();
    image.addEventListener('load', () => resolve({
      src: image.getAttribute('src'),
      complete: image.complete,
      naturalWidth: image.naturalWidth,
      naturalHeight: image.naturalHeight
    }), { once: true });
    image.addEventListener('error', () => resolve({
      src: image.getAttribute('src'),
      complete: image.complete,
      naturalWidth: image.naturalWidth,
      naturalHeight: image.naturalHeight
    }), { once: true });
    image.src = SavoraCatalog.imageFor({ image: 'https://attacker.invalid/not-catalog.jpg' });
  })`);
  assert(fallback.src === 'assets/images/food-placeholder.svg' && fallback.complete && fallback.naturalWidth > 0 && fallback.naturalHeight > 0, `intentional local image fallback failed: ${JSON.stringify(fallback)}`);
  return fallback;
}

async function checkDiscoveryRuntime(client) {
  await setViewport(client, 1440);
  await navigate(client, routes.discover);
  await evaluate(client, "localStorage.removeItem(SavoraState.KEY); location.reload(); true");
  await waitFor(client, "document.getElementById('product-result-count')?.textContent.startsWith('4')", 'initial discovery render');
  const emptyTracking = await evaluate(client, `({
    hasMap: Boolean(document.getElementById('order-map')),
    copy: document.getElementById('active-order-content').textContent.trim()
  })`);
  assert(!emptyTracking.hasMap && emptyTracking.copy.includes('Nothing on the way yet'), 'empty discovery tracking fabricated an active order or map');

  await evaluate(client, `(() => {
    const input = document.getElementById('search-input');
    input.value = 'Pizza Hut';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    return true;
  })()`);
  await waitFor(client, "document.getElementById('product-result-count').textContent === '1 dish' && document.getElementById('restaurant-result-count').textContent === '1 restaurant'", 'composed restaurant search');
  const search = await evaluate(client, `({
    products: document.querySelectorAll('#food-products-grid .discovery-card').length,
    restaurants: document.querySelectorAll('#restaurant-grid .discovery-card').length,
    productText: document.getElementById('food-products-grid').textContent,
    restaurantText: document.getElementById('restaurant-grid').textContent
  })`);
  assert(search.products === 1 && search.restaurants === 1 && search.productText.includes('Pizza Hut') && search.restaurantText.includes('Pizza Hut'), 'discovery does not search dish and restaurant names together');

  await evaluate(client, "document.querySelector('[data-category=\"burger\"]').click(); true");
  await waitFor(client, "document.getElementById('product-result-count').textContent === '0 dishes' && document.getElementById('restaurant-result-count').textContent === '0 restaurants'", 'category and search intersection');
  const intersection = await evaluate(client, `({
    productEmpty: document.getElementById('food-products-grid').textContent.includes('No dishes match'),
    restaurantEmpty: document.getElementById('restaurant-grid').textContent.includes('No restaurants match')
  })`);
  assert(intersection.productEmpty && intersection.restaurantEmpty, 'composed discovery filters did not render both empty states');
  return { emptyTracking, search, intersection };
}

async function addConfiguredProduct(client) {
  await navigate(client, routes.product);
  await waitFor(client, "!document.getElementById('product-detail-content').hidden", 'product detail render');
  await evaluate(client, `(() => {
    document.getElementById('portion-2-large').click();
    document.getElementById('addon-2-2-cheese').click();
    document.getElementById('increase-quantity').click();
    const note = document.getElementById('special-notes');
    note.value = '<img src=x onerror=window.__task7Xss=1>';
    note.dispatchEvent(new Event('input', { bubbles: true }));
    return true;
  })()`);
  const configured = await evaluate(client, `({
    total: document.getElementById('page-total-price').textContent,
    quantity: document.getElementById('page-qty').textContent,
    portion: document.getElementById('portion-2-large').checked,
    cheese: document.getElementById('addon-2-2-cheese').checked
  })`);
  assert(configured.total === '$39.48' && configured.quantity === '2' && configured.portion && configured.cheese, `product option price is incorrect: ${JSON.stringify(configured)}`);
  await evaluate(client, "document.getElementById('product-customization-form').requestSubmit(); true");
  await waitFor(client, "SavoraState.load().cart.length === 1 && SavoraState.load().cart[0].quantity === 2", 'configured cart insertion');
  const stored = await evaluate(client, `(() => {
    const line = SavoraState.load().cart[0];
    return { quantity: line.quantity, unitPrice: line.unitPrice, options: line.options, note: line.note };
  })()`);
  assert(Math.abs(stored.unitPrice - 19.74) < 0.000001 && stored.options.some(option => option.id === 'portion-large') && stored.options.some(option => option.id === '2-cheese'), `configured cart state is incorrect: ${JSON.stringify(stored)}`);
  return { configured, stored };
}

async function checkCartDialogKeyboard(client) {
  await setViewport(client, 320);
  await navigate(client, routes.cart);
  const cartEvidence = await evaluate(client, `(() => {
    const remove = document.querySelector('.remove-line-button');
    return {
      removeName: remove?.getAttribute('aria-label'),
      literalNote: document.getElementById('full-cart-items').textContent.includes('<img src=x onerror=window.__task7Xss=1>'),
      injectedImageCount: [...document.querySelectorAll('#full-cart-items img')].filter(image => image.getAttribute('src') === 'x').length,
      trustedImage: document.querySelector('#full-cart-items img')?.src === new URL(SavoraCatalog.products['2'].image, location.href).href
    };
  })()`);
  assert(cartEvidence.removeName === 'Remove Supreme Pepperoni Pizza 12"', `cart removal name is not item-specific: ${JSON.stringify(cartEvidence)}`);
  assert(cartEvidence.literalNote && cartEvidence.injectedImageCount === 0 && cartEvidence.trustedImage, `cart persisted text/image boundary failed: ${JSON.stringify(cartEvidence)}`);

  await evaluate(client, "document.getElementById('open-cart-btn').click(); true");
  await waitFor(client, "document.getElementById('cart-overlay').classList.contains('active')", 'opening cart drawer');
  await waitFor(client, "document.activeElement.getAttribute('aria-label') === 'Close cart'", 'cart drawer initial focus');
  await screenshot(client, 'cart-drawer-320.png');
  const trap = await evaluate(client, `(() => {
    const dialog = document.getElementById('cart-overlay');
    const nodes = [...dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')].filter(node => !node.hidden);
    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    last.focus();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));
    const forward = document.activeElement === first;
    first.focus();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true }));
    return { forward, backward: document.activeElement === last, stayedInside: dialog.contains(document.activeElement) };
  })()`);
  assert(trap.forward && trap.backward && trap.stayedInside, `cart dialog focus trap failed: ${JSON.stringify(trap)}`);
  const escape = await evaluate(client, `(() => {
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    return {
      hidden: document.getElementById('cart-overlay').hidden,
      focusReturned: document.activeElement.id === 'open-cart-btn'
    };
  })()`);
  assert(escape.hidden && escape.focusReturned, `cart dialog Escape/focus return failed: ${JSON.stringify(escape)}`);
  return { cartEvidence, trap, escape };
}

async function placeOrderAndReorder(client) {
  await setViewport(client, 1440);
  await navigate(client, routes.checkout);
  await waitFor(client, "document.querySelectorAll('#checkout-items-list .checkout-item').length === 1", 'checkout summary render');
  await evaluate(client, `(() => {
    document.getElementById('checkout-address').value = '77 Task Seven Street';
    document.getElementById('checkout-note').value = '<img src=x onerror=window.__task7Xss=1> Leave at reception';
    document.getElementById('pay_cash').click();
    document.getElementById('checkout-form').requestSubmit();
    return true;
  })()`);
  await waitFor(client, "SavoraState.load().orders.length === 1 && !document.getElementById('checkout-success').hidden", 'local demo order placement');
  const placed = await evaluate(client, `(() => {
    const state = SavoraState.load();
    return {
      cartCount: state.cart.length,
      orderCount: state.orders.length,
      order: state.orders[0],
      success: document.getElementById('checkout-success').textContent
    };
  })()`);
  assert(placed.cartCount === 0 && placed.orderCount === 1 && placed.order.items[0].quantity === 2 && placed.order.items[0].options.length === 2, `checkout lost configuration or duplicated the order: ${JSON.stringify(placed)}`);
  assert(placed.order.deliveryNote === '<img src=x onerror=window.__task7Xss=1> Leave at reception', `checkout discarded delivery note: ${JSON.stringify(placed)}`);
  await screenshot(client, 'checkout-success-1440.png');
  await waitFor(client, "location.pathname.endsWith('/customer_history.php')", 'checkout redirect to Orders', 5000);
  await waitFor(client, "document.querySelector('.order-reorder-button')", 'order history render');
  const deliveryNote = await evaluate(client, `(() => ({
    state: SavoraState.load().orders[0].deliveryNote,
    historyText: document.querySelector('.order-delivery-note')?.textContent || '',
    trackingText: document.querySelector('.active-order-delivery-note')?.textContent || '',
    injected: Boolean(document.querySelector('.order-delivery-note img, .active-order-delivery-note img')),
    xss: window.__task7Xss || 0
  }))()`);
  assert(deliveryNote.state.includes('<img src=x') && deliveryNote.historyText.includes('<img src=x') && deliveryNote.trackingText.includes('<img src=x') && !deliveryNote.injected && deliveryNote.xss === 0, `delivery note was not preserved as text-only order context: ${JSON.stringify(deliveryNote)}`);
  await evaluate(client, "document.querySelector('.order-reorder-button').click(); true");
  await waitFor(client, "SavoraState.load().cart.length === 1", 'exact reorder');
  const reordered = await evaluate(client, `(() => {
    const line = SavoraState.load().cart[0];
    return { quantity: line.quantity, unitPrice: line.unitPrice, options: line.options, note: line.note };
  })()`);
  assert(reordered.quantity === 2 && Math.abs(reordered.unitPrice - 19.74) < 0.000001 && reordered.options.length === 2 && reordered.note.includes('<img src=x'), `reorder did not preserve exact configuration: ${JSON.stringify(reordered)}`);
  return { placed, deliveryNote, reordered };
}

async function checkMapTileFallback(client) {
  await setViewport(client, 1440);
  await client.send('Network.setBlockedURLs', { urls: ['*://*.basemaps.cartocdn.com/*'] });
  try {
    await navigate(client, routes.discover);
    await waitFor(client, "document.getElementById('order-map')?.dataset.mapStatus === 'degraded'", 'visible degraded map state', 9000);
    const fallback = await evaluate(client, `(() => {
      const map = document.getElementById('order-map');
      const message = map.querySelector('.map-fallback-message');
      return {
        status: map.dataset.mapStatus,
        message: message.textContent.trim(),
        visible: getComputedStyle(message).display !== 'none',
        label: map.getAttribute('aria-label')
      };
    })()`);
    assert(fallback.status === 'degraded' && fallback.visible && fallback.message.includes('Map tiles unavailable') && fallback.label.includes('tiles are unavailable'), `map fallback is not clear: ${JSON.stringify(fallback)}`);
    await screenshot(client, 'map-fallback-1440.png');
    return fallback;
  } finally {
    await client.send('Network.setBlockedURLs', { urls: [] });
  }
}

async function seedFavorites(client) {
  await evaluate(client, `(() => {
    let state = SavoraState.load();
    if (!state.favorites.restaurants.includes('Pizza Hut')) state = SavoraState.toggleFavorite(state, 'restaurants', 'Pizza Hut');
    if (!state.favorites.products.includes('2')) state = SavoraState.toggleFavorite(state, 'products', '2');
    SavoraState.persist(state);
    return true;
  })()`);
}

async function checkFavoriteCreation(client) {
  await setViewport(client, 1440);
  await navigate(client, routes.discover);
  await evaluate(client, `(() => {
    const state = SavoraState.load();
    state.favorites = { restaurants: [], products: [] };
    SavoraState.persist(state);
    return true;
  })()`);
  await client.send('Page.reload', { ignoreCache: true });
  await waitFor(client, "document.querySelector('[data-favorite-kind=\"restaurants\"][data-favorite-id=\"Pizza Hut\"]')", 'Discover restaurant favorite control');
  const restaurantAdded = await evaluate(client, `(() => {
    const button = document.querySelector('[data-favorite-kind="restaurants"][data-favorite-id="Pizza Hut"]');
    button.click();
    return {
      pressed: button.getAttribute('aria-pressed'),
      label: button.getAttribute('aria-label'),
      restaurants: SavoraState.load().favorites.restaurants,
      toast: document.querySelector('.toast')?.textContent || ''
    };
  })()`);
  assert(restaurantAdded.pressed === 'true' && restaurantAdded.label.includes('Remove Pizza Hut') && restaurantAdded.restaurants.includes('Pizza Hut') && restaurantAdded.toast.includes('added to favorites'), `Discover restaurant favorite add failed: ${JSON.stringify(restaurantAdded)}`);

  await navigate(client, routes.product);
  await waitFor(client, "!document.getElementById('product-detail-content').hidden", 'product detail before favorite');
  const productAdded = await evaluate(client, `(() => {
    const button = document.getElementById('product-favorite-button');
    button.click();
    return {
      pressed: button.getAttribute('aria-pressed'),
      label: button.getAttribute('aria-label'),
      products: SavoraState.load().favorites.products,
      toast: document.querySelector('.toast')?.textContent || ''
    };
  })()`);
  assert(productAdded.pressed === 'true' && productAdded.label.includes('Remove Supreme Pepperoni Pizza') && productAdded.products.includes('2') && productAdded.toast.includes('added to favorites'), `Product favorite add failed: ${JSON.stringify(productAdded)}`);

  await client.send('Page.reload', { ignoreCache: true });
  await waitFor(client, "document.getElementById('product-favorite-button')?.getAttribute('aria-pressed') === 'true'", 'product favorite reload persistence');
  await navigate(client, routes.favorites);
  await waitFor(client, "document.querySelectorAll('#favorite-restaurants-grid .favorite-card').length === 1 && document.querySelectorAll('#favorite-products-grid .favorite-card').length === 1", 'favorite creation render after reload');

  await navigate(client, routes.product);
  const productRemoved = await evaluate(client, `(() => {
    const button = document.getElementById('product-favorite-button');
    button.click();
    return {
      pressed: button.getAttribute('aria-pressed'),
      label: button.getAttribute('aria-label'),
      products: SavoraState.load().favorites.products,
      toast: document.querySelector('.toast')?.textContent || ''
    };
  })()`);
  assert(productRemoved.pressed === 'false' && productRemoved.label.includes('Add Supreme Pepperoni Pizza') && productRemoved.products.length === 0 && productRemoved.toast.includes('removed from favorites'), `Product favorite remove failed: ${JSON.stringify(productRemoved)}`);

  await navigate(client, routes.discover);
  const restaurantRemoved = await evaluate(client, `(() => {
    const button = document.querySelector('[data-favorite-kind="restaurants"][data-favorite-id="Pizza Hut"]');
    button.click();
    return {
      pressed: button.getAttribute('aria-pressed'),
      label: button.getAttribute('aria-label'),
      restaurants: SavoraState.load().favorites.restaurants,
      toast: document.querySelector('.toast')?.textContent || ''
    };
  })()`);
  assert(restaurantRemoved.pressed === 'false' && restaurantRemoved.label.includes('Add Pizza Hut') && restaurantRemoved.restaurants.length === 0 && restaurantRemoved.toast.includes('removed from favorites'), `Discover restaurant favorite remove failed: ${JSON.stringify(restaurantRemoved)}`);
  return { restaurantAdded, productAdded, productRemoved, restaurantRemoved };
}

async function checkFavorites(client) {
  await setViewport(client, 320);
  await navigate(client, routes.favorites);
  await waitFor(client, "document.querySelectorAll('#favorite-restaurants-grid .favorite-card').length === 1", 'favorite restaurant render');
  const tabs = await evaluate(client, `(() => {
    const first = document.getElementById('favorite-restaurants-tab');
    first.focus();
    first.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true, cancelable: true }));
    return {
      selected: document.getElementById('favorite-products-tab').getAttribute('aria-selected'),
      focused: document.activeElement.id,
      productPanelHidden: document.getElementById('favorite-products-panel').hidden
    };
  })()`);
  assert(tabs.selected === 'true' && tabs.focused === 'favorite-products-tab' && !tabs.productPanelHidden, `favorite keyboard tabs failed: ${JSON.stringify(tabs)}`);
  await evaluate(client, "document.querySelector('#favorite-products-grid .favorite-heart-button').click(); true");
  await waitFor(client, "document.querySelector('#favorite-products-grid .favorite-empty-state')", 'favorite dish empty state');
  await evaluate(client, "document.getElementById('favorite-restaurants-tab').click(); document.querySelector('#favorite-restaurants-grid .favorite-heart-button').click(); true");
  await waitFor(client, "document.querySelector('#favorite-restaurants-grid .favorite-empty-state')", 'favorite restaurant empty state');
  const empty = await evaluate(client, `({
    restaurants: SavoraState.load().favorites.restaurants,
    products: SavoraState.load().favorites.products,
    restaurantCta: document.querySelector('#favorite-restaurants-grid a')?.getAttribute('href'),
    productCta: document.querySelector('#favorite-products-grid a')?.getAttribute('href')
  })`);
  assert(empty.restaurants.length === 0 && empty.products.length === 0 && empty.restaurantCta === 'customer_dashboard.php' && empty.productCta === 'customer_dashboard.php', `favorite removals/empty states failed: ${JSON.stringify(empty)}`);
  await delay(400);
  const toastBounds = await evaluate(client, `(() => {
    const toasts = [...document.querySelectorAll('.toast')];
    return toasts.map(toast => {
      const rect = toast.getBoundingClientRect();
      return { left: rect.left, right: rect.right, viewport: innerWidth };
    });
  })()`);
  assert(toastBounds.every(toast => toast.left >= 0 && toast.right <= toast.viewport), `settled favorite toasts overflow the mobile viewport: ${JSON.stringify(toastBounds)}`);
  await screenshot(client, 'favorites-empty-320.png');
  return { tabs, empty, toastBounds };
}

async function checkProfile(client) {
  await setViewport(client, 1440);
  await navigate(client, routes.profile);
  await evaluate(client, `(() => {
    window.__task7Xss = 0;
    const values = {
      'profile-full-name': 'Task Seven Customer',
      'profile-email': 'task7@example.com',
      'profile-phone': '+66 8123 4567',
      'profile-address': '<img src=x onerror=window.__task7Xss=1> 77 Task Seven Street'
    };
    for (const [id, value] of Object.entries(values)) document.getElementById(id).value = value;
    document.getElementById('profile-form').requestSubmit();
    return true;
  })()`);
  await waitFor(client, "document.getElementById('profile-save-status').textContent.includes('saved locally on this device')", 'profile local save');
  await client.send('Page.reload', { ignoreCache: true });
  await waitFor(client, "document.getElementById('profile-address')?.value.includes('77 Task Seven Street')", 'profile reload persistence');
  const profile = await evaluate(client, `({
    state: SavoraState.load().profile,
    addressValue: document.getElementById('profile-address').value,
    savedAddressText: document.getElementById('saved-address-copy').textContent,
    injectedImage: Boolean(document.querySelector('#saved-address-copy img')),
    passwordClaim: document.body.textContent.includes('Password changes saved'),
    xss: window.__task7Xss || 0
  })`);
  assert(profile.state.fullName === 'Task Seven Customer' && profile.addressValue.includes('<img src=x') && profile.savedAddressText.includes('<img src=x'), `profile did not persist/render supported fields as text: ${JSON.stringify(profile)}`);
  assert(!profile.injectedImage && !profile.passwordClaim && profile.xss === 0, `profile unsafe/password behavior detected: ${JSON.stringify(profile)}`);
  await evaluate(client, `(() => {
    const state = SavoraState.load();
    state.profile.address = '77 Task Seven Street';
    SavoraState.persist(state);
    return true;
  })()`);
  return profile;
}

async function checkWallet(client) {
  await setViewport(client, 1440);
  await navigate(client, routes.wallet);
  await evaluate(client, "document.getElementById('wallet-open-topup').click(); true");
  await waitFor(client, "!document.getElementById('wallet-topup-dialog').hidden", 'wallet top-up dialog');
  await waitFor(client, "document.activeElement.getAttribute('aria-label') === 'Close top-up dialog'", 'wallet dialog initial focus');
  await screenshot(client, 'wallet-topup-dialog-1440.png');
  const keyboard = await evaluate(client, `(() => {
    const dialog = document.getElementById('wallet-topup-dialog');
    const nodes = [...dialog.querySelectorAll('button:not([disabled]), input:not([disabled])')].filter(node => !node.hidden);
    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    last.focus();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));
    const forward = document.activeElement === first;
    first.focus();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true }));
    const backward = document.activeElement === last;
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    return { forward, backward, closed: dialog.hidden, focusReturned: document.activeElement.id === 'wallet-open-topup' };
  })()`);
  assert(keyboard.forward && keyboard.backward && keyboard.closed && keyboard.focusReturned, `wallet dialog keyboard behavior failed: ${JSON.stringify(keyboard)}`);
  await evaluate(client, `(() => {
    document.getElementById('wallet-open-topup').click();
    const input = document.getElementById('wallet-topup-amount');
    input.value = '50';
    document.getElementById('wallet-topup-form').requestSubmit();
    return true;
  })()`);
  await waitFor(client, "SavoraState.load().wallet.balance === 50 && document.getElementById('wallet-page-balance').textContent === '$50.00'", 'immediate wallet top-up');
  const wallet = await evaluate(client, `(() => {
    const state = SavoraState.load();
    return {
      balance: state.wallet.balance,
      transaction: state.wallet.transactions[0],
      rendered: document.getElementById('wallet-page-balance').textContent,
      activity: document.getElementById('wallet-transaction-list').textContent
    };
  })()`);
  assert(wallet.transaction.kind === 'credit' && wallet.activity.includes('Credit') && wallet.activity.includes('+$50.00'), `wallet Credit did not render immediately: ${JSON.stringify(wallet)}`);
  return { keyboard, wallet };
}

async function checkMaliciousOrder(client) {
  await navigate(client, routes.orders);
  await evaluate(client, `(() => {
    window.__task7Xss = 0;
    const state = SavoraState.load();
    state.orders.unshift({
      id: 'MALICIOUS-TEXT',
      status: 'completed',
      address: '<svg onload=window.__task7Xss=1>',
      paymentMethod: 'cash',
      promoCode: '',
      items: [{
        lineId: 'malicious-line',
        id: '2',
        name: '<img src=x onerror=window.__task7Xss=1>',
        image: 'https://attacker.invalid/payload.png',
        unitPrice: 13.99,
        quantity: 1,
        options: [{ id: 'bad', label: '<b>unsafe</b>', price: 0 }],
        note: '<script>window.__task7Xss=1</script>'
      }],
      subtotal: 13.99,
      deliveryFee: 2,
      total: 15.99,
      createdAt: new Date().toISOString()
    });
    SavoraState.persist(state);
    location.reload();
    return true;
  })()`);
  await waitFor(client, "document.body.textContent.includes('<img src=x onerror=window.__task7Xss=1>')", 'malicious order text render');
  const security = await evaluate(client, `({
    xss: window.__task7Xss || 0,
    literalText: document.body.textContent.includes('<img src=x onerror=window.__task7Xss=1>'),
    injectedPayload: Boolean(document.querySelector('.order-card img[src="x"], .order-card svg[onload], .order-card script')),
    attackerImage: [...document.images].some(image => image.src.includes('attacker.invalid')),
    orderImagesTrusted: [...document.querySelectorAll('.order-card-image[src]')].every(image => image.src === new URL(SavoraCatalog.products['2'].image, location.href).href)
  })`);
  assert(security.xss === 0 && security.literalText && !security.injectedPayload && !security.attackerImage && security.orderImagesTrusted, `malicious persisted order escaped the text/catalog boundary: ${JSON.stringify(security)}`);
  return security;
}

async function visualSweep(client) {
  await seedFavorites(client);
  const results = [];
  for (const width of [1440, 768, 320]) {
    await setViewport(client, width);
    for (const [name, route] of Object.entries(routes)) {
      await navigate(client, route);
      await waitFor(client, "Boolean(document.querySelector('main'))", `${name} main landmark`);
      if (name === 'product') await waitFor(client, "!document.getElementById('product-detail-content').hidden", 'product visual render');
      const assets = await visualAssetStatus(client, expectedCriticalImages[name], expectedCriticalBackgrounds[name], `${name} at ${width}px`);
      const expectedPath = new URL(route, `${baseUrl}/`).pathname;
      const metrics = await evaluate(client, `(() => {
        const main = document.querySelector('main');
        const bodyText = document.body.textContent;
        return {
          path: location.pathname,
          title: document.title,
          heading: [...main.querySelectorAll('h1')].find(node => !node.closest('[hidden]'))?.textContent.trim() || '',
          viewport: innerWidth,
          scrollWidth: document.documentElement.scrollWidth,
          mainWidth: main.getBoundingClientRect().width,
          hasMain: Boolean(main),
          mojibake: /GrabFood|Order #1042|Ã¢â‚¬Â¢|Ã°Å¸|Â·|â†’|âˆ’|â€¦|Ã—/.test(bodyText),
          overflowers: [...document.querySelectorAll('main *')].map(node => {
            const rect = node.getBoundingClientRect();
            return {
              tag: node.tagName,
              id: node.id,
              className: typeof node.className === 'string' ? node.className : '',
              left: Math.round(rect.left),
              right: Math.round(rect.right),
              scrollWidth: node.scrollWidth,
              clientWidth: node.clientWidth
            };
          }).filter(item => item.right > innerWidth + 1 || item.left < -1 || item.scrollWidth > item.clientWidth + 1).slice(0, 12)
        };
      })()`);
      assert(metrics.hasMain && metrics.heading, `${name} lacks its rendered hierarchy at ${width}px`);
      assert(metrics.path === expectedPath, `${name} rendered the wrong route at ${width}px: expected ${expectedPath}, received ${metrics.path}`);
      assert(metrics.title === expectedDocumentTitles[name], `${name} document title mismatch at ${width}px: expected ${expectedDocumentTitles[name]}, received ${metrics.title}`);
      assert(metrics.scrollWidth <= metrics.viewport, `${name} overflows at ${width}px (${metrics.scrollWidth}px > ${metrics.viewport}px): ${JSON.stringify(metrics.overflowers)}`);
      assert(!metrics.mojibake, `${name} renders legacy or mojibake copy at ${width}px`);
      const filename = `${name}-${width}.png`;
      await screenshot(client, filename);
      results.push({ name, width, ...metrics, assets, screenshot: path.join(artifactDir, filename) });
    }
  }
  return results;
}

async function main() {
  await fs.mkdir(artifactDir, { recursive: true });
  const client = await openPage();
  const exceptions = [];
  const requests = new Map();
  const networkFailures = [];
  client.on('Runtime.exceptionThrown', event => {
    const details = event.exceptionDetails || {};
    exceptions.push({
      text: details.text || 'JavaScript exception',
      description: details.exception?.description || '',
      url: details.url || '',
      line: Number(details.lineNumber || 0) + 1
    });
  });
  client.on('Network.requestWillBeSent', event => requests.set(event.requestId, event.request?.url || ''));
  client.on('Network.loadingFailed', event => networkFailures.push({
    url: requests.get(event.requestId) || '',
    error: event.errorText || '',
    blockedReason: event.blockedReason || ''
  }));

  try {
    await login(client);
    const mobileMenu = await checkMobileMenuKeyboard(client);
    const desktopNavigation = await checkDesktopNavigation(client);
    const intentionalImageFallback = await checkIntentionalImageFallback(client);
    const discovery = await checkDiscoveryRuntime(client);
    const product = await addConfiguredProduct(client);
    const cartDialog = await checkCartDialogKeyboard(client);
    const orderFlow = await placeOrderAndReorder(client);
    const mapFallback = await checkMapTileFallback(client);
    const favoriteCreation = await checkFavoriteCreation(client);
    await seedFavorites(client);
    const profile = await checkProfile(client);
    const wallet = await checkWallet(client);
    const visual = await visualSweep(client);
    const security = await checkMaliciousOrder(client);
    const favorites = await checkFavorites(client);

    const sameOriginFailures = networkFailures.filter(item => item.url.startsWith(baseUrl));
    const externalFailures = networkFailures.filter(item => item.url && !item.url.startsWith(baseUrl));
    assert(sameOriginFailures.length === 0, `same-origin network failures: ${JSON.stringify(sameOriginFailures)}`);
    assert(exceptions.length === 0, `runtime exceptions: ${JSON.stringify(exceptions)}`);

    const report = {
      status: 'PASS',
      mobileMenu,
      desktopNavigation,
      intentionalImageFallback,
      discovery,
      product,
      cartDialog,
      orderFlow,
      mapFallback,
      favoriteCreation,
      favorites,
      profile,
      wallet,
      security,
      visual,
      runtimeExceptions: exceptions,
      sameOriginNetworkFailures: sameOriginFailures,
      externalNetworkFailures: externalFailures
    };
    await fs.writeFile(path.join(artifactDir, 'results.json'), JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report, null, 2));
  } finally {
    client.close();
  }
}

main().catch(error => {
  console.error(error.stack || error.message);
  process.exitCode = 1;
});
