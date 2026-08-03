const debugPort = Number(process.env.SAVORA_CDP_PORT || 9227);
const baseUrl = (process.env.SAVORA_BASE_URL || 'http://localhost:8085/Savora').replace(/\/$/, '');

class CdpClient {
  constructor(url) {
    this.socket = new WebSocket(url);
    this.nextId = 1;
    this.pending = new Map();
  }

  async connect() {
    await new Promise((resolve, reject) => {
      this.socket.addEventListener('open', resolve, { once: true });
      this.socket.addEventListener('error', reject, { once: true });
    });
    this.socket.addEventListener('message', event => {
      const message = JSON.parse(event.data);
      if (!message.id) return;
      const pending = this.pending.get(message.id);
      if (!pending) return;
      this.pending.delete(message.id);
      if (message.error) pending.reject(new Error(message.error.message));
      else pending.resolve(message.result || {});
    });
  }

  send(method, params = {}) {
    const id = this.nextId++;
    const promise = new Promise((resolve, reject) => this.pending.set(id, { resolve, reject }));
    this.socket.send(JSON.stringify({ id, method, params }));
    return promise;
  }

  close() {
    this.socket.close();
  }
}

const delay = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
const assert = (condition, message) => { if (!condition) throw new Error(message); };

async function evaluate(client, expression) {
  const result = await client.send('Runtime.evaluate', {
    expression,
    awaitPromise: true,
    returnByValue: true
  });
  if (result.exceptionDetails) {
    throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text || 'Browser evaluation failed');
  }
  return result.result?.value;
}

async function waitFor(client, expression, message, timeout = 12000) {
  const started = Date.now();
  while (Date.now() - started < timeout) {
    if (await evaluate(client, expression)) return;
    await delay(100);
  }
  throw new Error('Timed out: ' + message);
}

async function openPage() {
  const versionResponse = await fetch('http://127.0.0.1:' + debugPort + '/json/version');
  if (!versionResponse.ok) throw new Error('Chrome CDP returned HTTP ' + versionResponse.status + '.');
  const version = await versionResponse.json();
  if (!version.webSocketDebuggerUrl) throw new Error('Chrome CDP did not expose a WebSocket debugger URL.');

  const browser = new CdpClient(version.webSocketDebuggerUrl);
  await browser.connect();
  const { browserContextId } = await browser.send('Target.createBrowserContext');
  const { targetId } = await browser.send('Target.createTarget', { url: 'about:blank', browserContextId });
  let target = null;
  for (let attempt = 0; attempt < 40 && !target; attempt += 1) {
    const response = await fetch('http://127.0.0.1:' + debugPort + '/json/list');
    const targets = response.ok ? await response.json() : [];
    target = targets.find(candidate => candidate.id === targetId);
    if (!target) await delay(75);
  }
  if (!target?.webSocketDebuggerUrl) throw new Error('Chrome target did not expose a debugger URL.');

  const client = new CdpClient(target.webSocketDebuggerUrl);
  await client.connect();
  await Promise.all([client.send('Page.enable'), client.send('Runtime.enable'), client.send('Network.enable')]);
  client.cleanup = async () => {
    client.close();
    try { await browser.send('Target.closeTarget', { targetId }); } catch (_) {}
    try { await browser.send('Target.disposeBrowserContext', { browserContextId }); } catch (_) {}
    browser.close();
  };
  return client;
}

