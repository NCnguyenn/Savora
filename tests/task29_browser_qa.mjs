import fs from 'node:fs/promises';
import path from 'node:path';

const debugPort = Number(process.env.SAVORA_CDP_PORT || 9227);
const baseUrl = process.env.SAVORA_BASE_URL || 'http://localhost/Savora';
const artifactDir = path.resolve('.superpowers/sdd/task29/browser-qa');
const viewports = [
  { width: 320, height: 900 },
  { width: 768, height: 1024 },
  { width: 1440, height: 1000 }
];
const routes = {
  public: ['index.php', 'register.php', 'register_customer.php', 'register_restaurant.php', 'register_driver.php', 'forgot_password.php', 'reset_password.php'],
  admin: ['admin_accounts.php', 'admin_restaurants.php', 'admin_drivers.php'],
  customer: ['customer_dashboard.php', 'customer_cart.php', 'customer_checkout.php', 'customer_history.php', 'customer_profile.php', 'customer_favorites.php', 'customer_wallet.php'],
  restaurant: ['restaurant_dashboard.php', 'restaurant_orders.php', 'restaurant_order_history.php', 'restaurant_menu.php', 'restaurant_menu_item.php', 'restaurant_profile.php', 'restaurant_operations.php', 'restaurant_finance.php', 'restaurant_invoices.php', 'restaurant_analytics.php', 'restaurant_reviews.php'],
  driver: ['driver_dashboard.php', 'driver_delivery.php', 'driver_history.php', 'driver_earnings.php', 'driver_profile.php']
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

  close() { this.socket.close(); }
}

const delay = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
const assert = (condition, message) => { if (!condition) throw new Error(message); };
const routeName = route => route.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '');

async function openPage() {
  const versionResponse = await fetch(`http://127.0.0.1:${debugPort}/json/version`);
  if (!versionResponse.ok) throw new Error(`Unable to inspect browser: ${versionResponse.status}`);
  const version = await versionResponse.json();
  const browser = new CdpClient(version.webSocketDebuggerUrl);
  await browser.connect();
  const { browserContextId } = await browser.send('Target.createBrowserContext');
  const { targetId } = await browser.send('Target.createTarget', { url: 'about:blank', browserContextId });
  let target = null;
  for (let attempt = 0; attempt < 30 && !target; attempt += 1) {
    const response = await fetch(`http://127.0.0.1:${debugPort}/json/list`);
    const targets = response.ok ? await response.json() : [];
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
    try { await browser.send('Target.closeTarget', { targetId }); } catch (_) { /* already closed */ }
    try { await browser.send('Target.disposeBrowserContext', { browserContextId }); } catch (_) { /* already disposed */ }
    browser.close();
  };
  return client;
}

async function evaluate(client, expression) {
  const result = await client.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text || 'Runtime evaluation failed');
  return result.result ? result.result.value : undefined;
}

async function waitFor(client, expression, message, timeout = 10000) {
  const started = Date.now();
  while (Date.now() - started < timeout) {
    if (await evaluate(client, expression)) return;
    await delay(75);
  }
  throw new Error(`Timed out: ${message}`);
}

async function navigate(client, route) {
  const url = new URL(route, `${baseUrl.replace(/\/$/, '')}/`).href;
  await client.send('Page.navigate', { url });
  await waitFor(client, "document.readyState === 'complete'", `loading ${route}`);
  await delay(250);
}

async function setViewport(client, viewport) {
  await client.send('Emulation.setDeviceMetricsOverride', {
    width: viewport.width,
    height: viewport.height,
    deviceScaleFactor: 1,
    mobile: false
  });
}

async function loginAs(client, role) {
  await navigate(client, 'logout.php');
  await navigate(client, 'index.php');
  await waitFor(client, "Boolean(document.querySelector('[name=username]') && document.querySelector('[name=password]'))", 'login form');
  await evaluate(client, `(() => {
    document.querySelector('[name=username]').value = ${JSON.stringify(role)};
    document.querySelector('[name=password]').value = '123456';
    document.querySelector('form').submit();
    return true;
  })()`);
  const destination = {
    customer: 'customer_dashboard.php',
    restaurant: 'restaurant_dashboard.php',
    driver: 'driver_dashboard.php',
    admin: 'admin_dashboard.php'
  }[role];
  await waitFor(client, `location.pathname.endsWith('/${destination}')`, `${role} login`);
}

