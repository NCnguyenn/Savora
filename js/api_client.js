(function attachApiClient(root, factory) {
  const api = factory(root);
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraApi = Object.assign(root.SavoraApi || {}, api);
}(typeof window === 'undefined' ? globalThis : window, function createApiClient(root) {
  'use strict';

  const requestError = (response, payload) => {
    const error = new Error((payload && payload.message) || 'Request failed.');
    error.status = response && Number(response.status || 0);
    error.errors = (payload && payload.errors) || {};
    error.referenceId = (payload && payload.referenceId) || '';
    return error;
  };

  async function csrfToken() {
    if (root.SavoraCsrfToken) return root.SavoraCsrfToken;
    const bridge = root.SavoraPlatformBridge;
    const snapshot = bridge && typeof bridge.getSnapshot === 'function' ? bridge.getSnapshot() : null;
    if (snapshot && snapshot.csrfToken) return snapshot.csrfToken;
    if (bridge && typeof bridge.refresh === 'function') {
      const refreshed = await bridge.refresh();
      if (refreshed && refreshed.csrfToken) return refreshed.csrfToken;
    }
    return '';
  }

  async function decode(response) {
    try { return await response.json(); } catch (_) { return { ok: false, message: 'Invalid server response.' }; }
  }

  async function get(url) {
    let response;
    try { response = await root.fetch(url, { credentials: 'same-origin' }); } catch (error) { throw requestError({ status: 0 }, { message: error.message }); }
    const payload = await decode(response);
    if (!response.ok || !payload.ok) throw requestError(response, payload);
    return payload.data;
  }

  async function post(url, body, intentKey) {
    if (!String(intentKey || '').trim()) throw new Error('A stable intent key is required.');
    let response;
    try {
      response = await root.fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': await csrfToken(),
          'Idempotency-Key': intentKey
        },
        body: JSON.stringify(body || {})
      });
    } catch (error) { throw requestError({ status: 0 }, { message: error.message }); }
    const payload = await decode(response);
    if (!response.ok || !payload.ok) throw requestError(response, payload);
    return payload.data;
  }

  return { get, post };
}));
