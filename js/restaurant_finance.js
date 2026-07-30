(function attachRestaurantFinance(root, factory) {
  const api = factory(root);
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraRestaurantFinance = api;
}(typeof window === 'undefined' ? null : window, function createRestaurantFinance(root) {
  'use strict';
  const text = value => typeof value === 'string' ? value.slice(0, 500) : '';

  function filterDocumentsForTab(documents, tab, filters = {}) {
    const records = Array.isArray(documents) ? documents : [];
    if (tab !== 'invoices') return records.slice();
    const query = text(filters.search).trim().toLowerCase();
    return records.filter(item => !filters.date || !item.issued || text(item.issued).slice(0, 10) >= filters.date)
      .filter(item => !filters.status || filters.status === 'all' || item.status === filters.status)
      .filter(item => !query || `${item.id} ${item.order} ${item.kind}`.toLowerCase().includes(query));
  }

  if (!root || !root.document) return { filterDocumentsForTab };

  const doc = root.document;
  const money = value => root.SavoraRestaurantUI.formatMoney(Number(value) || 0);
  const dateLabel = value => {
    const parsed = new Date(value);
    return Number.isNaN(parsed.valueOf()) ? 'No saved date' : new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' }).format(parsed);
  };
  const ui = () => root.SavoraRestaurantUI;
  const finance = () => root.SavoraRestaurantState.deriveFinance(root.SavoraState.load());
  const say = (selector, message) => {
    const target = doc.querySelector(selector);
    if (target) target.textContent = message;
  };
  const transactionLabel = type => type === 'refund' ? 'Refund' : 'Sale';
  const statusChip = status => ui().el('span', { className: `restaurant-status-chip status-${status === 'refunded' ? 'cancelled' : 'completed'}` }, status === 'refunded' ? 'Refunded' : 'Available');

  function filteredTransactions() {
    const result = finance().transactions.slice();
    const date = doc.querySelector('[name="finance-date-range"]');
    const search = doc.querySelector('[name="finance-transaction-search"]');
    const type = doc.querySelector('[name="finance-transaction-type"]');
    const query = text(search && search.value).trim().toLowerCase();
    return result.filter(transaction => !date || !date.value || text(transaction.createdAt).slice(0, 10) >= date.value)
      .filter(transaction => !type || type.value === 'all' || transaction.type === type.value)
      .filter(transaction => !query || `${transaction.orderId} ${transaction.type}`.toLowerCase().includes(query))
      .sort((a, b) => text(b.createdAt).localeCompare(text(a.createdAt)));
  }

  function transactionRow(transaction) {
    return ui().el('tr', {}, [
      ui().el('td', {}, dateLabel(transaction.createdAt)), ui().el('th', { scope: 'row' }, transaction.orderId || 'Local order'),
      ui().el('td', {}, transactionLabel(transaction.type)), ui().el('td', {}, money(transaction.amount)),
      ui().el('td', {}, money(transaction.type === 'refund' ? 0 : -transaction.fee)), ui().el('td', {}, money(transaction.net)),
      ui().el('td', {}, statusChip(transaction.status))
    ]);
  }

  function transactionCard(transaction) {
    return ui().el('article', { className: 'restaurant-card' }, [
      ui().el('h3', {}, transaction.orderId || 'Local order'), ui().el('p', {}, `${dateLabel(transaction.createdAt)} · ${transactionLabel(transaction.type)}`),
      ui().el('p', {}, `Amount ${money(transaction.amount)} · Net ${money(transaction.net)}`), statusChip(transaction.status)
    ]);
  }

  function renderFinance() {
    const page = doc.querySelector('[data-finance-page]');
    if (!page) return;
    const result = finance();
    const values = { grossSales: result.grossSales, netRevenue: result.netRevenue, fees: result.platformFees, refunds: result.refundTotal };
    Object.entries(values).forEach(([key, value]) => {
      const target = doc.querySelector(`[data-finance-${key === 'grossSales' ? 'gross-sales' : key === 'netRevenue' ? 'net-revenue' : key}]`);
      if (target) target.textContent = money(value);
    });
    const payout = doc.querySelector('[data-next-payout]');
    if (payout) payout.textContent = money(result.netRevenue);
    const orderCount = doc.querySelector('[data-payout-order-count]');
    if (orderCount) orderCount.textContent = String(result.completedOrders);
    const bars = doc.querySelector('[data-finance-chart-bars]');
    const summary = doc.querySelector('[data-finance-chart-summary]');
    if (bars) {
      bars.replaceChildren();
      const max = Math.max(...result.transactions.map(transaction => Math.abs(transaction.amount)), 1);
      result.transactions.slice(-14).forEach(transaction => {
        const bar = ui().el('div', { className: `restaurant-chart-bar restaurant-chart-bar-${transaction.type}`, title: `${transactionLabel(transaction.type)} ${money(transaction.amount)}` });
        bar.style.height = `${Math.max(8, Math.round((Math.abs(transaction.amount) / max) * 220))}px`;
        bars.append(bar);
      });
    }
    if (summary) summary.textContent = `${result.completedOrders} completed orders, ${result.refundedOrders} refunded orders, and a local 10% fee estimate.`;
    const records = filteredTransactions();
    const body = doc.querySelector('[data-finance-transaction-body]');
    const cards = doc.querySelector('[data-finance-transaction-cards]');
    if (body) body.replaceChildren();
    if (cards) cards.replaceChildren();
    if (!records.length) {
      const empty = ui().el('p', { className: 'restaurant-empty' }, 'No local transactions match these filters.');
      if (cards) cards.append(empty);
      if (body) body.append(ui().el('tr', {}, [ui().el('td', { colspan: 7 }, 'No local transactions match these filters.')]));
    } else records.forEach(transaction => { if (body) body.append(transactionRow(transaction)); if (cards) cards.append(transactionCard(transaction)); });
  }

  function documentsFor(type) {
    const result = finance();
    if (type === 'statements') return [{ id: 'STMT-LOCAL', kind: 'Payout statement preview', order: 'All local completed orders', issued: '', amount: result.netRevenue, status: 'available' }];
    if (type === 'tax') return [];
    return result.transactions.map(transaction => ({
      id: `${transaction.type === 'refund' ? 'CRN' : 'INV'}-${transaction.orderId || 'LOCAL'}`,
      kind: transaction.type === 'refund' ? 'Refund credit note' : 'Order invoice', order: transaction.orderId,
      issued: transaction.createdAt, amount: transaction.amount, status: 'available', transaction
    }));
  }

  const documentState = { tab: 'invoices', selected: '' };

  function activateDocumentTab(tabName, focusTab) {
    const next = doc.querySelector(`[data-document-tab="${tabName}"]`);
    if (!next) return;
    documentState.tab = tabName;
    documentState.selected = '';
    renderDocuments();
    if (focusTab) next.focus();
  }

  function filteredDocuments() {
    const date = doc.querySelector('[name="document-date-range"]');
    const search = doc.querySelector('[name="document-search"]');
    const status = doc.querySelector('[name="document-status"]');
    return filterDocumentsForTab(documentsFor(documentState.tab), documentState.tab, {
      date: date && date.value, search: search && search.value, status: status && status.value
    });
  }

  function documentRow(item) {
    const preview = ui().el('button', { type: 'button', 'data-document-select': item.id }, 'Preview');
    const download = ui().el('button', { type: 'button', 'data-document-download': item.id }, 'Demo download');
    return ui().el('tr', {}, [ui().el('th', { scope: 'row' }, item.id), ui().el('td', {}, item.order), ui().el('td', {}, item.issued ? dateLabel(item.issued) : 'Current local view'), ui().el('td', {}, money(item.amount)), ui().el('td', {}, statusChip(item.status)), ui().el('td', {}, [preview, ' ', download])]);
  }

  function documentCard(item) {
    return ui().el('article', { className: 'restaurant-card' }, [ui().el('h3', {}, item.id), ui().el('p', {}, `${item.kind} · ${money(item.amount)}`), ui().el('p', {}, item.order), ui().el('button', { type: 'button', 'data-document-select': item.id }, 'Preview'), ui().el('button', { type: 'button', 'data-document-download': item.id }, 'Demo download')]);
  }

  function renderDocumentPreview(item) {
    const preview = doc.querySelector('[data-document-preview]');
    if (!preview) return;
    preview.replaceChildren(ui().el('h2', { id: 'document-preview-title' }, item ? item.id : 'Select a document'));
    if (!item) { preview.append(ui().el('p', { className: 'restaurant-field-hint' }, 'Choose an order invoice or local payout-preview statement to inspect it.')); return; }
    preview.append(ui().el('p', {}, item.kind), ui().el('p', {}, `Order or period: ${item.order}`), ui().el('p', {}, `Amount: ${money(item.amount)}`), ui().el('p', { className: 'restaurant-field-hint' }, 'This is a local demo preview, not a server-generated accounting document.'));
    const print = ui().el('button', { type: 'button', 'data-document-print': item.id }, 'Print local preview');
    const download = ui().el('button', { type: 'button', 'data-document-download': item.id }, 'Demo download');
    preview.append(print, download);
  }

  function renderDocuments() {
    const page = doc.querySelector('[data-documents-page]');
    if (!page) return;
    const result = finance();
    const all = documentsFor('invoices');
    const count = doc.querySelector('[data-document-count]'); if (count) count.textContent = String(all.length + 1);
    const total = doc.querySelector('[data-document-total]'); if (total) total.textContent = money(result.grossSales + result.refundTotal);
    doc.querySelectorAll('[data-document-tab]').forEach(button => {
      const selected = button.dataset.documentTab === documentState.tab;
      button.setAttribute('aria-selected', String(selected));
      button.setAttribute('tabindex', selected ? '0' : '-1');
    });
    doc.querySelectorAll('[data-document-panel]').forEach(panel => { panel.hidden = panel.dataset.documentPanel !== documentState.tab; });
    const records = filteredDocuments();
    if (!documentState.selected || !records.some(item => item.id === documentState.selected)) documentState.selected = records[0] ? records[0].id : '';
    const body = doc.querySelector('[data-document-table-body]'); const cards = doc.querySelector('[data-document-cards]');
    const statementDocuments = doc.querySelector('[data-statement-documents]');
    if (body) body.replaceChildren(); if (cards) cards.replaceChildren(); if (statementDocuments) statementDocuments.replaceChildren();
    if (documentState.tab === 'statements' && statementDocuments) {
      if (records.length) records.forEach(item => statementDocuments.append(documentCard(item)));
      else statementDocuments.append(ui().el('p', { className: 'restaurant-empty' }, 'No local payout statement is available yet.'));
    } else if (!records.length && documentState.tab !== 'tax') {
      const empty = ui().el('p', { className: 'restaurant-empty' }, 'No local documents match these filters.');
      if (cards) cards.append(empty); if (body) body.append(ui().el('tr', {}, [ui().el('td', { colspan: 6 }, 'No local documents match these filters.')]));
    } else if (documentState.tab === 'invoices') records.forEach(item => { if (body) body.append(documentRow(item)); if (cards) cards.append(documentCard(item)); });
    renderDocumentPreview(records.find(item => item.id === documentState.selected));
  }

  function bindFinance() {
    const page = doc.querySelector('[data-finance-page]');
    if (!page) return;
    page.addEventListener('input', event => { if (event.target.closest('[data-transaction-filters]')) renderFinance(); });
    page.addEventListener('change', event => { if (event.target.closest('[data-transaction-filters]')) renderFinance(); });
    page.addEventListener('click', event => {
      if (event.target.closest('[data-request-payout]')) say('[data-finance-feedback]', 'Local payout preview requested. No money will move and no bank account is connected.');
      if (event.target.closest('[data-manage-payout-account]')) say('[data-finance-feedback]', 'Payout accounts cannot be changed in this local demo.');
    });
    renderFinance();
  }

  function bindDocuments() {
    const page = doc.querySelector('[data-documents-page]');
    if (!page) return;
    page.addEventListener('input', event => { if (event.target.closest('[data-document-filters]')) renderDocuments(); });
    page.addEventListener('change', event => { if (event.target.closest('[data-document-filters]')) renderDocuments(); });
    page.addEventListener('click', event => {
      const tab = event.target.closest('[data-document-tab]'); const select = event.target.closest('[data-document-select]');
      const download = event.target.closest('[data-document-download]'); const print = event.target.closest('[data-document-print]');
      if (tab) activateDocumentTab(tab.dataset.documentTab, false);
      if (select) { documentState.selected = select.dataset.documentSelect; renderDocuments(); }
      if (download) say('[data-document-feedback]', 'Demo download requested. This is not a server-generated accounting document.');
      if (print) window.print();
      if (event.target.closest('[data-monthly-statement]')) say('[data-document-feedback]', 'Monthly statement preview selected. This local demo does not create an accounting document.');
    });
    page.addEventListener('keydown', event => {
      const current = event.target.closest('[data-document-tab]');
      if (!current) return;
      const tabs = [...page.querySelectorAll('[data-document-tab]')];
      const index = tabs.indexOf(current);
      const destination = event.key === 'ArrowRight' ? (index + 1) % tabs.length
        : event.key === 'ArrowLeft' ? (index - 1 + tabs.length) % tabs.length
          : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : -1;
      if (destination < 0) return;
      event.preventDefault();
      const next = tabs[destination];
      activateDocumentTab(next.dataset.documentTab, true);
    });
    renderDocuments();
  }

  function initialize() {
    if (!root.SavoraRestaurantState || !root.SavoraState || !ui()) return;
    bindFinance(); bindDocuments();
  }
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', initialize, { once: true }); else initialize();
  return { filterDocumentsForTab };
}));
