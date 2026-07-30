const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('Restaurant Overview uses the shared authenticated shell and semantic data hooks', () => {
  for (const file of [
    'components/restaurant_header.php',
    'components/restaurant_footer.php',
    'css/restaurant_style.css',
    'js/restaurant_ui.js'
  ]) {
    assert.ok(fs.existsSync(path.join(root, file)), `${file} must exist`);
  }

  const page = read('restaurant_dashboard.php');
  const header = read('components/restaurant_header.php');
  const footer = read('components/restaurant_footer.php');

  assert.match(page, /require_once\s+__DIR__\s*\.\s*['\"]\/components\/restaurant_header\.php['\"]/);
  assert.match(page, /require_once\s+__DIR__\s*\.\s*['\"]\/components\/restaurant_footer\.php['\"]/);
  assert.match(header, /session_status\(\)\s*===\s*PHP_SESSION_NONE/);
  assert.match(header, /\(\$_SESSION\['role'\]\s*\?\?\s*''\)\s*!==\s*'restaurant'/);
  assert.equal((page.match(/<main\b/gi) || []).length, 1, 'Overview has one main landmark');
  assert.match(page, /<h1[^>]*>\s*Restaurant Overview\s*<\/h1>/);
  assert.match(page, /data-overview-kpis/);
  assert.match(page, /data-overview-chart/);
  assert.match(page, /data-live-queue/);
  assert.match(page, /data-top-items/);
  assert.match(page, /data-low-stock/);
  assert.match(page, /SavoraRestaurantState\.load\(\)/);
  assert.match(page, /SavoraState\.load\(\)/);
  assert.match(page, /SavoraRestaurantUI\.el/);
  assert.doesNotMatch(`${page}\n${header}\n${footer}`, /href\s*=\s*["']#["']/i);
  assert.doesNotMatch(`${page}\n${header}\n${footer}`, /\son[a-z]+\s*=/i);
});

test('Restaurant shell has local assets, accessible navigation, and named UI helpers', () => {
  const header = read('components/restaurant_header.php');
  const footer = read('components/restaurant_footer.php');
  const css = read('css/restaurant_style.css');
  const ui = read('js/restaurant_ui.js');

  assert.match(header, /assets\/vendor\/fontawesome\/css\/all\.min\.css/);
  assert.match(header, /css\/restaurant_style\.css/);
  assert.match(footer, /js\/restaurant_state\.js/);
  assert.match(footer, /js\/customer_state\.js/);
  assert.match(footer, /js\/restaurant_ui\.js/);
  assert.match(header, /class="skip-link"/);
  assert.match(header, /role="dialog"/);
  assert.match(header, /aria-current="page"/);
  assert.match(header, /for="restaurant-search"/);
  assert.match(header, /data-accepting-orders/);
  assert.match(header, /data-owner-menu/);
  assert.match(footer, /aria-live="polite"/);
  assert.match(ui, /function el\(/);
  assert.match(ui, /function showToast\(/);
  assert.match(ui, /function formatMoney\(/);
  assert.match(ui, /function statusChip\(/);
  assert.match(ui, /function refreshShell\(/);
  assert.match(ui, /function openDialog\(/);
  assert.match(ui, /function closeDialog\(/);
  assert.doesNotMatch(ui, /innerHTML\s*=/);
  assert.match(css, /--restaurant-primary:\s*#073B2B/);
  assert.match(css, /--restaurant-focus:\s*#1B75D0/);
  assert.match(css, /:focus-visible/);
  assert.match(css, /prefers-reduced-motion/);
  for (const breakpoint of ['1024px', '768px', '480px']) {
    assert.match(css, new RegExp(`@media \\(max-width: ${breakpoint.replace('.', '\\.')}\\)`));
  }
});

test('Restaurant shell closes a mobile dialog from its scrim and keeps the trigger state in sync', () => {
  const ui = read('js/restaurant_ui.js');

  assert.match(ui, /event\.target\.closest\('\[data-close-dialog\]'\)/);
  assert.match(ui, /function setDialogTrigger\(dialog, expanded\)/);
  assert.match(ui, /setDialogTrigger\(dialog, true\)/);
  assert.match(ui, /setDialogTrigger\(dialog, false\)/);
  assert.match(ui, /\[aria-controls="\$\{dialog\.id\}"\]/);
});

test('Restaurant stylesheet supplies reusable responsive tables and accessible form controls', () => {
  const css = read('css/restaurant_style.css');

  assert.match(css, /\.restaurant-table-wrap\s*\{[^}]*overflow-x:\s*auto;/);
  assert.match(css, /\.restaurant-table\s*\{[^}]*width:\s*100%;/);
  assert.match(css, /\.restaurant-table th,\s*\.restaurant-table td/);
  assert.match(css, /\.restaurant-form\s*\{/);
  assert.match(css, /\.restaurant-field\s*\{/);
  assert.match(css, /\.restaurant-field input,\s*\.restaurant-field select,\s*\.restaurant-field textarea/);
  assert.match(css, /\.restaurant-field input:focus-visible/);
  assert.match(css, /@media \(max-width: 480px\)[\s\S]*\.restaurant-table th,\s*\.restaurant-table td/);
});

test('Live Order Center uses the shared shell and exposes accessible order-operation hooks', () => {
  for (const file of ['restaurant_orders.php', 'js/restaurant_orders.js']) {
    assert.ok(fs.existsSync(path.join(root, file)), `${file} must exist`);
  }

  const page = read('restaurant_orders.php');
  const controller = read('js/restaurant_orders.js');
  assert.match(page, /require_once\s+__DIR__\s*\.\s*['"]\/components\/restaurant_header\.php['"]/);
  assert.match(page, /require_once\s+__DIR__\s*\.\s*['"]\/components\/restaurant_footer\.php['"]/);
  assert.equal((page.match(/<main\b/gi) || []).length, 1, 'Live Orders has one main landmark');
  assert.match(page, /<h1[^>]*>\s*Live Order Center\s*<\/h1>/);
  assert.match(page, /data-live-order-counts/);
  assert.match(page, /role="tablist"/);
  assert.match(page, /data-live-order-filter/);
  assert.match(page, /data-live-order-list/);
  assert.match(page, /data-order-details/);
  assert.match(page, /name="prep-minutes"/);
  for (const action of ['accept', 'reject', 'ready']) assert.match(page, new RegExp(`data-order-action="${action}"`));
  assert.match(page, /data-order-feedback[^>]*aria-live="polite"/);
  assert.match(page, /js\/restaurant_orders\.js/);
  assert.match(controller, /SavoraRestaurantState\.updateOrderStatus/);
  assert.match(controller, /SavoraState\.persist/);
  assert.match(controller, /textContent/);
  assert.doesNotMatch(`${page}\n${controller}`, /innerHTML\s*=/);
  assert.doesNotMatch(`${page}\n${controller}`, /\son[a-z]+\s*=/i);
  assert.doesNotMatch(page, /href\s*=\s*["']#["']/i);
});

test('Order History provides safe responsive records, audit detail, and real follow-up links', () => {
  assert.ok(fs.existsSync(path.join(root, 'restaurant_order_history.php')), 'restaurant_order_history.php must exist');
  const page = read('restaurant_order_history.php');
  const controller = read('js/restaurant_orders.js');
  const css = read('css/restaurant_style.css');

  assert.match(page, /require_once\s+__DIR__\s*\.\s*['"]\/components\/restaurant_header\.php['"]/);
  assert.match(page, /require_once\s+__DIR__\s*\.\s*['"]\/components\/restaurant_footer\.php['"]/);
  assert.equal((page.match(/<main\b/gi) || []).length, 1, 'Order History has one main landmark');
  assert.match(page, /<h1[^>]*>\s*Order History\s*<\/h1>/);
  assert.match(page, /data-history-summary/);
  for (const control of ['history-date', 'history-search', 'history-status', 'history-fulfillment']) assert.match(page, new RegExp(`name="${control}"`));
  assert.match(page, /data-history-table/);
  assert.match(page, /data-history-cards/);
  assert.match(page, /data-history-details/);
  assert.match(page, /data-status-timeline/);
  assert.match(page, /data-history-pagination/);
  assert.match(page, /data-history-page="previous"/);
  assert.match(page, /data-history-page="next"/);
  assert.match(page, /data-history-invoice/);
  assert.match(page, /data-history-order/);
  assert.match(page, /data-history-reorder/);
  assert.match(page, /aria-live="polite"/);
  for (const view of ['invoice', 'order', 'reorder']) {
    assert.match(page, new RegExp(`href="restaurant_order_history\\.php\\?order=&amp;view=${view}#history-details-title"`));
  }
  assert.ok(fs.existsSync(path.join(root, 'restaurant_order_history.php')), 'follow-up route must exist');
  assert.match(controller, /historyPage:\s*1/);
  assert.match(controller, /HISTORY_PAGE_SIZE/);
  assert.match(controller, /records\.slice\(/);
  assert.match(controller, /data-history-page/);
  assert.match(controller, /new URLSearchParams\(root\.location\.search\)/);
  assert.match(controller, /historyParams\.get\('order'\)/);
  assert.match(controller, /historyParams\.get\('view'\)/);
  assert.match(controller, /restaurant_order_history\.php\?order=/);
  assert.match(controller, /view=invoice/);
  assert.match(controller, /view=order/);
  assert.match(controller, /view=reorder/);
  assert.doesNotMatch(`${page}\n${controller}`, /customer_history\.php\?/i);
  assert.doesNotMatch(`${page}\n${controller}`, /restaurant_invoices\.php\?/i);
  assert.match(css, /@media \(max-width: 768px\)[\s\S]*\[data-history-table\]\s*\{\s*display:\s*none;/);
  assert.match(css, /@media \(max-width: 768px\)[\s\S]*\[data-history-cards\]\s*\{\s*display:\s*grid;/);
  assert.match(css, /@media \(min-width: 769px\)[\s\S]*\[data-history-cards\]\s*\{\s*display:\s*none;/);
  assert.doesNotMatch(page, /\son[a-z]+\s*=/i);
  assert.doesNotMatch(page, /href\s*=\s*["']#["']/i);
});

test('Live Order details retains its labelled heading after safe rerendering', () => {
  const page = read('restaurant_orders.php');
  const controller = read('js/restaurant_orders.js');

  assert.match(page, /data-order-details[^>]*aria-labelledby="live-order-details-title"/);
  assert.match(controller, /heading\('h2', 'Order details', 'live-order-details-title'\)/);
});

test('Menu Management and Menu Item Editor use the shared shell with accessible customer-preview controls', () => {
  for (const file of ['restaurant_menu.php', 'restaurant_menu_item.php', 'js/restaurant_menu.js']) {
    assert.ok(fs.existsSync(path.join(root, file)), `${file} must exist`);
  }
  const menu = read('restaurant_menu.php');
  const editor = read('restaurant_menu_item.php');
  const controller = read('js/restaurant_menu.js');

  for (const page of [menu, editor]) {
    assert.match(page, /require_once\s+__DIR__\s*\.\s*['"]\/components\/restaurant_header\.php['"]/);
    assert.match(page, /require_once\s+__DIR__\s*\.\s*['"]\/components\/restaurant_footer\.php['"]/);
    assert.equal((page.match(/<main\b/gi) || []).length, 1, 'each menu route has one main landmark');
    assert.doesNotMatch(page, /\son[a-z]+\s*=/i);
    assert.doesNotMatch(page, /href\s*=\s*["']#["']/i);
  }

  assert.match(menu, /<h1[^>]*>\s*Menu Management\s*<\/h1>/);
  for (const hook of ['data-menu-search', 'data-menu-category', 'data-menu-availability', 'data-menu-sort', 'data-menu-view', 'data-menu-list', 'data-menu-feedback']) assert.match(menu, new RegExp(hook));
  assert.match(controller, /restaurant_menu_item\.php\?id=/);
  assert.match(controller, /data-menu-availability-toggle/);

  assert.match(editor, /<h1[^>]*data-menu-editor-title[^>]*>\s*Add Menu Item\s*<\/h1>/);
  for (const field of ['menu-name', 'menu-description', 'menu-category', 'menu-image', 'menu-price', 'menu-compare-price', 'menu-tax-category', 'menu-available', 'menu-stock', 'menu-prep-time', 'menu-dietary-tags']) assert.match(editor, new RegExp(`name="${field}"`));
  assert.match(editor, /data-menu-option-groups/);
  assert.match(editor, /data-menu-add-ons/);
  assert.match(editor, /data-menu-customer-preview/);
  assert.match(editor, /data-menu-validation[^>]*aria-live="polite"/);
  assert.match(editor, /data-menu-status[^>]*aria-live="polite"/);
  assert.match(editor, /data-menu-save="draft"/);
  assert.match(editor, /data-menu-save="publish"/);

  assert.match(controller, /api\.setMenuItem/);
  assert.match(controller, /api\.setItemAvailability/);
  assert.match(controller, /api\.persist/);
  assert.match(controller, /textContent/);
  assert.doesNotMatch(controller, /innerHTML\s*=/);
});
