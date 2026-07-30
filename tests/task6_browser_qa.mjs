import fs from 'node:fs/promises';
import path from 'node:path';

const debugPort = 9227;
const baseUrl = 'http://localhost/Savora';
const artifactDir = path.resolve('.superpowers/sdd/customer-ui-2026-07-29');

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

async function openPage() {
  const response = await fetch(`http://127.0.0.1:${debugPort}/json/new?about:blank`, { method: 'PUT' });
  if (!response.ok) throw new Error(`Unable to create browser page: ${response.status}`);
  const target = await response.json();
  const client = new CdpClient(target.webSocketDebuggerUrl);
  await client.connect();
  await Promise.all([client.send('Page.enable'), client.send('Runtime.enable'), client.send('Network.enable')]);
  await client.send('Network.setCacheDisabled', { cacheDisabled: true });
  await client.send('Page.bringToFront');
  return client;
}

async function evaluate(client, expression) {
  const result = await client.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.text || 'Runtime evaluation failed');
  return result.result ? result.result.value : undefined;
}

async function waitFor(client, expression, message, timeout = 6000) {
  const started = Date.now();
  while (Date.now() - started < timeout) {
    if (await evaluate(client, expression)) return;
    await delay(75);
  }
  throw new Error(`Timed out: ${message}`);
}

