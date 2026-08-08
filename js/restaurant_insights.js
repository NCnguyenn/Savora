(function attachRestaurantInsights(root, factory) {
  const api = factory(root);
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraRestaurantInsights = api;
}(typeof window === 'undefined' ? null : window, function createRestaurantInsights(root) {
  'use strict';
  const text = value => typeof value === 'string' ? value.slice(0, 500) : '';
  const reviewText = value => text(value).slice(0, 300);
  const money = value => `$${(Number(value) || 0).toFixed(2)}`;
  const average = values => values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : 0;
  const countFulfilledOrders = statusCounts => {
    if (!statusCounts || typeof statusCounts !== 'object') return 0;
    return ['delivered', 'completed'].reduce((total, status) => total + Math.max(0, Number(statusCounts[status]) || 0), 0);
  };
  const dateLabel = value => {
    const date = new Date(value);
    return Number.isNaN(date.valueOf()) ? 'No saved date' : new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' }).format(date);
  };

  function verifiedReviews(records) {
    return (Array.isArray(records) ? records : []).map(review => {
      const rating = Math.min(5, Math.max(1, Number(review && review.rating) || 0));
      return {
        id: text(review && review.publicId), orderId: text(review && review.orderReference), customer: text(review && review.customerName) || 'Verified customer', rating,
        comment: text(review && review.comment), createdAt: text(review && review.createdAt), items: Array.isArray(review && review.items) ? review.items : [], topics: [],
        food: rating, packaging: rating, preparation: rating, reply: reviewText(review && review.replyText),
        replyStatus: ['draft', 'published'].includes(review && review.replyStatus) ? review.replyStatus : '', repliedAt: text(review && review.repliedAt),
        version: Number(review && review.version || 0)
      };
    }).sort((a, b) => b.createdAt.localeCompare(a.createdAt));
  }

  function filterReviews(reviews, filters = {}) {
    const rating = text(filters.rating || 'all');
    const status = text(filters.status || 'all');
    const query = text(filters.query).trim().toLowerCase();
    return (Array.isArray(reviews) ? reviews : []).filter(review => rating === 'all' || (rating === '3' ? review.rating <= 3 : review.rating === Number(rating)))
      .filter(review => status === 'all' || (status === 'replied' ? review.replyStatus === 'published' : review.replyStatus !== 'published'))
      .filter(review => !query || `${review.customer} ${review.comment} ${review.orderId} ${review.topics.join(' ')}`.toLowerCase().includes(query));
  }

  function ordersInDateRange(orders, days) {
    const validDateKey = value => {
      const key = text(value).slice(0, 10);
      if (!/^\d{4}-\d{2}-\d{2}$/.test(key)) return '';
      const date = new Date(`${key}T00:00:00.000Z`);
      return Number.isFinite(date.valueOf()) && date.toISOString().slice(0, 10) === key ? key : '';
    };
    const dated = (Array.isArray(orders) ? orders : []).map(order => ({ order, key: validDateKey(order && order.createdAt) })).filter(entry => entry.key);
    if (!dated.length) return [];
    const latestKey = dated.map(entry => entry.key).sort().at(-1);
    const cutoff = new Date(`${latestKey}T00:00:00.000Z`);
    cutoff.setUTCDate(cutoff.getUTCDate() - (Math.max(1, Number(days) || 1) - 1));
    const cutoffKey = cutoff.toISOString().slice(0, 10);
    return dated.filter(entry => entry.key >= cutoffKey && entry.key <= latestKey).map(entry => entry.order);
  }

  if (!root || !root.document) return { verifiedReviews, filterReviews, ordersInDateRange, countFulfilledOrders };

  const doc = root.document;
  const ui = () => root.SavoraRestaurantUI;
  let serverOrders = [];
  let serverAnalytics = null;
  const element = (tag, attrs, children) => ui().el(tag, attrs || {}, children || []);
  const say = (selector, value) => { const node = doc.querySelector(selector); if (node) node.textContent = value; };
  const selectedRange = () => Number(doc.querySelector('[data-analytics-range]')?.value || 30);
  const buildAnalytics = () => {
    if (serverAnalytics && serverAnalytics.kpis) {
      const kpis = serverAnalytics.kpis;
      const statusCounts = Object.fromEntries((Array.isArray(serverAnalytics.status) ? serverAnalytics.status : []).map(row => [text(row.status), Number(row.total) || 0]));
      const completedOrders = countFulfilledOrders(statusCounts);
      const orders = Number(kpis.orders) || 0;
      return {
        totalOrders: orders,
        completedOrders,
        grossSales: Number(kpis.gmv) || 0,
        netRevenue: Number(kpis.netRevenue) || 0,
        averageOrderValue: orders ? (Number(kpis.gmv) || 0) / orders : 0,
        repeatCustomers: Number(serverAnalytics.repeatCustomers) || 0,
        salesByDay: (Array.isArray(serverAnalytics.trend) ? serverAnalytics.trend : []).map(row => ({ key: text(row.day), orders: Number(row.orders) || 0, revenue: Number(row.gmv) || 0 })),
        statusCounts,
        orderingTimes: Object.fromEntries(Array.from({ length: 24 }, (_, hour) => [String(hour), 0])),
        menuItems: [],
        kitchen: { averagePrepMinutes: Number(serverAnalytics.durationMinutes) || 0 }
      };
    }
    const orders = ordersInDateRange(serverOrders, selectedRange());
    const completed = orders.filter(order => order && ['delivered', 'completed'].includes(order.status));
    const refunded = orders.filter(order => order && order.status === 'refunded');
    const grossSales = completed.reduce((sum, order) => sum + Math.max(0, Number(order.total) || 0), 0);
    const refundTotal = refunded.reduce((sum, order) => sum - Math.max(0, Number(order.total) || 0), 0);
    const customers = new Map();
    const orderingTimes = Object.fromEntries(Array.from({ length: 24 }, (_, hour) => [String(hour), 0]));
    const sales = new Map();
    const menu = new Map();
    const prepTimes = [];
    orders.forEach(order => {
      const createdAt = text(order && order.createdAt);
      const date = /^\d{4}-\d{2}-\d{2}/.test(createdAt) ? createdAt.slice(0, 10) : '';
      const hour = new Date(createdAt).getUTCHours();
      if (Number.isInteger(hour)) orderingTimes[String(hour)] += 1;
      const customer = text(order && order.customer && order.customer.userId);
      if (customer) customers.set(customer, (customers.get(customer) || 0) + 1);
      const prep = Number(order && order.prepMinutes);
      if (Number.isFinite(prep)) prepTimes.push(Math.max(0, prep));
      if (order && !['delivered', 'completed'].includes(order.status)) return;
      if (date) {
        const day = sales.get(date) || { key: date, orders: 0, revenue: 0 };
        day.orders += 1; day.revenue += Math.max(0, Number(order.total) || 0); sales.set(date, day);
      }
      const items = Array.isArray(order && order.items) ? order.items : [];
      items.forEach(item => {
        const name = text(item && item.name);
        if (!name) return;
        const quantity = Math.max(1, Number(item.quantity) || 1);
        const current = menu.get(name) || { name, quantity: 0, revenue: 0 };
        current.quantity += quantity; current.revenue += Math.max(0, Number(item.unitPrice) || 0) * quantity; menu.set(name, current);
      });
    });
    const salesByDay = [...sales.values()].sort((a, b) => a.key.localeCompare(b.key));
    const statusCounts = Object.fromEntries(['pending', 'confirmed', 'preparing', 'ready_for_pickup', 'assigned', 'picked_up', 'delivered', 'completed', 'cancelled', 'refunded'].map(status => [status, 0]));
    orders.forEach(order => { if (Object.hasOwn(statusCounts, order.status)) statusCounts[order.status] += 1; });
    const completedRevenue = grossSales;
    return {
      totalOrders: orders.length,
      completedOrders: completed.length,
      grossSales: completedRevenue,
      netRevenue: completedRevenue - (completedRevenue * 0.1) + refundTotal,
      averageOrderValue: completed.length ? completedRevenue / completed.length : 0,
      repeatCustomers: [...customers.values()].filter(count => count > 1).length,
      salesByDay,
      statusCounts,
      orderingTimes,
      menuItems: [...menu.values()].sort((a, b) => b.revenue - a.revenue || a.name.localeCompare(b.name)),
      kitchen: { averagePrepMinutes: prepTimes.length ? prepTimes.reduce((sum, value) => sum + value, 0) / prepTimes.length : 0 }
    };
  };

  function renderAnalytics() {
    if (!doc.querySelector('[data-analytics-page]')) return;
    const result = buildAnalytics();
    say('[data-analytics-revenue]', money(result.netRevenue));
    say('[data-analytics-orders]', String(result.totalOrders));
    say('[data-analytics-aov]', money(result.averageOrderValue));
    say('[data-analytics-repeat]', String(result.repeatCustomers));
    const bars = doc.querySelector('[data-sales-chart-bars]');
    const summary = doc.querySelector('[data-sales-chart-summary]');
    if (bars) {
      bars.replaceChildren();
      const max = Math.max(...result.salesByDay.map(day => day.revenue), 1);
      result.salesByDay.forEach(day => {
        const bar = element('div', { className: 'restaurant-chart-bar', title: `${day.key}: ${money(day.revenue)} from ${day.orders} completed orders` });
        bar.style.height = `${Math.max(8, Math.round((day.revenue / max) * 220))}px`;
        bars.append(bar);
      });
    }
    if (summary) summary.textContent = result.salesByDay.length ? result.salesByDay.map(day => `${day.key}: ${money(day.revenue)} (${day.orders} orders)`).join('; ') : 'No completed local orders in this range.';
    const statuses = doc.querySelector('[data-status-chart-list]');
    if (statuses) {
      statuses.replaceChildren();
      Object.entries(result.statusCounts).filter(([, count]) => count).forEach(([status, count]) => statuses.append(element('div', {}, [element('dt', {}, status.replaceAll('_', ' ')), element('dd', {}, String(count))])));
    }
    say('[data-status-chart-summary]', result.totalOrders ? `${result.totalOrders} local orders in this range.` : 'No local orders in this range.');
    const heatmap = doc.querySelector('[data-ordering-heatmap-grid]');
    if (heatmap) {
      heatmap.replaceChildren();
      Object.entries(result.orderingTimes).forEach(([hour, count]) => heatmap.append(element('span', { className: 'restaurant-status-chip', title: `${hour}:00 — ${count} orders` }, `${hour}:00 ${count}`)));
    }
    say('[data-ordering-heatmap-summary]', result.totalOrders ? Object.entries(result.orderingTimes).filter(([, count]) => count).map(([hour, count]) => `${hour}:00: ${count}`).join('; ') : 'No local ordering-time data in this range.');
    const menu = doc.querySelector('[data-menu-performance-list]');
    if (menu) {
      menu.replaceChildren();
      result.menuItems.forEach(item => menu.append(element('li', {}, [element('span', {}, item.name), element('strong', {}, `${item.quantity} sold · ${money(item.revenue)}`)])));
      if (!result.menuItems.length) menu.append(element('li', { className: 'restaurant-empty' }, 'No completed local menu items in this range.'));
    }
    say('[data-kitchen-prep]', `${Math.round(result.kitchen.averagePrepMinutes)} min`);
    say('[data-kitchen-performance-summary]', result.kitchen.averagePrepMinutes ? `Average prep time across local orders: ${result.kitchen.averagePrepMinutes.toFixed(1)} minutes.` : 'No local prep-time data in this range.');
    say('[data-analytics-insight-copy]', result.menuItems.length ? `${result.menuItems[0].name} is the top local item with ${result.menuItems[0].quantity} sold.` : 'Complete local orders to surface a practical insight.');
  }

  let selectedReviewId = '';
  let serverReviewRecords = [];
  const currentReviews = () => verifiedReviews(serverReviewRecords);
  const readReviewFilters = () => ({ rating: doc.querySelector('[name="review-rating"]')?.value, status: doc.querySelector('[name="review-status"]')?.value, query: doc.querySelector('[name="review-search"]')?.value });
  function reviewCard(review) {
    const selected = review.id === selectedReviewId;
    const card = element('article', { className: 'restaurant-card', tabIndex: 0, role: 'button', 'aria-pressed': String(selected) }, [
      element('h3', {}, `${review.customer} · ${'★'.repeat(review.rating)}${'☆'.repeat(5 - review.rating)}`),
      element('p', {}, review.comment || 'No written feedback.'),
      element('p', { className: 'restaurant-field-hint' }, `${review.orderId} · ${dateLabel(review.createdAt)} · Verified completed order`),
      element('p', { className: 'restaurant-field-hint' }, review.replyStatus === 'published' ? 'Public reply saved' : review.replyStatus === 'draft' ? 'Reply draft saved' : 'Needs a public reply')
    ]);
    const select = () => { selectedReviewId = review.id; renderReviews(); };
    card.addEventListener('click', select);
    card.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); select(); } });
    return card;
  }
  function renderReviews() {
    if (!doc.querySelector('[data-reviews-page]')) return;
    const reviews = currentReviews();
    const visible = filterReviews(reviews, readReviewFilters());
    const selected = visible.find(review => review.id === selectedReviewId) || visible[0] || null;
    selectedReviewId = selected ? selected.id : '';
    const ratings = reviews.map(review => review.rating);
    say('[data-review-average]', ratings.length ? average(ratings).toFixed(1) : '—');
    say('[data-review-count]', ratings.length ? `${ratings.length} verified review${ratings.length === 1 ? '' : 's'}` : 'No verified reviews');
    [['food', 'food'], ['packaging', 'packaging'], ['preparation', 'preparation']].forEach(([selector, key]) => say(`[data-review-${selector}]`, reviews.length ? average(reviews.map(review => review[key])).toFixed(1) : '—'));
    const list = doc.querySelector('[data-review-list]');
    if (list) { list.replaceChildren(); visible.forEach(review => list.append(reviewCard(review))); if (!visible.length) list.append(element('p', { className: 'restaurant-empty' }, 'No verified reviews match these filters.')); }
    const context = doc.querySelector('[data-review-order-context]');
    const textarea = doc.querySelector('[name="review-public-reply"]');
    if (context) context.textContent = selected ? `${selected.customer} · ${selected.orderId} · ${selected.items.map(item => text(item && item.name)).filter(Boolean).join(', ') || 'No item details saved'}` : 'Choose a verified review to see its order context.';
    if (textarea) { textarea.value = selected ? selected.reply : ''; textarea.disabled = !selected; }
    updateCharacterCount();
    const topics = reviews.flatMap(review => review.topics).reduce((map, topic) => { map.set(topic, (map.get(topic) || 0) + 1); return map; }, new Map());
    say('[data-review-topics-list]', topics.size ? [...topics.entries()].sort((a, b) => b[1] - a[1]).map(([topic, count]) => `${topic} (${count})`).join(' · ') : 'No verified feedback topics yet.');
  }
  function updateCharacterCount() {
    const textarea = doc.querySelector('[name="review-public-reply"]');
    say('[data-review-character-count]', `${reviewText(textarea?.value).length} / 300`);
  }
  async function saveReply(publish) {
    const selected = currentReviews().find(review => review.id === selectedReviewId);
    const textarea = doc.querySelector('[name="review-public-reply"]');
    if (!selected || !textarea) { say('[data-review-feedback]', 'Choose a verified review before writing a reply.'); return; }
    const reply = reviewText(textarea.value).trim();
    if (!reply) { say('[data-review-feedback]', 'Write a public reply before saving.'); return; }
    const scope = `restaurant-review-${selected.id}`;
    try {
      await root.SavoraApi.post('api/reviews.php', { action: 'reply_review', payload: { publicId: selected.id, reply, status: publish ? 'published' : 'draft', version: selected.version } }, root.SavoraApi.intentKey(scope));
      serverReviewRecords = await root.SavoraApi.get('api/reviews.php'); root.SavoraApi.clearIntentKey(scope);
      say('[data-review-feedback]', publish ? 'Public reply refreshed from the server.' : 'Reply draft refreshed from the server.'); renderReviews();
    } catch (error) { say('[data-review-feedback]', error.message || 'Reply was not saved.'); }
  }
  function bind() {
    doc.querySelector('[data-analytics-range]')?.addEventListener('change', loadAnalytics);
    doc.querySelector('[data-export-analytics]')?.addEventListener('click', () => { root.location.href = 'api/analytics.php?export=csv'; });
    doc.querySelector('[data-export-reviews]')?.addEventListener('click', () => say('[data-review-feedback]', 'Review export is not available yet.'));
    doc.querySelector('[data-review-filters]')?.addEventListener('input', renderReviews);
    doc.querySelector('[data-review-filters]')?.addEventListener('change', renderReviews);
    doc.querySelector('[name="review-public-reply"]')?.addEventListener('input', updateCharacterCount);
    doc.querySelector('[data-review-save-draft]')?.addEventListener('click', () => saveReply(false));
    doc.querySelector('[data-review-publish]')?.addEventListener('click', () => saveReply(true));
  }
  async function loadAnalytics() {
    const range = selectedRange();
    const to = new Date();
    const from = new Date(to);
    from.setUTCDate(from.getUTCDate() - Math.max(1, range) + 1);
    const params = new URLSearchParams({ from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) });
    try {
      const report = await root.SavoraApi.get(`api/analytics.php?${params.toString()}`);
      serverAnalytics = report && report.kpis ? report : null;
      renderAnalytics();
    } catch (error) {
      serverAnalytics = null;
      say('[data-analytics-feedback]', error.message || 'Server analytics data is unavailable.');
      renderAnalytics();
    }
  }
  async function initialize() {
    bind();
    try {
      const snapshot = await root.SavoraApi.get('api/orders.php?pageSize=50');
      serverOrders = Array.isArray(snapshot && snapshot.orders) ? snapshot.orders : [];
    } catch (error) {
      say('[data-analytics-feedback]', error.message || 'Server analytics data is unavailable.');
    }
    await loadAnalytics();
    if (!doc.querySelector('[data-reviews-page]')) return;
    try { serverReviewRecords = await root.SavoraApi.get('api/reviews.php'); renderReviews(); }
    catch (error) { say('[data-review-feedback]', error.message || 'Reviews are unavailable.'); }
  }
  initialize();
  return { verifiedReviews, filterReviews, ordersInDateRange, countFulfilledOrders };
}));
