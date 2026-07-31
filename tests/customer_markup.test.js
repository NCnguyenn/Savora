const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const read = name => fs.readFileSync(path.join(__dirname, '..', name), 'utf8');

const customerRoutes = [
  'customer_dashboard.php',
  'product_detail.php',
  'customer_cart.php',
  'customer_checkout.php',
  'customer_history.php',
  'customer_favorites.php',
  'customer_profile.php',
  'customer_wallet.php'
];

const migratedCustomerRenderers = [
  'components/customer_footer.php',
  'js/customer_ui.js',
  ...customerRoutes
];

test('all Customer routes expose a main landmark', () => {
  for (const route of customerRoutes) {
    assert.match(read(route), /<main\b/, `${route} must declare a main landmark`);
  }
});

test('Customer header exposes all five primary navigation routes', () => {
  const header = read('components/customer_header.php');
  const routes = {
    'customer_dashboard.php': 'Discover',
    'customer_history.php': 'Orders',
    'customer_favorites.php': 'Favorites',
    'customer_wallet.php': 'Wallet',
    'customer_profile.php': 'Profile'
  };

  for (const [route, label] of Object.entries(routes)) {
    assert.match(header, new RegExp(`'${route}'\\s*=>\\s*\\['${label}'`));
  }
});

test('Customer header supplies page-specific document titles for every Customer route', () => {
  const header = read('components/customer_header.php');
  const expectedTitles = {
    'customer_dashboard.php': 'Discover | Savora',
    'product_detail.php': 'Dish details | Savora',
    'customer_cart.php': 'Your cart | Savora',
    'customer_checkout.php': 'Checkout | Savora',
    'customer_history.php': 'Your orders | Savora',
    'customer_favorites.php': 'Favorites | Savora',
    'customer_profile.php': 'Your profile | Savora',
    'customer_wallet.php': 'Savora Pay | Savora'
  };

  for (const [route, title] of Object.entries(expectedTitles)) {
    assert.match(header, new RegExp(`'${route}'\\s*=>\\s*'${title.replace(/[|]/g, '\\|')}'`));
  }
  assert.match(header, /<title><\?php echo htmlspecialchars\(\$document_title/);
});

test('Customer experience no longer exposes legacy branding, fixed orders or mojibake', () => {
  const files = ['components/customer_header.php', ...migratedCustomerRenderers];
  const source = files.map(read).join('\n');
  assert.doesNotMatch(source, /GrabFood|Order #1042|Ã¢â‚¬Â¢|Ã°Å¸|Â·|â†’|âˆ’|â€¦|Ã—/);
});

test('migrated Customer renderers avoid unsafe HTML, inline handlers, alerts and polling', () => {
  for (const file of migratedCustomerRenderers) {
    const source = read(file);
    assert.doesNotMatch(source, /innerHTML\s*=/, `${file} must not assign innerHTML`);
    assert.doesNotMatch(source, /outerHTML\s*=/, `${file} must not assign outerHTML`);
    assert.doesNotMatch(source, /\binsertAdjacentHTML\s*\(/, `${file} must not insert adjacent HTML`);
    assert.doesNotMatch(source, /\bdocument\.write\s*\(/, `${file} must not call document.write()`);
    assert.doesNotMatch(source, /\balert\s*\(/, `${file} must not call alert()`);
    assert.doesNotMatch(source, /\son[a-z]+\s*=/i, `${file} must not use inline event handlers`);
    assert.doesNotMatch(source, /\bsetInterval\s*\(/, `${file} must not poll with setInterval()`);
  }
});

test('shared chrome provides semantic navigation, dialog and live-status hooks', () => {
  const header = read('components/customer_header.php');
  const footer = read('components/customer_footer.php');

  assert.match(header, /<nav[^>]*aria-label="Customer navigation"/);
  assert.match(header, /aria-controls="customer-mobile-menu"/);
  assert.match(footer, /role="dialog"/);
  assert.match(footer, /aria-live="polite"/);
});

test('shared renderer does not inject persisted data via innerHTML', () => {
  for (const source of ['components/customer_footer.php', 'js/customer_ui.js']) {
    assert.doesNotMatch(read(source), /innerHTML\s*=/);
  }
});

test('chrome refresh owns the active navigation state', () => {
  assert.match(read('js/customer_ui.js'), /querySelectorAll\('\.customer-nav a'\)/);
});

test('shared UI exposes the specified toast method to page renderers', () => {
  assert.match(read('js/customer_ui.js'), /const api = \{[\s\S]{0,180}showToast:\s*announce/);
});

test('mobile navigation exposes semantic toggle and menu hooks', () => {
  const header = read('components/customer_header.php');
  assert.match(header, /class="mobile-menu-toggle"[^>]*aria-expanded="false"/);
  assert.match(header, /aria-controls="customer-mobile-menu"/);
  assert.match(header, /id="customer-mobile-menu"[^>]*data-open="false"/);
});

test('Customer stylesheets contain no remote asset URLs', () => {
  for (const file of ['css/style.css', 'css/customer_style.css']) {
    assert.doesNotMatch(read(file), /https?:\/\//i, `${file} must only reference local visual assets`);
  }
});

test('browser QA gates visible critical CSS backgrounds', () => {
  const dashboard = read('customer_dashboard.php');
  const browserQa = read('tests/task7_browser_qa.mjs');
  assert.match(dashboard, /class="discovery-hero"[^>]*data-critical-background/);
  assert.match(dashboard, /class="promo-banner"[^>]*data-critical-background/);
  assert.match(browserQa, /querySelectorAll\('\[data-critical-background\]'\)/);
  assert.match(browserQa, /backgroundImage/);
  assert.match(browserQa, /sameOrigin/);
  assert.match(browserQa, /naturalWidth/);
});

test('critical Customer icons and catalog images are self-hosted with an intentional fallback', () => {
  const header = read('components/customer_header.php');
  const footer = read('components/customer_footer.php');
  const Catalog = require('../js/customer_catalog.js');
  const requiredAssets = [
    'assets/vendor/fontawesome/css/all.min.css',
    'assets/vendor/fontawesome/webfonts/fa-solid-900.woff2',
    'assets/vendor/fontawesome/webfonts/fa-regular-400.woff2',
    'assets/vendor/fontawesome/LICENSE.txt',
    'assets/vendor/leaflet/leaflet.css',
    'assets/vendor/leaflet/leaflet.js',
    'assets/vendor/leaflet/LICENSE',
    'assets/images/catalog/mega-burger-feast-combo.jpg',
    'assets/images/catalog/supreme-pepperoni-pizza.jpg',
    'assets/images/catalog/deluxe-salmon-tuna-sushi.jpg',
    'assets/images/catalog/brown-sugar-boba-milk-tea.jpg',
    'assets/images/backgrounds/shared-food-table.jpg',
    'assets/images/backgrounds/discovery-pasta.jpg',
    'assets/images/backgrounds/produce-promo.jpg',
    'assets/images/food-placeholder.svg',
    'assets/THIRD_PARTY_NOTICES.md'
  ];

  assert.match(header, /href="assets\/vendor\/fontawesome\/css\/all\.min\.css"/);
  assert.match(header, /href="assets\/vendor\/leaflet\/leaflet\.css"/);
  assert.match(footer, /src="assets\/vendor\/leaflet\/leaflet\.js"/);
  assert.doesNotMatch(`${header}\n${footer}`, /cdnjs\.cloudflare\.com|unpkg\.com/);
  for (const asset of requiredAssets) {
    const localPath = path.join(__dirname, '..', asset);
    assert.ok(fs.existsSync(localPath), `${asset} must exist locally`);
    assert.ok(fs.statSync(localPath).size > 0, `${asset} must not be empty`);
  }
  for (const product of Object.values(Catalog.products)) {
    assert.match(product.image, /^assets\/images\/catalog\/[a-z0-9-]+\.jpg$/);
  }
  assert.equal(Catalog.imageFor(null), 'assets/images/food-placeholder.svg');
  assert.equal(Catalog.imageFor({ image: '' }), 'assets/images/food-placeholder.svg');
  assert.equal(Catalog.imageFor({ image: 'https://attacker.invalid/image.jpg' }), 'assets/images/food-placeholder.svg');
});

test('tracking map declares a visible degraded-tile fallback', () => {
  const dashboard = read('customer_dashboard.php');
  const css = read('css/customer_style.css');
  assert.match(dashboard, /map-fallback-message/);
  assert.match(dashboard, /tileerror/);
  assert.match(dashboard, /data-map-status/);
  assert.match(css, /\.map-tiles-degraded\s+\.map-fallback-message/);
});

test('cart and checkout render persisted cart data with DOM nodes, not HTML strings', () => {
  for (const source of ['customer_cart.php', 'customer_checkout.php']) {
    const page = read(source);
    assert.doesNotMatch(page, /innerHTML\s*=/);
    assert.match(page, /replaceChildren\(/);
    assert.match(page, /textContent\s*=/);
  }
});

test('compatibility cart is ready before any DOM-ready page renderer can run', () => {
  const ui = read('js/customer_ui.js');
  const globalsAssigned = ui.indexOf('Object.assign(root, {');
  const syncAtLoad = ui.indexOf('syncLegacyState(load());', globalsAssigned);
  const domReady = ui.indexOf("documentRef.addEventListener('DOMContentLoaded', initialize");
  assert.ok(globalsAssigned >= 0, 'legacy globals are exported during script evaluation');
  assert.ok(syncAtLoad >= 0, 'the compatibility cart is initialized during script evaluation');
  assert.ok(domReady >= 0, 'the shared UI has a DOM-ready listener');
  assert.ok(syncAtLoad < domReady, 'the compatibility cart is initialized before DOM-ready listeners');
});

test('small screens retain Logout and stack the full-cart layout', () => {
  const header = read('components/customer_header.php');
  const cart = read('customer_cart.php');
  const css = read('css/customer_style.css');

  assert.match(header, /class="logout-link mobile-logout"/);
  assert.match(css, /@media \(max-width: 480px\)[\s\S]*\.mobile-logout\s*\{\s*display:\s*inline-flex;/);
  assert.match(cart, /setAttribute\('role', 'group'\)/);
  assert.match(cart, /setAttribute\('aria-label', 'Item quantity'\)/);
  assert.match(css, /@media \(max-width: 480px\)[\s\S]*#cart-page-layout\s*\{\s*grid-template-columns:\s*1fr\s*!important;/);
});

test('checkout stacks on tablet screens and uses the state-backed wallet checkout path', () => {
  const checkout = read('customer_checkout.php');
  const css = read('css/customer_style.css');

  assert.match(checkout, /id="checkout-page-layout"/);
  assert.match(css, /@media \(max-width: 768px\)[\s\S]*#checkout-page-layout\s*\{\s*grid-template-columns:\s*1fr\s*!important;/);
  assert.doesNotMatch(checkout, /\|\|\s*150(?:\.00)?/);
  assert.doesNotMatch(checkout, /localStorage\.getItem\('savora_wallet'\)/);
  assert.doesNotMatch(checkout, /saveState\(\)/);
  assert.doesNotMatch(checkout, /window\.cart\s*=\s*\[\]/);
  assert.match(checkout, /SavoraState\.load\(\)/);
  assert.match(checkout, /SavoraState\.placeDemoOrder\(/);
  assert.match(checkout, /SavoraState\.persist\(result\.state\)/);
  assert.doesNotMatch(css, /\.checkout-order-summary\s*\{\s*grid-row:\s*1;/);
});

test('full cart uses normalized state, safe DOM rendering and explicit line controls', () => {
  const cart = read('customer_cart.php');
  const css = read('css/customer_style.css');

  assert.match(cart, /SavoraState\.load\(\)/);
  assert.match(cart, /SavoraUI\.el/);
  assert.match(cart, /SavoraState\.updateCartQuantity\(/);
  assert.match(cart, /SavoraState\.removeCartLine\(/);
  assert.match(cart, /Increase quantity for/);
  assert.match(cart, /Decrease quantity for/);
  assert.match(cart, /['"]aria-label['"]:\s*`Remove \$\{name\}`/);
  assert.match(cart, /href="customer_dashboard\.php"/);
  assert.doesNotMatch(cart, /Array\.isArray\(window\.cart\)/);
  assert.match(css, /\.summary-primary-action\[hidden\]\s*\{\s*display:\s*none;/);
  assert.match(css, /\.line-customizations li\s*\{[^}]*overflow-wrap:\s*anywhere;/);
});

test('cart and checkout promo messaging never claims an uncalculated discount', () => {
  for (const source of ['customer_cart.php', 'customer_checkout.php']) {
    const page = read(source);
    assert.match(page, /total(?:s)? (?:remain|stay) unchanged/i);
    assert.doesNotMatch(page, /discount-applied|promo-savings/);
  }
});

test('checkout is a labelled, guarded form with accessible payments and local success feedback', () => {
  const checkout = read('customer_checkout.php');

  assert.match(checkout, /<form[^>]*id="checkout-form"/);
  assert.match(checkout, /<label[^>]*for="checkout-address"/);
  assert.match(checkout, /<label[^>]*for="checkout-note"/);
  assert.match(checkout, /<label[^>]*for="checkout-promo"/);
  assert.match(checkout, /<fieldset/);
  assert.match(checkout, /<legend/);
  assert.match(checkout, /type="radio"[^>]*value="wallet"/);
  assert.match(checkout, /type="radio"[^>]*value="cash"/);
  assert.match(checkout, /let isSubmitting = false/);
  assert.match(checkout, /setAttribute\('aria-busy',\s*pending \? 'true' : 'false'\)/);
  assert.match(checkout, /Demo order placed locally/);
  assert.match(checkout, /SavoraUI\.(?:showToast|announce)/);
  assert.doesNotMatch(checkout, /\balert\s*\(/);
  assert.doesNotMatch(checkout, /onclick=/);
});

test('checkout consults Restaurant accepting state and reports a local placement error safely', () => {
  const checkout = read('customer_checkout.php');
  const footer = read('components/customer_footer.php');

  assert.match(footer, /js\/restaurant_state\.js/);
  assert.match(checkout, /window\.SavoraRestaurantState\.load\(\)/);
  assert.match(checkout, /SavoraState\.placeDemoOrder\([\s\S]*window\.SavoraRestaurantState\.load\(\)/);
  assert.match(checkout, /showCheckoutToast\(error\.message/);
  assert.doesNotMatch(checkout, /\balert\s*\(/);
});

test('checkout persists delivery notes and orders render them as text context', () => {
  const state = read('js/customer_state.js');
  const checkout = read('customer_checkout.php');
  const history = read('customer_history.php');
  const dashboard = read('customer_dashboard.php');

  assert.match(state, /deliveryNote/);
  assert.match(checkout, /deliveryNote:\s*document\.getElementById\('checkout-note'\)\.value/);
  assert.match(history, /deliveryNote/);
  assert.match(history, /order-delivery-note/);
  assert.match(history, /active-order-delivery-note/);
  assert.match(dashboard, /deliveryNote/);
  assert.match(dashboard, /tracking-delivery-note/);
  assert.doesNotMatch(history, /innerHTML\s*=/);
  assert.doesNotMatch(dashboard, /innerHTML\s*=/);
});

test('catalog product records keep restaurant-specific detail data', () => {
  const Catalog = require('../js/customer_catalog.js');
  const pizza = Catalog.products['2'];
  assert.equal(pizza.restaurant, 'Pizza Hut');
  assert.notEqual(pizza.ingredients.join('|'), Catalog.products['1'].ingredients.join('|'));
  assert.ok(pizza.addOns.every(option => option.productId === pizza.id));
});

test('discovery source contains no hard-coded active-order number', () => {
  assert.doesNotMatch(read('customer_dashboard.php'), /Order #1042/);
});

test('discovery and product detail use semantic data-driven controls', () => {
  const discovery = read('customer_dashboard.php');
  const detail = read('product_detail.php');

  assert.match(discovery, /<main/);
  assert.match(discovery, /<section[^>]*aria-labelledby=/);
  assert.match(discovery, /<button[^>]*data-category=/);
  assert.match(discovery, /SavoraCatalog\.products/);
  assert.match(discovery, /SavoraState\.load\(\)/);
  assert.doesNotMatch(discovery, /innerHTML\s*=/);

  assert.match(detail, /<fieldset/);
  assert.match(detail, /<legend/);
  assert.match(detail, /SavoraCatalog\.products/);
  assert.match(detail, /SavoraState\.addCartLine\(/);
  assert.match(detail, /addEventListener\(/);
  assert.doesNotMatch(detail, /innerHTML\s*=/);
});

test('Discover and Product expose independent accessible favorite controls', () => {
  const discovery = read('customer_dashboard.php');
  const detail = read('product_detail.php');
  const css = read('css/customer_style.css');

  assert.match(discovery, /className:\s*'discovery-card-shell'/);
  assert.match(discovery, /className:\s*'discovery-favorite-button'/);
  assert.match(discovery, /data-favorite-kind/);
  assert.match(discovery, /aria-pressed/);
  assert.match(discovery, /toggleFavorite\(/);
  assert.match(discovery, /ui\.announce\(/);
  assert.match(detail, /id="product-favorite-button"/);
  assert.match(detail, /aria-pressed/);
  assert.match(detail, /toggleFavorite\(/);
  assert.match(detail, /ui\.announce\(/);
  assert.match(css, /\.discovery-card-shell\s*\{/);
  assert.match(css, /\.discovery-favorite-button\s*\{/);
});

test('product detail handles unknown products and responsive two-column layout', () => {
  const detail = read('product_detail.php');
  const css = read('css/customer_style.css');

  assert.match(detail, /class="customer-two-column/);
  assert.match(detail, /Product not found/);
  assert.match(detail, /href="customer_dashboard\.php"/);
  assert.match(css, /@media \(max-width: 768px\)[\s\S]*\.customer-two-column\s*\{\s*grid-template-columns:\s*1fr;/);
});

test('orders render persisted status-aware history and exact configuration reorder controls', () => {
  const history = read('customer_history.php');
  const css = read('css/customer_style.css');

  assert.match(history, /data-order-filter="all"[^>]*aria-pressed="true"/);
  assert.match(history, /data-order-filter="active"/);
  assert.match(history, /data-order-filter="completed"/);
  assert.match(history, /data-order-filter="cancelled"/);
  assert.match(history, /SavoraState\.getActiveOrder\(/);
  assert.match(history, /SavoraState\.addCartLine\(/);
  assert.match(history, /line\.options,\s*line\.note/);
  assert.match(history, /replaceChildren\(/);
  assert.match(history, /textContent\s*=/);
  assert.match(history, /href:\s*['"]customer_dashboard\.php['"]/);
  assert.doesNotMatch(history, /innerHTML\s*=/);
  assert.doesNotMatch(history, /onclick=/);
  assert.match(css, /@media \(max-width: 768px\)[\s\S]*\.orders-layout\s*\{\s*grid-template-columns:\s*1fr;/);
});

test('Customer order history opens a selected Restaurant follow-up record without reordering it', () => {
  const history = read('customer_history.php');

  assert.match(history, /new URLSearchParams\(window\.location\.search\)/);
  assert.match(history, /params\.get\('order'\)/);
  assert.match(history, /params\.get\('reorder'\)/);
  assert.match(history, /data-customer-order-id/);
  assert.match(history, /requestedCard\.focus\(\)/);
  assert.match(history, /requestedCard\.scrollIntoView/);
  assert.doesNotMatch(history, /reorder\(requestedOrder/);
});

test('favorites use accessible tabs, independent heart controls and state-backed empty panels', () => {
  const favorites = read('customer_favorites.php');
  const css = read('css/customer_style.css');

  assert.match(favorites, /role="tablist"/);
  assert.match(favorites, /role="tab"[^>]*aria-controls="favorite-restaurants-panel"/);
  assert.match(favorites, /role="tab"[^>]*aria-controls="favorite-products-panel"/);
  assert.match(favorites, /<section[^>]*id="favorite-restaurants-panel"[^>]*role="tabpanel"/);
  assert.match(favorites, /<section[^>]*id="favorite-products-panel"[^>]*role="tabpanel"/);
  assert.match(favorites, /SavoraState\.toggleFavorite\(/);
  assert.match(favorites, /Remove .* from favorites/);
  assert.match(favorites, /SavoraUI\.openMenuModal\(/);
  assert.match(favorites, /replaceChildren\(/);
  assert.match(favorites, /textContent\s*=/);
  assert.match(favorites, /keydown/);
  assert.doesNotMatch(favorites, /innerHTML\s*=/);
  assert.doesNotMatch(favorites, /onclick=/);
  assert.match(css, /@media \(max-width: 480px\)[\s\S]*\.favorite-card-grid\s*\{\s*grid-template-columns:\s*1fr;/);
});

test('orders and the shared cart renderer never use a localStorage-derived image URL', () => {
  const history = read('customer_history.php');
  const sharedUi = read('js/customer_ui.js');

  assert.doesNotMatch(history, /line\.image/);
  assert.match(history, /catalog\.imageFor\(catalogProduct\)/);
  assert.match(history, /src:\s*catalog\.imageFor\(product\)/);

  assert.doesNotMatch(sharedUi, /line\.image/);
  assert.doesNotMatch(sharedUi, /item\.(?:img|image)/);
  assert.match(sharedUi, /productImage\(catalogProduct\)/);
  assert.match(sharedUi, /addToCart:[\s\S]{0,350}\|\|\s*\{[^}]*image:\s*['"]['"][^}]*\}/);

  for (const pageName of ['customer_cart.php', 'customer_checkout.php']) {
    const page = read(pageName);
    assert.doesNotMatch(page, /line\.image/);
    assert.match(page, /SavoraCatalog\.products\[String\(line\.id\)\]/);
    assert.match(page, /SavoraCatalog\.imageFor\(catalogProduct\)/);
  }
});

test('focusable favorites tabpanels retain a visible keyboard focus indicator', () => {
  const favorites = read('customer_favorites.php');
  const css = read('css/customer_style.css');

  assert.match(favorites, /role="tabpanel"[\s\S]{0,100}tabindex="0"/);
  assert.doesNotMatch(css, /\.favorite-panel:focus\s*\{\s*outline:\s*none;/);
  assert.match(css, /\.favorite-panel:focus-visible\s*\{[^}]*outline:/);
});

test('profile is a labelled local-demo form without a password update claim', () => {
  const profile = read('customer_profile.php');

  assert.match(profile, /<form[^>]*id="profile-form"/);
  for (const id of ['profile-full-name', 'profile-email', 'profile-phone', 'profile-address']) {
    assert.match(profile, new RegExp(`<label[^>]*for="${id}"`));
  }
  assert.match(profile, /autocomplete="name"/);
  assert.match(profile, /autocomplete="email"/);
  assert.match(profile, /autocomplete="tel"/);
  assert.match(profile, /autocomplete="street-address"/);
  assert.match(profile, /saved locally on this device/i);
  assert.match(profile, /SavoraState\.setProfile\(/);
  assert.match(profile, /SavoraState\.persist\(/);
  assert.doesNotMatch(profile, /type="password"/);
  assert.doesNotMatch(profile, /password changes saved/i);
  assert.doesNotMatch(profile, /onsubmit=/);
  assert.doesNotMatch(profile, /innerHTML\s*=/);
  assert.doesNotMatch(profile, /\balert\s*\(/);
});

test('wallet uses event-driven safe rendering and an accessible top-up form', () => {
  const wallet = read('customer_wallet.php');
  const css = read('css/customer_style.css');

  assert.match(wallet, /id="wallet-topup-dialog"[^>]*role="dialog"[^>]*aria-modal="true"/);
  assert.match(wallet, /aria-labelledby="wallet-topup-title"/);
  assert.match(wallet, /<form[^>]*id="wallet-topup-form"/);
  assert.match(wallet, /<label[^>]*for="wallet-topup-amount"/);
  assert.match(wallet, /SavoraState\.load\(\)/);
  assert.match(wallet, /SavoraState\.topUpWallet\(/);
  assert.match(wallet, /SavoraState\.persist\(/);
  assert.match(wallet, /SavoraUI\.refreshChrome\(\)/);
  assert.match(wallet, /replaceChildren\(/);
  assert.match(wallet, /textContent\s*=/);
  assert.match(wallet, /['"]Credit['"]/);
  assert.match(wallet, /['"]Debit['"]/);
  assert.match(wallet, /Local demo/);
  assert.doesNotMatch(wallet, /setInterval\s*\(/);
  assert.doesNotMatch(wallet, /localStorage\.getItem\(/);
  assert.doesNotMatch(wallet, /innerHTML\s*=/);
  assert.doesNotMatch(wallet, /onclick=/);
  assert.doesNotMatch(wallet, /\balert\s*\(/);
  assert.match(css, /@media \(max-width: 768px\)[\s\S]*\.wallet-layout\s*\{\s*grid-template-columns:\s*1fr;/);
});

test('Customer discovery renders bridged restaurant storefront and accepting status safely', () => {
  const dashboard = read('customer_dashboard.php');
  const catalog = read('js/customer_catalog.js');

  assert.match(catalog, /description:\s*text\(source\.description\)/);
  assert.match(catalog, /acceptingOrders/);
  assert.match(catalog, /weeklyHours/);
  assert.match(catalog, /specialHours/);
  assert.match(dashboard, /restaurant\.acceptingOrders === false/);
  assert.match(dashboard, /restaurant\.description/);
  assert.match(dashboard, /restaurant\.address/);
  assert.match(dashboard, /text:\s*restaurant\.status/);
  assert.doesNotMatch(dashboard, /innerHTML\s*=/);
});

test('Customer discovery uses the menu image fallback and explicit fulfillment copy', () => {
  const dashboard = read('customer_dashboard.php');

  assert.match(dashboard, /restaurant\.image\s*\?\s*SavoraCatalog\.imageFor\(\{ image: restaurant\.image \}\)\s*:\s*SavoraCatalog\.imageFor\(menu\[0\]\)/);
  assert.match(dashboard, /Delivery and pickup available/);
  assert.match(dashboard, /Delivery available within/);
  assert.match(dashboard, /Pickup available/);
  assert.match(dashboard, /Delivery and pickup unavailable/);
});

test('Customer tracking loads Driver state and renders dispatch visibility safely', () => {
  const footer = read('components/customer_footer.php');
  const dashboard = read('customer_dashboard.php');
  const history = read('customer_history.php');

  assert.match(footer, /js\/driver_state\.js/);
  assert.match(dashboard, /SavoraDriverState/);
  assert.match(dashboard, /deliveryForOrder/);
  assert.match(dashboard, /dispatchForOrder/);
  assert.match(dashboard, /function initializeTrackingMap\(delivery\)/);
  assert.match(dashboard, /const driverState = delivery && window\.SavoraDriverState/);
  assert.match(dashboard, /if \(driverLocation\) \{/);
  assert.match(history, /SavoraDriverState/);
  assert.match(history, /deliveryForOrder/);
  assert.match(history, /dispatchForOrder/);
  assert.match(history, /Searching for a nearby driver/);
  assert.match(history, /Driver assigned/);
  assert.doesNotMatch(`${dashboard}\n${history}`, /innerHTML\s*=/);
});
