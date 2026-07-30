(function attachDriverHistory(root) {
  'use strict';

  if (!root || !root.document) return;
  const doc = root.document;
  const DriverState = root.SavoraDriverState;
  const ui = root.SavoraDriverUI;
  const page = doc.querySelector('[data-driver-page="history"]');
  if (!page || !DriverState || !ui) return;

  let records = [];
  let selectedId = '';
  const params = new URLSearchParams(root.location.search);

  const statusLabel = status => status === 'delivered' ? 'Completed' : ui.titleCase(status);
  const completionDate = delivery => delivery.deliveredAt || delivery.cancelledAt || delivery.acceptedAt;

  function summary() {
    const all = DriverState.normalize(DriverState.load()).deliveries;
    const completed = all.filter(item => item.status === 'delivered');
    const cancelled = all.filter(item => item.status === 'cancelled' || item.status === 'failed');
    const distance = completed.reduce((sum, item) => sum + item.distanceKm, 0);
    doc.querySelector('[data-history-completed]').textContent = String(completed.length);
    doc.querySelector('[data-history-cancelled]').textContent = String(cancelled.length);
    doc.querySelector('[data-history-distance]').textContent = `${distance.toFixed(1)} km`;
  }

  function filteredRecords() {
    const query = String(doc.querySelector('[data-history-search]').value || '').trim().toLowerCase();
    const after = doc.querySelector('[data-history-date]').value;
    const status = doc.querySelector('[data-history-status]').value;
    return DriverState.deriveHistory(DriverState.load()).filter(delivery => {
      const searchable = `${delivery.orderId} ${delivery.restaurantName} ${delivery.customerName} ${delivery.pickupAddress} ${delivery.dropoffAddress}`.toLowerCase();
      const date = String(completionDate(delivery) || '').slice(0, 10);
      return (!query || searchable.includes(query)) &&
        (!after || date >= after) &&
        (status === 'all' || delivery.status === status);
    });
  }

  function statusChip(delivery) {
    return ui.el('span', { className: `driver-chip ${delivery.status === 'delivered' ? '' : 'is-coral'}` }, [
      ui.icon(delivery.status === 'delivered' ? 'fa-circle-check' : 'fa-circle-exclamation'),
      statusLabel(delivery.status)
    ]);
  }

  function viewButton(delivery) {
    return ui.el('button', {
      type: 'button',
      className: 'driver-table-action',
      dataset: { historySelect: delivery.orderId },
      'aria-label': `View details for ${delivery.orderId}`
    }, 'View details');
  }

  function tableRow(delivery) {
    return ui.el('tr', {}, [
      ui.el('th', { scope: 'row', text: `#${delivery.orderId}` }),
      ui.el('td', { text: ui.formatDate(completionDate(delivery)) }),
      ui.el('td', { text: delivery.restaurantName }),
      ui.el('td', { text: delivery.customerName }),
      ui.el('td', { text: `${delivery.distanceKm.toFixed(1)} km` }),
      ui.el('td', {}, statusChip(delivery)),
      ui.el('td', { text: ui.money(delivery.earnings + delivery.bonus) }),
      ui.el('td', {}, viewButton(delivery))
    ]);
  }

  function historyCard(delivery) {
    return ui.el('article', { className: 'driver-card' }, [
      ui.el('header', {}, [ui.el('h2', { text: `#${delivery.orderId}` }), statusChip(delivery)]),
      ui.el('p', { text: `${delivery.restaurantName} → ${delivery.customerName}` }),
      ui.el('p', { className: 'driver-muted', text: `${ui.formatDate(completionDate(delivery))} · ${delivery.distanceKm.toFixed(1)} km · ${ui.money(delivery.earnings + delivery.bonus)}` }),
      viewButton(delivery)
    ]);
  }

  function render() {
    summary();
    records = filteredRecords();
    const tbody = doc.querySelector('[data-history-results]');
    const cards = doc.querySelector('[data-history-cards]');
    const empty = doc.querySelector('[data-history-empty]');
    doc.querySelector('[data-history-count]').textContent = `${records.length} ${records.length === 1 ? 'delivery' : 'deliveries'}`;
    tbody.replaceChildren(...records.map(tableRow));
    cards.replaceChildren(...records.map(historyCard));
    empty.hidden = records.length > 0;
    if (!selectedId && params.get('delivery')) selectedId = params.get('delivery');
    if (selectedId && records.some(record => record.orderId === selectedId)) openDetails(selectedId, false);
  }

  function detailRow(iconName, label, value) {
    return ui.el('div', { className: 'driver-history-detail-row' }, [
      ui.el('span', {}, ui.icon(iconName)),
      ui.el('div', {}, [ui.el('small', { text: label }), ui.el('strong', { text: value })])
    ]);
  }

  function openDetails(orderId, focus = true) {
    const delivery = DriverState.deliveryForOrder(DriverState.load(), orderId);
    const drawer = doc.querySelector('[data-history-drawer]');
    const detail = doc.querySelector('[data-history-detail]');
    if (!delivery || !drawer || !detail) return;
    selectedId = orderId;
    const timeline = ui.el('ol', { className: 'driver-history-detail-timeline', 'aria-label': 'Delivery timeline' },
      delivery.milestones.map(entry => ui.el('li', {}, [
        ui.icon('fa-circle-check'),
        ui.el('span', {}, [ui.el('strong', { text: ui.titleCase(entry.status) }), ui.el('small', { text: ui.formatDate(entry.createdAt) })])
      ]))
    );
    detail.replaceChildren(
      ui.el('div', { className: 'driver-history-detail-heading' }, [
        ui.el('div', {}, [ui.el('h3', { text: `Order #${delivery.orderId}` }), ui.el('p', { text: ui.formatDate(completionDate(delivery)) })]),
        statusChip(delivery)
      ]),
      timeline,
      detailRow('fa-store', 'Pickup', `${delivery.restaurantName} · ${delivery.pickupAddress}`),
      detailRow('fa-house', 'Drop-off', `${delivery.customerName} · ${delivery.dropoffAddress}`),
      detailRow('fa-money-bill-wave', 'Payment', delivery.paymentMethod === 'cash'
        ? `Cash collected ${ui.money(delivery.orderTotal)}`
        : 'Paid with Savora Pay'),
      detailRow('fa-dollar-sign', 'Your earnings', ui.money(delivery.earnings + delivery.bonus))
    );
    drawer.hidden = false;
    doc.body.classList.add('driver-dialog-open');
    if (focus) drawer.querySelector('[data-history-close]')?.focus();
  }

  function closeDetails() {
    const drawer = doc.querySelector('[data-history-drawer]');
    if (drawer) drawer.hidden = true;
    doc.body.classList.remove('driver-dialog-open');
    selectedId = '';
  }

  function exportCsv() {
    const rows = [
      ['Order', 'Date', 'Restaurant', 'Customer', 'Distance km', 'Status', 'Earnings'],
      ...records.map(delivery => [
        delivery.orderId,
        completionDate(delivery),
        delivery.restaurantName,
        delivery.customerName,
        delivery.distanceKm,
        statusLabel(delivery.status),
        (delivery.earnings + delivery.bonus).toFixed(2)
      ])
    ];
    const escape = value => `"${String(value == null ? '' : value).replace(/"/g, '""')}"`;
    const blob = new Blob([rows.map(row => row.map(escape).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = ui.el('a', { href: url, download: 'savora-driver-history.csv' });
    doc.body.append(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
    ui.showToast('Delivery history exported.');
  }

  doc.querySelector('[data-history-filters]')?.addEventListener('input', render);
  doc.querySelector('[data-history-filters]')?.addEventListener('change', render);
  doc.querySelector('[data-history-export]')?.addEventListener('click', exportCsv);
  page.addEventListener('click', event => {
    const select = event.target.closest('[data-history-select]');
    if (select) openDetails(select.dataset.historySelect);
  });
  doc.querySelectorAll('[data-history-close]').forEach(button => button.addEventListener('click', closeDetails));
  doc.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !doc.querySelector('[data-history-drawer]').hidden) closeDetails();
  });
  root.addEventListener('storage', render);
  render();
}(typeof window === 'undefined' ? null : window));
