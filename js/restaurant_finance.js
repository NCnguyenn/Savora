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
    return Number.isNaN(parsed.valueOf()) ? 'No issued date' : new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' }).format(parsed);
  };
  const ui = () => root.SavoraRestaurantUI;
  const emptyReport = () => ({ filters: {}, kpis: { grossSales: 0, platformFees: 0, refunds: 0, netRevenue: 0, completedOrders: 0, refundedOrders: 0, averageOrderValue: 0 }, transactions: [], payouts: [], documents: [] });
  let serverReport = emptyReport();
  const say = (selector, message) => { const target = doc.querySelector(selector); if (target) target.textContent = message; };
  const finance = () => ({ ...emptyReport().kpis, ...(serverReport.kpis || {}), transactions: Array.isArray(serverReport.transactions) ? serverReport.transactions : [] });
  const transactionLabel = type => type === 'refund' ? 'Refund' : 'Sale';
  const statusChip = status => ui().el('span', { className: `restaurant-status-chip status-${status === 'refunded' ? 'cancelled' : 'completed'}` }, status === 'refunded' ? 'Refunded' : 'Recorded');

  function reportQuery() {
    const from = doc.querySelector('[name="finance-date-range"]')?.value || doc.querySelector('[name="document-date-range"]')?.value || '';
    const to = new Date().toISOString().slice(0, 10);
    return new URLSearchParams({ ...(from ? { from } : {}), to }).toString();
  }

  async function loadServerReport() {
    const report = await root.SavoraApi.get(`api/finance.php?${reportQuery()}`);
    serverReport = report && report.kpis ? report : emptyReport();
  }

  function filteredTransactions() {
    const result = finance().transactions.slice();
    const date = doc.querySelector('[name="finance-date-range"]');
    const search = doc.querySelector('[name="finance-transaction-search"]');
    const type = doc.querySelector('[name="finance-transaction-type"]');
    const query = text(search && search.value).trim().toLowerCase();
    return result.filter(transaction => !date || !date.value || text(transaction.createdAt).slice(0, 10) >= date.value)
      .filter(transaction => !type || type.value === 'all' || transaction.type === type.value)
      .filter(transaction => !query || `${transaction.order} ${transaction.reference} ${transaction.type}`.toLowerCase().includes(query))
      .sort((a, b) => text(b.createdAt).localeCompare(text(a.createdAt)));
  }

  function transactionRow(transaction) {
    return ui().el('tr', {}, [ui().el('td', {}, dateLabel(transaction.createdAt)), ui().el('th', { scope: 'row' }, transaction.order || transaction.reference), ui().el('td', {}, transactionLabel(transaction.type)), ui().el('td', {}, money(transaction.amount)), ui().el('td', {}, money(-transaction.fee)), ui().el('td', {}, money(transaction.net)), ui().el('td', {}, statusChip(transaction.status))]);
  }

  function transactionCard(transaction) {
    return ui().el('article', { className: 'restaurant-card' }, [ui().el('h3', {}, transaction.order || transaction.reference), ui().el('p', {}, `${dateLabel(transaction.createdAt)} · ${transactionLabel(transaction.type)}`), ui().el('p', {}, `Amount ${money(transaction.amount)} · Net ${money(transaction.net)}`), statusChip(transaction.status)]);
  }

  function renderFinance() {
    const page = doc.querySelector('[data-finance-page]');
    if (!page) return;
    const result = finance();
    const values = { grossSales: result.grossSales, netRevenue: result.netRevenue, fees: result.platformFees, refunds: result.refunds };
    Object.entries(values).forEach(([key, value]) => { const target = doc.querySelector(`[data-finance-${key === 'grossSales' ? 'gross-sales' : key === 'netRevenue' ? 'net-revenue' : key}]`); if (target) target.textContent = money(value); });
    const payout = doc.querySelector('[data-next-payout]');
    const nextPayout = (Array.isArray(serverReport.payouts) ? serverReport.payouts : []).find(item => !['paid', 'cancelled'].includes(item.status));
    if (payout) payout.textContent = money(nextPayout ? nextPayout.amount : 0);
    const orderCount = doc.querySelector('[data-payout-order-count]'); if (orderCount) orderCount.textContent = String(result.completedOrders);
    const bars = doc.querySelector('[data-finance-chart-bars]');
    const summary = doc.querySelector('[data-finance-chart-summary]');
    if (bars) { bars.replaceChildren(); const max = Math.max(...result.transactions.map(transaction => Math.abs(Number(transaction.amount) || 0)), 1); result.transactions.slice(-14).forEach(transaction => { const bar = ui().el('div', { className: `restaurant-chart-bar restaurant-chart-bar-${transaction.type}`, title: `${transactionLabel(transaction.type)} ${money(transaction.amount)}` }); bar.style.height = `${Math.max(8, Math.round((Math.abs(Number(transaction.amount) || 0) / max) * 220))}px`; bars.append(bar); }); }
    if (summary) summary.textContent = `${result.completedOrders} recorded sales, ${result.refundedOrders} refunds, and server ledger amounts.`;
    const records = filteredTransactions(); const body = doc.querySelector('[data-finance-transaction-body]'); const cards = doc.querySelector('[data-finance-transaction-cards]');
    if (body) body.replaceChildren(); if (cards) cards.replaceChildren();
    if (!records.length) { if (cards) cards.append(ui().el('p', { className: 'restaurant-empty' }, 'No server transactions match these filters.')); if (body) body.append(ui().el('tr', {}, [ui().el('td', { colspan: 7 }, 'No server transactions match these filters.')])); }
    else records.forEach(transaction => { if (body) body.append(transactionRow(transaction)); if (cards) cards.append(transactionCard(transaction)); });
  }

  function documentsFor(type) {
    const documents = Array.isArray(serverReport.documents) ? serverReport.documents : [];
    if (type === 'statements') return documents.filter(item => item.kind === 'Payout statement');
    if (type === 'tax') return [];
    return documents.filter(item => item.kind !== 'Payout statement');
  }

  const documentState = { tab: 'invoices', selected: '' };
  function activateDocumentTab(tabName, focusTab) { const next = doc.querySelector(`[data-document-tab="${tabName}"]`); if (!next) return; documentState.tab = tabName; documentState.selected = ''; renderDocuments(); if (focusTab) next.focus(); }
  function filteredDocuments() { const date = doc.querySelector('[name="document-date-range"]'); const search = doc.querySelector('[name="document-search"]'); const status = doc.querySelector('[name="document-status"]'); return filterDocumentsForTab(documentsFor(documentState.tab), documentState.tab, { date: date && date.value, search: search && search.value, status: status && status.value }); }
  function documentRow(item) { const preview = ui().el('button', { type: 'button', 'data-document-select': item.id }, 'View'); const print = ui().el('button', { type: 'button', 'data-document-print': item.id }, 'Open printable document'); return ui().el('tr', {}, [ui().el('th', { scope: 'row' }, item.id), ui().el('td', {}, item.order), ui().el('td', {}, item.issued ? dateLabel(item.issued) : '—'), ui().el('td', {}, money(item.amount)), ui().el('td', {}, statusChip(item.status)), ui().el('td', {}, [preview, ' ', print])]); }
  function documentCard(item) { return ui().el('article', { className: 'restaurant-card' }, [ui().el('h3', {}, item.id), ui().el('p', {}, `${item.kind} · ${money(item.amount)}`), ui().el('p', {}, item.order), ui().el('button', { type: 'button', 'data-document-select': item.id }, 'View'), ui().el('button', { type: 'button', 'data-document-print': item.id }, 'Open printable document')]); }
  function renderDocumentPreview(item) { const preview = doc.querySelector('[data-document-preview]'); if (!preview) return; preview.replaceChildren(ui().el('h2', { id: 'document-preview-title' }, item ? item.id : 'Select a document')); if (!item) { preview.append(ui().el('p', { className: 'restaurant-field-hint' }, 'Choose a server-generated invoice or payout statement.')); return; } preview.append(ui().el('p', {}, item.kind), ui().el('p', {}, `Order or period: ${item.order}`), ui().el('p', {}, `Amount: ${money(item.amount)}`), ui().el('p', { className: 'restaurant-field-hint' }, 'This document is generated from the authenticated server ledger.'), ui().el('button', { type: 'button', 'data-document-print': item.id }, 'Open printable document')); }
  function renderDocuments() { const page = doc.querySelector('[data-documents-page]'); if (!page) return; const all = documentsFor('invoices'); const count = doc.querySelector('[data-document-count]'); if (count) count.textContent = String(all.length + documentsFor('statements').length); const total = doc.querySelector('[data-document-total]'); if (total) total.textContent = money(finance().grossSales + finance().refunds); doc.querySelectorAll('[data-document-tab]').forEach(button => { const selected = button.dataset.documentTab === documentState.tab; button.setAttribute('aria-selected', String(selected)); button.setAttribute('tabindex', selected ? '0' : '-1'); }); doc.querySelectorAll('[data-document-panel]').forEach(panel => { panel.hidden = panel.dataset.documentPanel !== documentState.tab; }); const records = filteredDocuments(); if (!documentState.selected || !records.some(item => item.id === documentState.selected)) documentState.selected = records[0] ? records[0].id : ''; const body = doc.querySelector('[data-document-table-body]'); const cards = doc.querySelector('[data-document-cards]'); const statements = doc.querySelector('[data-statement-documents]'); if (body) body.replaceChildren(); if (cards) cards.replaceChildren(); if (statements) statements.replaceChildren(); if (documentState.tab === 'statements' && statements) { if (records.length) records.forEach(item => statements.append(documentCard(item))); else statements.append(ui().el('p', { className: 'restaurant-empty' }, 'No server payout statement is available.')); } else if (!records.length && documentState.tab !== 'tax') { if (cards) cards.append(ui().el('p', { className: 'restaurant-empty' }, 'No server documents match these filters.')); if (body) body.append(ui().el('tr', {}, [ui().el('td', { colspan: 6 }, 'No server documents match these filters.')])); } else if (documentState.tab === 'invoices') records.forEach(item => { if (body) body.append(documentRow(item)); if (cards) cards.append(documentCard(item)); }); renderDocumentPreview(records.find(item => item.id === documentState.selected)); }

  function openPrintableDocument(documentId) { const item = (Array.isArray(serverReport.documents) ? serverReport.documents : []).find(document => document.id === documentId); if (!item || !item.printUrl) { say('[data-document-feedback]', 'Printable document is unavailable.'); return; } root.open(item.printUrl, '_blank', 'noopener,noreferrer'); }
  function bindFinance() { const page = doc.querySelector('[data-finance-page]'); if (!page) return; page.addEventListener('input', event => { if (event.target.closest('[data-transaction-filters]')) renderFinance(); }); page.addEventListener('change', async event => { if (!event.target.closest('[data-transaction-filters]')) return; if (event.target.matches('[name="finance-date-range"]')) { try { await loadServerReport(); } catch (error) { say('[data-finance-feedback]', error.message || 'Server finance data is unavailable.'); } } renderFinance(); }); page.addEventListener('click', event => { if (event.target.closest('[data-request-payout]')) say('[data-finance-feedback]', 'Payout requests are managed through the server operations workflow.'); if (event.target.closest('[data-manage-payout-account]')) say('[data-finance-feedback]', 'Payout account changes require an authenticated server workflow.'); }); renderFinance(); }
  function bindDocuments() { const page = doc.querySelector('[data-documents-page]'); if (!page) return; page.addEventListener('input', event => { if (event.target.closest('[data-document-filters]')) renderDocuments(); }); page.addEventListener('change', async event => { if (!event.target.closest('[data-document-filters]')) return; if (event.target.matches('[name="document-date-range"]')) { try { await loadServerReport(); } catch (error) { say('[data-document-feedback]', error.message || 'Server documents are unavailable.'); } } renderDocuments(); }); page.addEventListener('click', event => { const tab = event.target.closest('[data-document-tab]'); const select = event.target.closest('[data-document-select]'); const print = event.target.closest('[data-document-print]'); if (tab) activateDocumentTab(tab.dataset.documentTab, false); if (select) { documentState.selected = select.dataset.documentSelect; renderDocuments(); } if (print) openPrintableDocument(print.dataset.documentPrint); if (event.target.closest('[data-monthly-statement]')) { documentState.tab = 'statements'; documentState.selected = ''; renderDocuments(); say('[data-document-feedback]', 'The server payout statement is ready to view or print.'); } }); page.addEventListener('keydown', event => { const current = event.target.closest('[data-document-tab]'); if (!current) return; const tabs = [...page.querySelectorAll('[data-document-tab]')]; const index = tabs.indexOf(current); const destination = event.key === 'ArrowRight' ? (index + 1) % tabs.length : event.key === 'ArrowLeft' ? (index - 1 + tabs.length) % tabs.length : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : -1; if (destination < 0) return; event.preventDefault(); const next = tabs[destination]; activateDocumentTab(next.dataset.documentTab, true); }); renderDocuments(); }
  async function initialize() { if (!root.SavoraApi || !ui()) return; try { await loadServerReport(); } catch (error) { say('[data-finance-feedback]', error.message || 'Server finance data is unavailable.'); say('[data-document-feedback]', error.message || 'Server documents are unavailable.'); } bindFinance(); bindDocuments(); }
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', initialize, { once: true }); else initialize();
  return { filterDocumentsForTab };
}));