async function navigate(client, url) {
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

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

async function main() {
  const client = await openPage();
  const exceptions = [];
  client.on('Runtime.exceptionThrown', event => {
    const details = event.exceptionDetails || {};
    exceptions.push({
      text: details.text || 'JavaScript exception',
      description: details.exception?.description || '',
      url: details.url || '',
      line: Number(details.lineNumber || 0) + 1,
      column: Number(details.columnNumber || 0) + 1
    });
  });

  try {
    await setViewport(client, 1440);
    await navigate(client, `${baseUrl}/index.php`);
    await evaluate(client, `(() => {
      document.getElementById('username').value = 'customer';
      document.getElementById('password').value = '123456';
      document.querySelector('form').submit();
      return true;
    })()`);
    await waitFor(client, "location.pathname.endsWith('/customer_dashboard.php')", 'customer login');

    await evaluate(client, "localStorage.removeItem('savora_customer_state_v2'); true");
    await navigate(client, `${baseUrl}/customer_profile.php`);
    await evaluate(client, `(() => {
      const values = {
        'profile-full-name': 'Task Six Customer',
        'profile-email': 'task6@example.com',
        'profile-phone': '+65 9000 0050',
        'profile-address': '50 Savora Lane'
      };
      for (const [id, value] of Object.entries(values)) {
        const input = document.getElementById(id);
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
      }
      document.getElementById('profile-form').requestSubmit();
      return true;
    })()`);
    await waitFor(client, "document.getElementById('profile-save-status').textContent.includes('saved locally on this device')", 'profile save confirmation');
    const savedProfile = await evaluate(client, `(() => {
      const state = SavoraState.load();
      return {
        profile: state.profile,
        status: document.getElementById('profile-save-status').textContent,
        avatar: document.querySelector('[data-avatar]').textContent
      };
    })()`);
    assert(savedProfile.profile.fullName === 'Task Six Customer', 'profile full name did not persist');
    assert(savedProfile.profile.address === '50 Savora Lane', 'profile address did not persist');
    assert(savedProfile.avatar === 'T', 'shared avatar did not refresh after profile save');

    await client.send('Page.reload', { ignoreCache: true });
    await waitFor(client, "document.readyState === 'complete' && document.getElementById('profile-address')?.value === '50 Savora Lane'", 'profile reload persistence');
    const reloadedProfile = await evaluate(client, `({
      fullName: document.getElementById('profile-full-name').value,
      email: document.getElementById('profile-email').value,
      phone: document.getElementById('profile-phone').value,
      address: document.getElementById('profile-address').value
    })`);
    assert(reloadedProfile.fullName === 'Task Six Customer', 'profile full name missing after reload');
    assert(reloadedProfile.email === 'task6@example.com', 'profile email missing after reload');
    assert(reloadedProfile.phone === '+65 9000 0050', 'profile phone missing after reload');

    const overflow = [];
    for (const width of [320, 768, 1440]) {
      await setViewport(client, width);
      for (const page of ['profile', 'wallet']) {
        await navigate(client, `${baseUrl}/customer_${page}.php`);
        const metrics = await evaluate(client, `({
          viewport: innerWidth,
          scrollWidth: document.documentElement.scrollWidth,
          mainWidth: document.querySelector('main').getBoundingClientRect().width
        })`);
        overflow.push({ page, width, ...metrics, passes: metrics.scrollWidth <= metrics.viewport });
        assert(metrics.scrollWidth <= metrics.viewport, `${page} overflows at ${width}px (${metrics.scrollWidth}px)`);
        await screenshot(client, `task-6-${page}-${width}.png`);
      }
    }

    await setViewport(client, 1440);
    await navigate(client, `${baseUrl}/customer_wallet.php`);
    const initialWallet = await evaluate(client, `({
      balance: SavoraState.load().wallet.balance,
      rendered: document.getElementById('wallet-page-balance').textContent
    })`);
    assert(initialWallet.balance === 0 && initialWallet.rendered === '$0.00', 'zero balance did not render as $0.00');

    await evaluate(client, "document.getElementById('wallet-open-topup').click(); true");
    await waitFor(client, "!document.getElementById('wallet-topup-dialog').hidden", 'opening top-up dialog');
    await waitFor(client, "document.activeElement.getAttribute('aria-label') === 'Close top-up dialog'", 'dialog requestAnimationFrame focus');
    const initialFocus = await evaluate(client, "document.activeElement.getAttribute('aria-label')");
    assert(initialFocus === 'Close top-up dialog', 'dialog did not focus its first control');
    const tabTrap = await evaluate(client, `(() => {
      const dialog = document.getElementById('wallet-topup-dialog');
      const nodes = [...dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')].filter(node => !node.hidden);
      const first = nodes[0];
      const last = nodes[nodes.length - 1];
      last.focus();
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));
      const wrapsForward = document.activeElement === first;
      first.focus();
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true }));
      return { wrapsForward, wrapsBackward: document.activeElement === last, stayedInside: dialog.contains(document.activeElement) };
    })()`);
    assert(tabTrap.wrapsForward && tabTrap.wrapsBackward && tabTrap.stayedInside, `dialog focus trap failed: ${JSON.stringify(tabTrap)}`);
    const escapeEvidence = await evaluate(client, `(() => {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
      return { hidden: document.getElementById('wallet-topup-dialog').hidden, focusedId: document.activeElement.id };
    })()`);
    assert(escapeEvidence.hidden, `Escape did not close top-up dialog: ${JSON.stringify(escapeEvidence)}`);
    const focusReturned = await evaluate(client, "document.activeElement.id === 'wallet-open-topup'");
    assert(focusReturned, 'dialog did not return focus to the Add demo funds button');

    await evaluate(client, "document.getElementById('wallet-open-topup').click(); true");
    await waitFor(client, "!document.getElementById('wallet-topup-dialog').hidden", 'reopening top-up dialog');
    await screenshot(client, 'task-6-wallet-topup-dialog-1440.png');
    await evaluate(client, `(() => {
      const input = document.getElementById('wallet-topup-amount');
      input.value = '0';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      document.getElementById('wallet-topup-form').dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
      return true;
    })()`);
    const invalidResult = await evaluate(client, `({
      balance: SavoraState.load().wallet.balance,
      dialogOpen: !document.getElementById('wallet-topup-dialog').hidden,
      validationMessage: document.getElementById('wallet-topup-amount').validationMessage
    })`);
    assert(invalidResult.balance === 0, 'invalid amount changed wallet balance');
    assert(invalidResult.dialogOpen, 'invalid amount closed the dialog');
    assert(invalidResult.validationMessage.includes('greater than $0'), 'invalid amount did not expose validation feedback');

    await evaluate(client, `(() => {
      window.__task6RefreshCalls = 0;
      const refresh = SavoraUI.refreshChrome;
      SavoraUI.refreshChrome = function () {
        window.__task6RefreshCalls += 1;
        return refresh();
      };
      const input = document.getElementById('wallet-topup-amount');
      input.value = '50';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      document.getElementById('wallet-topup-form').requestSubmit();
      return true;
    })()`);
    await waitFor(client, "SavoraState.load().wallet.balance === 50 && document.getElementById('wallet-page-balance').textContent === '$50.00'", '$50 immediate wallet render');
    const toppedUp = await evaluate(client, `(() => {
      const state = SavoraState.load();
      return {
        balance: state.wallet.balance,
        transaction: state.wallet.transactions[0],
        activityText: document.getElementById('wallet-transaction-list').textContent,
        dialogClosed: document.getElementById('wallet-topup-dialog').hidden,
        refreshCalls: window.__task6RefreshCalls,
        chromeBalance: window.walletBalance
      };
    })()`);
    assert(toppedUp.transaction.kind === 'credit' && toppedUp.transaction.amount === 50, '$50 Credit transaction missing');
    assert(toppedUp.activityText.includes('Credit') && toppedUp.activityText.includes('+$50.00'), 'Credit semantics did not render immediately');
    assert(toppedUp.dialogClosed, 'valid top-up did not close the dialog');
    assert(toppedUp.refreshCalls === 1 && toppedUp.chromeBalance === 50, 'shared chrome did not refresh exactly once');
    await delay(400);
    await screenshot(client, 'task-6-wallet-50-credit-1440.png');

    await client.send('Page.reload', { ignoreCache: true });
    await waitFor(client, "document.readyState === 'complete' && document.getElementById('wallet-page-balance')?.textContent === '$50.00'", 'wallet reload persistence');
    const walletReloaded = await evaluate(client, "document.getElementById('wallet-transaction-list').textContent.includes('Credit')");
    assert(walletReloaded, 'wallet Credit activity missing after reload');

    assert(exceptions.length === 0, `runtime exceptions: ${JSON.stringify(exceptions)}`);
    console.log(JSON.stringify({
      status: 'PASS',
      profile: { savedProfile, reloadedProfile },
      wallet: { initialWallet, invalidResult, toppedUp, persistedAfterReload: walletReloaded },
      dialog: { initialFocus, tabTrap, escapeEvidence, focusReturned },
      overflow,
      runtimeExceptions: exceptions
    }, null, 2));
  } finally {
    client.close();
  }
}

main().catch(error => {
  console.error(error.stack || error.message);
  process.exitCode = 1;
});