async function auditRouteMatrix(client, group, runtimeErrors, report) {
  for (const viewport of viewports) {
    await setViewport(client, viewport);
    for (const route of routes[group]) {
      runtimeErrors.length = 0;
      await navigate(client, route);
      await client.send('Page.bringToFront');
      const state = await evaluate(client, `(() => {
        const main = document.querySelector('main');
        const focusCandidates = [...document.querySelectorAll('main a[href], main button:not([disabled]), main input:not([disabled]), main select:not([disabled]), main textarea:not([disabled]), .login-card a[href], .login-card button:not([disabled]), .login-card input:not([disabled]), header a[href], header button:not([disabled]), nav a[href], nav button:not([disabled])')];
        const focusTarget = focusCandidates.find(node => !node.hidden && !node.disabled && node.getClientRects().length) || focusCandidates[0];
        focusTarget?.focus();
        return {
          title: document.title,
          main: Boolean(main),
          form: Boolean(document.querySelector('.login-card, main')),
          overflow: Math.max(0, document.documentElement.scrollWidth - window.innerWidth),
          overflowNodes: [...document.querySelectorAll('body *')]
            .filter(node => {
              const rect = node.getBoundingClientRect();
              return rect.left < -2 || rect.right > window.innerWidth + 2;
            })
            .slice(0, 8)
            .map(node => {
              const rect = node.getBoundingClientRect();
              return {
                tag: node.tagName,
                id: node.id,
                className: typeof node.className === 'string' ? node.className : '',
                left: Math.round(rect.left),
                right: Math.round(rect.right),
                width: Math.round(rect.width)
              };
            }),
          target: focusTarget?.tagName || '',
          targetTabIndex: focusTarget?.tabIndex ?? -1,
          targetDisabled: Boolean(focusTarget?.disabled),
          targetVisible: Boolean(focusTarget && focusTarget.getClientRects().length),
          emptyLabels: [...document.querySelectorAll('label')].filter(label => !label.textContent.trim() && !label.getAttribute('aria-label')).length,
          mixedLanguageMarkers: (document.body.innerText.match(/\b(Đăng|Tài khoản|Mật khẩu|Nhà hàng|Tài xế|Khách hàng)\b/gi) || []).length
        };
      })()`);
      assert(state.form, `${group}/${route} has no page landmark at ${viewport.width}px`);
      assert(state.title.includes('Savora'), `${group}/${route} has invalid title at ${viewport.width}px`);
      assert(state.overflow <= 2, `${group}/${route} overflows horizontally by ${state.overflow}px at ${viewport.width}px: ${JSON.stringify(state.overflowNodes)}`);
      assert(state.target && state.targetTabIndex >= 0 && !state.targetDisabled && state.targetVisible, `${group}/${route} has no keyboard-focusable visible target at ${viewport.width}px: ${JSON.stringify(state)}`);
      assert(state.emptyLabels === 0, `${group}/${route} has ${state.emptyLabels} empty labels at ${viewport.width}px`);
      assert(state.mixedLanguageMarkers === 0, `${group}/${route} contains mixed-language copy at ${viewport.width}px`);
      assert(!runtimeErrors.length, `${group}/${route} raised runtime errors at ${viewport.width}px: ${runtimeErrors.join(' | ')}`);
      const screenshot = await client.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
      await fs.writeFile(path.join(artifactDir, 'screenshots', `${group}-${routeName(route)}-${viewport.width}.png`), Buffer.from(screenshot.data, 'base64'));
      report.routes.push({ group, route, width: viewport.width, overflow: state.overflow });
    }
  }
}

