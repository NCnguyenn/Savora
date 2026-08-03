(function attachDriverHistory(root) {
  'use strict';

  if (!root || !root.document) return;
  const doc = root.document;
  const Api = root.SavoraApi;
  const ui = root.SavoraDriverUI;
  const page = doc.querySelector('[data-driver-page="history"]');
  if (!page || !Api || !ui) return;

  let records = [];
  let selectedId = '';
  const params = new URLSearchParams(root.location.search);
  const text = value => String(value || '');
  const statusLabel = status => status === 'delivered' ? 'Completed' : ui.titleCase(status);
  const completionDate = delivery => delivery.deliveredAt || delivery.acceptedAt || delivery.createdAt;

  const fromServerOrder = order => {
    const assignment = order && order.assignment;
    if (!assignment) return null;
    return {
      orderId: order.id,
      status: assignment.status || order.status,
      restaurantName: order.restaurantName,
      customerName: order.customer && order.customer.fullName || 'Customer',
      pickupAddress: [order.restaurant && order.restaurant.address, order.restaurant && order.restaurant.city].filter(Boolean).join(', '),
      dropoffAddress: order.address,
      distanceKm: 0,
      earnings: Number(assignment.earning || 0),
      bonus: 0,
      paymentMethod: order.paymentMethod,
      orderTotal: Number(order.total || 0),
      acceptedAt: assignment.acceptedAt,
      deliveredAt: assignment.deliveredAt,
      createdAt: order.createdAt,
      milestones: Array.isArray(assignment.milestones) ? assignment.milestones : []
    };
  };

  function summary() {
    const completed = records.filter(item => item.status === 'delivered');
    const cancelled = records.filter(item => ['cancelled', 'failed'].includes(item.status));
    const distance = completed.reduce((sum, item) => sum + item.distanceKm, 0);
    doc.querySelector('[data-history-completed]').textContent = String(completed.length);
    doc.querySelector('[data-history-cancelled]').textContent = String(cancelled.length);
    doc.querySelector('[data-history-distance]').textContent = `${distance.toFixed(1)} km`;
  }

  function filteredRecords() {
    const query = text(doc.querySelector('[data-history-search]').value).trim().toLowerCase();
    const after = doc.querySelector('[data-history-date]').value;
    const status = doc.querySelector('[data-history-status]').value;
    return records.filter(delivery => {
      const searchable = `${delivery.orderId} ${delivery.restaurantName} ${delivery.customerName} ${delivery.pickupAddress} ${delivery.dropoffAddress}`.toLowerCase();
      const date = text(completionDate(delivery)).slice(0, 10);
      return (!query || searchable.includes(query)) && (!after || date >= after) && (status === 'all' || delivery.status === status);
    });
  }

  function statusChip(delivery) {
    return ui.el('span', { className: `driver-chip ${delivery.status === 'delivered' ? '' : 'is-coral'}` }, [
      ui.icon(delivery.status === 'delivered' ? 'fa-circle-check' : 'fa-circle-exclamation'), statusLabel(delivery.status)
    ]);
  }

  function viewButton(delivery) {
    return ui.el('button', { type: 'button', className: 'driver-table-action', dataset: { historySelect: delivery.orderId }, 'aria-label': `View details for ${delivery.orderId}` }, 'View details');
  }

  function tableRow(delivery) {
    return ui.el('tr', {}, [
      ui.el('th', { scope: 'row', text: `#${delivery.orderId}` }), ui.el('td', { text: ui.formatDate(completionDate(delivery)) }),
      ui.el('td', { text: delivery.restaurantName }), ui.el('td', { text: delivery.customerName }), ui.el('td', { text: `${delivery.distanceKm.toFixed(1)} km` }),
      ui.el('td', {}, statusChip(delivery)), ui.el('td', { text: ui.money(delivery.earnings + delivery.bonus) }), ui.el('td', {}, viewButton(delivery))
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
    const visible = filteredRecords();
    const tbody = doc.querySelector('[data-history-results]');
    const cards = doc.querySelector('[data-history-cards]');
    const empty = doc.querySelector('[data-history-empty]');
    doc.querySelector('[data-history-count]').textContent = `${visible.length} ${visible.length === 1 ? 'delivery' : 'deliveries'}`;
    tbody.replaceChildren(...visible.map(tableRow));
    cards.replaceChildren(...visible.map(historyCard));
    empty.hidden = visible.length > 0;
    if (!selectedId && params.get('delivery')) selectedId = params.get('delivery');
    if (selectedId && visible.some(record => record.orderId === selectedId) && doc.querySelector('[data-history-drawer]')?.hidden) openDetails(selectedId);
  }

  function detailRow(iconName, label, value) {
    return ui.el('div', { className: 'driver-history-detail-row' }, [ui.el('span', {}, ui.icon(iconName)), ui.el('div', {}, [ui.el('small', { text: label }), ui.el('strong', { text: value })])]);
  }

  function openDetails(orderId, trigger) {
    const delivery = records.find(item => item.orderId === orderId);
    const drawer = doc.querySelector('[data-history-drawer]');
    const detail = doc.querySelector('[data-history-detail]');
    if (!delivery || !drawer || !detail) return;
    selectedId = orderId;
    const timeline = ui.el(
      'ol',
      { className: 'driver-history-detail-timeline', 'aria-label': 'Delivery timeline' },
      delivery.milestones.map(entry => ui.el('li', {}, [
        ui.icon('fa-circle-check'),
        ui.el('span', {}, [
          ui.el('strong', { text: ui.titleCase(entry.status) }),
          ui.el('small', { text: ui.formatDate(entry.createdAt) })
        ])
      ]))
    );
    detail.replaceChildren(
      ui.el('div', { className: 'driver-history-detail-heading' }, [ui.el('div', {}, [ui.el('h3', { text: `Order #${delivery.orderId}` }), ui.el('p', { text: ui.formatDate(completionDate(delivery)) })]), statusChip(delivery)]),
      timeline,
      detailRow('fa-store', 'Pickup', `${delivery.restaurantName} · ${delivery.pickupAddress}`),
      detailRow('fa-house', 'Drop-off', `${delivery.customerName} · ${delivery.dropoffAddress}`),
      detailRow('fa-money-bill-wave', 'Payment', delivery.paymentMethod === 'cash' ? `Cash collected ${ui.money(delivery.orderTotal)}` : 'Paid with Savora Pay'),
      detailRow('fa-dollar-sign', 'Your earnings', ui.money(delivery.earnings + delivery.bonus))
    );
    ui.openDialog(drawer, trigger);
  }

  function closeDetails() {
    const drawer = doc.querySelector('[data-history-drawer]');
    if (drawer) ui.closeDialog(drawer);
    selectedId = '';
  }

  function exportCsv() {
    const rows = [['Order', 'Date', 'Restaurant', 'Customer', 'Distance km', 'Status', 'Earnings'], ...filteredRecords().map(delivery => [delivery.orderId, completionDate(delivery), delivery.restaurantName, delivery.customerName, delivery.distanceKm, statusLabel(delivery.status), (delivery.earnings + delivery.bonus).toFixed(2)])];
    const escape = value => `"${String(value == null ? '' : value).replace(/"/g, '""')}"`;
    const blob = new Blob([rows.map(row => row.map(escape).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob); const link = ui.el('a', { href: url, download: 'savora-driver-history.csv' });
    doc.body.append(link); link.click(); link.remove(); URL.revokeObjectURL(url); ui.showToast('Server delivery history exported.');
  }

  async function initialize() {
    try {
      const [snapshot] = await Promise.all([Api.get('api/orders.php?pageSize=50'), Api.get('api/dispatch.php')]);
      records = (Array.isArray(snapshot && snapshot.orders) ? snapshot.orders : []).map(fromServerOrder).filter(Boolean).filter(item => ['delivered', 'cancelled', 'failed'].includes(item.status));
    } catch (error) {
      ui.showToast(error.message || 'Server delivery history is unavailable.', 'error');
    }
    render();
  }

  doc.querySelector('[data-history-filters]')?.addEventListener('input', render);
  doc.querySelector('[data-history-filters]')?.addEventListener('change', render);
  doc.querySelector('[data-history-export]')?.addEventListener('click', exportCsv);
  page.addEventListener('click', event => { const select = event.target.closest('[data-history-select]'); if (select) openDetails(select.dataset.historySelect, select); });
  doc.querySelectorAll('[data-history-close]').forEach(button => button.addEventListener('click', closeDetails));
  doc.addEventListener('keydown', event => { if (event.key === 'Escape' && !doc.querySelector('[data-history-drawer]').hidden) closeDetails(); });
  initialize();
}(typeof window === 'undefined' ? null : window));
