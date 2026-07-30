(function attachDriverEarnings(root) {
  'use strict';

  if (!root || !root.document) return;
  const doc = root.document;
  const DriverState = root.SavoraDriverState;
  const ui = root.SavoraDriverUI;
  const page = doc.querySelector('[data-driver-page="earnings"]');
  if (!page || !DriverState || !ui) return;

  const dayMs = 24 * 60 * 60 * 1000;
  const startOfWeek = date => {
    const current = new Date(date);
    current.setHours(0, 0, 0, 0);
    const day = current.getDay() || 7;
    current.setDate(current.getDate() - day + 1);
    return current;
  };
  const isoWeekValue = date => {
    const current = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
    const day = current.getUTCDay() || 7;
    current.setUTCDate(current.getUTCDate() + 4 - day);
    const yearStart = new Date(Date.UTC(current.getUTCFullYear(), 0, 1));
    const week = Math.ceil((((current - yearStart) / dayMs) + 1) / 7);
    return `${current.getUTCFullYear()}-W${String(week).padStart(2, '0')}`;
  };
  const weekStartFromValue = value => {
    const match = /^(\d{4})-W(\d{2})$/.exec(String(value || ''));
    if (!match) return startOfWeek(new Date());
    const simple = new Date(Number(match[1]), 0, 1 + (Number(match[2]) - 1) * 7);
    return startOfWeek(simple);
  };

  function recordsForWeek(summary, weekStart) {
    const end = new Date(weekStart.getTime() + (7 * dayMs));
    return summary.records.filter(delivery => {
      const date = new Date(delivery.deliveredAt);
      return !Number.isNaN(date.getTime()) && date >= weekStart && date < end;
    });
  }

  function renderChart(records, weekStart) {
    const chart = doc.querySelector('[data-earnings-chart]');
    if (!chart) return;
    const days = Array.from({ length: 7 }, (_, index) => {
      const date = new Date(weekStart.getTime() + index * dayMs);
      const entries = records.filter(delivery => String(delivery.deliveredAt).slice(0, 10) === date.toISOString().slice(0, 10));
      return {
        date,
        base: entries.reduce((sum, delivery) => sum + delivery.earnings, 0),
        bonus: entries.reduce((sum, delivery) => sum + delivery.bonus, 0)
      };
    });
    const max = Math.max(1, ...days.map(day => day.base + day.bonus));
    chart.replaceChildren(...days.map(day => {
      const total = day.base + day.bonus;
      return ui.el('div', { className: 'driver-chart-day' }, [
        ui.el('div', {
          className: 'driver-chart-bar',
          role: 'img',
          'aria-label': `${ui.formatDate(day.date, { weekday: 'long' })}: ${ui.money(total)}`
        }, [
          ui.el('span', { className: 'is-base', style: `height:${Math.max(2, (day.base / max) * 100)}%` }),
          ui.el('span', { className: 'is-bonus', style: `height:${(day.bonus / max) * 100}%` })
        ]),
        ui.el('strong', { text: ui.formatDate(day.date, { weekday: 'short' }) }),
        ui.el('small', { text: ui.money(total) })
      ]);
    }));
    chart.setAttribute('aria-label', `Weekly earnings from ${ui.formatDate(weekStart, { month: 'short', day: 'numeric' })}`);
  }

  function renderRows(records) {
    const body = doc.querySelector('[data-earnings-records]');
    const empty = doc.querySelector('[data-earnings-empty]');
    body.replaceChildren(...records.slice().reverse().map(delivery => ui.el('tr', {}, [
      ui.el('th', { scope: 'row', text: `#${delivery.orderId}` }),
      ui.el('td', { text: ui.formatDate(delivery.deliveredAt) }),
      ui.el('td', { text: ui.money(delivery.earnings) }),
      ui.el('td', { text: `${delivery.distanceKm.toFixed(1)} km` }),
      ui.el('td', { text: ui.money(delivery.bonus) }),
      ui.el('td', { text: ui.money(delivery.earnings + delivery.bonus) })
    ])));
    empty.hidden = records.length > 0;
  }

  function render() {
    const summary = DriverState.deriveEarnings(DriverState.load());
    const weekInput = doc.querySelector('[data-earnings-week]');
    if (!weekInput.value) weekInput.value = isoWeekValue(new Date());
    const weekStart = weekStartFromValue(weekInput.value);
    const records = recordsForWeek(summary, weekStart);
    const total = records.reduce((sum, delivery) => sum + delivery.earnings + delivery.bonus, 0);
    const bonuses = records.reduce((sum, delivery) => sum + delivery.bonus, 0);
    doc.querySelector('[data-earnings-total]').textContent = ui.money(total);
    doc.querySelector('[data-earnings-deliveries]').textContent = String(records.length);
    doc.querySelector('[data-earnings-average]').textContent = ui.money(records.length ? total / records.length : 0);
    doc.querySelector('[data-earnings-bonuses]').textContent = ui.money(bonuses);
    doc.querySelector('[data-payout-amount]').textContent = ui.money(total);
    const payoutDate = new Date(weekStart.getTime() + 11 * dayMs);
    doc.querySelector('[data-payout-date]').textContent = `Scheduled for ${ui.formatDate(payoutDate, { month: 'short', day: 'numeric' })}`;
    doc.querySelector('[data-cod-collected]').textContent = ui.money(summary.codCollected);
    doc.querySelector('[data-cod-settle]').textContent = ui.money(summary.amountToSettle);
    doc.querySelector('[data-cod-message]').textContent = summary.amountToSettle
      ? 'Local preview: settle collected cash with Savora.'
      : 'No cash settlement is due.';
    renderChart(records, weekStart);
    renderRows(records);
  }

  doc.querySelector('[data-earnings-week]')?.addEventListener('change', render);
  doc.querySelector('[data-download-statement]')?.addEventListener('click', () => {
    ui.showToast('Opening the local statement print preview.');
    root.print();
  });
  doc.querySelector('[data-view-payout]')?.addEventListener('click', () => {
    ui.showToast('Payout details are a local preview.');
  });
  doc.querySelector('[data-cod-instructions]')?.addEventListener('click', () => {
    ui.showToast('COD settlement instructions are not connected to a payment service in this demo.');
  });
  root.addEventListener('storage', render);
  render();
}(typeof window === 'undefined' ? null : window));