async function checkPartnerMultipart(client, runtimeErrors, report) {
  for (const type of ['restaurant', 'driver']) {
    await setViewport(client, viewports[0]);
    runtimeErrors.length = 0;
    await navigate(client, type === 'restaurant' ? 'register_restaurant.php' : 'register_driver.php');
    const result = await evaluate(client, `(() => {
      const form = document.querySelector('[data-auth-form]');
      const files = [...document.querySelectorAll('input[type="file"]')];
      const data = form ? new FormData(form) : null;
      return {
        enctype: form?.enctype,
        method: form?.method,
        type: ${JSON.stringify(type)},
        names: files.map(input => input.name),
        required: files.every(input => input.required),
        formDataHasFileFields: Boolean(data && files.every(input => data.has(input.name)))
      };
    })()`);
    if (type === 'restaurant') assert(result.enctype === 'multipart/form-data', 'Restaurant application form is not multipart/form-data');
    assert(result.method === 'post', `${type} application form is not POST`);
    assert(result.type === type, `${type} application form has wrong type marker`);
    assert(type === 'restaurant' ? (result.names.length === 1 && result.names[0] === 'logo' && !result.required) : result.names.length === 0, `${type} application form has an invalid file contract`);
    assert(!runtimeErrors.length, `${type} application form raised runtime errors: ${runtimeErrors.join(' | ')}`);
    report.partnerMultipart.push({ type, fileInputs: result.names.length });
  }
}

async function checkRestaurantServerPages(client, runtimeErrors, report) {
  await loginAs(client, 'restaurant');
  await setViewport(client, { width: 1440, height: 1000 });
  await navigate(client, 'restaurant_finance.php');
  await waitFor(client, "performance.getEntriesByType('resource').some(entry => entry.name.includes('api/finance.php'))", 'server finance request');
  const finance = await evaluate(client, `(() => ({
    feedback: document.querySelector('[data-finance-feedback]')?.textContent.trim() || '',
    transactions: document.querySelectorAll('[data-finance-transaction-body] tr').length,
    serverText: document.querySelector('[data-finance-page]')?.innerText.includes('server ledger')
  }))()`);
  assert(!finance.feedback, `Finance page reported a server error: ${finance.feedback}`);
  assert(finance.serverText, 'Finance page is missing server-ledger copy');
  await navigate(client, 'restaurant_invoices.php');
  await waitFor(client, "performance.getEntriesByType('resource').some(entry => entry.name.includes('api/finance.php'))", 'server documents request');
  const documents = await evaluate(client, `(() => ({
    feedback: document.querySelector('[data-document-feedback]')?.textContent.trim() || '',
    count: Number(document.querySelector('[data-document-count]')?.textContent || 0),
    buttons: document.querySelectorAll('[data-document-select]').length,
    serverText: document.querySelector('[data-documents-page]')?.innerText.includes('server-generated')
  }))()`);
  assert(!documents.feedback, `Invoice page reported a server error: ${documents.feedback}`);
  assert(documents.serverText && documents.count >= 0, 'Invoice page is not server-backed');
  if (documents.buttons) {
    await evaluate(client, "document.querySelector('[data-document-select]').click()");
    assert(await evaluate(client, "document.querySelector('[data-document-preview]').innerText.includes('server')"), 'Invoice preview is not server-backed');
  }
  await navigate(client, 'restaurant_dashboard.php');
  await checkMobileDialog(client, '.restaurant-mobile-menu-button', 'restaurant-mobile-navigation', runtimeErrors, report);
  report.serverPages.push({ page: 'finance', transactionRows: finance.transactions });
  report.serverPages.push({ page: 'invoices', documents: documents.count });
}

