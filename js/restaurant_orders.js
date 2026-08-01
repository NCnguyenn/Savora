(function attachRestaurantOrders(root) {
  'use strict';

  if (!root || !root.document) return;
  const doc = root.document;
  const ui = () => root.SavoraRestaurantUI;
  const driverState = () => root.SavoraDriverState;
  const liveStatuses = ['pending', 'confirmed', 'preparing', 'ready_for_pickup', 'on_the_way'];
  const historyStatuses = ['completed', 'cancelled', 'refunded'];
  const labels = { pending: 'New', confirmed: 'Accepted', preparing: 'Preparing', ready_for_pickup: 'Ready for pickup', on_the_way: 'On the way', completed: 'Completed', cancelled: 'Cancelled', refunded: 'Refunded' };
  const HISTORY_PAGE_SIZE = 7;
  const state = { liveFilter: 'all', liveSearch: '', selectedLiveId: '', selectedHistoryId: '', historyPage: 1, historyView: 'order', requestedHistoryOrderId: '' };
  const text = value => typeof value === 'string' ? value : '';
  const ordersForRestaurant = () => {
    const restaurant = root.SavoraRestaurantState.load();
    const customer = root.SavoraState.load();
    return (customer.orders || []).filter(order => order && order.restaurantId === restaurant.profile.id);
  };
  const formatDate = value => {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Date unavailable' : new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }).format(date);
  };
  const fulfillment = order => text(order.address).trim() ? 'Delivery' : 'Pickup';
  const announce = (selector, message) => {
    const node = doc.querySelector(selector);
    if (node) node.textContent = message;
  };
  const button = (label, action, disabled) => ui().el('button', { type: 'button', 'data-order-action': action, disabled: Boolean(disabled) }, label);
  const heading = (tag, value, id) => ui().el(tag, id ? { id } : {}, value);

  function renderLiveCounts(orders) {
    doc.querySelectorAll('[data-order-count]').forEach(node => {
      node.textContent = String(orders.filter(order => order.status === node.dataset.orderCount).length);
    });
    const total = doc.querySelector('[data-live-order-total]');
    if (total) total.textContent = `${orders.length} live order${orders.length === 1 ? '' : 's'}`;
  }

  function renderLiveList() {
    const list = doc.querySelector('[data-live-order-list]');
    if (!list) return;
    const live = ordersForRestaurant().filter(order => liveStatuses.includes(order.status));
    renderLiveCounts(live);
    const query = state.liveSearch.trim().toLowerCase();
    const filtered = live.filter(order => (state.liveFilter === 'all' || order.status === state.liveFilter)
      && (!query || `${order.id} ${order.address} ${(order.items || []).map(item => item.name).join(' ')}`.toLowerCase().includes(query)));
    if (!state.selectedLiveId || !live.some(order => order.id === state.selectedLiveId)) state.selectedLiveId = filtered[0] ? filtered[0].id : '';
    list.replaceChildren();
    if (!filtered.length) {
      list.append(ui().el('p', { className: 'restaurant-empty' }, 'No live orders match this filter.'));
    } else {
      filtered.forEach(order => {
        const selected = order.id === state.selectedLiveId;
        const row = ui().el('button', { type: 'button', className: 'restaurant-card', 'data-order-select': order.id, 'aria-pressed': String(selected) }, [
          ui().statusChip(order.status),
          ui().el('span', {}, [heading('strong', order.id), ui().el('span', { className: 'restaurant-queue-meta' }, `${(order.items || []).length} item${(order.items || []).length === 1 ? '' : 's'} · ${fulfillment(order)}`)]),
          ui().el('span', {}, ui().formatMoney(order.total))
        ]);
        list.append(row);
      });
    }
    doc.querySelectorAll('[data-live-order-filter]').forEach(tab => tab.setAttribute('aria-selected', String(tab.dataset.liveOrderFilter === state.liveFilter)));
    renderLiveDetails(live.find(order => order.id === state.selectedLiveId));
  }

  function renderLiveDetails(order) {
    const panel = doc.querySelector('[data-order-details]');
    if (!panel) return;
    panel.replaceChildren(heading('h2', 'Order details', 'live-order-details-title'));
    if (!order) {
      panel.append(ui().el('p', { className: 'restaurant-empty' }, 'Select a live order to review its items and actions.'));
      return;
    }
    const items = ui().el('ul', { className: 'restaurant-queue-list', 'aria-label': 'Order items' });
    (order.items || []).forEach(item => items.append(ui().el('li', {}, [ui().el('span', {}, `${item.quantity || 1} × ${text(item.name) || 'Menu item'}`), ui().el('strong', {}, ui().formatMoney((Number(item.unitPrice) || 0) * (Number(item.quantity) || 1)))])));
    const prep = ui().el('select', { id: 'prep-minutes', name: 'prep-minutes', 'aria-label': 'Preparation time' }, [10, 15, 20, 25, 30, 40, 50, 60].map(minutes => ui().el('option', { value: minutes, selected: Number(order.prepMinutes || 20) === minutes }, `${minutes} minutes`)));
    const actions = ui().el('div', { className: 'restaurant-actions', 'aria-label': 'Order actions' });
    actions.append(
      button('Reject order', 'reject', !['pending', 'confirmed', 'preparing'].includes(order.status)),
      button('Accept order', 'accept', order.status !== 'pending'),
      button('Ready for pickup', 'ready', order.status !== 'preparing')
    );
    if (order.status === 'confirmed') {
      actions.append(button('Start preparing', 'prepare', false));
    }
    const delivery = driverState()
      ? driverState().deliveryForOrder(driverState().load(), order.id)
      : null;
    const dispatchState = driverState()
      ? driverState().dispatchForOrder(driverState().load(), order.id)
      : null;
    const dispatchCopy = delivery
      ? `${delivery.driverName || 'Assigned driver'} · ${delivery.status.replace(/_/g, ' ')}`
      : dispatchState && dispatchState.status === 'offer_sent'
        ? 'Offer sent to an eligible nearby driver'
      : order.status === 'ready_for_pickup'
        ? 'Searching for an available driver'
        : 'Driver assignment begins when this order is ready for pickup';
    const dispatch = ui().el('section', { className: 'restaurant-dispatch-status' }, [
      heading('h3', 'Driver dispatch'),
      ui().el('p', {}, dispatchCopy),
      delivery ? ui().el('dl', {}, [
        ui().el('div', {}, [ui().el('dt', {}, 'Driver'), ui().el('dd', {}, delivery.driverName || 'Assigned driver')]),
        ui().el('div', {}, [ui().el('dt', {}, 'Vehicle'), ui().el('dd', {}, delivery.vehicle || 'Vehicle details unavailable')]),
        ui().el('div', {}, [ui().el('dt', {}, 'Delivery status'), ui().el('dd', {}, delivery.status.replace(/_/g, ' '))])
      ]) : null
    ].filter(Boolean));
    panel.append(
      ui().el('p', {}, [heading('strong', order.id), ' ', labels[order.status] || 'New']),
      ui().el('p', {}, `Customer delivery address: ${text(order.address) || 'Pickup at restaurant'}`),
      ui().el('p', {}, `Customer note: ${text(order.deliveryNote) || 'No delivery note provided.'}`),
      items,
      ui().el('p', {}, `Total: ${ui().formatMoney(order.total)}`),
      ui().el('label', { className: 'restaurant-field', for: 'prep-minutes' }, ['Preparation time', prep]),
      dispatch,
      actions
    );
  }

  async function updateSelectedOrder(action) {
    const order = ordersForRestaurant().find(item => item.id === state.selectedLiveId);
    if (!order) return;
    const target = { accept: 'confirmed', reject: 'cancelled', prepare: 'preparing', ready: 'ready_for_pickup' }[action];
    if (!target) return;
    const prep = doc.querySelector('[name="prep-minutes"]');
    try {
      const customer = root.SavoraState.load();
      const next = root.SavoraRestaurantState.updateOrderStatus(customer, order.id, target, { prepMinutes: prep ? prep.value : 20 });
      if (!root.SavoraPlatformBridge) throw new Error('The platform connection is not ready.');
      const intentScope = 'restaurant-order-' + order.id + '-' + target;
      await root.SavoraPlatformBridge.command('restaurant_order_status', { reference_code: order.id, status: target }, root.SavoraApi.intentKey(intentScope));
      root.SavoraApi.clearIntentKey(intentScope);
      root.SavoraState.persist(next);
      announce('[data-order-feedback]', `${order.id} is now ${labels[target].toLowerCase()}. Saved to the local customer order.`);
      ui().showToast(`${order.id} updated locally.`);
      renderLiveList();
      renderHistory();
    } catch (error) {
      announce('[data-order-feedback]', text(error && error.message) || 'This order cannot move to that status.');
    }
  }

  function filterHistory(orders) {
    const date = doc.querySelector('[name="history-date"]');
    const search = doc.querySelector('[name="history-search"]');
    const status = doc.querySelector('[name="history-status"]');
    const fulfillmentFilter = doc.querySelector('[name="history-fulfillment"]');
    const query = text(search && search.value).trim().toLowerCase();
    return orders.filter(order => historyStatuses.includes(order.status))
      .filter(order => !date || !date.value || text(order.createdAt).slice(0, 10) >= date.value)
      .filter(order => !status || status.value === 'all' || order.status === status.value)
      .filter(order => !fulfillmentFilter || fulfillmentFilter.value === 'all' || fulfillment(order).toLowerCase() === fulfillmentFilter.value)
      .filter(order => !query || `${order.id} ${order.address} ${(order.items || []).map(item => item.name).join(' ')}`.toLowerCase().includes(query));
  }

  function historyRow(order) {
    const details = ui().el('button', { type: 'button', 'data-history-select': order.id }, 'View details');
    return ui().el('tr', {}, [
      ui().el('th', { scope: 'row' }, order.id), ui().el('td', {}, formatDate(order.createdAt)), ui().el('td', {}, text(order.address) || 'Pickup customer'),
      ui().el('td', {}, fulfillment(order)), ui().el('td', {}, String((order.items || []).length)), ui().el('td', {}, ui().formatMoney(order.total)),
      ui().el('td', {}, ui().statusChip(order.status)), ui().el('td', {}, details)
    ]);
  }

  function historyCard(order) {
    return ui().el('article', { className: 'restaurant-card' }, [
      heading('h3', order.id), ui().statusChip(order.status), ui().el('p', {}, formatDate(order.createdAt)),
      ui().el('p', {}, `${fulfillment(order)} · ${(order.items || []).length} items · ${ui().formatMoney(order.total)}`),
      ui().el('button', { type: 'button', 'data-history-select': order.id }, 'View details')
    ]);
  }

  function renderHistory() {
    const tableBody = doc.querySelector('[data-history-table-body]');
    const cards = doc.querySelector('[data-history-cards]');
    if (!tableBody || !cards) return;
    const all = ordersForRestaurant();
    doc.querySelectorAll('[data-history-count]').forEach(node => node.textContent = String(all.filter(order => order.status === node.dataset.historyCount).length));
    const sales = all.filter(order => order.status === 'completed').reduce((sum, order) => sum + (Number(order.total) || 0), 0);
    const salesNode = doc.querySelector('[data-history-sales]');
    if (salesNode) salesNode.textContent = ui().formatMoney(sales);
    const records = filterHistory(all).sort((a, b) => text(b.createdAt).localeCompare(text(a.createdAt)));
    const requestedIndex = records.findIndex(order => order.id === state.requestedHistoryOrderId);
    if (requestedIndex >= 0) {
      state.selectedHistoryId = records[requestedIndex].id;
      state.historyPage = Math.floor(requestedIndex / HISTORY_PAGE_SIZE) + 1;
    }
    const totalPages = Math.max(1, Math.ceil(records.length / HISTORY_PAGE_SIZE));
    state.historyPage = Math.min(Math.max(1, state.historyPage), totalPages);
    const pageStart = (state.historyPage - 1) * HISTORY_PAGE_SIZE;
    const pageRecords = records.slice(pageStart, pageStart + HISTORY_PAGE_SIZE);
    if (!state.selectedHistoryId || !records.some(order => order.id === state.selectedHistoryId)) state.selectedHistoryId = records[0] ? records[0].id : '';
    tableBody.replaceChildren(); cards.replaceChildren();
    if (!records.length) {
      const empty = ui().el('p', { className: 'restaurant-empty' }, 'No historical orders match these filters.');
      cards.append(empty);
      tableBody.append(ui().el('tr', {}, [ui().el('td', { colspan: 8 }, 'No historical orders match these filters.')]));
    } else pageRecords.forEach(order => { tableBody.append(historyRow(order)); cards.append(historyCard(order)); });
    const count = doc.querySelector('[data-history-result-count]');
    if (count) count.textContent = records.length ? `Showing ${pageStart + 1}–${pageStart + pageRecords.length} of ${records.length} records` : '0 records';
    const previous = doc.querySelector('[data-history-page="previous"]');
    const next = doc.querySelector('[data-history-page="next"]');
    if (previous) previous.disabled = state.historyPage === 1;
    if (next) next.disabled = state.historyPage === totalPages;
    renderHistoryDetails(records.find(order => order.id === state.selectedHistoryId));
    if (state.requestedHistoryOrderId) {
      announce('[data-history-feedback]', requestedIndex >= 0
        ? `${historyViewLabel()} opened for ${state.selectedHistoryId}.`
        : 'The requested local order was not found.');
      state.requestedHistoryOrderId = '';
    }
  }

  function historyViewLabel() {
    return { invoice: 'Local invoice preview', order: 'Order details', reorder: 'Reorder item details' }[state.historyView] || 'Order details';
  }

  function renderHistoryDetails(order) {
    const drawer = doc.querySelector('[data-history-details]');
    const content = doc.querySelector('[data-history-detail-content]');
    const timeline = doc.querySelector('[data-status-timeline]');
    if (!drawer || !content || !timeline) return;
    if (!order) { drawer.hidden = true; return; }
    drawer.hidden = false;
    content.replaceChildren(
      heading('p', historyViewLabel()),
      ui().el('p', {}, `${order.id} · ${formatDate(order.createdAt)}`),
      ui().el('p', {}, `${fulfillment(order)} · ${ui().formatMoney(order.total)}`),
      ui().el('p', { className: 'restaurant-empty' }, state.historyView === 'invoice'
        ? 'Local order summary for review; no server-generated invoice is available.'
        : state.historyView === 'reorder'
          ? 'Review the saved item details before choosing any future reorder action.'
          : 'Local order details for this selected record.')
    );
    const itemList = ui().el('ul', { className: 'restaurant-queue-list', 'aria-label': 'Completed order items' });
    (order.items || []).forEach(item => itemList.append(ui().el('li', {}, `${item.quantity || 1} × ${text(item.name) || 'Menu item'}`)));
    content.append(itemList);
    timeline.replaceChildren();
    const events = Array.isArray(order.statusHistory) && order.statusHistory.length ? order.statusHistory : [{ status: order.status, createdAt: order.createdAt, actor: 'customer' }];
    events.forEach(event => timeline.append(ui().el('li', {}, `${labels[event.status] || event.status} · ${formatDate(event.createdAt)} · ${text(event.actor) || 'system'}`)));
    const id = encodeURIComponent(order.id);
    const invoice = doc.querySelector('[data-history-invoice]');
    const orderDetails = doc.querySelector('[data-history-order]');
    const reorder = doc.querySelector('[data-history-reorder]');
    if (invoice) invoice.href = `restaurant_order_history.php?order=${id}&view=invoice#history-details-title`;
    if (orderDetails) orderDetails.href = `restaurant_order_history.php?order=${id}&view=order#history-details-title`;
    if (reorder) reorder.href = `restaurant_order_history.php?order=${id}&view=reorder#history-details-title`;
  }

  function bindLiveCenter() {
    const page = doc.querySelector('[data-order-center]');
    if (!page) return;
    page.addEventListener('click', event => {
      const filter = event.target.closest('[data-live-order-filter]');
      const select = event.target.closest('[data-order-select]');
      const action = event.target.closest('[data-order-action]');
      if (filter) { state.liveFilter = filter.dataset.liveOrderFilter; renderLiveList(); }
      if (select) { state.selectedLiveId = select.dataset.orderSelect; renderLiveList(); }
      if (action) updateSelectedOrder(action.dataset.orderAction);
    });
    const search = doc.querySelector('[data-live-order-search]');
    if (search) search.addEventListener('input', () => { state.liveSearch = search.value; renderLiveList(); });
    renderLiveList();
  }

  function bindHistory() {
    const page = doc.querySelector('[data-order-history]');
    if (!page) return;
    const historyParams = new URLSearchParams(root.location.search);
    state.requestedHistoryOrderId = text(historyParams.get('order')).trim();
    const requestedView = text(historyParams.get('view'));
    state.historyView = ['invoice', 'order', 'reorder'].includes(requestedView) ? requestedView : 'order';
    page.addEventListener('input', event => { if (event.target.closest('[data-history-filters]')) { state.historyPage = 1; renderHistory(); } });
    page.addEventListener('change', event => { if (event.target.closest('[data-history-filters]')) { state.historyPage = 1; renderHistory(); } });
    page.addEventListener('click', event => {
      const select = event.target.closest('[data-history-select]');
      const pageControl = event.target.closest('[data-history-page]');
      if (select) { state.selectedHistoryId = select.dataset.historySelect; state.historyView = 'order'; renderHistory(); announce('[data-history-feedback]', `Showing details for ${state.selectedHistoryId}.`); }
      if (pageControl) { state.historyPage += pageControl.dataset.historyPage === 'next' ? 1 : -1; renderHistory(); }
      if (event.target.closest('[data-close-history-details]')) { const drawer = doc.querySelector('[data-history-details]'); if (drawer) drawer.hidden = true; }
    });
    renderHistory();
  }

  function initialize() {
    if (!root.SavoraRestaurantState || !root.SavoraState || !ui()) return;
    bindLiveCenter();
    bindHistory();
    root.addEventListener('storage', () => {
      renderLiveList();
      renderHistory();
    });
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', initialize, { once: true });
  else initialize();
}(typeof window === 'undefined' ? null : window));
