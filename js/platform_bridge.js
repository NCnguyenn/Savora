(function (root) {
  'use strict';

  let csrfToken = '';
  let snapshot = null;

  function intentKey(scope) {
    const storageKey = 'savora_intent_' + String(scope || 'command');
    const existing = root.sessionStorage.getItem(storageKey);
    if (existing) return existing;
    const key = 'role-' + root.crypto.randomUUID();
    root.sessionStorage.setItem(storageKey, key);
    return key;
  }

  function clearIntentKey(scope) {
    root.sessionStorage.removeItem('savora_intent_' + String(scope || 'command'));
  }

  async function refresh() {
    const response = await fetch('api/platform_state.php', { credentials: 'same-origin' });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to load platform data.');
    csrfToken = data.csrfToken || csrfToken;
    snapshot = data;
    root.dispatchEvent(new CustomEvent('savora:platform-state', { detail: data }));
    return data;
  }

  async function command(name,payload,idempotencyKey) {
    if (!idempotencyKey) throw new Error('A stable idempotency key is required.');
    if (!csrfToken) await refresh();
    const response = await fetch('api/platform_state.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken,
        'Idempotency-Key':idempotencyKey
      },
      body: JSON.stringify({ command: name, payload: payload || {} })
    });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to synchronize platform data.');
    await refresh();
    return data;
  }

  root.SavoraApi = Object.assign(root.SavoraApi || {}, { intentKey, clearIntentKey });
  root.SavoraPlatformBridge = { refresh, command, getSnapshot: function () { return snapshot; } };
  const initialize = function () {
    refresh().catch(function () { root.dispatchEvent(new CustomEvent('savora:platform-offline')); });
  };
  if (root.document.readyState === 'loading') root.document.addEventListener('DOMContentLoaded', initialize);
  else initialize();
}(window));