async function checkDriverServerPage(client, runtimeErrors, report) {
  await loginAs(client, 'driver');
  await setViewport(client, { width: 1440, height: 1000 });
  await navigate(client, 'driver_dashboard.php');
  await waitFor(client, "performance.getEntriesByType('resource').some(entry => entry.name.includes('api/dispatch.php'))", 'server dispatch request');
  const state = await evaluate(client, `(() => ({
    source: document.querySelector('[data-summary-source]')?.textContent.trim() || '',
    offers: document.querySelectorAll('[data-offer-accept], [data-offer-decline]').length,
    copy: document.querySelector('[data-delivery-offer]')?.innerText || ''
  }))()`);
  assert(state.source === 'MySQL server', `Driver summary source is ${JSON.stringify(state.source)}`);
  assert(state.offers === 0, `Driver received ${state.offers} offer action(s) before Phase 6`);
  assert(/server/i.test(state.copy), 'Driver offer panel is missing server-only copy');
  await checkMobileDialog(client, '.driver-mobile-menu', 'driver-mobile-navigation', runtimeErrors, report);
  report.serverPages.push({ page: 'driver-dispatch', offers: state.offers });
}

async function checkMobileDialog(client, triggerSelector, dialogId, runtimeErrors, report) {
  await setViewport(client, { width: 320, height: 900 });
  const trigger = await evaluate(client, `(() => {
    const button = document.querySelector(${JSON.stringify(triggerSelector)});
    if (!button) return { exists: false, url: location.href, title: document.title, bodyLength: document.body?.innerHTML.length || 0, shell: Boolean(document.querySelector('.restaurant-shell, .driver-shell')), buttons: [...document.querySelectorAll('button')].map(item => item.className).slice(0, 12) };
    button.click();
    return { exists: true, hidden: document.getElementById(${JSON.stringify(dialogId)})?.hidden, ui: Boolean(window.SavoraRestaurantUI || window.SavoraDriverUI) };
  })()`);
  assert(trigger.exists, `${triggerSelector} is missing at ${trigger.url || 'unknown URL'} (${trigger.title || 'untitled'}; body=${trigger.bodyLength || 0}; shell=${Boolean(trigger.shell)}; buttons=${JSON.stringify(trigger.buttons || [])})`);
  await waitFor(client, `!document.getElementById(${JSON.stringify(dialogId)})?.hidden`, `${dialogId} opens`);
  await evaluate(client, "document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))");
  await waitFor(client, `document.getElementById(${JSON.stringify(dialogId)})?.hidden`, `${dialogId} closes`);
  assert(!runtimeErrors.length, `${dialogId} raised runtime errors: ${runtimeErrors.join(' | ')}`);
  report.interactions.push(`${dialogId}:open-close`);
}

async function run() {
  await fs.mkdir(path.join(artifactDir, 'screenshots'), { recursive: true });
  const client = await openPage();
  const runtimeErrors = [];
  client.on('Runtime.exceptionThrown', params => {
    const details = params.exceptionDetails || {};
    runtimeErrors.push(`${details.url || 'inline'}:${Number(details.lineNumber || 0) + 1} ${details.exception?.description || details.text || 'Unknown runtime exception'}`);
  });
  const report = { routes: [], partnerMultipart: [], serverPages: [], interactions: [] };
  try {
    await auditRouteMatrix(client, 'public', runtimeErrors, report);
    await checkPartnerMultipart(client, runtimeErrors, report);
    await loginAs(client, 'customer');
    await auditRouteMatrix(client, 'customer', runtimeErrors, report);
    await checkRestaurantServerPages(client, runtimeErrors, report);
    await auditRouteMatrix(client, 'restaurant', runtimeErrors, report);
    await checkDriverServerPage(client, runtimeErrors, report);
    await auditRouteMatrix(client, 'driver', runtimeErrors, report);
    await loginAs(client, 'admin');
    await auditRouteMatrix(client, 'admin', runtimeErrors, report);
    await fs.writeFile(path.join(artifactDir, 'report.json'), JSON.stringify(report, null, 2));
    console.log(`Task 29 browser QA passed: ${report.routes.length} route/viewport checks, ${report.partnerMultipart.length} multipart forms, ${report.serverPages.length} server checks, ${report.interactions.length} interactions.`);
  } finally {
    await client.cleanup();
  }
}

run().catch(error => {
  console.error(error.stack || error.message || error);
  process.exitCode = 1;
});
