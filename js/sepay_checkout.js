(function attachSePayCheckout(root, factory) {
  const api = factory(root);
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraSePay = Object.assign(root.SavoraSePay || {}, api);
}(typeof window === 'undefined' ? globalThis : window, function createSePayCheckout(root) {
  'use strict';

  const formatter = new Intl.NumberFormat('vi-VN', {
    style: 'currency',
    currency: 'VND',
    maximumFractionDigits: 0
  });

  function formatVnd(value) {
    const amount = Number(value);
    if (!Number.isFinite(amount) || amount < 0) throw new TypeError('A valid VND amount is required.');
    return formatter.format(Math.round(amount));
  }

  function receiptModel(snapshot) {
    if (!snapshot || snapshot.paymentStatus !== 'paid' || snapshot.paymentMethod !== 'seapay') {
      throw new TypeError('A server-confirmed SePay receipt is required.');
    }
    return {
      referenceCode: String(snapshot.referenceCode || ''),
      amount: formatVnd(snapshot.amountVnd),
      methodLabel: 'SePay',
      paymentLabel: 'Đã thanh toán',
      paidAt: String(snapshot.paidAt || ''),
      orderLabel: snapshot.orderStatus === 'pending' ? 'Chờ nhà hàng xác nhận' : String(snapshot.orderStatus || '')
    };
  }

  function createController(dependencies) {
    const api = dependencies.api;
    const referenceCode = String(dependencies.referenceCode || '').trim();
    const demoMode = dependencies.demoMode === true;
    const setTimer = dependencies.setTimeout || root.setTimeout.bind(root);
    const clearTimer = dependencies.clearTimeout || root.clearTimeout.bind(root);
    const isVisible = dependencies.isVisible || (() => !root.document || root.document.visibilityState === 'visible');
    const onVisibilityChange = dependencies.onVisibilityChange || (listener => {
      if (!root.document) return () => {};
      root.document.addEventListener('visibilitychange', listener);
      return () => root.document.removeEventListener('visibilitychange', listener);
    });
    const renderPending = dependencies.renderPending || (() => {});
    const renderReceipt = dependencies.renderReceipt || (() => {});
    const renderStatus = dependencies.renderStatus || (() => {});
    const setDemoBusy = dependencies.setDemoBusy || (() => {});
    const navigate = dependencies.navigate || (url => root.location.assign(url));
    const statusUrl = `api/payment_status.php?order=${encodeURIComponent(referenceCode)}`;
    const demoScope = `customer-seapay-payment-${referenceCode}`;
    let timerId = null;
    let inFlight = null;
    let requestVersion = 0;
    let stopped = false;
    let destroyed = false;

    function cancelTimer() {
      if (timerId === null) return;
      clearTimer(timerId);
      timerId = null;
    }

    function schedule() {
      cancelTimer();
      if (destroyed || stopped || !isVisible()) return;
      timerId = setTimer(() => {
        timerId = null;
        void refresh();
      }, 3000);
    }

    function applySnapshot(snapshot) {
      if (!snapshot || snapshot.referenceCode !== referenceCode || snapshot.paymentMethod !== 'seapay') {
        throw new TypeError('Unexpected payment status response.');
      }
      if (snapshot.paymentStatus === 'paid') {
        stopped = true;
        cancelTimer();
        renderReceipt(receiptModel(snapshot));
        renderStatus('Thanh toán đã được xác nhận.', false);
        return snapshot;
      }
      if (snapshot.paymentStatus !== 'pending') throw new TypeError('Unsupported payment status.');
      renderPending(snapshot);
      renderStatus('Đang chờ SePay xác nhận giao dịch…', false);
      return snapshot;
    }

    async function refresh(forceFresh = false) {
      if (destroyed || stopped) return null;
      if (inFlight && !forceFresh) return inFlight;
      const requestId = ++requestVersion;
      const request = (async () => {
        try {
          const snapshot = await api.get(statusUrl);
          if (requestId !== requestVersion || destroyed || stopped) return null;
          return applySnapshot(snapshot);
        } catch (error) {
          if (requestId === requestVersion && !destroyed && !stopped) {
            renderStatus(error && error.message ? error.message : 'Không thể kiểm tra thanh toán. Hệ thống sẽ thử lại.', true);
          }
          return null;
        } finally {
          if (inFlight === request) {
            inFlight = null;
            schedule();
          }
        }
      })();
      inFlight = request;
      return request;
    }

    async function start() {
      const initial = dependencies.initialSnapshot;
      if (initial) {
        try { applySnapshot(initial); } catch (error) { renderStatus(error.message, true); }
      }
      if (!stopped) await refresh();
    }

    async function simulatePayment() {
      if (!demoMode || destroyed || stopped) return null;
      setDemoBusy(true);
      renderStatus('Đang xác nhận thanh toán giả lập…', false);
      try {
        const result = await api.post(
          'api/payment_demo.php',
          { action: 'simulate_success', payload: { referenceCode } },
          api.intentKey(demoScope)
        );
        api.clearIntentKey(demoScope);
        await refresh(true);
        return result;
      } catch (error) {
        renderStatus(error && error.message ? error.message : 'Không thể xác nhận thanh toán giả lập.', true);
        return null;
      } finally {
        setDemoBusy(false);
      }
    }

    function acknowledge() {
      navigate(`customer_history.php?order=${encodeURIComponent(referenceCode)}`);
    }

    const removeVisibilityListener = onVisibilityChange(() => {
      if (destroyed || stopped) return;
      if (!isVisible()) {
        cancelTimer();
        return;
      }
      void refresh();
    });

    function destroy() {
      destroyed = true;
      cancelTimer();
      removeVisibilityListener();
    }

    return { start, refresh, simulatePayment, acknowledge, destroy };
  }

  function init(config) {
    const document = root.document;
    if (!document || !config || !config.referenceCode || !root.SavoraApi) return null;
    const pending = document.querySelector('[data-seapay-pending]');
    const receipt = document.querySelector('[data-seapay-receipt]');
    const status = document.querySelector('[data-seapay-status]');
    const demoButton = document.querySelector('[data-demo-seapay-confirm]');
    const receiptButton = document.querySelector('[data-seapay-receipt-ok]');
    const setText = (selector, value) => {
      const node = document.querySelector(selector);
      if (node) node.textContent = value;
    };
    const controller = createController({
      api: root.SavoraApi,
      referenceCode: config.referenceCode,
      demoMode: config.demoMode,
      initialSnapshot: config.initialSnapshot,
      renderPending(snapshot) {
        if (pending) pending.hidden = false;
        if (receipt) receipt.hidden = true;
        setText('[data-seapay-amount]', formatVnd(snapshot.amountVnd));
        setText('[data-seapay-reference]', snapshot.referenceCode);
      },
      renderReceipt(model) {
        if (pending) pending.hidden = true;
        if (receipt) receipt.hidden = false;
        setText('[data-seapay-receipt-reference]', model.referenceCode);
        setText('[data-seapay-receipt-amount]', model.amount);
        setText('[data-seapay-receipt-method]', model.methodLabel);
        setText('[data-seapay-receipt-payment]', model.paymentLabel);
        setText('[data-seapay-receipt-paid-at]', model.paidAt);
        setText('[data-seapay-receipt-order]', model.orderLabel);
      },
      renderStatus(message, isError) {
        if (!status) return;
        status.textContent = message;
        status.classList.toggle('is-error', isError === true);
      },
      setDemoBusy(value) {
        if (!demoButton) return;
        demoButton.disabled = value;
        demoButton.setAttribute('aria-busy', String(value));
      }
    });
    if (demoButton) demoButton.addEventListener('click', () => { void controller.simulatePayment(); });
    if (receiptButton) receiptButton.addEventListener('click', () => controller.acknowledge());
    void controller.start();
    return controller;
  }

  return { formatVnd, receiptModel, createController, init };
}));
