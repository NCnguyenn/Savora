(function attachRestaurantInsights(root, factory) {
  const api = factory(root);
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.SavoraRestaurantInsights = api;
}(typeof window === 'undefined' ? null : window, function createRestaurantInsights(root) {
  'use strict';
  const text = value => typeof value === 'string' ? value.slice(0, 500) : '';
  const reviewText = value => text(value).slice(0, 300);
  const readOrders = state => Array.isArray(state && state.orders) ? state.orders : [];
  const money = value => `$${(Number(value) || 0).toFixed(2)}`;
  const average = values => values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : 0;
  const dateLabel = value => {
    const date = new Date(value);
    return Number.isNaN(date.valueOf()) ? 'No saved date' : new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' }).format(date);
  };

  function verifiedReviews(customerState, restaurantState) {
    const saved = new Map((restaurantState && restaurantState.reviews || []).map(review => [text(review.id), review]));
    return readOrders(customerState).filter(order => order && order.status === 'completed' && order.review && typeof order.review === 'object').map(order => {
      const review = order.review;
      const id = text(review.id) || `order-${text(order.id)}`;
      const persisted = saved.get(id) || {};
      const rating = Math.min(5, Math.max(1, Number(review.rating) || 0));
      return {
        id, orderId: text(order.id) || 'Local order', customer: text(review.customer || order.customerName || 'Verified customer'), rating,
        comment: text(review.comment), createdAt: text(review.createdAt || order.createdAt), items: Array.isArray(order.items) ? order.items : [],
        topics: Array.isArray(review.topics) ? review.topics.map(text).filter(Boolean).slice(0, 4) : [],
        food: Number(review.food) || rating, packaging: Number(review.packaging) || rating, preparation: Number(review.preparation) || rating,
        reply: reviewText(persisted.reply), replyStatus: persisted.status === 'published' ? 'published' : persisted.status === 'draft' ? 'draft' : '',
        repliedAt: text(persisted.repliedAt)
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

  if (!root || !root.document) return { verifiedReviews, filterReviews, ordersInDateRange };

  const doc = root.document;
  const ui = () => root.SavoraRestaurantUI;
  const customerState = () => root.SavoraState.load();
  const api = root.SavoraRestaurantState;
  const restaurantState = () => api.load();
  const element = (tag, attrs, children) => ui().el(tag, attrs || {}, children || []);
  const say = (selector, value) => { const node = doc.querySelector(selector); if (node) node.textContent = value; };
  const selectedRange = () => Number(doc.querySelector('[data-analytics-range]')?.value || 30);
  const analytics = () => api.deriveAnalytics({ ...customerState(), orders: ordersInDateRange(readOrders(customerState()), selectedRange()) });

  function renderAnalytics() {
    if (!doc.querySelector('[data-analytics-page]')) return;
    const result = analytics();
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
  const currentReviews = () => verifiedReviews(customerState(), restaurantState());
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
    say('[data-review-count]', ratings.length ? `${ratings.length} verified local review${ratings.length === 1 ? '' : 's'}` : 'No verified reviews');
    [['food', 'food'], ['packaging', 'packaging'], ['preparation', 'preparation']].forEach(([selector, key]) => say(`[data-review-${selector}]`, reviews.length ? average(reviews.map(review => review[key])).toFixed(1) : '—'));
    const list = doc.querySelector('[data-review-list]');
    if (list) { list.replaceChildren(); visible.forEach(review => list.append(reviewCard(review))); if (!visible.length) list.append(element('p', { className: 'restaurant-empty' }, 'No verified local reviews match these filters.')); }
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
  function saveReply(publish) {
    const selected = currentReviews().find(review => review.id === selectedReviewId);
    const textarea = doc.querySelector('[name="review-public-reply"]');
    if (!selected || !textarea) { say('[data-review-feedback]', 'Choose a verified review before writing a reply.'); return; }
    const reply = reviewText(textarea.value).trim();
    if (!reply) { say('[data-review-feedback]', 'Write a public reply before saving.'); return; }
    api.persist(api.setReviewReply(restaurantState(), selected.id, reply, publish ? 'published' : 'draft'));
    say('[data-review-feedback]', publish ? 'Public reply published in this local demo.' : 'Reply draft saved in this browser.');
    renderReviews();
  }
  function bind() {
    doc.querySelector('[data-analytics-range]')?.addEventListener('change', renderAnalytics);
    doc.querySelector('[data-export-analytics]')?.addEventListener('click', () => say('[data-analytics-feedback]', 'Export is a local-demo preview; no report file was created.'));
    doc.querySelector('[data-export-reviews]')?.addEventListener('click', () => say('[data-review-feedback]', 'Export is a local-demo preview; no feedback file was created.'));
    doc.querySelector('[data-review-filters]')?.addEventListener('input', renderReviews);
    doc.querySelector('[data-review-filters]')?.addEventListener('change', renderReviews);
    doc.querySelector('[name="review-public-reply"]')?.addEventListener('input', updateCharacterCount);
    doc.querySelector('[data-review-save-draft]')?.addEventListener('click', () => saveReply(false));
    doc.querySelector('[data-review-publish]')?.addEventListener('click', () => saveReply(true));
  }
  bind(); renderAnalytics(); renderReviews();
  return { verifiedReviews, filterReviews, ordersInDateRange };
}));
