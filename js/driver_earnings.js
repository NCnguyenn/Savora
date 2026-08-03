(function attachDriverEarnings(root) {
  'use strict';

  if (!root || !root.document) return;
  const doc = root.document;
  const Api = root.SavoraApi;
  const ui = root.SavoraDriverUI;
  const page = doc.querySelector('[data-driver-page="earnings"]');
  if (!page || !Api || !ui) return;

  const dayMs = 24 * 60 * 60 * 1000;
  let records = [];
  const startOfWeek = date => { const current = new Date(date); current.setHours(0, 0, 0, 0); const day = current.getDay() || 7; current.setDate(current.getDate() - day + 1); return current; };
  const isoWeekValue = date => { const current = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate())); const day = current.getUTCDay() || 7; current.setUTCDate(current.getUTCDate() + 4 - day); const yearStart = new Date(Date.UTC(current.getUTCFullYear(), 0, 1)); const week = Math.ceil((((current - yearStart) / dayMs) + 1) / 7); return `${current.getUTCFullYear()}-W${String(week).padStart(2, '0')}`; };
  const weekStartFromValue = value => { const match = /^(\d{4})-W(\d{2})$/.exec(String(value || '')); if (!match) return startOfWeek(new Date()); return startOfWeek(new Date(Number(match[1]), 0, 1 + (Number(match[2]) - 1) * 7)); };
  const fromServerOrder = order => {
    const assignment = order && order.assignment;
    if (!assignment || assignment.status !== 'delivered') return null;
    return { orderId: order.id, deliveredAt: assignment.deliveredAt, earnings: Number(assignment.earning || 0), bonus: 0, distanceKm: 0, paymentMethod: order.paymentMethod, orderTotal: Number(order.total || 0) };
  };
  const recordsForWeek = weekStart => { const end = new Date(weekStart.getTime() + (7 * dayMs)); return records.filter(delivery => { const date = new Date(delivery.deliveredAt); return !Number.isNaN(date.getTime()) && date >= weekStart && date < end; }); };

  function renderChart(currentRecords, weekStart) {
    const chart = doc.querySelector('[data-earnings-chart]');
    if (!chart) return;
    const days = Array.from({ length: 7 }, (_, index) => { const date = new Date(weekStart.getTime() + index * dayMs); const entries = currentRecords.filter(delivery => String(delivery.deliveredAt).slice(0, 10) === date.toISOString().slice(0, 10)); return { date, base: entries.reduce((sum, delivery) => sum + delivery.earnings, 0), bonus: entries.reduce((sum, delivery) => sum + delivery.bonus, 0) }; });
    const max = Math.max(1, ...days.map(day => day.base + day.bonus));
    chart.replaceChildren(...days.map(day => { const total = day.base + day.bonus; return ui.el('div', { className: 'driver-chart-day' }, [ui.el('div', { className: 'driver-chart-bar', role: 'img', 'aria-label': `${ui.formatDate(day.date, { weekday: 'long' })}: ${ui.money(total)}` }, [ui.el('span', { className: 'is-base', style: `height:${Math.max(2, (day.base / max) * 100)}%` }), ui.el('span', { className: 'is-bonus', style: `height:${(day.bonus / max) * 100}%` })]), ui.el('strong', { text: ui.formatDate(day.date, { weekday: 'short' }) }), ui.el('small', { text: ui.money(total) })]); }));
    chart.setAttribute('aria-label', `Weekly server earnings from ${ui.formatDate(weekStart, { month: 'short', day: 'numeric' })}`);
  }

  function renderRows(currentRecords) {
    const body = doc.querySelector('[data-earnings-records]');
    const empty = doc.querySelector('[data-earnings-empty]');
    body.replaceChildren(...currentRecords.slice().reverse().map(delivery => ui.el('tr', {}, [ui.el('th', { scope: 'row', text: `#${delivery.orderId}` }), ui.el('td', { text: ui.formatDate(delivery.deliveredAt) }), ui.el('td', { text: ui.money(delivery.earnings) }), ui.el('td', { text: `${delivery.distanceKm.toFixed(1)} km` }), ui.el('td', { text: ui.money(delivery.bonus) }), ui.el('td', { text: ui.money(delivery.earnings + delivery.bonus) })])));
    empty.hidden = currentRecords.length > 0;
  }

  function render() {
    const weekInput = doc.querySelector('[data-earnings-week]');
    if (!weekInput.value) weekInput.value = isoWeekValue(new Date());
    const weekStart = weekStartFromValue(weekInput.value);
    const currentRecords = recordsForWeek(weekStart);
    const total = currentRecords.reduce((sum, delivery) => sum + delivery.earnings + delivery.bonus, 0);
    const bonuses = currentRecords.reduce((sum, delivery) => sum + delivery.bonus, 0);
    const allTotal = records.reduce((sum, delivery) => sum + delivery.earnings + delivery.bonus, 0);
    doc.querySelector('[data-earnings-total]').textContent = ui.money(total);
    doc.querySelector('[data-earnings-deliveries]').textContent = String(currentRecords.length);
    doc.querySelector('[data-earnings-average]').textContent = ui.money(currentRecords.length ? total / currentRecords.length : 0);
    doc.querySelector('[data-earnings-bonuses]').textContent = ui.money(bonuses);
    doc.querySelector('[data-payout-amount]').textContent = ui.money(total);
    doc.querySelector('[data-payout-date]').textContent = 'Payout schedule is managed by the server.';
    doc.querySelector('[data-cod-collected]').textContent = ui.money(records.filter(item => item.paymentMethod === 'cash').reduce((sum, item) => sum + item.orderTotal, 0));
    doc.querySelector('[data-cod-settle]').textContent = ui.money(allTotal);
    doc.querySelector('[data-cod-message]').textContent = 'Cash settlement status is read from server records.';
    renderChart(currentRecords, weekStart); renderRows(currentRecords);
  }

  async function initialize() {
    try { const [snapshot] = await Promise.all([Api.get('api/orders.php?status=delivered&pageSize=50'), Api.get('api/dispatch.php')]); records = (Array.isArray(snapshot && snapshot.orders) ? snapshot.orders : []).map(fromServerOrder).filter(Boolean); }
    catch (error) { ui.showToast(error.message || 'Server earnings are unavailable.', 'error'); }
    render();
  }
  doc.querySelector('[data-earnings-week]')?.addEventListener('change', render);
  doc.querySelector('[data-download-statement]')?.addEventListener('click', () => { ui.showToast('Opening the server earnings print preview.'); root.print(); });
  doc.querySelector('[data-view-payout]')?.addEventListener('click', () => ui.showToast('Payout details are read-only until the server payout service is connected.'));
  doc.querySelector('[data-cod-instructions]')?.addEventListener('click', () => ui.showToast('COD settlement instructions are not available until the server payout service is connected.'));
  initialize();
}(typeof window === 'undefined' ? null : window));