async function navigate(client, route) {
  await client.send('Page.navigate', { url: baseUrl + '/' + route.replace(/^\//, '') });
  await waitFor(client, "document.readyState === 'complete'", 'loading ' + route);
  await delay(300);
}

async function locationPath(client) {
  return evaluate(client, 'location.pathname');
}

async function login(client, username, returnTo = '') {
  const query = returnTo ? '?return_to=' + encodeURIComponent(returnTo) : '';
  await navigate(client, 'login.php' + query);
  await waitFor(client, "Boolean(document.querySelector('[name=username]') && document.querySelector('[name=password]'))", 'login form');
  const submitScript = "(() => {"
    + "document.querySelector('[name=username]').value = " + JSON.stringify(username) + ";"
    + "document.querySelector('[name=password]').value = '123456';"
    + "document.querySelector('form').submit(); return true;"
    + "})()";
  await evaluate(client, submitScript);
  await waitFor(client, "location.pathname.endsWith('/customer_dashboard.php') || location.pathname.endsWith('/customer_checkout.php') || location.pathname.endsWith('/restaurant_dashboard.php') || location.pathname.endsWith('/driver_dashboard.php') || location.pathname.endsWith('/admin_dashboard.php')", username + ' login');
}

async function run() {
  const client = await openPage();
  try {
    await navigate(client, '');
    await waitFor(client, "Boolean(document.querySelector('.customer-header') && document.querySelector('#featured-restaurants-grid .home-restaurant-card'))", 'public Customer Home');
    const home = await evaluate(client, "(() => ({"
      + "path: location.pathname,"
      + "restaurantCount: document.querySelectorAll('#featured-restaurants-grid .home-restaurant-card').length,"
      + "foodCount: document.querySelectorAll('#popular-food-grid .home-product-card').length,"
      + "drinkCount: document.querySelectorAll('#popular-drink-grid .home-product-card').length,"
      + "firstRestaurantHref: [...document.querySelectorAll('#featured-restaurants-grid a')].find(node => node.href.includes('customer_restaurant.php'))?.getAttribute('href') || '',"
      + "loginForm: Boolean(document.querySelector('[name=username]')),"
      + "labels: [...document.querySelectorAll('.customer-nav a')].map(node => node.textContent.trim()),"
      + "authenticated: window.SavoraCustomerAuthenticated,"
      + "signIn: document.querySelector('.customer-sign-in')?.textContent.trim() || ''"
      + "}))()");
    assert(home.path.endsWith('/customer_dashboard.php'), 'Root did not route to Customer Home: ' + home.path);
    assert(!home.loginForm, 'Public Home unexpectedly rendered the login form.');
    for (const label of ['Discover', 'Orders', 'Favorites', 'Wallet', 'Profile']) assert(home.labels.includes(label), 'Navigation is missing ' + label + '.');
    assert(home.authenticated === false && home.signIn === 'Sign in', 'Guest navigation state is incorrect.');
    assert(home.restaurantCount === 6, 'Home must show six featured restaurants: ' + home.restaurantCount);
    assert(home.foodCount >= 6 && home.drinkCount >= 6, 'Home must show both food and drink overview cards.');
    assert(home.firstRestaurantHref.includes('customer_restaurant.php?restaurant='), 'Home restaurant card does not link to its storefront.');

    await navigate(client, home.firstRestaurantHref);
    await waitFor(client, "Boolean(document.querySelector('#storefront-name') && document.querySelectorAll('#storefront-food-grid .restaurant-menu-card').length === 6 && document.querySelectorAll('#storefront-drink-grid .restaurant-menu-card').length === 2)", 'restaurant storefront');
    const storefront = await evaluate(client, "(() => ({"
      + "name: document.querySelector('#storefront-name')?.textContent.trim() || '',"
      + "slogan: document.querySelector('#storefront-slogan')?.textContent.trim() || '',"
      + "address: document.querySelector('#storefront-address')?.textContent.trim() || '',"
      + "hours: document.querySelector('#storefront-hours-list')?.textContent.trim() || '',"
      + "food: document.querySelectorAll('#storefront-food-grid .restaurant-menu-card').length,"
      + "drinks: document.querySelectorAll('#storefront-drink-grid .restaurant-menu-card').length"
      + "}))()"
    );
    assert(storefront.name && storefront.slogan && storefront.address && storefront.hours, 'Storefront is missing brand or restaurant details.');
    assert(storefront.food === 6 && storefront.drinks === 2, 'Storefront must show six food items and two drinks.');
    await evaluate(client, "[...document.querySelectorAll('[data-restaurant-filter]')].find(button => button.dataset.restaurantFilter === 'drinks')?.click()");
    await waitFor(client, "document.querySelectorAll('#storefront-drink-grid .restaurant-menu-card').length === 2 && document.querySelectorAll('#storefront-food-grid .restaurant-menu-card').length === 1", 'restaurant drink filter');
    await evaluate(client, "[...document.querySelectorAll('[data-restaurant-filter]')].find(button => button.dataset.restaurantFilter === 'all')?.click()");

    for (const width of [1920, 1440, 768, 390]) {
      await client.send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width <= 768 });
      await navigate(client, 'customer_dashboard.php');
      await waitFor(client, "Boolean(document.querySelector('#featured-restaurants-grid .home-restaurant-card'))", 'responsive Customer Home');
      const overflow = await evaluate(client, 'document.documentElement.scrollWidth <= window.innerWidth + 1');
      assert(overflow, 'Customer Home overflows horizontally at ' + width + 'px.');
    }
    await client.send('Emulation.clearDeviceMetricsOverride');

    await navigate(client, 'product_detail.php?id=1');
    const productAvailable = await evaluate(client, "Boolean(document.querySelector('#product-title')?.textContent.trim())");
    if (productAvailable) {
      await evaluate(client, "document.querySelector('#add-product-to-cart').click()");
      await delay(250);
    } else {
      await evaluate(client, "SavoraState.persist(SavoraState.addCartLine(SavoraState.load(), { id: 'qa-product', restaurantId: 'qa-restaurant', restaurantName: 'QA Restaurant', restaurant: 'QA Restaurant', name: 'QA Dish', image: '', price: 12 }, 1))");
    }
    await navigate(client, 'customer_cart.php');
    await waitFor(client, "Boolean(document.querySelector('#full-cart-items article'))", 'guest cart item');
    const cart = await evaluate(client, "(() => ({"
      + "itemCount: document.querySelectorAll('#full-cart-items article').length,"
      + "checkout: document.querySelector('#btn-checkout')?.getAttribute('href') || ''"
      + "}))()");
    assert(cart.itemCount > 0, 'Guest cart did not retain the added product.');
    assert(cart.checkout.includes('login.php') && cart.checkout.includes('return_to'), 'Guest Checkout is not gated by login: ' + cart.checkout);

    await evaluate(client, "document.querySelector('#btn-checkout').click()");
    await waitFor(client, "location.pathname.endsWith('/login.php')", 'guest checkout login gate');
    assert(await evaluate(client, "document.querySelector('[name=return_to]')?.value === 'customer_checkout.php'"), 'Checkout return route was not preserved.');
    assert(await evaluate(client, "document.body.innerText.includes('Please sign in to continue to checkout.')"), 'Checkout login notice is missing.');

    await login(client, 'customer', 'customer_checkout.php');
    let currentPath = await locationPath(client);
    assert(currentPath.endsWith('/customer_checkout.php'), 'Customer did not return to Checkout: ' + currentPath);
    assert(await evaluate(client, "Boolean(window.SavoraCustomerAuthenticated)"), 'Authenticated customer chrome was not restored.');
    assert(await evaluate(client, "Boolean(document.querySelector('.user-avatar') && document.querySelector('.logout-link'))"), 'Authenticated avatar/logout controls are missing.');

    await navigate(client, 'logout.php');
    await navigate(client, 'customer_profile.php');
    await waitFor(client, "location.pathname.endsWith('/login.php')", 'guest profile login gate');
    assert(await evaluate(client, "document.body.innerText.includes('Please sign in to continue.')"), 'Profile login notice is missing.');

    await login(client, 'restaurant');
    currentPath = await locationPath(client);
    assert(currentPath.endsWith('/restaurant_dashboard.php'), 'Restaurant demo login destination changed.');
    await navigate(client, 'logout.php');
    await login(client, 'driver');
    currentPath = await locationPath(client);
    assert(currentPath.endsWith('/driver_dashboard.php'), 'Driver demo login destination changed.');
    await navigate(client, 'logout.php');
    await login(client, 'admin');
    currentPath = await locationPath(client);
    assert(currentPath.endsWith('/admin_dashboard.php'), 'Admin demo login destination changed.');

    console.log('PASS: public Customer Home, guest cart, login gates, return routes, and role redirects (' + baseUrl + ')');
  } finally {
    await client.cleanup();
  }
}

run().catch(error => {
  console.error('BLOCKED/FAIL: ' + error.message);
  process.exitCode = 1;
});
